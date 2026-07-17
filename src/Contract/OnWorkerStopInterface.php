<?php

declare(strict_types=1);

/**
 * Invoked when a worker stops.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnWorkerStopInterface
{
    /**
     * On worker stop.
     *
     * @param Server $server
     * @param int $workerId
     *
     * @return void
     */
    public function onWorkerStop(Server $server, int $workerId): void;
}
