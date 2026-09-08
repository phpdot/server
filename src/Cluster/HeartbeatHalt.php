<?php

declare(strict_types=1);

/**
 * Cancels the cluster heartbeat's tick when a worker exits — the other half
 * of {@see Heartbeat}.
 *
 * The tick holds the worker's event loop open, so an uncanceled heartbeat
 * turns every stop and reload into a full drain-window wait measured in
 * tens of seconds. The farewell ({@see HeartbeatFarewell}) removes the node
 * from the registry; this removes the thing keeping the lights on.
 *
 * Resolves the SAME Heartbeat instance the WorkerStarted listener built (it
 * is a singleton), so the id it cancels is the id that was set.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Cluster;

use PHPdot\Server\Attribute\ServerListener;
use PHPdot\Server\Event\WorkerExiting;

#[ServerListener]
final class HeartbeatHalt
{
    public function __construct(private readonly Heartbeat $heartbeat) {}

    /**
     * @param WorkerExiting $event The lifecycle event
     *
     * @return void
     */
    public function __invoke(WorkerExiting $event): void
    {
        $this->heartbeat->halt();
    }
}
