<?php

declare(strict_types=1);

// A local CLI client for the example provider. Sends one synthetic article.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$path = getenv('VHA_PROVIDER_CONFIG');
if (!$path || !is_readable($path)) {
    fwrite(STDERR, "Set VHA_PROVIDER_CONFIG to the private provider configuration.\n");
    exit(1);
}
$config = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$metadata = [
    'site_id' => $config['site_id'],
    'entity_uuid' => '6a9fd8f0-1c49-4a65-84c1-74fbb4dea88a',
    'entity_type' => 'node',
    'bundle' => 'article',
    'langcode' => 'en',
    'generation' => '3',
    'operation' => 'publish',
];
$title = $argv[1] ?? 'Winter service hours';
$snapshot = json_encode($metadata + ['fields' => ['title' => [['value' => $title]]]], JSON_THROW_ON_ERROR);
$call = [
    'protocol' => 'drupal-external-tool/1',
    'tool' => ['id' => 'external:verified_human_publish', 'version' => 1],
    'invocation_id' => bin2hex(random_bytes(16)),
    'context' => ['site_id' => $config['site_id']],
    'inputs' => ['publication' => $metadata + ['snapshot_json' => $snapshot, 'snapshot_hash' => hash('sha256', $snapshot)]],
];
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['site_token']],
    'content' => json_encode($call, JSON_THROW_ON_ERROR),
    'timeout' => 3,
    'follow_location' => 0,
]]);
$response = file_get_contents($config['origin'] . '/invoke', false, $context);
if ($response === false) {
    exit(1);
}
$result = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
if (($result['invocation_id'] ?? null) !== $call['invocation_id'] || !isset($result['outputs'])) {
    throw new RuntimeException('Unexpected response to the publication request.');
}
echo json_encode($result['outputs'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
