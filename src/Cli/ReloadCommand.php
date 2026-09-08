<?php

declare(strict_types=1);

/**
 * server:reload — zero-downtime worker reload of the running server: SIGUSR1
 * via the pid file (SIGUSR2 with --task for task workers only). Workers drain
 * in-flight work and re-fork with fresh post-fork code; pre-fork state (config,
 * routes, definitions) needs server:restart instead. Reloading a stopped
 * server is an error, not a no-op.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Cli;

use PHPdot\Console\Command;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Process\ProcessController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:reload', description: 'Reload the workers with zero downtime.')]
final class ReloadCommand extends Command
{
    /**
     * Create the command over the process controller.
     *
     * @param ProcessController $process Pid-file management and signalling
     * @param ServerConfig $master The mode a reload means something in
     */
    public function __construct(
        private readonly ProcessController $process,
        private readonly ServerConfig $master,
    ) {
        parent::__construct();
    }

    /**
     * Define the task-workers-only option.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption('task', 't', InputOption::VALUE_NONE, 'Reload task workers only');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskOnly = (bool) $input->getOption('task');

        /*
         * BASE mode has no manager recycling connection-owning workers:
         * Swoole's reload touches task workers only, so a full reload here
         * recycles nothing, drops no connections, and picks up no code —
         * while still reporting zero downtime. Refusing is the honest
         * answer; the operator wants server:restart.
         */
        if (!$taskOnly && $this->master->mode === SWOOLE_BASE) {
            $this->error($output, 'server:reload recycles no workers in SWOOLE_BASE mode (Swoole reloads task workers only) — use server:restart to pick up code.');

            return self::FAILURE;
        }

        try {
            if (!$this->process->isRunning()) {
                $this->error($output, 'Server is not running — nothing to reload.');

                return self::FAILURE;
            }

            if (!$this->process->signal($taskOnly ? SIGUSR2 : SIGUSR1)) {
                $this->error($output, 'Server went away before the reload signal could be delivered.');

                return self::FAILURE;
            }
        } catch (ServerException $e) {
            $this->error($output, $e->getMessage());

            return self::FAILURE;
        }

        $this->success($output, $taskOnly ? 'Task workers reloading.' : 'Workers reloading with zero downtime.');

        return self::SUCCESS;
    }
}
