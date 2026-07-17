<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Unit;

use PHPdot\Server\Contract\Transport;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Server;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Server as SwooleServer;

/**
 * Unit coverage for Server::ensurePortsAvailable (bind-probe correctness:
 * IPv6 hosts, non-TCP socket types, SSL flag) and the serve() re-entry guard.
 * No Swoole master is started; probes use plain PHP sockets.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class ServerPortsAndGuardsTest extends TestCase
{
    #[Test]
    public function freeIpv6PortPassesTheProbe(): void
    {
        $port = $this->freePort('[::1]');

        $server = new Server();
        $server->attach($this->transport('::1', $port, SWOOLE_SOCK_TCP6));

        $server->ensurePortsAvailable();
        self::assertTrue(true, 'a free IPv6 port must not read as occupied');
    }

    #[Test]
    public function occupiedIpv6PortFailsTheProbe(): void
    {
        [$holder, $port] = $this->holdPort('[::1]');

        $server = new Server();
        $server->attach($this->transport('::1', $port, SWOOLE_SOCK_TCP6));

        try {
            $this->expectException(ServerException::class);
            $this->expectExceptionMessage((string) $port);
            $server->ensurePortsAvailable();
        } finally {
            fclose($holder);
        }
    }

    #[Test]
    public function occupiedIpv4PortStillFailsTheProbe(): void
    {
        [$holder, $port] = $this->holdPort('127.0.0.1');

        $server = new Server();
        $server->attach($this->transport('127.0.0.1', $port, SWOOLE_SOCK_TCP));

        try {
            $this->expectException(ServerException::class);
            $server->ensurePortsAvailable();
        } finally {
            fclose($holder);
        }
    }

    #[Test]
    public function sslFlaggedTcpTransportIsStillProbed(): void
    {
        [$holder, $port] = $this->holdPort('127.0.0.1');

        $server = new Server();
        $server->attach($this->transport('127.0.0.1', $port, SWOOLE_SOCK_TCP | SWOOLE_SSL));

        try {
            $this->expectException(ServerException::class);
            $server->ensurePortsAvailable();
        } finally {
            fclose($holder);
        }
    }

    #[Test]
    public function udpTransportSkipsTheTcpProbe(): void
    {
        // A TCP listener on the number says nothing about the UDP port.
        [$holder, $port] = $this->holdPort('127.0.0.1');

        $server = new Server();
        $server->attach($this->transport('127.0.0.1', $port, SWOOLE_SOCK_UDP));

        try {
            $server->ensurePortsAvailable();
            self::assertTrue(true, 'UDP transports must not be TCP-probed');
        } finally {
            fclose($holder);
        }
    }

    #[Test]
    public function serveCanOnlyBeCalledOnce(): void
    {
        $server = new Server();
        $server->attach($this->transport('127.0.0.1', $this->freePort('127.0.0.1'), SWOOLE_SOCK_TCP, registerThrows: true));

        try {
            $server->serve($this->handler());
            self::fail('the stub transport should abort the first serve()');
        } catch (ServerException $e) {
            self::assertSame('stub transport aborts registration', $e->getMessage());
        }

        // The master exists now; a retry must fail fast instead of silently
        // re-subscribing transports and building a second master.
        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('already started');
        $server->serve($this->handler());
    }

    private function transport(string $host, int $port, int $sockType, bool $registerThrows = false): Transport
    {
        return new class ($host, $port, $sockType, $registerThrows) implements Transport {
            public function __construct(
                private readonly string $host,
                private readonly int $port,
                private readonly int $sockType,
                private readonly bool $registerThrows,
            ) {}

            public function host(): string
            {
                return $this->host;
            }

            public function port(): int
            {
                return $this->port;
            }

            public function sockType(): int
            {
                return $this->sockType;
            }

            public function settings(): array
            {
                return [];
            }

            public function register(SwooleServer $master, bool $primary, object|null $handler): void
            {
                if ($this->registerThrows) {
                    throw new ServerException('stub transport aborts registration');
                }
            }
        };
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('never invoked');
            }
        };
    }

    private function freePort(string $bindHost): int
    {
        $sock = stream_socket_server("tcp://{$bindHost}:0", $errno, $errstr);
        self::assertIsResource($sock, "could not allocate a free port: {$errstr}");
        $port = $this->portOf($sock);
        fclose($sock);

        return $port;
    }

    /**
     * Bind and HOLD a port so the probe sees it occupied.
     *
     * @return array{0: resource, 1: int}
     */
    private function holdPort(string $bindHost): array
    {
        $sock = stream_socket_server("tcp://{$bindHost}:0", $errno, $errstr);
        self::assertIsResource($sock, "could not hold a port: {$errstr}");

        return [$sock, $this->portOf($sock)];
    }

    /**
     * @param resource $sock
     */
    private function portOf($sock): int
    {
        $name = stream_socket_get_name($sock, false);
        self::assertIsString($name);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
