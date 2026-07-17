<?php

declare(strict_types=1);

/**
 * CallbackStreamInterface.
 *
 * A PSR-7 stream body that emits its content via a deferred callback, enabling
 * chunked/streamed responses through Swoole's write().
 *
 * Temporary home: this contract moves to phpdot/contracts (migration step 0) so
 * both phpdot/server-swoole and phpdot/server reference one canonical copy.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use Closure;

interface CallbackStreamInterface
{
    /**
     * The deferred streaming callback. Receives a writer: fn(string $chunk): void.
     *
     * @return Closure(Closure(string): void): void
     */
    public function getCallback(): Closure;
}
