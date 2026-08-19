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

use PHPdot\Server\Attribute\ServerListener;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Event\WorkerStarted;
use Psr\Container\ContainerInterface;
use Throwable;

#[ServerListener]
final class Heartbeat
{
    public const string KEY_PREFIX = 'phpdot:cluster:nodes:';

    private const int INTERVAL_MS = 5000;

    private const int TTL_SECONDS = 15;

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

        $redis = new \PHPdot\Redis\RedisConnection($config);

        $nodeId = self::identity($this->master, $this->http);
        $masterPid = $this->masterPid($event);
        $startedAt = time();

        $beat = function () use ($event, $redis, $nodeId, $masterPid, $startedAt): void {
            if ($this->beating) {
                return;
            }

            $this->beating = true;

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
            }
        };

        $beat();
        \Swoole\Timer::tick(self::INTERVAL_MS, $beat);
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
