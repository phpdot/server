<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * M2: HTTP and raw TCP coexist on ONE Server/process. A request flows through the
 * PSR-15 pipeline (HttpServer) while a TCP client connects to the added TcpServer
 * port and gets its framed line echoed back — same master, same process.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class HttpAndTcpTest extends ServerTestCase
{
    private int $tcpPort = 0;

    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/http_and_tcp_runner.php';
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
    public function httpStillServesAlongsideTcp(): void
    {
        $response = $this->rawRequest("GET /ok HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('200', $this->statusLine($response));
        self::assertSame('OK', $this->bodyOf($response));
    }

    #[Test]
    public function tcpEchoesFramedLine(): void
    {
        $reply = $this->rawTcp("hello\n");

        self::assertSame('ECHO: hello', $reply);
    }

    /**
     * Send raw bytes to the TCP port and read until the reply's trailing newline.
     */
    private function rawTcp(string $payload): string
    {
        $fp = fsockopen('127.0.0.1', $this->tcpPort, $errno, $errstr, 5.0);
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
