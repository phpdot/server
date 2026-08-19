<?php

declare(strict_types=1);

/**
 * server:restart — graceful stop, then delegate to server:start in the same
 * process (which then blocks on the event loop, or daemonizes with -d).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Cli;

use PHPdot\Console\Command;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Process\ProcessController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:restart', description: 'Stop the server, then start it again.')]
final class RestartCommand extends Command
{
    protected bool $coroutine = false;

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
     * Define the daemon option, forwarded to server:start.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run in the background (daemonize)');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->process->stop();
        } catch (ServerException $e) {
            $this->error($output, $e->getMessage());

            return self::FAILURE;
        }

        $application = $this->getApplication();

        if ($application === null) {
            $this->error($output, 'No console application available to launch server:start.');

            return self::FAILURE;
        }

        $arguments = ['command' => 'server:start'];

        if ((bool) $input->getOption('daemon')) {
            $arguments['--daemon'] = true;
        }

        return $application->find('server:start')->run(new ArrayInput($arguments), $output);
    }
}
