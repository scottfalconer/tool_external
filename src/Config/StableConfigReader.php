<?php

declare(strict_types=1);

namespace Drupal\tool_external\Config;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Reads deployment-stable configuration for cached external tool contracts.
 *
 * Tool API caches one catalog for the site. Language, domain and other
 * request-dependent module overrides must not change its schema or authority.
 * Cloning core's config object retains settings.php overrides and their normal
 * merge semantics without mutating the object used by other Drupal consumers.
 */
final class StableConfigReader {

  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * Returns stored data plus settings.php overrides, or [] for missing config.
   */
  public function read(string $name): array {
    $config = clone $this->configFactory->get($name);
    if ($config->isNew()) {
      return [];
    }
    $config->setModuleOverride([]);
    return $config->get();
  }

  /**
   * Applies the same settings.php overrides to staged import data.
   */
  public function withOverrides(string $name, array $data): array {
    $config = clone $this->configFactory->get($name);
    $config->initWithData($data);
    $config->setModuleOverride([]);
    return $config->get();
  }

}
