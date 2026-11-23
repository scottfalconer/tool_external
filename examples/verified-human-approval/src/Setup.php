<?php

declare(strict_types=1);

namespace SourceFunctions\VerifiedHumanApproval;

use VerifiedHumanApproval\Core\ChallengeBinding;

final class Setup
{
    /** @return array{config_path: string, browser_password: string, enrollment_code: string} */
    public static function create(string $path, string $origin, string $siteId, bool $testFixture = false): array
    {
        if (file_exists($path)) {
            throw new \RuntimeException('Refusing to replace existing provider configuration.');
        }
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
            throw new \RuntimeException('Cannot create private configuration directory.');
        }
        if (file_exists($parent . '/approval.sqlite')) {
            throw new \RuntimeException('Refusing to replace existing provider database.');
        }
        $password = bin2hex(random_bytes(18));
        $enrollment = bin2hex(random_bytes(32));
        $keypair = sodium_crypto_sign_keypair();
        $config = [
            'database' => $parent . '/approval.sqlite', 'origin' => $origin,
            'rp_id' => (string) parse_url($origin, PHP_URL_HOST), 'site_id' => $siteId,
            'reviewer_id' => 'editor', 'site_token' => bin2hex(random_bytes(32)),
            'browser_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'signing_secret' => ChallengeBinding::encode(sodium_crypto_sign_secretkey($keypair)),
            'test_fixture' => $testFixture,
        ];
        // Restrict before writing; generated secrets never belong in webroot/git.
        $previous = umask(0077);
        try {
            file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
            chmod($path, 0600);
            $app = new Application($config);
            $app->initialize($enrollment);
            chmod($config['database'], 0600);
        } finally {
            umask($previous);
        }
        return ['config_path' => $path, 'browser_password' => $password, 'enrollment_code' => $enrollment];
    }
}
