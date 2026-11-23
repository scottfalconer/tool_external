<?php

declare(strict_types=1);

namespace SourceFunctions\VerifiedHumanApproval;

use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;

/** Records authorization here. The Drupal database is intentionally not called. */
final readonly class AuthorizePublication implements ActionAdapter
{
    public const ID = 'drupal.authorize_publication';

    public function __construct(private \PDO $pdo, private ExecutionGuard $guard, private string $siteId) {}

    public function id(): string { return self::ID; }

    public function version(): string { return '1'; }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $publication = Publication::validate($input, $this->siteId);
        return new PreparedAction(
            $this->id(),
            $this->version(),
            ['site_id' => $publication['site_id'], 'entity_uuid' => $publication['entity_uuid'], 'entity_type' => 'node'],
            $publication,
            ['generation' => $publication['generation'], 'snapshot_hash' => $publication['snapshot_hash']],
            [
                'heading' => 'Authorize this Drupal publication candidate',
                'snapshot_json' => $publication['snapshot_json'],
                'boundary' => 'This approves the exact field snapshot. It does not publish Drupal content, approve referenced content or certify the rendered page. Return to Drupal and retry publication.',
            ],
            ['site' => $publication['site_id'], 'entity' => $publication['entity_uuid'], 'hash' => $publication['snapshot_hash']],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        Publication::validate($action->effect, $this->siteId);
        // Drupal rechecks its current generation and snapshot at save time. This
        // action has no Drupal connection and makes no remote-state assertion.
    }

    public function execute(Actor $actor, PreparedAction $action): array
    {
        $this->guard->assertExecuting($actor, $action);
        $id = $this->guard->current()?->requestId ?? throw new \LogicException('Missing execution context.');
        $result = ['authorization_id' => $id, 'site_id' => $this->siteId, 'entity_uuid' => $action->effect['entity_uuid'],
            'generation' => $action->effect['generation'], 'snapshot_hash' => $action->effect['snapshot_hash'],
            'effect' => 'publication_authorized', 'drupal_published' => false];
        $this->pdo->prepare('INSERT INTO publication_authorizations (id, result_json) VALUES (?, ?)')
            ->execute([$id, json_encode($result, JSON_THROW_ON_ERROR)]);
        return $result;
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $query = $this->pdo->prepare('SELECT result_json FROM publication_authorizations WHERE id = ?');
        $query->execute([$result['authorization_id'] ?? '']);
        $stored = $query->fetchColumn();
        if (!is_string($stored) || json_decode($stored, true, flags: JSON_THROW_ON_ERROR) !== $result
            || $result['snapshot_hash'] !== $action->effect['snapshot_hash'] || $result['drupal_published'] !== false) {
            throw new ApprovalException('postcondition_failed', 'The local publication authorization was not recorded.');
        }
    }
}
