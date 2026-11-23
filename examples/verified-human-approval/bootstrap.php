<?php

declare(strict_types=1);

// Point VHA_AUTOLOAD at the pinned approval library's Composer autoloader.
$autoload = getenv('VHA_AUTOLOAD') ?: __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Set VHA_AUTOLOAD to the installed Verified Human Approval Composer autoloader.');
}
require_once $autoload;
spl_autoload_register(static function (string $class): void {
    $prefix = 'SourceFunctions\\VerifiedHumanApproval\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
