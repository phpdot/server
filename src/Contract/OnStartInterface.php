<?php

declare(strict_types=1);

/**
 * Invoked in the master process once the event loop has started.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnStartInterface
{
    /**
     * On start.
     *
     * @param Server $server
     *
     * @return void
     */
    public function onStart(Server $server): void;
}
