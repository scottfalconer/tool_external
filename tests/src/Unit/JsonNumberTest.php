<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tool_external\Definition\DefinitionFactory;
use Drupal\tool_external\Exception\ExternalToolException;
use Drupal\tool_external\Transport\JsonValues;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guards JSON's finite numeric boundary before native typed-data coercion.
 */
#[Group('tool_external')]
final class JsonNumberTest extends UnitTestCase {

  /**
   * Checks that JSON numbers must remain finite after decoding.
   */
  public function testOverflowingJsonNumberIsRejected(): void {
    // PHP accepts this JSON syntax and produces INF instead of throwing.
    $decoded = json_decode('{"amount":1e999}', FALSE, 64, JSON_THROW_ON_ERROR);
    $this->assertTrue(is_infinite($decoded->amount));
    $definitions = (new DefinitionFactory())->outputs(['amount' => ['data_type' => 'float']]);
    $this->expectException(ExternalToolException::class);
    JsonValues::outputs($decoded, $definitions);
  }

}
