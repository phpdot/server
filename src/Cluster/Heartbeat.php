<?php

declare(strict_types=1);

/**
 * Heartbeat — publishes this node into the Redis cluster registry so
 * server:cluster:status can see every node with no SSH anywhere (plan D9:
 * visibility only). Cluster visibility is a SERVER feature; Redis is only its
 * storage driver, so the coupling is soft: when phpdot/redis is not installed
 * this listener no-ops and the redis package never learns the server exists.
 *
 * Worker 0 refreshes the node entry every 5s with a 15s TTL: a dead or
 * partitioned node simply expires from the registry. Registry writes are
 * best-effort — a Redis outage must never take a healthy node down with it.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Cluster;

use PHPdot\Container\Attribute\Singleton;
use PHPdot\Server\Attribute\ServerListener;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Event\WorkerStarted;
use Psr\Container\ContainerInterface;
use Throwable;

#[ServerListener]
#[Singleton]
final class Heartbeat
{
    public const string KEY_PREFIX = 'phpdot:cluster:nodes:';

    private const int INTERVAL_MS = 5000;

    private const int TTL_SECONDS = 15;

    /**
     * Wall-clock bound on one beat: the watchdog cancels a beat still running
     * after this, because a socket park under the coroutine hooks outlives
     * every configured timeout.
     */
    private const int BEAT_LIMIT_MS = 5000;

    /**
     * True while a beat is in flight.
     *
     * State rather than a captured local because the two writes below are
     * separated by a SUSPENSION POINT, not by ordinary control flow: the
     * socket work between them yields, and the timer may enter the closure
     * again before the `finally` runs. Reading it as a plain local reads as
     * dead code — set false, checked, never true in between — which is only
     * true of a runtime without coroutines.
     */
    private bool $beating = false;

    /**
     * The in-flight beat's coroutine, so {@see halt()} can cancel it — the
     * park that actually holds the drain.
     */
    private null|int $beatCid = null;

    /**
     * The tick's id, kept so {@see HeartbeatHalt} can cancel it on worker
     * exit. A live timer holds the worker's event loop open, so without this
     * the drain window is always spent in full — the master waits on a
     * heartbeat nobody can stop.
     */
    private null|int $tickId = null;

    /**
     * Create the heartbeat over the container and server identity.
     *
     * @param ContainerInterface $container Resolves the redis connection when installed
     * @param ServerConfig $master Node identity (nodeId) source
     * @param HttpServerConfig $http Port for the derived identity and the row
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ServerConfig $master,
        private readonly HttpServerConfig $http,
    ) {}

    /**
     * Start the registry heartbeat in worker 0 (re-fires after every reload).
     * A no-op when phpdot/redis is not installed. The connection is DEDICATED
     * — constructed here, never borrowed: a timer holds its connection
     * forever, and borrowing one from a pool would hand it back to request
     * coroutines while the timer still writes on it.
     *
     * Each tick runs in its own coroutine, so a beat that outlives the
     * interval — a frozen socket after the host sleeps, a stalled network —
     * would meet the next beat on the same connection, and Swoole forbids two
     * coroutines reading one socket. A tick therefore yields to the one still
     * in flight rather than joining it.
     *
     * @param WorkerStarted $event The lifecycle event
     *
     * @return void
     */
    public function __invoke(WorkerStarted $event): void
    {
        if ($event->workerId !== 0 || !class_exists(\PHPdot\Redis\RedisConnection::class)) {
            return;
        }

        $config = $this->container->get(\PHPdot\Redis\Config\RedisConfig::class);

        if (!$config instanceof \PHPdot\Redis\Config\RedisConfig) {
            return;
        }

        /*
         * A BOUNDED copy: the host's read timeout is its business, but this
         * connection belongs to a timer, and a beat parked on a dead socket
         * must return on its own — the tick's cancellation cannot recall a
         * beat already in flight, so an unbounded read here is an unbounded
         * worker drain no matter who cancels what.
         */
        $bounded = new \PHPdot\Redis\Config\RedisConfig(
            host: $config->host,
            port: $config->port,
            path: $config->path,
            password: $config->password,
            username: $config->username,
            database: $config->database,
            timeout: $config->timeout === 0.0 ? 2.0 : $config->timeout,
            retryInterval: $config->retryInterval,
            readTimeout: $config->readTimeout === 0.0 ? 2.0 : $config->readTimeout,
            tls: $config->tls,
            ssl: $config->ssl,
            maxRetries: $config->maxRetries,
            persistent: $config->persistent,
            context: $config->context,
        );

        $redis = new \PHPdot\Redis\RedisConnection($bounded);

        $nodeId = self::identity($this->master, $this->http);
        $masterPid = $this->masterPid($event);
        $startedAt = time();

        $beat = function () use ($event, $redis, $nodeId, $masterPid, $startedAt): void {
            if ($this->beating) {
                return;
            }

            $this->beating = true;
            $this->beatCid = \Swoole\Coroutine::getCid();

            try {
                $stats = $event->server->getMaster()->stats();

                if (!$redis->isConnected()) {
                    $redis->connect();
                }

                $redis->getClient()->setex(
                    self::KEY_PREFIX . $nodeId,
                    self::TTL_SECONDS,
                    (string) json_encode([
                        'node_id' => $nodeId,
                        'host' => (string) gethostname(),
                        'port' => $this->http->port,
                        'pid' => $masterPid,
                        'started_at' => $startedAt,
                        'beat_at' => time(),
                        'stats' => $stats,
                    ]),
                );
            } catch (Throwable) {
            } finally {
                $this->beating = false;
                $this->beatCid = null;
            }
        };

        /*
         * Each beat in its OWN coroutine, so a beat parked on an unanswering
         * socket can be CANCELLED. Socket timeouts do not bind under the
         * coroutine hooks — a connect or setex parked on a dead peer parks for
         * the process's life — and the drain waits on it. The watchdog bounds
         * every beat; halt() bounds the last one.
         */
        $arm = static function () use ($beat): void {
            $cid = \Swoole\Coroutine::create($beat);

            if ($cid === false) {
                return;
            }

            \Swoole\Timer::after(self::BEAT_LIMIT_MS, static function () use ($cid): void {
                if (\Swoole\Coroutine::exists($cid)) {
                    \Swoole\Coroutine::cancel($cid);
                }
            });
        };

        /*
         * The first beat runs inline as well as armed: the registry entry must
         * exist before the first tick, so cluster:status sees a node the
         * moment the server answers.
         */
        $beat();
        $arm();

        $tickId = \Swoole\Timer::tick(self::INTERVAL_MS, $arm);

        /*
         * A false id means the timer never armed (the loop is gone) — nothing
         * to cancel, nothing to keep.
         */
        $this->tickId = $tickId === false ? null : $tickId;
    }

    /**
     * Cancel the tick AND the in-flight beat — the worker-exit call.
     *
     * Idempotent and safe in every worker: only worker 0 ever holds a tick.
     * The cancel matters as much as the clear: a beat parked on an
     * unanswering socket is what actually holds the drain open, and no timer
     * cancellation recalls it.
     *
     * @return void
     */
    public function halt(): void
    {
        if ($this->tickId !== null) {
            \Swoole\Timer::clear($this->tickId);

            $this->tickId = null;
        }

        if ($this->beatCid !== null && \Swoole\Coroutine::exists($this->beatCid)) {
            \Swoole\Coroutine::cancel($this->beatCid);
        }
    }

    /**
     * The pid an operator can actually signal — the master. Never the
     * shared-memory master_pid slot in BASE mode: workers overwrite it with
     * their own pid (the L8 lesson), and a worker pid changes on every
     * reload. In BASE, worker 0's PARENT is the master; in PROCESS mode the
     * shared slot is authoritative. Falls back to this worker's pid when
     * neither source answers.
     *
     * @param WorkerStarted $event The lifecycle event
     *
     * @return int
     */
    private function masterPid(WorkerStarted $event): int
    {
        if ($this->master->mode === SWOOLE_BASE) {
            return function_exists('posix_getppid') ? posix_getppid() : (int) getmypid();
        }

        $pid = $event->server->getMaster()->master_pid;

        return $pid > 0 ? $pid : (int) getmypid();
    }

    /**
     * The node's cluster identity: configured nodeId, or hostname:port.
     * Shared with HeartbeatFarewell so registration and removal can never
     * disagree on the key. The fallback is deliberately STABLE across
     * restarts — a rebooted node replaces its own entry instead of leaking a
     * stale sibling — which means two machines sharing a hostname and port
     * coalesce into one row: such fleets must set nodeId explicitly.
     *
     * @param ServerConfig $master Node identity source
     * @param HttpServerConfig $http Port for the derived identity
     *
     * @return string
     */
    public static function identity(ServerConfig $master, HttpServerConfig $http): string
    {
        if ($master->nodeId !== '') {
            return $master->nodeId;
        }

        return (string) gethostname() . ':' . $http->port;
    }
}
