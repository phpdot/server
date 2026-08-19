<?php

declare(strict_types=1);

/**
 * WorkerStarted — A worker process has started — fires post-fork, inside the worker.
 * Delivered synchronously to #[ServerListener] classes by the ListenerBridge.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Event;

use PHPdot\Server\Server;

final readonly class WorkerStarted
{
    /**
     * Create the event.
     *
     * @param Server $server The running server
     * @param int $workerId The worker id
     */
    public function __construct(
        public readonly Server $server,
        public readonly int $workerId,
    ) {}
}
