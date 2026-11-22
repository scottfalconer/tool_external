<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Unit;

use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\tool_external\Config\StableConfigReader;
use Drupal\tool_external\Exception\ExternalToolException;
use Drupal\tool_external\Transport\ConnectionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies platform decisions before an external request can be constructed.
 */
#[Group('tool_external')]
final class ConnectionPolicyTest extends UnitTestCase {

  /**
   * Verifies that invalid connection settings fail before a request.
   */
  #[DataProvider('blockedConfigurations')]
  public function testRejectsInvalidConnection(array $overrides, string $url, string $failure): void {
    $platform = array_replace([
      'enabled' => TRUE,
      'allowed_hosts' => ['functions.example.test'],
      'secrets' => ['example' => 'synthetic-secret'],
    ], $overrides);
    $config = $this->getConfigFactoryStub([
      'tool_external.settings' => [
        'connections' => ['example' => ['url' => $url, 'secret' => 'example', 'timeout' => 2.0]],
      ],
    ]);
    $resolver = new ConnectionResolver(new StableConfigReader($config), new Settings(['tool_external' => $platform]));
    try {
      $resolver->resolve('example');
      $this->fail('The platform must deny this connection.');
    }
    catch (ExternalToolException $exception) {
      $this->assertSame($failure, $exception->failureCode());
    }
  }

  /**
   * Provides invalid connections and their expected failure codes.
   */
  public static function blockedConfigurations(): array {
    $url = 'https://functions.example.test/invoke';
    $bound = ['url' => $url, 'token' => 'synthetic-secret'];
    return [
      'disabled environment' => [['enabled' => FALSE], 'https://functions.example.test/invoke', 'disabled'],
      'unapproved host' => [[], 'https://other.example.test/invoke', 'destination'],
      'plaintext public URL' => [[], 'http://functions.example.test/invoke', 'destination'],
      'missing credential' => [['secrets' => []], 'https://functions.example.test/invoke', 'credentials'],
      'another allowed host' => [
        ['allowed_hosts' => ['functions.example.test', 'partner.example.test'], 'secrets' => ['example' => $bound]],
        'https://partner.example.test/invoke',
        'credentials',
      ],
      'another allowed-host route' => [
        ['secrets' => ['example' => $bound]],
        'https://functions.example.test/other',
        'credentials',
      ],
      'missing bound token' => [['secrets' => ['example' => ['url' => $bound['url']]]], $bound['url'], 'credentials'],
      'missing bound URL' => [['secrets' => ['example' => ['token' => $bound['token']]]], $bound['url'], 'credentials'],
      'submillisecond platform timeout' => [['max_timeout' => 0.0009], $url, 'configuration'],
      'overflowing platform timeout' => [['max_timeout' => PHP_FLOAT_MAX], $url, 'configuration'],
    ];
  }

  /**
   * The same endpoint can use its bound token or an existing string entry.
   */
  public function testResolvesBoundAndLegacyCredentials(): void {
    $url = 'https://functions.example.test/invoke';
    $config = $this->getConfigFactoryStub([
      'tool_external.settings' => [
        'connections' => ['example' => ['url' => $url, 'secret' => 'example']],
      ],
    ]);
    foreach ([['url' => $url, 'token' => 'bound-token'], 'legacy-token'] as $credential) {
      $settings = new Settings([
        'tool_external' => [
          'enabled' => TRUE,
          'allowed_hosts' => ['functions.example.test'],
          'secrets' => ['example' => $credential],
        ],
      ]);
      $resolver = new ConnectionResolver(new StableConfigReader($config), $settings);
      $expected = is_array($credential) ? 'bound-token' : 'legacy-token';
      $this->assertSame($expected, $resolver->resolve('example')['secret']);
    }
  }

  /**
   * A tiny requested timeout must not turn into cURL's unlimited zero value.
   */
  public function testRejectsSubmillisecondConnectionTimeout(): void {
    $config = $this->getConfigFactoryStub([
      'tool_external.settings' => [
        'connections' => [
          'example' => [
            'url' => 'https://functions.example.test/invoke',
            'secret' => 'example',
            'timeout' => 0.0009,
          ],
        ],
      ],
    ]);
    $resolver = new ConnectionResolver(new StableConfigReader($config), new Settings([
      'tool_external' => [
        'enabled' => TRUE,
        'allowed_hosts' => ['functions.example.test'],
        'secrets' => ['example' => 'synthetic-secret'],
      ],
    ]));
    $this->expectException(ExternalToolException::class);
    $this->expectExceptionMessage('between 0.001');
    $resolver->resolve('example');
  }

}
