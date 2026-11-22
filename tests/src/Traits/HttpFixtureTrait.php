<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Traits;

use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Process\Process;

/**
 * Starts the packaged loopback HTTP fixture when running PHPUnit directly.
 */
trait HttpFixtureTrait {

  /**
   * The server owned by this test, if it started one.
   */
  private ?Process $httpFixture = NULL;

  /**
   * Returns the shared test URL or starts a server on an OS-assigned port.
   */
  private function httpFixtureUrl(): string {
    $configured = getenv('TOOL_EXTERNAL_TEST_URL');
    if ($configured !== FALSE && $configured !== '') {
      return rtrim($configured, '/');
    }

    $script = dirname(__DIR__, 2) . '/fixtures/http-server.mjs';
    $this->httpFixture = new Process([getenv('NODE_BINARY') ?: 'node', $script], NULL, ['PORT' => '0'], NULL, 10);
    $this->httpFixture->start();
    $line = '';
    $this->httpFixture->waitUntil(static function (string $type, string $data) use (&$line): bool {
      if ($type === Process::OUT) {
        $line .= $data;
      }
      return str_contains($line, "\n");
    });
    if (!preg_match('/^READY ([0-9]+)\r?\n$/D', $line, $matches)) {
      throw new \RuntimeException('The HTTP fixture requires Node.js and loopback networking. ' . $this->httpFixture->getErrorOutput());
    }
    return 'http://127.0.0.1:' . $matches[1];
  }

  /**
   * Stops the local fixture after the test, including on assertion failures.
   */
  #[After]
  protected function stopHttpFixture(): void {
    $this->httpFixture?->stop(0);
  }

}
