<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool_external\Entity\ExternalTool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies site-approved upstream declarations through native Tool API.
 */
#[Group('tool_external')]
#[RunTestsInSeparateProcesses]
final class UpstreamDeclarationTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'serialization', 'tool', 'tool_external'];

  /**
   * Registers a provider declaration and invokes it over loopback HTTP.
   */
  public function testWorkerDeclaresAndRendersMediaFeed(): void {
    $endpoint = getenv('TOOL_EXTERNAL_TEST_URL');
    if (!$endpoint) {
      $this->markTestSkipped('Run tests/run-tests.py for the worker fixture.');
    }
    $this->installConfig(['system', 'user', 'tool_external']);
    $this->installEntitySchema('user');
    $this->createUser();
    $json = (string) $this->container->get('http_client')->request('GET', $endpoint . '/feed/definition')->getBody();
    $tool = ExternalTool::fromDeclaration($json, 'partner', ToolOperation::Transform);
    $tool->save();

    // Discovery and schema generation work before a connection is configured.
    $id = 'external:' . $tool->id();
    $plugin = $this->container->get('plugin.manager.tool')->createInstance($id);
    $serializer = $this->container->get('tool.definition_serializer');
    $input_schema = $serializer->normalizeInputSchema($plugin);
    $this->assertSame('string', $input_schema['properties']['episodes']['items']['properties']['media_url']['type']);
    $this->assertSame('integer', $serializer->normalizeOutputSchema($plugin)['properties']['item_count']['type']);

    $inputs = [
      'channel' => 'Test channel',
      'channel_url' => 'https://example.test/',
      'episodes' => [
        [
          'id' => 'episode-42',
          'title' => 'Rescue & Recovery',
          'media_url' => 'https://cdn.example.test/42.mp4?a=1&b=2',
        ],
      ],
    ];
    $runner = $this->container->get('tool.runner');
    $denied = $runner->run($id, $inputs);
    $this->assertSame(FailureCategory::Access, $denied->getResult()->getFailureCategory());
    $this->assertFalse($denied->hasExecuted());

    $this->setCurrentUser($this->createUser(['execute external tools']));
    $this->setSetting('tool_external', [
      'enabled' => TRUE,
      'allowed_hosts' => ['127.0.0.1'],
      'allow_local_http' => TRUE,
      'secrets' => ['feed' => 'fixture-only'],
    ]);
    $connection = ['url' => $endpoint . '/feed/invoke', 'secret' => 'feed', 'timeout' => 2.0];
    $config = $this->config('tool_external.settings');
    $config->set('connections.partner', $connection)->save();
    $result = $runner->run($id, $inputs)->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $outputs = $result->getContextValues();
    $this->assertSame(1, $outputs['item_count']);
    $xml = new \DOMDocument();
    $this->assertTrue($xml->loadXML($outputs['xml'], LIBXML_NONET));
    $item = $xml->getElementsByTagName('item')->item(0);
    $this->assertSame('Rescue & Recovery', $item->getElementsByTagName('title')->item(0)->textContent);
    $media = $item->getElementsByTagNameNS('http://search.yahoo.com/mrss/', 'content')->item(0);
    $this->assertSame($inputs['episodes'][0]['media_url'], $media->getAttribute('url'));

    $inputs['episodes'] = [];
    $empty = $runner->run($id, $inputs)->getResult();
    $this->assertSame(0, $empty->getContextValues()['item_count']);
    $connection['url'] = $endpoint . '/feed/bad-output';
    $config->set('connections.partner', $connection)->save();
    $bad = $runner->run($id, $inputs)->getResult();
    $this->assertFalse($bad->isSuccess());
    $this->assertSame([], $bad->getContextValues());
  }

  /**
   * Rejects permissions embedded in upstream declarations.
   */
  public function testProviderCannotSetInstallationPolicy(): void {
    $path = $this->container->get('extension.list.module')->getPath('tool_external');
    $data = json_decode(file_get_contents($path . '/tests/fixtures/feed-tool.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    $data['permission'] = 'access content';
    $this->expectException(\InvalidArgumentException::class);
    ExternalTool::fromDeclaration(json_encode($data, JSON_THROW_ON_ERROR), 'partner', ToolOperation::Transform);
  }

  /**
   * Checks that file registration preserves an existing local policy.
   */
  public function testRegisteringFilePreservesExistingRegistration(): void {
    $this->installConfig(['system', 'tool_external']);
    $module_directory = $this->container->get('extension.list.module')->getPath('tool_external');
    // Drush php:script passes arguments to the included script in $extra.
    $extra = [
      $module_directory . '/tests/fixtures/feed-tool.json',
      'first_partner',
      'transform',
      'administer external tools',
    ];
    ob_start();
    try {
      include $module_directory . '/examples/register-declaration.php';
    }
    finally {
      $message = ob_get_clean();
    }
    $this->assertStringContainsString('Registered external:partner_mrss', $message);
    $stored = $this->container->get('config.storage')->read('tool_external.tool.partner_mrss');
    $this->assertSame('administer external tools', $stored['permission']);
    $extra[1] = 'other_partner';
    try {
      include $module_directory . '/examples/register-declaration.php';
      $this->fail('A duplicate registration must preserve the installed tool.');
    }
    catch (EntityStorageException) {
      $this->assertSame($stored, $this->container->get('config.storage')->read('tool_external.tool.partner_mrss'));
    }
  }

}
