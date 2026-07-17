<?php

declare(strict_types=1);

/**
 * Invoked when a worker exits abnormally.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnWorkerErrorInterface
{
    /**
     * On worker error.
     *
     * @param Server $server
     * @param int $workerId
     * @param int $workerPid
     * @param int $exitCode
     * @param int $signal
     *
     * @return void
     */
    public function onWorkerError(Server $server, int $workerId, int $workerPid, int $exitCode, int $signal): void;
}
