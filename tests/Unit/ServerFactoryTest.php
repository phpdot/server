<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Unit;

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Config\TcpServerConfig;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Server;
use PHPdot\Server\ServerFactory;
use PHPdot\Server\Tests\Fixtures\Listener\ScanProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * ServerFactory assembly rules: enabled transports attach, nothing enabled
 * fails at creation time (plan lesson L3 — misconfiguration surfaces at boot,
 * never inside serve()), and listener discovery loads NOTHING into the
 * pre-fork process — a class loaded here is frozen into every worker
 * generation and reload stops applying its edits.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class ServerFactoryTest extends TestCase
{
    #[Test]
    public function nothingEnabledFailsAtCreation(): void
    {
        $factory = new ServerFactory(
            new Server(new ServerConfig(workerNum: 1)),
            new HttpServerConfig(enabled: false),
            new TcpServerConfig(enabled: false),
            new ResponseFactory(),
            $this->stubContainer(),
        );

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('No transport enabled');

        $factory->create();
    }

    #[Test]
    public function enabledHttpTransportIsAttached(): void
    {
        $server = new Server(new ServerConfig(workerNum: 1));

        $factory = new ServerFactory(
            $server,
            new HttpServerConfig(enabled: true, host: '127.0.0.1', port: 18123),
            new TcpServerConfig(enabled: false),
            new ResponseFactory(),
            $this->stubContainer(),
        );

        self::assertSame($server, $factory->create());
        self::assertSame([18123], $server->httpPorts());
    }

    #[Test]
    public function disabledHttpLeavesNoHttpPorts(): void
    {
        $server = new Server(new ServerConfig(workerNum: 1));

        $factory = new ServerFactory(
            $server,
            new HttpServerConfig(enabled: false),
            new TcpServerConfig(enabled: true),
            new ResponseFactory(),
            $this->stubContainer(),
        );

        self::assertSame($server, $factory->create());
        self::assertSame([], $server->httpPorts());
    }

    #[Test]
    public function listenerDiscoveryLoadsNothingIntoThePreForkProcess(): void
    {
        $factory = new ServerFactory(
            new Server(new ServerConfig(workerNum: 1)),
            new HttpServerConfig(enabled: true, host: '127.0.0.1', port: 18124),
            new TcpServerConfig(enabled: false),
            new ResponseFactory(),
            $this->stubContainer(),
        );

        $factory->discover([dirname(__DIR__) . '/Fixtures/Listener'])->create();

        self::assertFalse(
            class_exists(ScanProbe::class, false),
            'create() must keep scanned listener classes OUT of the pre-fork master',
        );
    }

    /**
     * A container stub for factory construction — listener discovery is off
     * (no discover() call), so it is never asked for anything.
     *
     * @return ContainerInterface
     */
    private function stubContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException("unexpected container get: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
