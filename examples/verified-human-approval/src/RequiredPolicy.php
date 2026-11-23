<?php

declare(strict_types=1);

namespace SourceFunctions\VerifiedHumanApproval;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\PolicyProvider;

final class RequiredPolicy implements PolicyProvider
{
    public function get(string $actionId, ?Actor $actor = null): ApprovalPolicy
    {
        if ($actionId !== AuthorizePublication::ID) {
            throw new \InvalidArgumentException('Unknown approval action.');
        }
        return new ApprovalPolicy($actionId, '1',
            'I authorize publication of the exact field snapshot {{hash}} for entity {{entity}} on site {{site}}. Drupal must still verify and publish it.',
            600, true);
    }
}
