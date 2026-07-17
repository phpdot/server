<?php

declare(strict_types=1);

/**
 * Invoked when a worker starts — including after a reload, since reloaded
 * workers re-fork. The place for per-(re)start setup: pool init, opcache_reset(),
 * cache warming.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnWorkerStartInterface
{
    /**
     * On worker start.
     *
     * @param Server $server
     * @param int $workerId
     *
     * @return void
     */
    public function onWorkerStart(Server $server, int $workerId): void;
}
