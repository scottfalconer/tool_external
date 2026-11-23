<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

use SourceFunctions\VerifiedHumanApproval\Setup;

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php bin/setup.php /private/provider.json https://approval.example.test DRUPAL_SITE_UUID\n");
    exit(2);
}
$result = Setup::create($argv[1], $argv[2], $argv[3]);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
