<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Unit;

use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Exception\ServerException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ServerConfig construction validates fail-fast: nonsense worker/wait values
 * would otherwise surface as Swoole runtime weirdness instead of a boot
 * error. Streaming (SSE/WS) servers configure their protections directly —
 * maxRequest 0 and a raised maxWaitTime — with no preset in between.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class ServerConfigTest extends TestCase
{
    #[Test]
    public function typedSettingsReachTheSwooleArray(): void
    {
        $settings = new ServerConfig(maxRequest: 5000, maxWaitTime: 30)->toArray();

        self::assertSame(5000, $settings['max_request']);
        self::assertSame(30, $settings['max_wait_time']);
    }

    #[Test]
    public function aStreamingServerCanDisableRecyclingDirectly(): void
    {
        $settings = new ServerConfig(maxRequest: 0)->toArray();

        self::assertSame(0, $settings['max_request']);
    }

    #[Test]
    public function aZeroWorkerCountFailsAtConstruction(): void
    {
        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('workerNum');

        new ServerConfig(workerNum: 0);
    }

    #[Test]
    public function aZeroDrainWindowFailsAtConstruction(): void
    {
        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('maxWaitTime');

        new ServerConfig(maxWaitTime: 0);
    }

    #[Test]
    public function anUnknownModeFailsAtConstruction(): void
    {
        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('mode');

        new ServerConfig(mode: 99);
    }

    #[Test]
    public function negativeCountsFailAtConstruction(): void
    {
        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('must not be negative');

        new ServerConfig(taskWorkerNum: -1);
    }
}
