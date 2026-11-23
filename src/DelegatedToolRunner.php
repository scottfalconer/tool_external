<?php

declare(strict_types=1);

namespace Drupal\tool_external;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\tool\Tool\Invoker;
use Drupal\tool\Tool\ToolInvocation;
use Drupal\tool\Tool\ToolRunner;
use Drupal\user\UserInterface;

/**
 * Preserves the initiating account across a trusted site's native run-as call.
 */
final class DelegatedToolRunner {

  /**
   * Provenance for each active call, keyed by the exact native invoker object.
   *
   * @var \WeakMap<\Drupal\tool\Tool\Invoker, array>
   */
  private readonly \WeakMap $calls;

  public function __construct(
    private readonly ToolRunner $runner,
    private readonly AccountProxyInterface $currentUser,
  ) {
    $this->calls = new \WeakMap();
  }

  /**
   * Captures the actor before Tool API checks and switches to the executor.
   *
   * Trusted PHP callers select the executor under their site's own policy.
   */
  public function run(string $tool_id, array $inputs, string $caller, ?UserInterface $executor = NULL, ?string $invoker_id = NULL): ToolInvocation {
    if (!str_starts_with($tool_id, 'external:') || !preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $caller)) {
      throw new \InvalidArgumentException('Supply an external tool ID and a caller identifier of up to 64 characters.');
    }
    $invoker = new Invoker($invoker_id ?? $caller);
    $this->calls[$invoker] = [
      'tool_id' => $tool_id,
      'caller' => $caller,
      'actor' => [
        'id' => (string) $this->currentUser->id(),
        'roles' => array_values($this->currentUser->getRoles()),
      ],
    ];
    try {
      return $this->runner->run($tool_id, $inputs, [], $invoker, $executor);
    }
    finally {
      unset($this->calls[$invoker]);
    }
  }

  /**
   * Returns provenance only for the matching tool during its native call.
   *
   * @internal
   */
  public function contextFor(string $tool_id, ?Invoker $invoker): ?array {
    $call = $invoker ? ($this->calls[$invoker] ?? NULL) : NULL;
    return ($call['tool_id'] ?? NULL) === $tool_id ? $call : NULL;
  }

}
