<?php

declare(strict_types=1);

namespace SourceFunctions\VerifiedHumanApproval;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Authorizer;
use VerifiedHumanApproval\Core\PreparedAction;

/** The configured reviewer is site policy, independent of the initiating user. */
final readonly class SiteAuthority implements Authorizer
{
    public function __construct(private \PDO $pdo, private Actor $reviewer) {}

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        $enabled = $this->pdo->query('SELECT enabled FROM provider_control WHERE id = 1')->fetchColumn();
        if ((int) $enabled !== 1 || !$this->reviewer->samePerson($actor)
            || ($action->target['site_id'] ?? null) !== $this->reviewer->tenantId) {
            throw new ApprovalException('not_allowed', 'This site or reviewer is not currently enabled.');
        }
    }
}
