<?php

declare(strict_types=1);

/**
 * ProcessController — pid-file based control of a running server from outside
 * its process: liveness, uptime, signals, and graceful stop with a bounded
 * escalation to SIGKILL. Swoole itself writes and removes the pid file
 * (ServerConfig::$pidFile → pid_file); this class only reads it and signals.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Process;

use PHPdot\Container\Attribute\Singleton;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Exception\ServerException;
use Swoole\Process as SwooleProcess;

#[Singleton]
final class ProcessController
{
    private const int POLL_INTERVAL_US = 100000;

    private const int START_TIME_SLACK_S = 5;

    /**
     * Create the controller over the master server configuration.
     *
     * @param ServerConfig $config The master configuration holding pidFile and stopTimeout
     */
    public function __construct(
        private readonly ServerConfig $config = new ServerConfig(),
    ) {}

    /**
     * The configured pid-file path.
     *
     * @throws ServerException When no pidFile is configured.
     *
     * @return string
     */
    public function pidFile(): string
    {
        if ($this->config->pidFile === '') {
            throw new ServerException(
                'No pidFile configured. Set pidFile in config/server/master.php to enable '
                . 'server:stop, server:restart, and server:status.',
            );
        }

        return $this->config->pidFile;
    }

    /**
     * Ensure the pid file's directory exists before Swoole writes to it.
     *
     * @throws ServerException When the directory cannot be created.
     *
     * @return void
     */
    public function preparePidDir(): void
    {
        $dir = dirname($this->pidFile());

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new ServerException("Cannot create pid directory: {$dir}");
        }
    }

    /**
     * The recorded master pid, or null when absent or malformed.
     *
     * @return int|null
     */
    public function pid(): int|null
    {
        $file = $this->pidFile();

        if (!is_file($file)) {
            return null;
        }

        $raw = trim((string) file_get_contents($file));

        return ctype_digit($raw) ? (int) $raw : null;
    }

    /**
     * Whether the recorded master process is alive AND is the process the pid
     * file was written for — not an unrelated one the OS recycled the pid onto.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->livePid() !== null;
    }

    /**
     * Seconds since the pid file was written, or null when not running. The
     * identity check makes the mtime trustworthy as a boot marker (a recycled
     * pid no longer reports as running), but an operator `touch` on the pid
     * file still resets the clock — the file is the only cross-platform
     * boot-time record this class has.
     *
     * @return int|null
     */
    public function uptime(): int|null
    {
        if (!$this->isRunning()) {
            return null;
        }

        $mtime = filemtime($this->pidFile());

        return $mtime === false ? null : max(0, time() - $mtime);
    }

    /**
     * Send a signal to the running master.
     *
     * @param int $signal The signal number
     *
     * @return bool Whether a running, identity-matching master received it.
     */
    public function signal(int $signal): bool
    {
        $pid = $this->livePid();

        return $pid !== null && SwooleProcess::kill($pid, $signal) === true;
    }

    /**
     * Stop the running server: SIGTERM, wait up to stopTimeout for a drained
     * exit, then escalate to SIGKILL unless stopTimeout is 0. A pid that fails
     * the identity check is treated as not running — the recorded server is
     * gone and the pid now belongs to someone else, so signalling it would
     * kill an innocent process.
     *
     * @return StopResult
     */
    public function stop(): StopResult
    {
        $pid = $this->livePid();

        if ($pid === null) {
            $this->removeStalePid();

            return StopResult::NotRunning;
        }

        SwooleProcess::kill($pid, SIGTERM);

        if ($this->waitForExit($pid, $this->config->stopTimeout)) {
            $this->removeStalePid();

            return StopResult::Graceful;
        }

        SwooleProcess::kill($pid, SIGKILL);
        $this->waitForExit($pid, 2);
        $this->removeStalePid();

        return StopResult::Forced;
    }

    /**
     * Poll until the pid exits or the timeout elapses.
     *
     * @param int $pid The process id to watch
     * @param int $seconds Timeout in whole seconds; 0 waits forever
     *
     * @return bool Whether the process exited within the window.
     */
    private function waitForExit(int $pid, int $seconds): bool
    {
        $deadline = $seconds === 0 ? PHP_FLOAT_MAX : microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (SwooleProcess::kill($pid, 0) === false) {
                return true;
            }

            usleep(self::POLL_INTERVAL_US);
        }

        return SwooleProcess::kill($pid, 0) === false;
    }

    /**
     * The recorded pid when it is alive AND passes the identity check, else null.
     *
     * @return int|null
     */
    private function livePid(): int|null
    {
        $pid = $this->pid();

        if ($pid === null || SwooleProcess::kill($pid, 0) === false) {
            return null;
        }

        return $this->identityMatches($pid) ? $pid : null;
    }

    /**
     * Whether the process is plausibly the one the pid file was written for.
     *
     * Swoole writes the pid file at boot, so the true master always STARTED
     * BEFORE the file's mtime. A recycled pid always started after the
     * original master died — necessarily after the file was written — so a
     * process start time later than the mtime (plus slack for second
     * truncation) proves the pid no longer belongs to this server. The start
     * time derives from `ps -o etime=` (macOS + Linux), an ELAPSED duration —
     * deliberately not lstart, whose wall-clock text parses in PHP's timezone
     * while ps prints the system's, skewing every comparison by the offset.
     * When ps is missing or unparseable the check degrades to permissive
     * rather than bricking server:stop.
     *
     * @param int $pid The alive process to vet
     *
     * @return bool
     */
    private function identityMatches(int $pid): bool
    {
        $mtime = @filemtime($this->pidFile());

        if ($mtime === false) {
            return true;
        }

        $elapsed = $this->elapsedSeconds($pid);

        return $elapsed === null || time() - $elapsed <= $mtime + self::START_TIME_SLACK_S;
    }

    /**
     * Seconds the process has been alive per `ps -o etime=` ([[dd-]hh:]mm:ss),
     * or null when ps is unavailable or the format is unrecognized.
     *
     * @param int $pid The process to measure
     *
     * @return int|null
     */
    private function elapsedSeconds(int $pid): int|null
    {
        $raw = shell_exec(sprintf('ps -p %d -o etime= 2>/dev/null', $pid));

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $etime = trim($raw);

        if (preg_match('/^(?:(\d+)-)?(?:(\d+):)?(\d+):(\d+)$/', $etime, $m) !== 1) {
            return null;
        }

        $days = (int) $m[1];
        $hours = (int) $m[2];

        return (($days * 24 + $hours) * 60 + (int) $m[3]) * 60 + (int) $m[4];
    }

    /**
     * Delete a leftover pid file so status never reports a dead pid as stale state.
     *
     * @return void
     */
    private function removeStalePid(): void
    {
        $file = $this->pidFile();

        if (is_file($file)) {
            @unlink($file);
        }
    }
}
