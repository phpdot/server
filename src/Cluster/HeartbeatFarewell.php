<?php

declare(strict_types=1);

/**
 * HeartbeatFarewell — removes this node from the Redis cluster registry on
 * graceful shutdown, so a stopped node vanishes from server:cluster:status
 * immediately instead of lingering "fresh" until its TTL expires. Crash paths
 * still rely on the TTL — that is what it is for. Same soft Redis coupling as
 * Heartbeat: without phpdot/redis this listener no-ops, and removal is
 * best-effort — a Redis outage must never disturb a shutdown.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Cluster;

use PHPdot\Server\Attribute\ServerListener;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Event\ServerShutdown;
use Psr\Container\ContainerInterface;
use Throwable;

#[ServerListener]
final class HeartbeatFarewell
{
    /**
     * Create the farewell over the container and server identity.
     *
     * @param ContainerInterface $container Resolves the redis connection when installed
     * @param ServerConfig $master Node identity (nodeId) source
     * @param HttpServerConfig $http Port for the derived identity
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ServerConfig $master,
        private readonly HttpServerConfig $http,
    ) {}

    /**
     * Delete this node's registry entry — the key Heartbeat::identity() names,
     * so registration and removal can never disagree.
     *
     * @param ServerShutdown $event The lifecycle event
     *
     * @return void
     */
    public function __invoke(ServerShutdown $event): void
    {
        if (!class_exists(\PHPdot\Redis\RedisConnection::class)) {
            return;
        }

        try {
            $config = $this->container->get(\PHPdot\Redis\Config\RedisConfig::class);

            if (!$config instanceof \PHPdot\Redis\Config\RedisConfig) {
                return;
            }

            $redis = new \PHPdot\Redis\RedisConnection($config);

            if (!$redis->isConnected()) {
                $redis->connect();
            }

            $redis->getClient()->del(Heartbeat::KEY_PREFIX . Heartbeat::identity($this->master, $this->http));
        } catch (Throwable) {
        }
    }
}
