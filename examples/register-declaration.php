<?php

/**
 * @file
 * Installs a reviewed, local function declaration with site-owned policy.
 */

use Drupal\tool\Tool\ToolOperation;
use Drupal\tool_external\Entity\ExternalTool;

/**
 * Arguments supplied by Drush php:script.
 *
 * @var array<int, string> $extra
 */
if (count($extra) < 3 || count($extra) > 4) {
  throw new InvalidArgumentException('Supply a local JSON file, connection, Tool API operation and optional Drupal permission.');
}
[$path, $connection, $operation] = $extra;
$local_path = realpath($path);
if ($local_path === FALSE || !is_file($local_path) || !is_readable($local_path)) {
  throw new InvalidArgumentException('Supply a readable local declaration file.');
}
$json = file_get_contents($local_path, FALSE, NULL, 0, 65537);
if ($json === FALSE) {
  throw new RuntimeException('Could not read the tool declaration.');
}
$tool = ExternalTool::fromDeclaration($json, $connection, ToolOperation::from($operation), $extra[3] ?? 'execute external tools');
$tool->save();
print 'Registered external:' . $tool->id() . PHP_EOL;
