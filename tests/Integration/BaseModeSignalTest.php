<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * SWOOLE_BASE signal regression: in BASE mode worker 0 runs onStart and then
 * onWorkerStart in the same OS process (swoole-src reactor_process.cc), so the
 * workerStart SIGINT-ignore used to overwrite the SIGINT→shutdown handler and
 * the server could only be killed by SIGTERM/SIGKILL. This boots a real BASE
 * server, sends SIGINT (what Ctrl+C delivers), and requires it to exit.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class BaseModeSignalTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_base_runner.php';
    }

    #[Test]
    public function sigintShutsDownABaseModeServer(): void
    {
        self::assertIsResource($this->process);

        $response = $this->rawRequest("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertStringContainsString('200', $this->statusLine($response), 'BASE server should serve before the signal');

        $pid = proc_get_status($this->process)['pid'];
        posix_kill($pid, SIGINT);

        $deadline = microtime(true) + 4.0;
        while (microtime(true) < $deadline) {
            if (proc_get_status($this->process)['running'] === false) {
                self::assertTrue(true);

                return;
            }
            usleep(50_000);
        }

        self::fail('BASE-mode server still running 4s after SIGINT — Ctrl+C would be ignored');
    }
}
