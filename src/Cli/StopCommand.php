<?php

declare(strict_types=1);

/**
 * server:stop — SIGTERM the running master via its pid file, wait up to
 * stopTimeout for a drained exit, then escalate to SIGKILL (unless
 * stopTimeout is 0). Idempotent: stopping a stopped server succeeds.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Cli;

use PHPdot\Console\Command;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Process\ProcessController;
use PHPdot\Server\Process\StopResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:stop', description: 'Stop the running server gracefully.')]
final class StopCommand extends Command
{
    /**
     * Create the command over the process controller.
     *
     * @param ProcessController $process Pid-file management and signalling
     */
    public function __construct(
        private readonly ProcessController $process,
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->process->stop();
        } catch (ServerException $e) {
            $this->error($output, $e->getMessage());

            return self::FAILURE;
        }

        match ($result) {
            StopResult::Graceful => $this->success($output, 'Server stopped (drained gracefully).'),
            StopResult::Forced => $this->warning($output, 'Server force-killed after the stop timeout.'),
            StopResult::NotRunning => $this->comment($output, 'Server is not running.'),
        };

        return self::SUCCESS;
    }
}
