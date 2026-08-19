<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Graceful-drain regression (plan lesson L6): a SIGTERM arriving while a
 * request is in flight must let the response complete before the process
 * exits — BASE-mode workers own their connections, so shutdown drains. Also
 * asserts a rapid double SIGINT exits cleanly instead of wedging the tree.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class DrainOnStopTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_drain_runner.php';
    }

    #[Test]
    public function sigtermDrainsAnInFlightRequest(): void
    {
        self::assertIsResource($this->process);

        $socket = stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 2.0);
        self::assertIsResource($socket, "connect failed: {$errstr}");
        stream_set_timeout($socket, 5);

        fwrite($socket, "GET /slow HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        usleep(300_000);

        $pid = proc_get_status($this->process)['pid'];
        posix_kill($pid, SIGTERM);

        $response = (string) stream_get_contents($socket);
        fclose($socket);

        self::assertStringContainsString('200', $this->statusLine($response), 'in-flight request was dropped by SIGTERM');
        self::assertStringContainsString('SLOW-OK', $response, 'in-flight response body did not complete');

        $this->assertExitsWithin(4.0, 'server still running 4s after SIGTERM with drain complete');
    }

    #[Test]
    public function doubleSigintExitsCleanly(): void
    {
        self::assertIsResource($this->process);

        $response = $this->rawRequest("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertStringContainsString('200', $this->statusLine($response), 'server should serve before the signals');

        $pid = proc_get_status($this->process)['pid'];
        posix_kill($pid, SIGINT);
        usleep(150_000);
        posix_kill($pid, SIGINT);

        $this->assertExitsWithin(4.0, 'server still running 4s after a double SIGINT');

        $log = (string) file_get_contents($this->logFile);
        self::assertStringNotContainsString('Deprecated', $log, 'double SIGINT produced deprecation noise');
    }

    /**
     * Poll until the runner exits or fail with the given message.
     *
     * @param float $seconds Timeout window
     * @param string $message Failure message
     *
     * @return void
     */
    private function assertExitsWithin(float $seconds, string $message): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (proc_get_status($this->process)['running'] === false) {
                self::assertTrue(true);

                return;
            }

            usleep(50_000);
        }

        self::fail($message);
    }
}
