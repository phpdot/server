<?php

declare(strict_types=1);

/**
 * Invoked when the manager process starts.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnManagerStartInterface
{
    /**
     * On manager start.
     *
     * @param Server $server
     *
     * @return void
     */
    public function onManagerStart(Server $server): void;
}
