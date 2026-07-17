<?php

declare(strict_types=1);

/**
 * Invoked before the workers reload.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnBeforeReloadInterface
{
    /**
     * On before reload.
     *
     * @param Server $server
     *
     * @return void
     */
    public function onBeforeReload(Server $server): void;
}
