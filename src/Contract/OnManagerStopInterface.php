<?php

declare(strict_types=1);

/**
 * Invoked when the manager process stops.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnManagerStopInterface
{
    /**
     * On manager stop.
     *
     * @param Server $server
     *
     * @return void
     */
    public function onManagerStop(Server $server): void;
}
