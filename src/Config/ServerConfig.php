<?php

declare(strict_types=1);

/**
 * ServerConfig — server-wide settings for the Swoole master (workers, process
 * mode, coroutine hooks, socket buffers, daemon, logging). Per-transport bind
 * (host/port/sockType) and HTTP-specific toggles live on the transports
 * (HttpServerConfig, …), NOT here.
 *
 * Auto-bound by phpdot/config when phpdot/package is installed: the user edits
 * config/server/master.php; the DTO is hydrated from that file.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Config;

use PHPdot\Container\Attribute\Config;

#[Config('server.master')]
final class ServerConfig
{
    /**
     * Create the server configuration.
     *
     * @param int|null $workerNum Worker count (null = swoole_cpu_num()).
     * @param int $taskWorkerNum Task worker count.
     * @param int $maxRequest Restart a worker after N requests.
     * @param int $maxCoroutine Max coroutines per worker.
     * @param int $mode SWOOLE_PROCESS (default) or SWOOLE_BASE.
     * @param bool $daemonize Run as daemon.
     * @param string $pidFile PID file path.
     * @param string $logFile Log file path.
     * @param int $logLevel SWOOLE_LOG_* constant.
     * @param int $backlog TCP backlog queue size.
     * @param bool $tcpNodelay Enable TCP nodelay.
     * @param bool $tcpKeepalive Enable TCP keepalive.
     * @param int $bufferOutputSize Output buffer size in bytes.
     * @param int $socketBufferSize Socket buffer size in bytes.
     * @param int $packageMaxLength Max package length in bytes.
     * @param int $maxWaitTime Seconds a worker may drain on reload/shutdown before it is
     *                         force-killed (ERRNO 9101). Swoole's default is 3 — raise it if
     *                         shutdown legitimately has to wait on slow in-flight work.
     * @param bool $orphanWatchdog Reap the manager/workers if the master dies without a
     *                             graceful teardown (SIGKILL, crash) — macOS has no parent-death
     *                             signal, so orphaned trees otherwise linger and poison the next
     *                             boot. PROCESS mode only; one lightweight user process.
     * @param int $hookFlags Swoole\Runtime::enableCoroutine() flags (SWOOLE_HOOK_ALL by default).
     * @param array<string,mixed> $rawSettings Extra Swoole settings merged underneath the typed ones.
     */
    public function __construct(
        public readonly int|null $workerNum = null,
        public readonly int $taskWorkerNum = 0,
        public readonly int $maxRequest = 100000,
        public readonly int $maxCoroutine = 100000,
        public readonly int $mode = SWOOLE_PROCESS,
        public readonly bool $daemonize = false,
        public readonly string $pidFile = '',
        public readonly string $logFile = '',
        public readonly int $logLevel = SWOOLE_LOG_INFO,
        public readonly int $backlog = 128,
        public readonly bool $tcpNodelay = true,
        public readonly bool $tcpKeepalive = false,
        public readonly int $bufferOutputSize = 2097152,
        public readonly int $socketBufferSize = 8388608,
        public readonly int $packageMaxLength = 2097152,
        public readonly int $maxWaitTime = 3,
        public readonly bool $orphanWatchdog = true,
        public readonly int $hookFlags = SWOOLE_HOOK_ALL,
        public readonly array $rawSettings = [],
    ) {}

    /**
     * Build the settings array for Swoole's Server::set(). Typed settings
     * override raw settings. Uses swoole_cpu_num() when workerNum is null.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $settings = [
            'worker_num' => $this->workerNum ?? swoole_cpu_num(),
            'task_worker_num' => $this->taskWorkerNum,
            'max_request' => $this->maxRequest,
            'max_coroutine' => $this->maxCoroutine,
            'daemonize' => $this->daemonize,
            'log_level' => $this->logLevel,
            'backlog' => $this->backlog,
            'open_tcp_nodelay' => $this->tcpNodelay,
            'open_tcp_keepalive' => $this->tcpKeepalive,
            'buffer_output_size' => $this->bufferOutputSize,
            'socket_buffer_size' => $this->socketBufferSize,
            'package_max_length' => $this->packageMaxLength,
            'max_wait_time' => $this->maxWaitTime,
            'enable_coroutine' => true,
        ];

        if ($this->pidFile !== '') {
            $settings['pid_file'] = $this->pidFile;
        }
        if ($this->logFile !== '') {
            $settings['log_file'] = $this->logFile;
        }

        return array_merge($this->rawSettings, $settings);
    }
}
