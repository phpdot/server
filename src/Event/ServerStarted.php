<?php

declare(strict_types=1);

/**
 * ServerStarted — The master has started and the event loop is live.
 * Delivered synchronously to #[ServerListener] classes by the ListenerBridge.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Event;

use PHPdot\Server\Server;

final readonly class ServerStarted
{
    /**
     * Create the event.
     *
     * @param Server $server The running server
     */
    public function __construct(
        public readonly Server $server,
    ) {}
}
