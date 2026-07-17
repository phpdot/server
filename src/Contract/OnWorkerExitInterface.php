<?php

declare(strict_types=1);

/**
 * Invoked during a worker's async exit (reload_async, on by default in 6.x),
 * before the reactor is force-killed. The place to unwind in-flight work — e.g.
 * cancelling SSE coroutines / draining TCP connections — so the worker exits
 * cleanly instead of being force-terminated (ERRNO 9101).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnWorkerExitInterface
{
    /**
     * On worker exit.
     *
     * @param Server $server
     * @param int $workerId
     *
     * @return void
     */
    public function onWorkerExit(Server $server, int $workerId): void;
}
