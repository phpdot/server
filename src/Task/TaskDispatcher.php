<?php

declare(strict_types=1);

/**
 * TaskDispatcher — dispatches work to task workers via the Swoole master.
 *
 * The dispatch surface extracted from the old god-class server. The task *event*
 * handlers (onTask/onFinish) are registered on the Server itself (server-level
 * events, wired pre-start) — dispatch lives here, one-way (no constructor cycle).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Task;

use Closure;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Server\Server;

#[Singleton]
final class TaskDispatcher
{
    /**
     * __construct.
     *
     * @param Server $server
     */
    public function __construct(
        private readonly Server $server,
    ) {}

    /**
     * Dispatch a task to a task worker. The optional $onFinish runs in the
     * dispatching worker when the task completes.
     *
     * @param mixed $data Task data (must be serializable)
     * @param int $dstWorkerId Target task worker (-1 = auto)
     * @param Closure(mixed): void|null $onFinish Completion callback
     *
     * @return int|false Task ID, or false on failure
     */
    public function task(mixed $data, int $dstWorkerId = -1, Closure|null $onFinish = null): int|false
    {
        return $this->server->getMaster()->task($data, $dstWorkerId, $onFinish);
    }

    /**
     * Dispatch many tasks concurrently (coroutine) and wait for all results.
     *
     * @param list<mixed> $tasks
     * @param float $timeout Seconds
     *
     * @return array<mixed>|false
     */
    public function taskCo(array $tasks, float $timeout = 0.5): array|false
    {
        return $this->server->getMaster()->taskCo($tasks, $timeout);
    }

    /**
     * Return a task's result from inside an onTask handler.
     *
     * @param mixed $data
     *
     * @return bool
     */
    public function finish(mixed $data): bool
    {
        return $this->server->getMaster()->finish($data);
    }
}
