<?php

declare(strict_types=1);

/**
 * Invoked after the workers reload.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Server;

interface OnAfterReloadInterface
{
    /**
     * On after reload.
     *
     * @param Server $server
     *
     * @return void
     */
    public function onAfterReload(Server $server): void;
}
