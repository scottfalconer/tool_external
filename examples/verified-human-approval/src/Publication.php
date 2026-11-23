<?php

declare(strict_types=1);

namespace SourceFunctions\VerifiedHumanApproval;

use VerifiedHumanApproval\Core\ApprovalException;

/** The external representation is owned by this example's declared Tool input. */
final class Publication
{
    public const KEYS = ['site_id', 'entity_uuid', 'entity_type', 'bundle', 'langcode', 'generation', 'operation', 'snapshot_json', 'snapshot_hash'];

    /** @return array<string, string> */
    public static function validate(mixed $input, string $siteId): array
    {
        if (!is_array($input) || array_diff(array_keys($input), self::KEYS) || array_diff(self::KEYS, array_keys($input))) {
            throw new ApprovalException('invalid_input', 'The publication contract is incomplete.');
        }
        foreach ($input as $name => $value) {
            if (!is_string($value) || $value === '' || strlen($value) > ($name === 'snapshot_json' ? 100000 : 255)) {
                throw new ApprovalException('invalid_input', 'Publication inputs must be bounded, nonempty strings.');
            }
        }
        if (!hash_equals($siteId, $input['site_id']) || $input['entity_type'] !== 'node' || $input['operation'] !== 'publish'
            || !preg_match('/^(0|[1-9][0-9]*)$/D', $input['generation'])
            || !preg_match('/^[a-f0-9]{64}$/D', $input['snapshot_hash'])
            || !hash_equals(hash('sha256', $input['snapshot_json']), $input['snapshot_hash'])) {
            throw new ApprovalException('invalid_input', 'The publication identity or snapshot digest is invalid.');
        }
        $snapshot = json_decode($input['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || array_is_list($snapshot)) {
            throw new ApprovalException('invalid_input', 'The frozen snapshot must be a JSON object.');
        }
        foreach (array_diff(self::KEYS, ['snapshot_json', 'snapshot_hash']) as $key) {
            if (($snapshot[$key] ?? null) !== $input[$key]) {
                throw new ApprovalException('invalid_input', 'Snapshot metadata must agree with the declared publication arguments.');
            }
        }
        return $input;
    }
}
