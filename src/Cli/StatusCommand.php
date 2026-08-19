<?php

declare(strict_types=1);

/**
 * server:status — truthful liveness from the pid file: running (pid, uptime)
 * exits 0; not running exits 3 (LSB convention), so scripts can branch on it.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Cli;

use PHPdot\Console\Command;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Process\ControlClient;
use PHPdot\Server\Process\ProcessController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:status', description: 'Show whether the server is running.')]
final class StatusCommand extends Command
{
    private const int EXIT_NOT_RUNNING = 3;

    /**
     * Create the command over the process controller and master config.
     *
     * @param ProcessController $process Pid-file management
     * @param ServerConfig $master Locates the control socket
     */
    public function __construct(
        private readonly ProcessController $process,
        private readonly ServerConfig $master,
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if (!$this->process->isRunning()) {
                $this->comment($output, 'Server is not running.');

                return self::EXIT_NOT_RUNNING;
            }

            $this->success($output, sprintf(
                'Server is running — pid %d, up %s.',
                $this->process->pid() ?? 0,
                $this->formatUptime($this->process->uptime() ?? 0),
            ));

            $this->describeLiveStats($output);
        } catch (ServerException $e) {
            $this->error($output, $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Enrich the status line from the control socket. A running pid that
     * cannot answer within a second is itself a finding — reported as
     * unresponsive, never silently omitted.
     *
     * @param OutputInterface $output The command output
     *
     * @return void
     */
    private function describeLiveStats(OutputInterface $output): void
    {
        $socket = $this->master->controlSocket();

        if ($socket === '') {
            return;
        }

        $stats = ControlClient::stats($socket, 1.0);

        if (!is_array($stats)) {
            $this->warning($output, 'Process is alive but the control socket did not answer within 1s — workers may be wedged.');

            return;
        }

        $this->comment($output, sprintf(
            'workers %d (%d idle) · connections %d · requests %d',
            $this->metric($stats, 'worker_num'),
            $this->metric($stats, 'idle_worker_num'),
            $this->metric($stats, 'connection_num'),
            $this->metric($stats, 'request_count'),
        ));
    }

    /**
     * A numeric metric from the decoded stats payload, 0 when absent or odd.
     *
     * @param array<mixed> $stats The decoded stats payload
     * @param string $key The metric key
     *
     * @return int
     */
    private function metric(array $stats, string $key): int
    {
        $value = $stats[$key] ?? 0;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /**
     * Render seconds as a compact human duration.
     *
     * @param int $seconds Uptime in seconds
     *
     * @return string
     */
    private function formatUptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return match (true) {
            $days > 0 => sprintf('%dd %dh %dm', $days, $hours, $minutes),
            $hours > 0 => sprintf('%dh %dm', $hours, $minutes),
            $minutes > 0 => sprintf('%dm %ds', $minutes, $secs),
            default => sprintf('%ds', $secs),
        };
    }
}
