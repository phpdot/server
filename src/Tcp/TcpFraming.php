<?php

declare(strict_types=1);

/**
 * TcpFraming — the wire-framing mode a TcpServer applies to its socket.
 *
 * An added TCP port on an Http/WebSocket master never fires `receive` in stream
 * mode (the HTTP parser swallows the bytes — verified Swoole 6.2.1), so TcpServer
 * always applies one of these framing modes. EOF/line is the default.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Tcp;

enum TcpFraming: string
{
    /**
     * Stream mode (no framing). Only fires `receive` reliably on a plain Swoole\Server primary.
     */
    case None = 'none';

    /**
     * Frame on a delimiter (`package_eof`).
     */
    case Eof = 'eof';

    /**
     * Frame on a length prefix (`package_length_type` + offsets).
     */
    case Length = 'length';
}
