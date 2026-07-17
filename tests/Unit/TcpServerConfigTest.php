<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Unit;

use PHPdot\Server\Config\TcpServerConfig;
use PHPdot\Server\Tcp\TcpFraming;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TcpServerConfig::toArray() emits only the Swoole keys for the selected mode.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class TcpServerConfigTest extends TestCase
{
    #[Test]
    public function eofFramingEmitsEofKeys(): void
    {
        $config = new TcpServerConfig(framing: TcpFraming::Eof, packageEof: "\r\n");

        self::assertSame(
            [
                'open_eof_check' => true,
                'open_eof_split' => true,
                'package_eof' => "\r\n",
                'package_max_length' => 2097152,
            ],
            $config->toArray(),
        );
    }

    #[Test]
    public function lengthFramingEmitsLengthKeys(): void
    {
        $config = new TcpServerConfig(
            framing: TcpFraming::Length,
            packageLengthType: 'N',
            lengthOffset: 0,
            bodyOffset: 4,
        );

        self::assertSame(
            [
                'open_length_check' => true,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
                'package_max_length' => 2097152,
            ],
            $config->toArray(),
        );
    }

    #[Test]
    public function noneFramingEmitsNothing(): void
    {
        self::assertSame([], (new TcpServerConfig(framing: TcpFraming::None))->toArray());
    }

    #[Test]
    public function defaultsAreEofLineFraming(): void
    {
        $config = new TcpServerConfig();

        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(9501, $config->port);
        self::assertSame(TcpFraming::Eof, $config->framing);
        self::assertSame("\n", $config->packageEof);
    }
}
