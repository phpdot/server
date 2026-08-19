<?php

declare(strict_types=1);

/**
 * scan-listeners — the #[ServerListener] discovery subprocess.
 *
 * Scanning reflects classes, and reflecting LOADS them: run in the pre-fork
 * master it would freeze every scanned app class into worker memory, and
 * reload would re-fork workers that still serve boot-time code. So the scan
 * runs here, in a throwaway process that loads whatever it needs and dies;
 * only strings travel back. Invoked by ListenerScan, never by hand.
 *
 * argv[1] = the application's composer autoload file; argv[2..] = directories
 * to scan. Prints JSON: {"routes": {event: [listener, ...]}, "skipped":
 * [class, ...]} — routes keyed by the event class each listener's __invoke
 * accepts, skipped naming classes without a usable __invoke signature.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

use PHPdot\Attribute\Scanner;
use PHPdot\Server\Attribute\ServerListener;
use PHPdot\Server\Listener\ListenerSignature;

$autoload = (string) ($argv[1] ?? '');

if ($autoload === '' || !is_file($autoload)) {
    fwrite(STDERR, "scan-listeners: missing or unreadable autoload file: {$autoload}\n");
    exit(1);
}

require $autoload;

$directories = array_values(array_filter(array_slice($argv, 2), is_dir(...)));

$routes = [];
$skipped = [];

if ($directories !== []) {
    $registry = new Scanner()->scan(
        directories: $directories,
        filter: [ServerListener::class],
        forceRescan: true,
    );

    foreach ($registry->findByAttribute(ServerListener::class) as $result) {
        $event = ListenerSignature::eventTypeOf($result->class);

        if ($event === null) {
            $skipped[] = $result->class;

            continue;
        }

        $routes[$event][] = $result->class;
    }
}

echo json_encode(['routes' => $routes, 'skipped' => $skipped], JSON_THROW_ON_ERROR);
