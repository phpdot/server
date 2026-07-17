<?php

declare(strict_types=1);

/**
 * Invoked when the master shuts down.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnShutdownInterface
{
    /**
     * On shutdown.
     *
     * @param Server $server
     *
     * @return void
     */
    public function onShutdown(Server $server): void;
}
