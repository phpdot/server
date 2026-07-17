<?php

declare(strict_types=1);

/**
 * Invoked just before the master shuts down.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnBeforeShutdownInterface
{
    /**
     * On before shutdown.
     *
     * @param Server $server
     *
     * @return void
     */
    public function onBeforeShutdown(Server $server): void;
}
