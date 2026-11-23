<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tool\Drush\RequirementStatus;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool_external\Entity\ExternalTool;
use GuzzleHttp\ClientInterface;
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

    $typed = $this->container->get('config.typed');
    $key = 'tool.plugin.external:campaign';
    $this->assertTrue($typed->hasConfigSchema($key));
    $inputs = ['title' => 'Campaign title'];
    $this->assertSame($inputs, $typed->createFromNameAndData($key, $inputs)->getValue());
  }

  /**
   * Reports readiness from local settings without connecting to the provider.
   */
  public function testRequirementsUseLocalConnectionPolicy(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    $this->container->set('http_client', $http);
    $this->createTool();
    $manager = $this->container->get('plugin.manager.tool');
    $check = static fn () => RequirementStatus::forTool($manager, 'external:campaign');
    $this->assertStringContainsString('disabled', $check()->label());
    $platform = ['enabled' => TRUE, 'allowed_hosts' => ['formatter.example']];
    $this->setSetting('tool_external', $platform);
    $this->assertStringContainsString('connection is not configured', $check()->label());
    $url = 'https://formatter.example/invoke';
    $this->config('tool_external.settings')->set('connections.missing_connection', [
      'url' => $url,
      'secret' => 'formatter',
    ])->save();
    $this->assertStringContainsString('credential is not available', $check()->label());
    $platform['secrets']['formatter'] = ['url' => $url, 'token' => 'fixture', 'allowed_tools' => ['external:campaign']];
    $this->setSetting('tool_external', $platform);
    $this->assertTrue($check()->met);
  }

  /**
   * Existing manager and deriver instances observe config writes and deletes.
   */
  public function testChangesInvalidateExistingManager(): void {
    $tool = $this->createTool();
    $manager = $this->container->get('plugin.manager.tool');
    $this->assertSame('Campaign', (string) $manager->getDefinition('external:campaign')->getLabel());

    $this->container->get('plugin.manager.action')->getDefinitions();
    $cache = $this->container->get('cache.discovery');
    $this->assertNotFalse($cache->get('action_info'));
    $tool->set('label', 'Updated campaign')->save();
    $this->assertFalse($cache->get('action_info'));
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
