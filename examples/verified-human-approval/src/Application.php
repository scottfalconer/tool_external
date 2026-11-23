<?php

declare(strict_types=1);

namespace SourceFunctions\VerifiedHumanApproval;

use Psr\Clock\ClockInterface;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

final class Application
{
    public readonly \PDO $pdo;
    public readonly PdoApprovalStore $store;
    public readonly WebAuthnVerifier $verifier;
    public readonly AnchoredCredentialRepository $credentials;
    public readonly ApprovalService $service;
    public readonly Actor $reviewer;
    public readonly ClockInterface $clock;
    public readonly TrustedKeys $trustedKeys;
    private readonly SiteAuthority $authority;

    /** @param array<string, mixed> $config */
    public function __construct(public readonly array $config, ?ClockInterface $clock = null)
    {
        foreach (['database', 'origin', 'rp_id', 'site_id', 'reviewer_id', 'site_token', 'browser_password_hash', 'signing_secret'] as $name) {
            if (!is_string($config[$name] ?? null) || $config[$name] === '') {
                throw new \InvalidArgumentException('Missing provider configuration: ' . $name);
            }
        }
        $this->clock = $clock ?? new class implements ClockInterface {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
        };
        $this->pdo = new \PDO('sqlite:' . $config['database'], options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 1]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->store = new PdoApprovalStore($this->pdo);
        $signer = SodiumEvidenceSigner::fromEncoded('source-functions-demo-1', $config['signing_secret']);
        $this->trustedKeys = TrustedKeys::single($signer->keyId(), $signer->publicKey());
        $audit = new AuditLog(new PdoAuditStore($this->pdo), $signer, $this->trustedKeys, $this->clock);
        $this->credentials = new AnchoredCredentialRepository(new PdoCredentialRepository($this->pdo), $audit);
        $this->verifier = new WebAuthnVerifier(new Configuration($config['rp_id'], $config['origin']), $this->credentials);
        $this->reviewer = new Actor($config['reviewer_id'], $config['site_id']);
        $guard = new ExecutionGuard();
        $this->authority = new SiteAuthority($this->pdo, $this->reviewer);
        $this->service = new ApprovalService(
            new ActionRegistry([new AuthorizePublication($this->pdo, $guard, $config['site_id'])]),
            new RequiredPolicy(), $this->authority, $this->store, $this->verifier, $this->clock, $guard, $signer,
            trustedKeys: $this->trustedKeys,
        );
    }

