<?php

declare(strict_types=1);

namespace Drupal\tool_external\Transport;

use Drupal\Core\Site\Settings;
use Drupal\tool_external\Config\StableConfigReader;
use Drupal\tool_external\Exception\ExternalToolException;

/**
 * Resolves portable connection names against operator-controlled settings.
 */
final class ConnectionResolver {

  /**
   * A positive millisecond count that also fits 32-bit cURL builds. */
  private const MAX_TIMEOUT_MILLISECONDS = 2147483647;

  public function __construct(
    private readonly StableConfigReader $config,
    private readonly Settings $settings,
  ) {}

  /**
   * Returns effective connection settings; secrets never enter export config.
   */
  public function resolve(string $name, string $tool_id): array {
    $platform = $this->settings->get('tool_external', []);
    if (!is_array($platform) || ($platform['enabled'] ?? FALSE) !== TRUE) {
      throw new ExternalToolException('disabled', 'External tool execution is disabled in this environment.');
    }
    $connections = $this->config->read('tool_external.settings')['connections'] ?? [];
    $connection = $connections[$name] ?? NULL;
    if (!is_array($connection)) {
      throw new ExternalToolException('connection', 'The external tool connection is not configured.');
    }
    // Per-environment URLs are supplied through ordinary config overrides.
    $url = $connection['url'] ?? '';
    $parts = is_string($url) ? parse_url($url) : FALSE;
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
      throw new ExternalToolException('destination', 'The external tool endpoint URL is invalid.');
    }
    $host = strtolower($parts['host']);
    $allowed = $platform['allowed_hosts'] ?? [];
    $allowed_hosts = is_array($allowed) ? array_filter($allowed, 'is_string') : [];
    if (!in_array($host, array_map('strtolower', $allowed_hosts), TRUE)) {
      throw new ExternalToolException('destination', 'The external tool host is not enabled by this platform.');
    }
    $local_http = ($platform['allow_local_http'] ?? FALSE) === TRUE
      && in_array($host, ['127.0.0.1', '[::1]', 'localhost'], TRUE)
      && $parts['scheme'] === 'http';
    if ($parts['scheme'] !== 'https' && !$local_http) {
      throw new ExternalToolException('destination', 'External tool endpoints must use HTTPS.');
    }
    $max_timeout = $this->positiveNumber($platform['max_timeout'] ?? 3.0, 'max_timeout');
    $timeout = min($this->positiveNumber($connection['timeout'] ?? 2.0, 'timeout'), $max_timeout);
    $secret_name = $connection['secret'] ?? '';
    $secrets = $platform['secrets'] ?? [];
    $secret = is_array($secrets) && is_string($secret_name) ? ($secrets[$secret_name] ?? NULL) : NULL;
    // Bind the bearer to its intended endpoint even when several providers are
    // allowed. Existing installs may still supply an unbound string secret.
    if (is_array($secret)) {
      if (($secret['url'] ?? NULL) !== $url) {
        throw new ExternalToolException('credentials', 'The external tool credential is not enabled for this endpoint.');
      }
      if (array_key_exists('allowed_tools', $secret) && !$this->allowsTool($secret['allowed_tools'], $tool_id)) {
        throw new ExternalToolException('credentials', 'The external tool credential is not enabled for this tool.');
      }
      $secret = $secret['token'] ?? NULL;
    }
    if (!is_string($secret) || $secret === '' || preg_match('/[\r\n]/', $secret)) {
      throw new ExternalToolException('credentials', 'The external tool credential is not available in this environment.');
    }
    return [
      'url' => $url,
      'secret' => $secret,
      'timeout' => $timeout,
      'max_request_bytes' => $this->positiveInteger($platform['max_request_bytes'] ?? 65536),
      'max_response_bytes' => $this->positiveInteger($platform['max_response_bytes'] ?? 65536),
    ];
  }

  /**
   * Checks the full plugin ID against an operator-supplied list of tools.
   */
  private function allowsTool(mixed $allowed, string $tool_id): bool {
    if (!is_array($allowed) || !array_is_list($allowed) || !in_array($tool_id, $allowed, TRUE)) {
      return FALSE;
    }
    foreach ($allowed as $id) {
      if (!is_string($id) || !preg_match('/^external:[a-z][a-z0-9_]*$/D', $id)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Validates seconds against the range supported by cURL.
   */
  private function positiveNumber(mixed $value, string $name): float {
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
      || $value < 0.001 || $value > self::MAX_TIMEOUT_MILLISECONDS / 1000) {
      throw new ExternalToolException('configuration', sprintf('The external tool %s must be a finite number between 0.001 and %s seconds.', $name, self::MAX_TIMEOUT_MILLISECONDS / 1000));
    }
    return (float) $value;
  }

  /**
   * Validates a positive byte limit.
   */
  private function positiveInteger(mixed $value): int {
    if (!is_int($value) || $value < 1) {
      throw new ExternalToolException('configuration', 'An external tool size limit is invalid.');
    }
    return $value;
  }

}
