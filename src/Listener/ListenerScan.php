<?php

declare(strict_types=1);

/**
 * ListenerScan — runs #[ServerListener] discovery in a throwaway subprocess
 * and returns pure strings.
 *
 * The scan reflects (and therefore LOADS) every class it inspects. Run in the
 * pre-fork master that loading is poison: forked workers inherit the loaded
 * classes, so every reload re-forks workers that still serve boot-time code —
 * "changes not applied" while the reload log lines look perfectly healthy.
 * This launcher keeps the master clean: the subprocess loads, reflects, dies;
 * the master receives only class names, and workers autoload the real classes
 * post-fork from current disk on first use.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Listener;

use Composer\Autoload\ClassLoader;
use PHPdot\Server\Exception\ServerException;
use ReflectionClass;

final class ListenerScan
{
    /**
     * Scan directories for #[ServerListener] classes without loading anything
     * into this process.
     *
     * Route keys and class names are plain strings on purpose: they cross a
     * process boundary as JSON, and proving them class-strings would require
     * loading them — the exact thing this class exists to avoid.
     *
     * @param list<string> $directories Absolute directories to scan
     *
     * @throws ServerException When the subprocess fails or answers garbage.
     *
     * @return array{routes: array<string, list<string>>, skipped: list<string>}
     */
    public function run(array $directories): array
    {
        $paths = array_values(array_filter($directories, is_dir(...)));

        if ($paths === []) {
            return ['routes' => [], 'skipped' => []];
        }

        $command = array_merge(
            [PHP_BINARY, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'scan-listeners.php', $this->autoloadFile()],
            $paths,
        );

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new ServerException('Listener scan subprocess failed to launch.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new ServerException(sprintf(
                'Listener scan subprocess exited with code %d: %s',
                $exit,
                trim($stderr) !== '' ? trim($stderr) : '(no stderr)',
            ));
        }

        $decoded = json_decode($stdout, true);

        if (!is_array($decoded) || !is_array($decoded['routes'] ?? null) || !is_array($decoded['skipped'] ?? null)) {
            throw new ServerException('Listener scan subprocess returned malformed JSON: ' . substr($stdout, 0, 200));
        }

        $routes = [];

        foreach ($decoded['routes'] as $event => $classes) {
            if (!is_string($event) || !is_array($classes)) {
                continue;
            }

            $routes[$event] = array_values(array_filter($classes, is_string(...)));
        }

        return [
            'routes' => $routes,
            'skipped' => array_values(array_filter($decoded['skipped'], is_string(...))),
        ];
    }

    /**
     * The composer autoload file of the RUNNING application, derived from the
     * registered ClassLoader — the subprocess must resolve classes exactly the
     * way this process does, whatever layout the app uses.
     *
     * @throws ServerException When no composer ClassLoader is registered.
     *
     * @return string
     */
    private function autoloadFile(): string
    {
        if (!class_exists(ClassLoader::class)) {
            throw new ServerException('Listener scan requires the composer autoloader in this process.');
        }

        $loaderFile = new ReflectionClass(ClassLoader::class)->getFileName();

        if (!is_string($loaderFile)) {
            throw new ServerException('Listener scan could not locate the composer autoloader on disk.');
        }

        $autoload = dirname($loaderFile, 2) . DIRECTORY_SEPARATOR . 'autoload.php';

        if (!is_file($autoload)) {
            throw new ServerException("Listener scan could not find the autoload file: {$autoload}");
        }

        return $autoload;
    }
}
