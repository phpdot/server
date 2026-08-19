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
use PHPdot\Server\Exception\ServerException;

#[Config('server.master')]
final class ServerConfig
{
    /**
     * Create the server configuration.
     *
     * @param int|null $workerNum Worker count (null = swoole_cpu_num()).
     * @param int $taskWorkerNum Task worker count.
     * @param int $maxRequest Restart a worker after N requests (0 disables). When the handler
     *                        speaks SSE/WS or a raw-socket (TCP) transport is attached the
     *                        server forces 0 itself — recycling a worker kills every stream
     *                        and raw connection it carries — and only an explicit override()
     *                        restores recycling on such a server.
     * @param int $maxCoroutine Max coroutines per worker.
     * @param int $mode SWOOLE_BASE (default) or SWOOLE_PROCESS. BASE is the default because
     *                  workers own their connections, so a graceful stop DRAINS in-flight
     *                  requests; in PROCESS mode the master's reactor owns every socket and
     *                  a master SIGTERM drops in-flight requests instantly (verified on
     *                  Swoole 6.2 — raw server, max_wait_time honored either way).
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
     *                         shutdown legitimately has to wait on slow in-flight work. When
     *                         the handler speaks SSE/WS or a raw-socket transport is attached
     *                         the server raises the floor to 30 itself so drains cover slow
     *                         stream/connection teardown.
     * @param int $stopTimeout Seconds server:stop waits after SIGTERM before escalating to
     *                         SIGKILL (0 = never escalate). Consumed by the CLI, not Swoole;
     *                         keep it above maxWaitTime so graceful drain always gets its
     *                         full window.
     * @param string $nodeId Cluster identity for this node (registry key + cluster:status row);
     *                       empty derives hostname:httpPort at heartbeat time.
     * @param bool $orphanWatchdog Reap the manager/workers if the master dies without a
     *                             graceful teardown (SIGKILL, crash) — macOS has no parent-death
     *                             signal, so orphaned trees otherwise linger, hold the port
     *                             (SO_REUSEPORT workers in BASE mode), and serve stale code.
     *                             Both modes; one lightweight user process.
     * @param int $hookFlags Swoole\Runtime::enableCoroutine() flags (SWOOLE_HOOK_ALL by default).
     * @param array<string,mixed> $rawSettings Extra Swoole settings merged underneath the typed ones.
     */
    public function __construct(
        public readonly int|null $workerNum = null,
        public readonly int $taskWorkerNum = 0,
        public readonly int $maxRequest = 100000,
        public readonly int $maxCoroutine = 100000,
        public readonly int $mode = SWOOLE_BASE,
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
        public readonly int $stopTimeout = 15,
        public readonly string $nodeId = '',
        public readonly bool $orphanWatchdog = true,
        public readonly int $hookFlags = SWOOLE_HOOK_ALL,
        public readonly array $rawSettings = [],
    ) {
        if ($this->workerNum !== null && $this->workerNum < 1) {
            throw new ServerException('workerNum must be >= 1 (or null for swoole_cpu_num()).');
        }

        if ($this->taskWorkerNum < 0 || $this->maxRequest < 0 || $this->stopTimeout < 0) {
            throw new ServerException('taskWorkerNum, maxRequest, and stopTimeout must not be negative.');
        }

        if ($this->maxWaitTime < 1) {
            throw new ServerException('maxWaitTime must be >= 1 second — Swoole treats it as a drain window.');
        }

        if ($this->mode !== SWOOLE_BASE && $this->mode !== SWOOLE_PROCESS) {
            throw new ServerException('mode must be SWOOLE_BASE or SWOOLE_PROCESS.');
        }
    }

    /**
     * The control-socket path beside the pid file, '' when no pidFile is
     * configured — server.pid gets server.sock, so two instances sharing a
     * pid directory can never collide on the socket. Server attaches the
     * ControlSocket listener here; the CLI (server:status) queries live
     * stats through it — CLI territory by design, never an HTTP endpoint.
     * Unix socket paths cap at ~104 bytes; keep pidFile reasonably shallow.
     *
     * @return string
     */
    public function controlSocket(): string
    {
        if ($this->pidFile === '') {
            return '';
        }

        return dirname($this->pidFile) . DIRECTORY_SEPARATOR
            . pathinfo($this->pidFile, PATHINFO_FILENAME) . '.sock';
    }

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
