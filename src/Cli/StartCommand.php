<?php

declare(strict_types=1);

/**
 * server:start — assemble the configured server and block on its event loop.
 * Checks ports BEFORE announcing, so the banner only ever describes a server
 * that is actually about to listen. With --watch the command becomes a small
 * supervisor that launches itself as a plain child (env-flagged) and does the
 * file-watching ITSELF: app-code edits SIGUSR1 the child (worker reload),
 * restart-classified pre-fork edits SIGTERM it (graceful drain) and relaunch
 * fresh. The server runs no watcher process, so nothing can pin its shutdown;
 * signals only ever target the pid proc_open returned. Owns the event loop, so
 * it opts out of the console's coroutine runner.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Cli;

use PHPdot\Console\Command;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Config\TcpServerConfig;
use PHPdot\Server\Config\WatchConfig;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Process\ProcessController;
use PHPdot\Server\ServerFactory;
use PHPdot\Server\Watch\FileWatcher;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:start', description: 'Start the server.', aliases: ['serve'])]
final class StartCommand extends Command
{
    protected bool $coroutine = false;

    private const string CHILD_ENV = 'PHPDOT_WATCH_CHILD';

    /**
     * Create the command over the factory, handler, and process controller.
     *
     * @param ServerFactory $factory Assembles the configured server
     * @param RequestHandlerInterface $handler The application's request handler
     * @param ProcessController $process Pid-file management
     * @param ServerConfig $master The master configuration
     * @param HttpServerConfig $http HTTP transport configuration (banner)
     * @param TcpServerConfig $tcp TCP transport configuration (banner)
     * @param WatchConfig $watch Development file-watching configuration
     */
    public function __construct(
        private readonly ServerFactory $factory,
        private readonly RequestHandlerInterface $handler,
        private readonly ProcessController $process,
        private readonly ServerConfig $master,
        private readonly HttpServerConfig $http,
        private readonly TcpServerConfig $tcp,
        private readonly WatchConfig $watch,
    ) {
        parent::__construct();
    }

    /**
     * Define the daemon and watch options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run in the background (daemonize)');
        $this->addOption('watch', 'w', InputOption::VALUE_NONE, 'Reload on file changes (development)');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daemon = (bool) $input->getOption('daemon');
        $watching = (bool) $input->getOption('watch');

        if ($daemon && $watching) {
            $this->error($output, '--watch is a foreground development mode and cannot combine with --daemon.');

            return self::FAILURE;
        }

        if ($watching && getenv(self::CHILD_ENV) !== '1') {
            return $this->supervise($output);
        }

        return $this->serve($output, $daemon, $watching);
    }

    /**
     * Boot and block on the event loop; the terminal branch for normal starts
     * and for the supervised watch child.
     *
     * @param OutputInterface $output The command output
     * @param bool $daemon Whether to daemonize
     * @param bool $watching Whether the watch child should attach the file-watcher
     *
     * @return int
     */
    private function serve(OutputInterface $output, bool $daemon, bool $watching): int
    {
        try {
            $server = $this->factory->create();

            if ($daemon) {
                $server->override(['daemonize' => true]);
            }

            $server->ensurePortsAvailable();

            if ($this->master->pidFile !== '') {
                $this->process->preparePidDir();
            } elseif ($daemon) {
                $this->warning($output, 'No pidFile configured — server:stop/status will not find this daemon.');
            }

            $this->announce($output, $watching);
            $this->writePreloadManifest();

            $server->serve($this->handler);
        } catch (ServerException $e) {
            $this->error($output, $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The watch supervisor: launch the child, then DO THE WATCHING HERE — the
     * supervisor is plain PHP that owns the child's exact pid, so file changes
     * translate into authoritative signals: app-code edits SIGUSR1 the child
     * (worker reload), restart-classified edits SIGTERM it (graceful drain) and
     * relaunch fresh. No watcher process inside the server, so nothing can pin
     * its shutdown; no marker files, no guessed pids anywhere.
     *
     * The supervisor shields itself from terminal signals with NO-OP HANDLERS,
     * never SIG_IGN: ignored dispositions survive exec (POSIX), so SIG_IGN here
     * would spawn children whose workers are deaf to the manager's SIGTERM —
     * every reload/shutdown would stall to the max_wait_time force-kill.
     *
     * @param OutputInterface $output The command output
     *
     * @return int The final child exit code.
     */
    private function supervise(OutputInterface $output): int
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, static function (): void {});
            pcntl_signal(SIGTERM, static function (): void {});
        }

        $rawArgv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
        $argv = [];

        foreach ($rawArgv as $arg) {
            if (is_scalar($arg)) {
                $argv[] = (string) $arg;
            }
        }

        $fileWatcher = new FileWatcher($this->watch->toWatcher());

        while (true) {
            $this->waitForFreePort(10.0);

            $child = proc_open(
                array_merge([PHP_BINARY], $argv),
                [0 => STDIN, 1 => STDOUT, 2 => STDERR],
                $pipes,
                null,
                array_merge(getenv(), [self::CHILD_ENV => '1']),
            );

            if (!is_resource($child)) {
                $this->error($output, '[watch] failed to launch the server child process.');

                return self::FAILURE;
            }

            $code = $this->superviseChild($output, $child, $fileWatcher);

            if ($code !== null) {
                return $code;
            }

            $this->comment($output, '[watch] restarting to pick up pre-fork changes…');
        }
    }

    /**
     * Watch files while the child runs. Reload-classified changes SIGUSR1 the
     * child in place; restart-classified changes SIGTERM it and return null
     * (relaunch). When the child exits on its own, return its exit code —
     * proc_get_status() only reports it on the first call after death, so
     * whichever observation point first sees the transition (the loop top, or
     * the pre-signal recheck that keeps SIGUSR1 off a pid the OS may have
     * recycled during the debounce window) returns immediately with it.
     *
     * @param OutputInterface $output The command output
     * @param resource $child The child process handle
     * @param FileWatcher $fileWatcher The change scanner/classifier
     *
     * @return int|null
     */
    private function superviseChild(OutputInterface $output, $child, FileWatcher $fileWatcher): int|null
    {
        $previous = $fileWatcher->snapshot();
        $interval = (int) (max(0.2, $this->watch->interval) * 1000000);
        $debounce = (int) (max(0.05, $this->watch->debounce) * 1000000);

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $status = proc_get_status($child);

            if ($status['running'] === false) {
                proc_close($child);

                return $status['exitcode'];
            }

            usleep($interval);
            $plan = $fileWatcher->plan($previous);

            if ($plan['reload'] === [] && $plan['restart'] === []) {
                $previous = $plan['snapshot'];

                continue;
            }

            usleep($debounce);
            $plan = $fileWatcher->plan($previous);
            $previous = $plan['snapshot'];

            $pinned = $this->preloadedAmong($plan['reload']);
            $restart = array_merge($plan['restart'], $pinned);
            $reload = array_values(array_diff($plan['reload'], $pinned));

            if ($restart !== []) {
                $this->comment($output, '[watch] restart needed: ' . $this->describe($restart));
                proc_terminate($child, SIGTERM);
                $this->reapChild($child);

                return null;
            }

            $status = proc_get_status($child);

            if ($status['running'] === false) {
                proc_close($child);

                return $status['exitcode'];
            }

            $this->comment($output, '[watch] reloading all workers — changed: ' . $this->describe($reload));
            \Swoole\Process::kill($status['pid'], SIGUSR1);
        }
    }

    /**
     * Record every file this process loaded BEFORE the fork, beside the pid
     * file. The watch supervisor reads it to classify changes: a pre-fork file
     * is baked into worker memory by fork inheritance, so editing it needs a
     * full restart — a reload would re-fork workers that still run the
     * boot-time copy ("changes not applied"). Paths are realpath-normalized
     * because the app may reach a file through symlinks (path repositories)
     * while the watcher walks the real tree.
     *
     * @return void
     */
    private function writePreloadManifest(): void
    {
        if ($this->master->pidFile === '') {
            return;
        }

        $files = [];

        foreach (get_included_files() as $file) {
            $real = realpath($file);
            $files[] = $real !== false ? $real : $file;
        }

        @file_put_contents($this->preloadManifestPath(), implode("\n", $files) . "\n");
    }

    /**
     * The changed paths the child loaded before forking workers, per the
     * manifest it wrote at boot. Empty when no manifest exists — classification
     * then falls back to the configured restart paths alone.
     *
     * @param list<string> $changed Changed absolute paths
     *
     * @return list<string>
     */
    private function preloadedAmong(array $changed): array
    {
        if ($changed === [] || $this->master->pidFile === '') {
            return [];
        }

        $manifest = @file_get_contents($this->preloadManifestPath());

        if ($manifest === false || $manifest === '') {
            return [];
        }

        $preloaded = array_flip(array_filter(
            explode("\n", $manifest),
            static fn(string $line): bool => $line !== '',
        ));

        return array_values(array_filter(
            $changed,
            static function (string $path) use ($preloaded): bool {
                $real = realpath($path);

                return isset($preloaded[$real !== false ? $real : $path]);
            },
        ));
    }

    /**
     * Where the pre-fork manifest lives: beside the pid file.
     *
     * @return string
     */
    private function preloadManifestPath(): string
    {
        return dirname($this->master->pidFile) . DIRECTORY_SEPARATOR . 'preloaded.list';
    }

    /**
     * Wait out a terminated child so its tree is fully gone before relaunch.
     *
     * @param resource $child The child process handle
     *
     * @return void
     */
    private function reapChild($child): void
    {
        while (proc_get_status($child)['running'] === true) {
            usleep(100000);
        }

        proc_close($child);
    }

    /**
     * Compact, cwd-relative listing of changed paths for watch notices.
     *
     * @param list<string> $paths Changed absolute paths
     *
     * @return string
     */
    private function describe(array $paths): string
    {
        $cwd = getcwd();

        $relative = array_map(
            static function (string $path) use ($cwd): string {
                if ($cwd !== false && str_starts_with($path, $cwd)) {
                    return ltrim(substr($path, strlen($cwd)), DIRECTORY_SEPARATOR);
                }

                return $path;
            },
            array_slice($paths, 0, 3),
        );

        $suffix = count($paths) > 3 ? sprintf(' (+%d more)', count($paths) - 3) : '';

        return implode(', ', $relative) . $suffix;
    }

    /**
     * Wait until the HTTP port is free before (re)launching a child — worker
     * sockets can outlive the child's master by a moment. Bounded; a still-held
     * port after the window is left for the child to report.
     *
     * @param float $seconds Patience window
     *
     * @return void
     */
    private function waitForFreePort(float $seconds): void
    {
        if (!$this->http->enabled) {
            return;
        }

        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $probe = @stream_socket_server(
                sprintf('tcp://%s:%d', $this->http->host === '0.0.0.0' ? '0.0.0.0' : $this->http->host, $this->http->port),
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            );

            if ($probe !== false) {
                fclose($probe);

                return;
            }

            usleep(100000);
        }
    }

    /**
     * Print one line per enabled transport, after the port check has passed.
     *
     * @param OutputInterface $output The command output
     * @param bool $watching Whether watch mode is active
     *
     * @return void
     */
    private function announce(OutputInterface $output, bool $watching): void
    {
        if ($this->http->enabled) {
            $this->info($output, sprintf('Listening on http://%s:%d', $this->http->host, $this->http->port));
        }

        if ($this->tcp->enabled) {
            $this->info($output, sprintf('Listening on tcp://%s:%d', $this->tcp->host, $this->tcp->port));
        }

        if ($watching) {
            $this->comment($output, '[watch] watching for changes — edits reload, pre-fork changes restart');
        }
    }

}
