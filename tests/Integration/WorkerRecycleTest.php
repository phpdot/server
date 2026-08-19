<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPdot\Server\Process\ControlClient;
use PHPUnit\Framework\Attributes\Test;

/**
 * Recycle/reload regressions (plan lesson L5): sustained requests across many
 * worker recycles (small max_request) must drop nothing, and an open SSE
 * stream must keep ticking through a SIGUSR1 worker reload while fresh
 * requests keep serving.
 *
 * The survival test deliberately outlives the OrphanWatchdog probe window:
 * in BASE mode Swoole's shared-memory master_pid is overwritten by workers,
 * and a pid-probing watchdog once reaped the whole healthy tree two seconds
 * after every reload — inside the window the other tests finish in, so they
 * all passed while every real server died.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class WorkerRecycleTest extends ServerTestCase
{
    private string $pidDir = '';

    protected function setUp(): void
    {
        $this->pidDir = sys_get_temp_dir() . '/phpdot_recycle_' . getmypid() . '_' . random_int(1000, 9999);
        mkdir($this->pidDir, 0o755, true);
        putenv('PHPDOT_TEST_MAX_REQUEST=20');
        putenv('PHPDOT_TEST_PID_FILE=' . $this->pidDir . '/server.pid');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('PHPDOT_TEST_MAX_REQUEST');
        putenv('PHPDOT_TEST_PID_FILE');
        parent::tearDown();
        @unlink($this->pidDir . '/server.pid');
        @unlink($this->pidDir . '/server.sock');
        @rmdir($this->pidDir);
    }

    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_recycle_runner.php';
    }

    #[Test]
    public function recyclingUnderSustainedRequestsDropsNothing(): void
    {
        $pids = [];

        for ($i = 0; $i < 300; $i++) {
            $response = $this->rawRequest("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

            self::assertStringContainsString('200', $this->statusLine($response), "request {$i} failed mid-recycle");

            $body = $this->bodyOf($response);
            self::assertStringStartsWith('OK-', $body, "request {$i} returned a broken body");
            $pids[substr($body, 3)] = true;
        }

        self::assertGreaterThan(2, count($pids), 'workers never recycled — max_request had no effect');
    }

    #[Test]
    public function sseStreamSurvivesWorkerReload(): void
    {
        self::assertIsResource($this->process);

        $socket = stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 2.0);
        self::assertIsResource($socket, "connect failed: {$errstr}");
        stream_set_timeout($socket, 5);

        fwrite($socket, "GET /sse HTTP/1.1\r\nHost: x\r\nAccept: text/event-stream\r\nConnection: close\r\n\r\n");

        $this->readUntil($socket, 'tick-3', 'stream never started ticking');

        $pid = proc_get_status($this->process)['pid'];
        posix_kill($pid, SIGUSR1);

        $this->readUntil($socket, 'tick-15', 'stream died across the worker reload');
        fclose($socket);

        $response = $this->rawRequest("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertStringContainsString('200', $this->statusLine($response), 'fresh requests broken after reload');
    }

    #[Test]
    public function reloadReforksEveryWorker(): void
    {
        self::assertIsResource($this->process);

        $master = proc_get_status($this->process)['pid'];
        $before = $this->childPids($master);
        self::assertGreaterThanOrEqual(2, count($before), 'fixture should run at least two workers');

        posix_kill($master, SIGUSR1);

        $deadline = microtime(true) + 8.0;
        $fresh = [];

        while (microtime(true) < $deadline) {
            $after = $this->childPids($master);
            $fresh = array_diff($after, $before);

            if (count($fresh) >= 2 && count($after) >= count($before)) {
                break;
            }

            usleep(100000);
        }

        self::assertGreaterThanOrEqual(
            2,
            count($fresh),
            'reload must re-fork EVERY worker, not one — a partial reload serves stale code from the rest',
        );

        $response = $this->rawRequest("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertStringContainsString('200', $this->statusLine($response), 'server broken after full re-fork');
    }

    #[Test]
    public function serverOutlivesTheWatchdogWindowAfterReload(): void
    {
        self::assertIsResource($this->process);

        posix_kill(proc_get_status($this->process)['pid'], SIGUSR1);

        $deadline = microtime(true) + 7.0;
        $served = 0;

        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 1.0);

            if (is_resource($fp)) {
                fclose($fp);
                $served++;
            }

            usleep(900_000);
        }

        self::assertGreaterThan(0, $served, 'server never answered during the watchdog window');

        self::assertTrue(
            proc_get_status($this->process)['running'],
            'the orphan watchdog reaped a healthy tree after a reload (BASE master_pid holds a worker pid)',
        );

        $response = $this->rawRequest("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertStringContainsString(
            '200',
            $this->statusLine($response),
            'server stopped serving beyond the watchdog window after a reload',
        );
    }

    #[Test]
    public function controlSocketAnswersLiveStats(): void
    {
        $stats = ControlClient::stats($this->pidDir . '/server.sock', 2.0);

        self::assertIsArray($stats, 'the control socket must answer a stats query');
        self::assertArrayHasKey('connection_num', $stats, 'payload must be the master stats');
        self::assertArrayHasKey('answering_worker_pid', $stats, 'payload must name the answering worker');
    }

    /**
     * Live child pids of the master (workers + user processes): read from
     * /proc where it exists (Linux — the slim CI image has no pgrep), else
     * fall back to pgrep (macOS).
     *
     * @param int $master The master pid
     *
     * @return list<int>
     */
    private function childPids(int $master): array
    {
        if (is_dir('/proc')) {
            $pids = [];

            foreach (glob('/proc/[0-9]*/status') ?: [] as $status) {
                $content = (string) @file_get_contents($status);

                if (preg_match('/^PPid:\s+(\d+)$/m', $content, $m) === 1 && (int) $m[1] === $master) {
                    $pids[] = (int) basename(dirname($status));
                }
            }

            return $pids;
        }

        $raw = (string) shell_exec('pgrep -P ' . $master . ' 2>/dev/null');

        $pids = [];

        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $chunk) {
            if (ctype_digit($chunk)) {
                $pids[] = (int) $chunk;
            }
        }

        return $pids;
    }

    /**
     * Read from the stream until the needle appears or fail with the message.
     *
     * @param resource $socket The open stream
     * @param string $needle The content to wait for
     * @param string $message Failure message
     *
     * @return void
     */
    private function readUntil($socket, string $needle, string $message): void
    {
        $buffer = '';
        $deadline = microtime(true) + 8.0;

        while (microtime(true) < $deadline) {
            $chunk = fread($socket, 8192);

            if (is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;
            }

            if (str_contains($buffer, $needle)) {
                return;
            }

            if (feof($socket)) {
                break;
            }

            usleep(20000);
        }

        self::fail($message . ' (buffer tail: ' . substr($buffer, -160) . ')');
    }
}
