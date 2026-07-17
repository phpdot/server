<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base for end-to-end tests that boot a real Server in a separate process and
 * drive it over raw TCP, so the exact bytes on the wire can be asserted.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
abstract class ServerTestCase extends TestCase
{
    /** @var resource|null */
    protected $process = null;

    protected int $port = 0;

    protected string $logFile = '';

    /**
     * Absolute path to the server runner script this test boots.
     */
    abstract protected function runnerScript(): string;

    protected function setUp(): void
    {
        $this->port = $this->findFreePort();
        $this->logFile = sys_get_temp_dir() . '/phpdot_server_it_' . getmypid() . '_' . $this->port . '.log';

        // Array form (no `/bin/sh -c` wrapper): proc_open's pid is then the PHP
        // master itself. A string command is run via the shell, and on Linux the
        // shell stays resident as the child while PHP is forked underneath it, so
        // proc_get_status()'s pid would be the shell — signals aimed at "the
        // master" would hit the shell and never reach Swoole (macOS's shell
        // exec-replaces the single command, which is why that platform was immune).
        $cmd = [PHP_BINARY, $this->runnerScript(), (string) $this->port];

        // Descriptors go to FILES, never to unread pipes (a full pipe buffer would hang the server).
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

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, SIGTERM);

            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                if (proc_get_status($this->process)['running'] === false) {
                    break;
                }
                usleep(50_000);
            }

            // Backstop: if graceful SIGTERM didn't stop it (e.g. a worker stuck mid-stream),
            // SIGKILL the WHOLE process tree. macOS has no PR_SET_PDEATHSIG, so killing only
            // the master would orphan the manager/worker; snapshot + kill the tree instead.
            if (proc_get_status($this->process)['running'] === true) {
                $this->killProcessTree(proc_get_status($this->process)['pid']);
            }

            proc_close($this->process);
            $this->process = null;
        }

        if ($this->logFile !== '' && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    /**
     * SIGKILL a process and its entire descendant tree (master → manager → workers).
     * Snapshots the tree first via `pgrep -P` (works on macOS + Linux; /proc does not
     * exist on macOS), then kills parent-first so the manager can't respawn a worker.
     */
    private function killProcessTree(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        foreach ($this->processTree($pid) as $target) {
            @posix_kill($target, SIGKILL);
        }
    }

    /**
     * @return list<int> $pid then every descendant, parent-first.
     */
    private function processTree(int $pid): array
    {
        $pids = [$pid];
        $children = (string) shell_exec('pgrep -P ' . $pid . ' 2>/dev/null');

        foreach (array_filter(array_map('intval', explode("\n", trim($children)))) as $child) {
            foreach ($this->processTree($child) as $descendant) {
                $pids[] = $descendant;
            }
        }

        return $pids;
    }

    protected function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock, "could not allocate a free port: {$errstr}");
        $name = stream_socket_get_name($sock, false);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($sock);

        return $port;
    }

    protected function waitForServer(): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if (is_resource($fp)) {
                fclose($fp);
                return;
            }

            if (is_resource($this->process) && proc_get_status($this->process)['running'] === false) {
                self::fail("server exited before becoming ready:\n" . (string) @file_get_contents($this->logFile));
            }

            usleep(100_000);
        }

        self::fail("server did not become ready in time:\n" . (string) @file_get_contents($this->logFile));
    }

    protected function rawRequest(string $raw): string
    {
        $fp = fsockopen('127.0.0.1', $this->port, $errno, $errstr, 5.0);
        self::assertIsResource($fp, "connect failed: {$errstr}");
        stream_set_timeout($fp, 5);

        fwrite($fp, $raw);

        $response = '';
        while (feof($fp) === false) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (stream_get_meta_data($fp)['timed_out'] === true) {
                break;
            }
        }
        fclose($fp);

        return $response;
    }

    protected function statusLine(string $response): string
    {
        $pos = strpos($response, "\r\n");
        return $pos === false ? $response : substr($response, 0, $pos);
    }

    protected function bodyOf(string $response): string
    {
        $parts = explode("\r\n\r\n", $response, 2);
        return $parts[1] ?? '';
    }
}
