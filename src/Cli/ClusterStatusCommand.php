<?php

declare(strict_types=1);

/**
 * server:cluster:status — every node in the Redis cluster registry, one table,
 * no SSH anywhere (plan D9: visibility only). Nodes appear via the Cluster
 * Heartbeat listener; a dead node expires from the registry by TTL and simply
 * drops off the table. Redis is the optional storage driver: without
 * phpdot/redis installed the command explains itself instead of existing
 * half-broken.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Cli;

use PHPdot\Console\Command;
use PHPdot\Server\Cluster\Heartbeat;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'server:cluster:status', description: 'Show every node in the cluster registry.')]
final class ClusterStatusCommand extends Command
{
    private const int STALE_AFTER_SECONDS = 10;

    /**
     * Create the command over the container — the redis connection resolves
     * lazily so listing commands never requires redis to be installed.
     *
     * @param ContainerInterface $container Resolves the registry connection on execute
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!class_exists(\PHPdot\Redis\RedisConnection::class)) {
            $this->error($output, 'Cluster visibility needs the registry: composer require phpdot/redis.');

            return self::FAILURE;
        }

        try {
            $redis = $this->container->get(\PHPdot\Redis\RedisConnection::class);

            if (!$redis instanceof \PHPdot\Redis\RedisConnection) {
                throw new \RuntimeException('container did not return a RedisConnection');
            }

            if (!$redis->isConnected()) {
                $redis->connect();
            }

            $client = $redis->getClient();
            $keys = $this->registryKeys($client);
        } catch (Throwable $e) {
            $this->error($output, 'Cluster registry unreachable: ' . $e->getMessage());

            return self::FAILURE;
        }

        $rows = [];

        foreach ($keys as $key) {
            $raw = $client->get($key);
            $node = is_string($raw) ? json_decode($raw, true) : null;

            if (is_array($node)) {
                $rows[] = $this->row($node);
            }
        }

        if ($rows === []) {
            $this->comment($output, 'No nodes in the cluster registry (nodes appear once they heartbeat).');

            return self::SUCCESS;
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a[1], $b[1]));

        $table = new Table($output);
        $table->setHeaders(['', 'node', 'pid', 'up', 'workers', 'conns', 'requests']);
        $table->setRows($rows);
        $table->render();

        return self::SUCCESS;
    }

    /**
     * Every node key in the registry, collected via cursor-based SCAN — never
     * KEYS, which walks the whole keyspace in one blocking call and stalls
     * every other client of a shared Redis. Iteration is bounded so a
     * misbehaving server cannot spin the cursor forever.
     *
     * @param \Redis $client The connected client
     *
     * @return list<string>
     */
    private function registryKeys(\Redis $client): array
    {
        $keys = [];
        $iterator = null;

        for ($i = 0; $i < 1000; $i++) {
            $batch = $client->scan($iterator, Heartbeat::KEY_PREFIX . '*', 100);

            if (is_array($batch)) {
                foreach ($batch as $key) {
                    $keys[] = $key;
                }
            }

            if (!is_int($iterator) || $iterator === 0) {
                break;
            }
        }

        return $keys;
    }

    /**
     * One table row from a decoded registry entry.
     *
     * @param array<mixed> $node The decoded node payload
     *
     * @return list<string>
     */
    private function row(array $node): array
    {
        $beatAge = time() - $this->int($node, 'beat_at');
        $fresh = $beatAge <= self::STALE_AFTER_SECONDS;
        $stats = is_array($node['stats'] ?? null) ? $node['stats'] : [];

        $nodeId = is_string($node['node_id'] ?? null) ? $node['node_id'] : '?';
        $suffix = $fresh ? '' : sprintf(' (last seen %ds ago)', $beatAge);

        return [
            $fresh ? '●' : '○',
            $nodeId . $suffix,
            (string) $this->int($node, 'pid'),
            $this->uptime(time() - $this->int($node, 'started_at')),
            (string) $this->int($stats, 'worker_num'),
            (string) $this->int($stats, 'connection_num'),
            (string) $this->int($stats, 'request_count'),
        ];
    }

    /**
     * A numeric field from a decoded payload, 0 when absent or odd.
     *
     * @param array<mixed> $data The decoded payload
     * @param string $key The field key
     *
     * @return int
     */
    private function int(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /**
     * Render seconds as a compact human duration.
     *
     * @param int $seconds Uptime in seconds
     *
     * @return string
     */
    private function uptime(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => sprintf('%dd %dh', $days, $hours),
            $hours > 0 => sprintf('%dh %dm', $hours, $minutes),
            $minutes > 0 => sprintf('%dm', $minutes),
            default => sprintf('%ds', $seconds),
        };
    }
}
