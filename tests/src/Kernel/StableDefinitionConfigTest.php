<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Kernel;

use Drupal\Core\Language\Language;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\tool_external\Entity\ExternalTool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Keeps discovery and execution configuration stable across request contexts.
 */
#[Group('tool_external')]
#[RunTestsInSeparateProcesses]
final class StableDefinitionConfigTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'language', 'tool', 'tool_external'];

  /**
   * Keeps cached tool contracts stable across language overrides.
   */
  public function testLanguageOverridesDoNotChangeCachedContracts(): void {
    $this->installConfig(['system', 'language', 'tool_external']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ExternalTool::create([
      'id' => 'review',
      'label' => 'Stable tool',
      'description' => 'Review probe.',
      'connection' => 'demo',
      'inputs' => ['title' => ['data_type' => 'string']],
      'outputs' => [],
    ])->save();
    $name = 'tool_external.tool.review';
    $override = $this->container->get('language.config_factory_override');
    // Programmatic overrides can include structural values even though these
    // fields are deliberately not offered as translatable schema values.
    $override->getStorage('fr')->write($name, [
      'label' => 'Context-specific label',
      'permission' => 'administer external tools',
      'connection' => 'different_destination',
      'inputs' => ['title' => ['data_type' => 'integer']],
    ]);
    $override->setLanguage(new Language(['id' => 'fr']));
    $this->container->get('config.factory')->reset($name);
    $manager = $this->container->get('plugin.manager.tool');
    $manager->clearCachedDefinitions();
    $reader = $this->container->get('tool_external.stable_config');
    $this->assertSame('Context-specific label', $this->container->get('config.factory')->get($name)->get('label'));
    $this->assertSame('Stable tool', (string) $manager->getDefinition('external:review')->getLabel());
    $this->assertSame('execute external tools', $manager->getDefinition('external:review')->getPermission());
    $this->assertSame('demo', $reader->read($name)['connection']);
    $this->assertSame('string', $reader->read($name)['inputs']['title']['data_type']);
    $override->setLanguage(new Language(['id' => 'en']));
    $this->container->get('config.factory')->reset($name);
    $this->assertSame('Stable tool', (string) $manager->getDefinition('external:review')->getLabel());
    $this->assertSame('string', $reader->read($name)['inputs']['title']['data_type']);

    $GLOBALS['config'][$name]['permission'] = 'administer external tools';
    $GLOBALS['config'][$name]['inputs']['title']['label'] = 'Platform title';
    $this->container->get('config.factory')->reset($name);
    $manager->clearCachedDefinitions();
    $this->assertSame('administer external tools', $manager->getDefinition('external:review')->getPermission());
    $this->assertSame('Platform title', $reader->read($name)['inputs']['title']['label']);
    $this->assertSame('string', $reader->read($name)['inputs']['title']['data_type']);
    $this->assertSame('execute external tools', $this->container->get('config.storage')->read($name)['permission']);
  }

}
