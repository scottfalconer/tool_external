<?php

declare(strict_types=1);

namespace Drupal\tool_external\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigRenameEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Invalidates the Tool API catalog when local tool configuration changes.
 */
final class DefinitionCacheSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly ContainerInterface $container) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after ConfigFactory's cache updates. Resolve the manager lazily so
    // unrelated config writes need not initialize plugin discovery services.
    return [
      ConfigEvents::SAVE => ['onChange', -100],
      ConfigEvents::DELETE => ['onChange', -100],
      ConfigEvents::RENAME => ['onChange', -100],
    ];
  }

  /**
   * Clears the catalog for both the old and new name of renamed config.
   */
  public function onChange(ConfigCrudEvent $event): void {
    $names = [$event->getConfig()->getName()];
    if ($event instanceof ConfigRenameEvent) {
      $names[] = $event->getOldName();
    }
    foreach ($names as $name) {
      if (str_starts_with($name, 'tool_external.tool.')) {
        $this->container->get('plugin.manager.tool')->clearCachedDefinitions();
        // Action providers may derive their own plugins from the tool catalog.
        $this->container->get('plugin.manager.action', ContainerInterface::NULL_ON_INVALID_REFERENCE)?->clearCachedDefinitions();
        return;
      }
    }
  }

}
