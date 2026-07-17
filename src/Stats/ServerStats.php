<?php

declare(strict_types=1);

/**
 * ServerStats — master/worker process introspection.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Stats;

use PHPdot\Container\Attribute\Singleton;
use PHPdot\Server\Server;

#[Singleton]
final class ServerStats
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
     * All server statistics as an associative array.
     *
     * @return array<mixed, mixed> The Swoole master's stats() snapshot
     */
    public function all(): array
    {
        return $this->server->getMaster()->stats();
    }

    /**
     * Worker id.
     *
     * @return int|false
     */
    public function workerId(): int|false
    {
        return $this->server->getMaster()->getWorkerId();
    }

    /**
     * Worker pid.
     *
     * @param int $workerId
     *
     * @return int|false
     */
    public function workerPid(int $workerId = -1): int|false
    {
        return $this->server->getMaster()->getWorkerPid($workerId);
    }

    /**
     * Worker status.
     *
     * @param int $workerId
     *
     * @return int|false
     */
    public function workerStatus(int $workerId = -1): int|false
    {
        return $this->server->getMaster()->getWorkerStatus($workerId);
    }

    /**
     * Master pid.
     *
     * @return int
     */
    public function masterPid(): int
    {
        return $this->server->getMaster()->getMasterPid();
    }

    /**
     * Manager pid.
     *
     * @return int
     */
    public function managerPid(): int
    {
        return $this->server->getMaster()->getManagerPid();
    }
}