    public static function fromFile(string $path): self
    {
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException('Cannot read provider configuration.');
        }
        return new self(json_decode($json, true, 32, JSON_THROW_ON_ERROR));
    }

    /** Installation only. Serving requests never creates or changes schema. */
    public function initialize(string $enrollmentToken): void
    {
        $this->store->initialize();
        (new PdoAuditStore($this->pdo))->initialize();
        (new PdoCredentialRepository($this->pdo))->initialize();
        $this->pdo->exec('CREATE TABLE publication_authorizations (id TEXT PRIMARY KEY, result_json TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE publication_attempts (fingerprint TEXT PRIMARY KEY, issuance INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE provider_control (id INTEGER PRIMARY KEY CHECK (id = 1), enabled INTEGER NOT NULL, enrollment_hash TEXT)');
        $this->pdo->prepare('INSERT INTO provider_control VALUES (1, 1, ?)')->execute([hash('sha256', $enrollmentToken)]);
    }

    /** Native external Tool API request; never uses the initiator as approver. */
    public function invoke(array $envelope): array
    {
        if (($envelope['protocol'] ?? null) !== 'drupal-external-tool/1'
            || !is_string($envelope['invocation_id'] ?? null) || strlen($envelope['invocation_id']) > 128
            || ($envelope['tool']['id'] ?? null) !== 'external:verified_human_publish'
            || ($envelope['tool']['version'] ?? null) !== 1
            || ($envelope['context']['site_id'] ?? null) !== $this->config['site_id']) {
            throw new ApprovalException('invalid_input', 'The external tool invocation is invalid.');
        }
        $publication = Publication::validate($envelope['inputs']['publication'] ?? null, $this->config['site_id']);
        $fingerprint = hash('sha256', CanonicalJson::encode([$this->reviewer->toArray(), $publication, 'adapter-v1', (new RequiredPolicy())->get(AuthorizePublication::ID)->digest()]));
        $issuance = $this->issuance($fingerprint);
        $state = $this->service->request($this->reviewer, AuthorizePublication::ID, $publication, $fingerprint . ':' . $issuance);
        if (!in_array($state->status, ['pending', 'completed'], true) || $this->clock->now() >= $state->request->expiresAt()) {
            // An explicit new publish attempt can ask again after expiry/cancel.
            // Keep the old immutable record, and do not permanently poison this
            // candidate's deterministic request key with a terminal result.
            $issuance = $this->issuance($fingerprint, $issuance);
            $state = $this->service->request($this->reviewer, AuthorizePublication::ID, $publication, $fingerprint . ':' . $issuance);
        }
        return ['protocol' => 'drupal-external-tool/1', 'invocation_id' => $envelope['invocation_id'],
            'outputs' => $this->outputs($state, $publication)];
    }

    /** @param array<string, string> $publication */
    private function outputs(ApprovalState $state, array $publication): array
    {
        $decision = 'denied';
        $message = 'Approval is ' . $state->status . '. Publish was not authorized.';
        if ($state->status === 'pending') {
            $decision = 'pending';
            $message = 'Human approval is required. Review the frozen field snapshot, then retry publication in Drupal.';
        } elseif ($state->status === 'completed'
            && ($state->execution['approval'] ?? null) === 'user_verified'
            && ($state->result['effect'] ?? null) === 'publication_authorized'
            && ($state->result['drupal_published'] ?? null) === false
            && ($state->result['snapshot_hash'] ?? null) === $publication['snapshot_hash']
            && ($state->result['generation'] ?? null) === $publication['generation']
            && $state->request->prepared()->version === '1'
            && ($state->request->payload()['policyDigest'] ?? null) === (new RequiredPolicy())->get(AuthorizePublication::ID)->digest()
            && is_string($state->proof['credentialId'] ?? null)
            && $this->credentials->find($state->proof['credentialId']) !== null
            && $this->clock->now() < $state->request->expiresAt()) {
            $this->authority->assertAllowed($this->reviewer, $state->request->prepared());
            $decision = 'approved';
            $message = 'The human approved this exact field snapshot. Drupal must still verify and commit publication.';
        }
        return ['decision' => $decision, 'approval_id' => $state->request->id,
            'proposal_digest' => $publication['snapshot_hash'],
            'review_url' => $this->config['origin'] . '/review/' . rawurlencode($state->request->id),
            'message' => $message];
    }

    private function issuance(string $fingerprint, ?int $rotate = null): int
    {
        return $this->store->transaction(function () use ($fingerprint, $rotate): int {
            $this->pdo->prepare('INSERT OR IGNORE INTO publication_attempts (fingerprint, issuance) VALUES (?, 0)')->execute([$fingerprint]);
            if ($rotate !== null) {
                $this->pdo->prepare('UPDATE publication_attempts SET issuance = issuance + 1 WHERE fingerprint = ? AND issuance = ?')->execute([$fingerprint, $rotate]);
            }
            $query = $this->pdo->prepare('SELECT issuance FROM publication_attempts WHERE fingerprint = ?');
            $query->execute([$fingerprint]);
            return (int) $query->fetchColumn();
        });
    }

    public function registrationOptions(): array
    {
        return $this->verifier->registrationOptions($this->reviewer, random_bytes(32), 'Site publication reviewer');
    }

    /** Trusted first enrollment: browser password plus separately delivered one-use code. */
    public function enroll(string $token, array $options, array $response): void
    {
        $this->store->transaction(function () use ($token, $options, $response): void {
            $expected = $this->pdo->query('SELECT enrollment_hash FROM provider_control WHERE id = 1')->fetchColumn();
            if (!is_string($expected) || !hash_equals($expected, hash('sha256', $token))) {
                throw new ApprovalException('invalid_enrollment', 'The one-time enrollment code is invalid or was used.');
            }
            $credential = $this->verifier->register($this->reviewer, $options, $response);
            $this->credentials->anchor($credential, ['method' => 'operator_bootstrap', 'one_time' => true]);
            $this->pdo->exec('UPDATE provider_control SET enrollment_hash = NULL WHERE id = 1');
        });
    }
}
