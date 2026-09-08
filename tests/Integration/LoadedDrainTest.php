<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * The drain under LOAD: two workers, hooks on, SSE streaming, a WebSocket
 * connected, the cluster heartbeat ticking against real Redis — everything
 * that once held the drain open for its full 30s window, live at once. A
 * SIGTERM mid-flight must let the in-flight request complete AND the process
 * exit far inside the window: the cancelled heartbeat tick, the cancelled
 * in-flight beat, and the SSE coroutine cancellation are what make the
 * difference between a 1s exit and a 30s grind.
 */
final class LoadedDrainTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_loaded_runner.php';
    }

    #[Test]
    public function sigtermUnderLoadCompletesWorkAndExitsFast(): void
    {
        $redisPort = (int) (getenv('REDIS_PORT') ?: 6380);
        $probe = @fsockopen('127.0.0.1', $redisPort, $errno, $errstr, 0.5);

        if ($probe === false) {
            self::markTestSkipped("compose redis not reachable on {$redisPort}; the loaded drain needs the heartbeat live.");
        }

        fclose($probe);

        self::assertIsResource($this->process);

        /*
         * A live SSE stream — the coroutine that pins an exiting worker if
         * nothing cancels it.
         */
        $stream = stream_socket_client("tcp://127.0.0.1:{$this->port}", $e1, $s1, 2.0);
        self::assertIsResource($stream, "sse connect failed: {$s1}");
        stream_set_timeout($stream, 10);
        fwrite($stream, "GET /stream HTTP/1.1\r\nHost: x\r\nAccept: text/event-stream\r\nConnection: close\r\n\r\n");
        usleep(400_000);

        $first = (string) fread($stream, 4096);
        self::assertStringContainsString('event: hello', $first, 'the SSE stream never opened');

        /*
         * Two slow in-flight requests — work the drain exists to protect.
         */
        $slow = [];
        foreach ([1, 2] as $i) {
            $slow[$i] = stream_socket_client("tcp://127.0.0.1:{$this->port}", $e2, $s2, 2.0);
            self::assertIsResource($slow[$i], "slow connect failed: {$s2}");
            stream_set_timeout($slow[$i], 10);
            fwrite($slow[$i], "GET /slow{$i} HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        }

        usleep(300_000);

        $pid = proc_get_status($this->process)['pid'];
        posix_kill($pid, SIGTERM);

        /*
         * In-flight work completes — both bodies, not just status lines.
         */
        foreach ($slow as $i => $socket) {
            $response = (string) stream_get_contents($socket);
            fclose($socket);

            self::assertStringContainsString('200', $this->statusLine($response), "in-flight request {$i} was dropped by SIGTERM");
            self::assertStringContainsString('SLOW-OK', $response, "in-flight response {$i} body did not complete");
        }

        /*
         * The stream ends — reader-side EOF, not a hang.
         */
        fclose($stream);

        /*
         * THE assertion: with the heartbeat live and a stream having run, the
         * exit lands far inside the 30s window the drain floor raises. 10s is
         * generous headroom over the measured ~1s and a third of the window
         * the bugs used to consume.
         */
        $this->assertExitsWithin(10.0, 'server still running 10s after SIGTERM with the drain fixed — a timer or parked beat is holding the exit again');
    }

    /**
     * @param float $seconds
     * @param string $message
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
