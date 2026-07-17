<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * ConnectionRegistry + WS close semantics against a real Server carrying HTTP,
 * WS, and a raw-TCP port on one master (Fixtures/server_ws_registry_runner.php).
 *
 * Regressions covered:
 *  - broadcast() must never write raw bytes into WS or HTTP streams (the
 *    master's connections iterator spans every port);
 *  - broadcast($data, $port) targets one listening port;
 *  - handleWsClose fires for real WS closes (client drop AND server-initiated
 *    disconnect()) and NEVER for plain-HTTP connections that never upgraded.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class WsRegistryBehaviourTest extends ServerTestCase
{
    use WebSocketClientTrait;

    private int $tcpPort = 0;

    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_ws_registry_runner.php';
    }

    protected function setUp(): void
    {
        $this->port = $this->findFreePort();
        $this->tcpPort = $this->findFreePort();
        $this->logFile = sys_get_temp_dir() . '/phpdot_server_it_' . getmypid() . '_' . $this->port . '.log';

        $cmd = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($this->runnerScript())
            . ' ' . $this->port
            . ' ' . $this->tcpPort;

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->logFile, 'w'],
            2 => ['file', $this->logFile, 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        self::assertIsResource($process, 'failed to launch server runner');
        $this->process = $process;

        $this->waitForServer();
    }

    #[Test]
    public function plainHttpConnectionNeverTriggersWsClose(): void
    {
        $response = $this->rawRequest("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertStringContainsString('200', $this->statusLine($response));

        usleep(500_000); // let the close event land in the log if it (wrongly) fires

        self::assertStringNotContainsString('WSCLOSE', $this->log(), 'handleWsClose fired for a connection that never upgraded');
    }

    #[Test]
    public function broadcastReachesTcpButNeverWsOrHttp(): void
    {
        $ws = $this->openWebSocket($this->port);
        $tcp = $this->openTcp();

        $response = $this->rawRequest("GET /broadcast HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertSame('sent-all', $this->bodyOf($response), 'broadcast must not corrupt the requesting HTTP response');

        self::assertSame('BCAST-ALL', $this->readTcpLine($tcp), 'raw-TCP client should receive the broadcast');

        // The WS stream must be untouched: the next thing on the socket has to be
        // a well-formed echo frame, not raw broadcast bytes.
        fwrite($ws, $this->encodeMaskedTextFrame('still-alive'));
        self::assertSame('echo:still-alive', $this->readTextFrame($ws), 'raw broadcast bytes corrupted the WS stream');

        fclose($ws);
        fclose($tcp);
    }

    #[Test]
    public function portScopedBroadcastOnlyHitsThatPort(): void
    {
        $ws = $this->openWebSocket($this->port);
        $tcp = $this->openTcp();

        $response = $this->rawRequest("GET /broadcast-port HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertSame('sent-port', $this->bodyOf($response));

        self::assertSame('BCAST-PORT', $this->readTcpLine($tcp));

        fwrite($ws, $this->encodeMaskedTextFrame('still-alive'));
        self::assertSame('echo:still-alive', $this->readTextFrame($ws));

        fclose($ws);
        fclose($tcp);
    }

    #[Test]
    public function clientDropFiresWsCloseForTheOpenedFd(): void
    {
        $ws = $this->openWebSocket($this->port);
        $fd = $this->lastOpenedFd();

        fclose($ws);

        self::assertTrue(
            $this->waitForLog("WSCLOSE {$fd}"),
            "handleWsClose({$fd}) did not fire after the client dropped",
        );
    }

    #[Test]
    public function disconnectOnDeadOrNonWsFdIsASilentNoOp(): void
    {
        $ws = $this->openWebSocket($this->port);
        $fd = $this->lastOpenedFd();
        fclose($ws);
        self::assertTrue($this->waitForLog("WSCLOSE {$fd}"));

        $response = $this->rawRequest("GET /disconnect-fd?fd={$fd} HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertSame('false', $this->bodyOf($response), 'disconnect on a dead fd must return false');

        foreach (range($fd + 1, $fd + 20) as $stale) {
            $this->rawRequest("GET /disconnect-fd?fd={$stale} HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        }

        usleep(300_000);
        self::assertStringNotContainsString(
            'not a websocket client',
            $this->log(),
            'sweeping dead/non-WS fds must not produce Swoole warnings',
        );
    }

    #[Test]
    public function serverInitiatedDisconnectFiresWsClose(): void
    {
        $ws = $this->openWebSocket($this->port);
        $fd = $this->lastOpenedFd();

        fwrite($ws, $this->encodeMaskedTextFrame('kick'));

        [$firstByte] = $this->readFrame($ws);
        self::assertSame(0x88, $firstByte, 'expected a CLOSE frame from the server-initiated disconnect');
        fclose($ws);

        self::assertTrue(
            $this->waitForLog("WSCLOSE {$fd}"),
            "handleWsClose({$fd}) did not fire after a server-initiated disconnect()",
        );
    }

    /**
     * @return resource
     */
    private function openTcp()
    {
        $fp = fsockopen('127.0.0.1', $this->tcpPort, $errno, $errstr, 5.0);
        self::assertIsResource($fp, "tcp connect failed: {$errstr}");
        stream_set_timeout($fp, 5);

        return $fp;
    }

    /**
     * Read one EOF-framed line from the TCP socket.
     *
     * @param resource $fp
     */
    private function readTcpLine($fp): string
    {
        $reply = '';
        while (!str_ends_with($reply, "\n")) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '' || stream_get_meta_data($fp)['timed_out'] === true) {
                break;
            }
            $reply .= $chunk;
        }

        return rtrim($reply, "\n");
    }

    private function log(): string
    {
        return (string) @file_get_contents($this->logFile);
    }

    /** The fd of the most recent WSOPEN line (polls: the log write is async). */
    private function lastOpenedFd(): int
    {
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            if (preg_match_all('/^WSOPEN (\d+)$/m', $this->log(), $m) > 0) {
                return (int) end($m[1]);
            }
            usleep(50_000);
        }

        self::fail('no WSOPEN line appeared in the runner log');
    }

    private function waitForLog(string $needle, float $timeout = 3.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (str_contains($this->log(), $needle)) {
                return true;
            }
            usleep(50_000);
        }

        return false;
    }
}
