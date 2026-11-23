<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\tool_external\Traits\HttpFixtureTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool_external\Entity\ExternalTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Exchanges actual HTTP requests with the example JavaScript implementation.
 */
#[Group('tool_external')]
#[RunTestsInSeparateProcesses]
final class ExternalHttpTest extends KernelTestBase {

  use HttpFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'tool', 'tool_external'];

  /**
   * Base URL of the loopback HTTP fixture.
   */
  private string $endpoint;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->endpoint = $this->httpFixtureUrl();
    $this->installConfig(['system', 'user', 'tool_external']);
    $this->installEntitySchema('user');
    $this->createUser();
    $this->setCurrentUser($this->createUser(['execute external tools']));
    $this->setSetting('tool_external', [
      'enabled' => TRUE,
      'allowed_hosts' => ['127.0.0.1'],
      'allow_local_http' => TRUE,
      'secrets' => ['fixture' => 'fixture-only'],
    ]);
    $this->connect('/invoke');
    $path = \Drupal::service('extension.list.module')->getPath('tool_external');
    ExternalTool::create(Yaml::parseFile($path . '/tests/fixtures/campaign-tool.yml'))->save();
  }

  /**
   * Exercises successful and rejected business input on the test endpoint.
   */
  public function testRealJavaScriptRoundTripAndChangedInput(): void {
    $runner = $this->container->get('tool.runner');
    $accepted = $runner->run('external:campaign_prepare', ['title' => '  Spring appeal  ', 'campaign_code' => 'CAM-123'])->getResult();
    $this->assertTrue($accepted->isSuccess(), (string) $accepted->getMessage());
    $this->assertSame(['campaign_label' => 'CAM-123: Spring appeal', 'violations' => []], $accepted->getContextValues());
    $rejected = $runner->run('external:campaign_prepare', ['title' => '  Spring appeal  ', 'campaign_code' => 'wrong'])->getResult();
    $this->assertTrue($rejected->isSuccess(), 'A business rejection remains a successfully executed tool.');
    $this->assertSame('invalid_campaign', $rejected->getContextValues()['violations'][0]['code']);
  }

  /**
   * Runs the tool when an informational response precedes the final JSON.
   */
  public function testInformationalResponses(): void {
    $runner = $this->container->get('tool.runner');
    $inputs = ['title' => 'Spring', 'campaign_code' => 'CAM-123'];
    foreach (['/continue', '/early-hints'] as $path) {
      $this->connect($path);
      $result = $runner->run('external:campaign_prepare', $inputs)->getResult();
      $this->assertTrue($result->isSuccess(), $path . ': ' . $result->getMessage());
      $this->assertSame('CAM-123: Spring', $result->getContextValues()['campaign_label']);
    }
  }

  /**
   * A restored plugin can execute with the transport supplied by Drupal.
   */
  public function testSerializedPluginExecutes(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('external:campaign_prepare');
    // Drupal's memory cache serializes values on write and restores on read.
    $cache = $this->container->get('cache.backend.memory')->get('external_tool_test');
    $cache->set('prepared_tool', $tool);
    $restored = $cache->get('prepared_tool')->data;
    $this->assertNotSame($tool, $restored);
    $restored->setInputValue('title', 'Spring appeal');
    $restored->setInputValue('campaign_code', 'CAM-123');
    $this->assertTrue($restored->access());
    $result = $restored->execute()->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->assertSame(['campaign_label' => 'CAM-123: Spring appeal', 'violations' => []], $result->getContextValues());
  }

  /**
   * A tiny configured timeout cannot fall through to an unlimited cURL wait.
   */
  public function testSubmillisecondTimeoutIsRejectedBeforeSlowEndpoint(): void {
    $this->config('tool_external.settings')->set('connections.campaign_functions', [
      'url' => $this->endpoint . '/slow_headers',
      'secret' => 'fixture',
      'timeout' => 0.0009,
    ])->save();
    $started = microtime(TRUE);
    $inputs = ['title' => 'Spring', 'campaign_code' => 'CAM-123'];
    $result = $this->container->get('tool.runner')->run('external:campaign_prepare', $inputs)->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('between 0.001', (string) $result->getMessage());
    $this->assertSame([], $result->getContextValues());
    $this->assertLessThan(0.4, microtime(TRUE) - $started, 'The endpoint would take at least 0.6 seconds to finish its headers.');
  }

  /**
   * Checks that transport failures withhold partial outputs.
   */
  #[DataProvider('failedEndpoints')]
  public function testTransportFailuresReturnNoOutputs(string $path, string $reason, ?string $outcome = NULL, ?int $status = NULL): void {
    $this->connect($path);
    if ($outcome !== NULL) {
      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects($this->once())->method('log')->with($this->anything(), $this->stringContains('{status_code}'), $this->callback(
        static fn (array $context): bool => $context['outcome'] === $outcome && $context['status_code'] === $status,
      ));
      $this->container->get('logger.channel.tool_external')->addLogger($logger);
    }
    $started = microtime(TRUE);
    $inputs = ['title' => 'Spring', 'campaign_code' => 'CAM-123'];
    $result = $this->container->get('tool.runner')->run('external:campaign_prepare', $inputs)->getResult();
    $this->assertFalse($result->isSuccess(), $path);
    $this->assertStringContainsString($reason, (string) $result->getMessage());
    $this->assertSame([], $result->getContextValues());
    $this->assertStringNotContainsString('fixture-only', (string) $result->getMessage());
    if ($path === '/slow_headers') {
      $this->assertLessThan(0.4, microtime(TRUE) - $started, 'Trickled headers cannot extend the total HTTP deadline.');
    }
  }

  /**
   * Provides loopback failure routes and their expected diagnostic messages.
   */
  public static function failedEndpoints(): array {
    return [
      ['/invalid', 'invalid JSON'],
      ['/early-hints/text', 'did not return JSON'],
      ['/oversize', 'response exceeds', 'response_size', 200],
      ['/oversize/html', 'did not return JSON', 'content_type', 200],
      ['/oversize/gateway', 'unsuccessful HTTP status', 'http_status', 502],
      ['/redirect', 'unsuccessful HTTP status'],
      ['/slow', 'deadline'],
      ['/slow_headers', 'deadline'],
    ];
  }

  /**
   * Points the test connection at the requested loopback route.
   */
  private function connect(string $path): void {
    $this->config('tool_external.settings')->set('connections.campaign_functions', [
      'url' => $this->endpoint . $path,
      'secret' => 'fixture',
      'timeout' => in_array($path, ['/slow', '/slow_headers'], TRUE) ? 0.1 : 2.0,
    ])->save();
  }

}
