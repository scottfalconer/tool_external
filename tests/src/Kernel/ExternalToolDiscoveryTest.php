<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool_external\Entity\ExternalTool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises local discovery, config export and ordinary Tool API access.
 */
#[Group('tool_external')]
#[RunTestsInSeparateProcesses]
final class ExternalToolDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'tool', 'tool_external'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'tool_external']);
  }

  /**
   * An endpoint can be missing without breaking discovery or cache rebuilds.
   */
  public function testLocalDiscoveryAndConfigExport(): void {
    $tool = $this->createTool();
    $definition = $this->container->get('plugin.manager.tool')->getDefinition('external:campaign');
    $this->assertInstanceOf(ToolDefinition::class, $definition);
    $this->assertSame('execute external tools', $definition->getPermission());
    $this->assertSame('string', $definition->getInputDefinition('title')->getDataType());
    $this->assertInstanceOf(MapOutputDefinition::class, $definition->getOutputDefinition('result'));
    $this->assertSame('boolean', $definition->getOutputDefinition('result')->getPropertyDefinition('allowed')->getDataType());

    $stored = $this->container->get('config.storage')->read('tool_external.tool.campaign');
    $this->assertTrue($stored['status']);
    $this->assertSame($tool->uuid(), $stored['uuid']);
    $this->assertSame('missing_connection', $stored['connection']);
    $this->assertArrayNotHasKey('credential', $stored);
  }

  /**
   * Existing manager and deriver instances observe config writes and deletes.
   */
  public function testChangesInvalidateExistingManager(): void {
    $tool = $this->createTool();
    $manager = $this->container->get('plugin.manager.tool');
    $this->assertSame('Campaign', (string) $manager->getDefinition('external:campaign')->getLabel());

    $tool->set('label', 'Updated campaign')->save();
    $this->assertSame('Updated campaign', (string) $manager->getDefinition('external:campaign')->getLabel());
    $tool->disable()->save();
    $this->assertFalse($manager->hasDefinition('external:campaign'));
    $tool->enable()->save();
    $this->assertTrue($manager->hasDefinition('external:campaign'));
    $tool->delete();
    $this->assertFalse($manager->hasDefinition('external:campaign'));
  }

  /**
   * Platform overrides affect discovery without becoming exported site config.
   */
  public function testPlatformOverrideChangesEffectiveDefinition(): void {
    $this->createTool();
    $name = 'tool_external.tool.campaign';
    $GLOBALS['config'][$name]['label'] = 'Platform campaign';
    $GLOBALS['config'][$name]['permission'] = 'administer external tools';
    $this->container->get('config.factory')->reset($name);
    $this->container->get('plugin.manager.tool')->clearCachedDefinitions();
    $definition = $this->container->get('plugin.manager.tool')->getDefinition('external:campaign');
    $this->assertSame('Platform campaign', (string) $definition->getLabel());
    $this->assertSame('administer external tools', $definition->getPermission());
    $this->assertSame('Campaign', $this->container->get('config.storage')->read($name)['label']);
  }

  /**
   * The existing runner's permission gate denies before creating the client.
   */
  public function testRunnerDeniesAnonymousWithoutCallingEndpoint(): void {
    $this->createTool();
    $invocation = $this->container->get('tool.runner')->run('external:campaign', ['title' => 'Example']);
    $this->assertFalse($invocation->getResult()->isSuccess());
    $this->assertSame(FailureCategory::Access, $invocation->getResult()->getFailureCategory());
    $this->assertFalse($invocation->hasExecuted());
  }

  /**
   * One malformed imported definition does not hide correctly configured tools.
   */
  public function testMalformedDefinitionIsExcluded(): void {
    $this->createTool();
    $data = $this->container->get('config.storage')->read('tool_external.tool.campaign');
    $data['id'] = 'malformed';
    $data['inputs']['title']['data_type'] = 'entity:node';
    $this->config('tool_external.tool.malformed')->setData($data)->save();
    $manager = $this->container->get('plugin.manager.tool');
    $this->assertTrue($manager->hasDefinition('external:campaign'));
    $this->assertFalse($manager->hasDefinition('external:malformed'));
  }

  /**
   * Registers the fixture tool for local discovery.
   */
  private function createTool(): ExternalTool {
    $tool = ExternalTool::create([
      'id' => 'campaign',
      'label' => 'Campaign',
      'description' => 'Checks a campaign.',
      'connection' => 'missing_connection',
      'inputs' => ['title' => ['data_type' => 'string']],
      'outputs' => [
        'result' => [
          'data_type' => 'map',
          'properties' => ['allowed' => ['data_type' => 'boolean']],
        ],
      ],
    ]);
    $tool->save();
    return $tool;
  }

}
