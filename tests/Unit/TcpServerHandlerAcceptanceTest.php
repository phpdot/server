<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Unit;

use PHPdot\Contracts\Server\TcpHandlerInterface;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Tcp\TcpServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Server as SwooleServer;

/**
 * TcpServer::register() must accept any handler typed against the published
 * contract (PHPdot\Contracts\Server\TcpHandlerInterface). Regression guard for
 * the pre-extraction era, when a package-local copy of the interface made the
 * instanceof check reject handlers written against phpdot/contracts.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class TcpServerHandlerAcceptanceTest extends TestCase
{
    #[Test]
    public function handlerTypedAgainstThePublishedContractIsAccepted(): void
    {
        $master = $this->createMock(SwooleServer::class);
        $master->expects(self::exactly(3))->method('on');

        $handler = new class implements TcpHandlerInterface {
            public function handleTcpConnect(int $fd): void {}

            public function handleTcpReceive(int $fd, string $data): void {}

            public function handleTcpClose(int $fd): void {}
        };

        (new TcpServer())->register($master, true, $handler);
    }

    #[Test]
    public function handlerWithoutTheContractIsRejected(): void
    {
        $master = $this->createStub(SwooleServer::class);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('TcpHandlerInterface');

        (new TcpServer())->register($master, true, new \stdClass());
    }
}
