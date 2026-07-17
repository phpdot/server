<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Parity harness — a TcpServer as the PRIMARY transport (no HttpServer), i.e. the
 * standalone raw-TCP server on a plain Swoole\Server master. Covers the primary
 * TCP path (TcpServer.php:82-89) that has zero coverage today and is an SR-M3
 * rewrite target. Locks the current framed-echo behaviour on the wire.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class TcpStandaloneTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/tcp_standalone_runner.php';
    }

    #[Test]
    public function primaryTcpTransportEchoesFramedLine(): void
    {
        $reply = $this->rawLine("ping\n");

        self::assertSame('ECHO: ping', $reply);
    }

    #[Test]
    public function primaryTcpTransportHandlesSequentialFrames(): void
    {
        $reply = $this->rawLine("one\n");
        self::assertSame('ECHO: one', $reply);

        $reply = $this->rawLine("two\n");
        self::assertSame('ECHO: two', $reply);
    }

    #[Test]
    public function invokesConnectAndCloseHandlers(): void
    {
        // A throwaway connection exercises connect + a clean close on the wire.
        $throwaway = fsockopen('127.0.0.1', $this->port, $errno, $errstr, 5.0);
        self::assertIsResource($throwaway, "tcp connect failed: {$errstr}");
        fclose($throwaway);

        // Poll the handler's counters (each STATS query is itself a connect+close,
        // so closes converges quickly despite async close processing).
        $connects = 0;
        $closes = 0;
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            if (preg_match('/connects=(\d+);closes=(\d+)/', $this->rawLine("STATS\n"), $m) === 1) {
                $connects = (int) $m[1];
                $closes = (int) $m[2];
                if ($closes >= 1) {
                    break;
                }
            }
            usleep(50_000);
        }

        self::assertGreaterThanOrEqual(2, $connects, 'handleTcpConnect should fire per connection');
        self::assertGreaterThanOrEqual(1, $closes, 'handleTcpClose should fire on disconnect');
    }

    /**
     * Connect to the primary TCP port, send one framed payload, read one
     * newline-terminated reply.
     */
    private function rawLine(string $payload): string
    {
        $fp = fsockopen('127.0.0.1', $this->port, $errno, $errstr, 5.0);
        self::assertIsResource($fp, "tcp connect failed: {$errstr}");
        stream_set_timeout($fp, 5);

        fwrite($fp, $payload);

        $reply = '';
        while (feof($fp) === false) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $reply .= $chunk;
            if (str_ends_with($reply, "\n")) {
                break;
            }
            if (stream_get_meta_data($fp)['timed_out'] === true) {
                break;
            }
        }
        fclose($fp);

        return rtrim($reply, "\n");
    }
}
