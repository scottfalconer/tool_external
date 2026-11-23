<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

use SourceFunctions\VerifiedHumanApproval\Application;
use SourceFunctions\VerifiedHumanApproval\Setup;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
function rejected(callable $action, string $message): void
{
    try { $action(); } catch (ApprovalException|JsonException $error) { check(true, $message); return; }
    check(false, $message);
}
function request(string $title = 'Reviewed title', string $generation = '1'): array
{
    $metadata = ['site_id' => 'test-site-uuid', 'entity_uuid' => '00000000-1111-2222-3333-444444444444',
        'entity_type' => 'node', 'bundle' => 'article', 'langcode' => 'en', 'generation' => $generation, 'operation' => 'publish'];
    $snapshot = json_encode($metadata + ['fields' => ['title' => [['value' => $title]], 'body' => [['value' => 'Exact body bytes', 'format' => 'plain_text']]]], JSON_THROW_ON_ERROR);
    return ['protocol' => 'drupal-external-tool/1', 'tool' => ['id' => 'external:verified_human_publish', 'version' => 1],
        'invocation_id' => 'test-invocation', 'context' => ['site_id' => 'test-site-uuid', 'actor' => ['id' => 'arbitrary-site-initiator']],
        'inputs' => ['publication' => $metadata + ['snapshot_json' => $snapshot, 'snapshot_hash' => hash('sha256', $snapshot)]]];
}

$directory = sys_get_temp_dir() . '/source-vha-provider-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
try {
    $setup = Setup::create($directory . '/config.json', 'https://approval.test', 'test-site-uuid', true);
    $config = json_decode(file_get_contents($setup['config_path']), true, flags: JSON_THROW_ON_ERROR);
    $clock = new MockClock('2026-11-21T12:00:00Z');
    $app = new Application($config, $clock);
    $authenticator = new VirtualAuthenticator('https://approval.test');
    $options = $app->registrationOptions();
    $registration = $authenticator->registration($options);
    rejected(fn () => $app->enroll('wrong-code', $options, $registration), 'Untrusted enrollment was accepted.');
    $app->enroll($setup['enrollment_code'], $options, $registration);
    check(count($app->credentials->forActor($app->reviewer)) === 1, 'The trusted passkey was not anchored.');
    rejected(fn () => $app->enroll($setup['enrollment_code'], $options, $registration), 'Enrollment code was reused.');

    $pending = $app->invoke(request())['outputs'];
    check($pending['decision'] === 'pending', 'Publication should initially require approval.');
    check((int) $app->pdo->query('SELECT COUNT(*) FROM publication_authorizations')->fetchColumn() === 0, 'Pending action executed.');
    check($app->invoke(request())['outputs']['approval_id'] === $pending['approval_id'], 'Pending retries created different proposals.');

    $ceremony = $app->service->begin($app->reviewer, $pending['approval_id']);
    rejected(fn () => $app->service->complete($app->reviewer, $ceremony->id, $authenticator->assertion($ceremony->options, ['flags' => 0x01])), 'A signature without user verification passed.');
    $ceremony = $app->service->begin($app->reviewer, $pending['approval_id']);
    $assertion = $authenticator->assertion($ceremony->options);
    $state = $app->service->complete($app->reviewer, $ceremony->id, $assertion);
    check($state->status === 'completed', 'The real passkey assertion failed.');
    check($state->execution['approval'] === 'user_verified', 'No verified-user execution record.');
    check($state->result['drupal_published'] === false, 'Authorization falsely claimed Drupal publication.');
    $verified = (new ReceiptVerifier())->verify((new EvidenceExporter())->export($state), $app->trustedKeys);
    check($verified['receiptSignedAt'] === Receipt::COMMIT, 'The authorization receipt was not signed at commit.');
    check($app->invoke(request())['outputs']['decision'] === 'approved', 'Exact approved candidate was rejected.');
    $app->service->complete($app->reviewer, $ceremony->id, $assertion);
    check((int) $app->pdo->query('SELECT COUNT(*) FROM publication_authorizations')->fetchColumn() === 1, 'Repeated completion issued a second authorization.');

    check($app->invoke(request('Changed title'))['outputs']['decision'] === 'pending', 'Changed content reused approval.');
    check($app->invoke(request(generation: '2'))['outputs']['decision'] === 'pending', 'Later generation reused approval.');
    $tampered = request();
    $tampered['inputs']['publication']['snapshot_json'] .= ' ';
    rejected(fn () => $app->invoke($tampered), 'Changed bytes with the old hash passed.');
    $otherSite = request();
    $otherSite['context']['site_id'] = 'another-site';
    rejected(fn () => $app->invoke($otherSite), 'Another site was accepted.');

    $app->store->transaction(fn () => $app->credentials->revoke($app->reviewer, $authenticator->id(), ['method' => 'test_revocation']));
    check($app->invoke(request())['outputs']['decision'] === 'denied', 'Revoked passkey authorization remained usable.');
    $clock->sleep(601);
    $fresh = $app->invoke(request())['outputs'];
    check($fresh['decision'] === 'pending' && $fresh['approval_id'] !== $pending['approval_id'], 'Expired authorization did not require fresh approval.');
    $app->service->cancel($app->reviewer, $fresh['approval_id']);
    $again = $app->invoke(request())['outputs'];
    check($again['decision'] === 'pending' && $again['approval_id'] !== $fresh['approval_id'], 'Cancelled proposal permanently poisoned the candidate.');
    $app->pdo->exec('UPDATE provider_control SET enabled = 0 WHERE id = 1');
    rejected(fn () => $app->invoke(request('Different after revocation')), 'Disabled site created an approval.');
    echo json_encode(['checks' => $checks, 'status' => 'passed', 'passkey' => 'real software ES256/WebAuthn assertion', 'receipt' => 'Ed25519 verified'], JSON_THROW_ON_ERROR), "\n";
} finally {
    unset($app);
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
