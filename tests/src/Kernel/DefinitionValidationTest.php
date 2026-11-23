<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Kernel;

use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Plugin\PluginDependencyTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tool\Tool\ToolManager;
use Drupal\tool_external\Entity\ExternalTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Rejects invalid contracts before config writes or callback execution.
 */
#[Group('tool_external')]
#[RunTestsInSeparateProcesses]
final class DefinitionValidationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'tool', 'tool_external'];

  /**
   * Number of attempted callback executions.
   */
  private static int $callbackExecutions = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'tool_external']);
  }

  /**
   * Returns a minimal valid tool configuration.
   */
  private function values(): array {
    return [
      'id' => 'review',
      'label' => 'Review',
      'description' => 'A portable tool contract.',
      'connection' => 'demo',
      'inputs' => ['title' => ['data_type' => 'string']],
      'outputs' => ['label' => ['data_type' => 'string']],
    ];
  }

  /**
   * Rejects empty members while preserving Tool API's AND/OR semantics.
   */
  public function testPermissionExpressions(): void {
    $tool = ExternalTool::create($this->values());
    foreach (['execute external tools,', '+execute external tools', 'one,  ,two', 'one+ +two'] as $permission) {
      try {
        $tool->set('permission', $permission)->save();
        $this->fail('An empty permission was accepted.');
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('must be nonempty', $e->getMessage());
      }
    }
    $account = $this->createStub(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(static fn ($name) => $name === 'execute external tools');
    $manager = $this->container->get('plugin.manager.tool');
    foreach ([',' => FALSE, '+' => TRUE] as $separator => $allowed) {
      $tool->set('permission', "administer external tools $separator execute external tools")->save();
      $definition = $manager->getDefinition('external:review');
      $this->assertSame($allowed, ToolManager::checkPermission($definition, $account)->isAllowed());
    }
  }

  /**
   * Rejects unsupported constraints when the tool is registered.
   */
  #[DataProvider('invalidConstraints')]
  public function testInvalidConstraintsCannotBeRegistered(array $constraints): void {
    $values = $this->values();
    $values['inputs']['title']['constraints'] = $constraints;
    try {
      ExternalTool::create($values)->save();
      $this->fail('The invalid constraint declaration was saved.');
    }
    catch (\InvalidArgumentException) {
      $this->assertFalse($this->container->get('config.storage')->exists('tool_external.tool.review'));
      $this->assertSame(0, self::$callbackExecutions);
    }
  }

  /**
   * Provides unsupported constraint definitions.
   */
  public static function invalidConstraints(): array {
    $callback = [self::class, 'normalizeWithCounter'];
    return [
      'unknown constraint' => [['NoSuchConstraint' => NULL]],
      'unknown option' => [['Length' => ['unrecognized' => 10]]],
      'invalid option type' => [['Length' => ['min' => 'not an integer']]],
      'PHP callback constraint' => [['Callback' => ['callback' => $callback]]],
      'NotBlank normalizer' => [['NotBlank' => ['normalizer' => $callback]]],
      'Length normalizer' => [['Length' => ['max' => 20, 'normalizer' => $callback]]],
      'Regex normalizer' => [['Regex' => ['pattern' => '/^x$/', 'normalizer' => $callback]]],
      'Choice callback' => [['Choice' => ['callback' => $callback]]],
      'AllowedValues callback' => [['AllowedValues' => ['callback' => $callback]]],
    ];
  }

  /**
   * Counts callback execution attempts.
   */
  public static function normalizeWithCounter(mixed $value): mixed {
    self::$callbackExecutions++;
    return $value;
  }

  /**
   * Checks constraint validation on nested inputs.
   */
  public function testNestedConstraintsAreValidated(): void {
    $values = $this->values();
    $values['outputs'] = [
      'items' => [
        'data_type' => 'list',
        'item_definition' => [
          'data_type' => 'map',
          'properties' => [
            'label' => [
              'data_type' => 'string',
              'constraints' => ['Length' => ['normalizer' => [self::class, 'normalizeWithCounter']]],
            ],
          ],
        ],
      ],
    ];
    $this->expectException(\InvalidArgumentException::class);
    ExternalTool::create($values)->save();
  }

  /**
   * Checks approved constraint behavior and plugin dependencies.
   */
  public function testValidNativeConstraintAndPluginDependency(): void {
    $values = $this->values();
    $values['inputs']['title']['constraints'] = ['Length' => ['min' => 1, 'max' => 50]];
    ExternalTool::create($values)->save();
    $plugin = $this->container->get('plugin.manager.tool')->createInstance('external:review');
    $consumer = new class() {
      use PluginDependencyTrait {
        getPluginDependencies as public;
      }
    };
    $this->assertSame([
      'module' => ['tool_external'],
      'config' => ['tool_external.tool.review'],
    ], $consumer->getPluginDependencies($plugin));
    $this->assertSame(['Length' => ['min' => 1, 'max' => 50]], $plugin->getInputDefinition('title')->getConstraints());
  }

  /**
   * Verifies each allowed constraint against its native plugin definition.
   */
  public function testSupportedConstraintsUseNativeDefinitions(): void {
    $examples = [
      'Blank' => NULL,
      'NotBlank' => NULL,
      'NotNull' => NULL,
      'Null' => NULL,
      'Length' => ['max' => 20],
      'Count' => ['min' => 0],
      'Range' => ['min' => 0, 'max' => 10],
      'Regex' => ['pattern' => '/^x+$/'],
      'Choice' => ['choices' => ['x']],
      'AllowedValues' => ['choices' => ['x']],
      'Email' => NULL,
      'Uuid' => NULL,
      'PositiveOrZero' => NULL,
      'IdenticalTo' => ['value' => 'x'],
    ];
    $factory = $this->container->get('tool_external.definition_factory');
    foreach ($examples as $id => $options) {
      $definition = $factory->inputs(['value' => ['data_type' => 'any', 'constraints' => [$id => $options]]]);
      $this->assertArrayHasKey($id, $definition['value']->getConstraints());
    }
  }

  /**
   * Verifies invalid imports preserve the existing configuration.
   */
  public function testInvalidImportDoesNotPartiallyWriteConfiguration(): void {
    ExternalTool::create($this->values())->save();
    $active = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($active, $sync);
    $original_name = $active->read('system.site')['name'];
    $tool = $sync->read('tool_external.tool.review');
    $tool['operation'] = 'invalid_operation';
    $sync->write('tool_external.tool.review', $tool);
    $site = $sync->read('system.site');
    $site['name'] = 'Must not be written';
    $sync->write('system.site', $site);
    try {
      $this->configImporter()->import();
      $this->fail('Import accepted an invalid contract.');
    }
    catch (ConfigImporterException $exception) {
      $this->assertStringContainsString('External tool configuration', $exception->getMessage());
      $this->assertSame($original_name, $active->read('system.site')['name']);
      $this->assertSame('transform', $active->read('tool_external.tool.review')['operation']);
    }
  }

}
