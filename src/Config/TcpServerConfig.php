<?php

declare(strict_types=1);

/**
 * TcpServerConfig — bind address, socket type, and wire framing for a TcpServer.
 *
 * Hydrated from config/server/tcp.php via #[Config('server.tcp')]; TcpServer
 * owns it. The framing mode selects how Swoole slices the byte stream into
 * `receive` events; flat params fill in the mode-specific values. toArray()
 * emits ONLY the keys for the selected mode (no FramingFactory). Defaults to
 * EOF + "\n".
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Config;

use PHPdot\Container\Attribute\Config;
use PHPdot\Server\Tcp\TcpFraming;

#[Config('server.tcp')]
final class TcpServerConfig
{
    /**
     * Create the TCP server configuration.
     *
     * @param int $sockType SWOOLE_SOCK_TCP (add SWOOLE_SSL for TLS)
     * @param TcpFraming $framing wire-framing mode (default EOF/line)
     * @param string $packageEof delimiter for EOF mode
     * @param string $packageLengthType pack() format char for the length prefix (Length mode)
     * @param int $lengthOffset byte offset of the length field (Length mode)
     * @param int $bodyOffset byte offset where the payload body begins (Length mode)
     * @param int $packageMaxLength max bytes per framed packet (caps buffering; both framed modes)
     * @param string $host
     * @param int $port
     */
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 9501,
        public readonly int $sockType = SWOOLE_SOCK_TCP,
        public readonly TcpFraming $framing = TcpFraming::Eof,
        public readonly string $packageEof = "\n",
        public readonly string $packageLengthType = 'N',
        public readonly int $lengthOffset = 0,
        public readonly int $bodyOffset = 4,
        public readonly int $packageMaxLength = 2097152,
    ) {}

    /**
     * Swoole settings for the selected framing mode.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->framing) {
            TcpFraming::None => [],
            TcpFraming::Eof => [
                'open_eof_check' => true,
                'open_eof_split' => true,
                'package_eof' => $this->packageEof,
                'package_max_length' => $this->packageMaxLength,
            ],
            TcpFraming::Length => [
                'open_length_check' => true,
                'package_length_type' => $this->packageLengthType,
                'package_length_offset' => $this->lengthOffset,
                'package_body_offset' => $this->bodyOffset,
                'package_max_length' => $this->packageMaxLength,
            ],
        };
    }
}
