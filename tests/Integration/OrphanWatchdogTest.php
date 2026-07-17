<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Orphan-tree regression: macOS has no parent-death signal, so a SIGKILLed
 * master used to strand the manager + workers forever — lingering processes
 * that poison the next boot (worker "abnormal exit signal=9" churn). The
 * OrphanWatchdog (default-on) must detect the dead master and reap the whole
 * tree by itself.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class OrphanWatchdogTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_watchdog_runner.php';
    }

    #[Test]
    public function sigkilledMasterDoesNotOrphanTheTree(): void
    {
        self::assertIsResource($this->process);
        $masterPid = proc_get_status($this->process)['pid'];

        $tree = $this->descendantsOf($masterPid);
        self::assertNotEmpty($tree, 'expected a manager/worker tree under the master');

        // The scenario the watchdog exists for: the master dies WITHOUT any teardown.
        posix_kill($masterPid, SIGKILL);

        // Watchdog: <=2s probe + SIGTERM manager (+3s grace steps if anything resists).
        $deadline = microtime(true) + 12.0;
        while (microtime(true) < $deadline) {
            // Reap the master like any real parent (shell, systemd) would —
            // a zombie master still answers kill(pid, 0) and blinds the probe.
            if (is_resource($this->process)) {
                proc_get_status($this->process);
            }

            if ($this->allDead($tree)) {
                self::assertTrue(true);

                return;
            }
            usleep(200_000);
        }

        $alive = array_filter($tree, static fn(int $pid): bool => posix_kill($pid, 0));
        foreach ($alive as $pid) {
            posix_kill($pid, SIGKILL); // clean up before failing
        }

        self::fail('orphaned processes survived 12s after the master was SIGKILLed: ' . implode(', ', $alive));
    }

    /**
     * @return list<int> every live descendant of $pid (manager, workers, user processes)
     */
    private function descendantsOf(int $pid): array
    {
        $pids = [];
        $children = (string) shell_exec('pgrep -P ' . $pid . ' 2>/dev/null');

        foreach (array_filter(array_map('intval', explode("\n", trim($children)))) as $child) {
            $pids[] = $child;
            foreach ($this->descendantsOf($child) as $grandchild) {
                $pids[] = $grandchild;
            }
        }

        return $pids;
    }

    /**
     * @param list<int> $pids
     */
    private function allDead(array $pids): bool
    {
        foreach ($pids as $pid) {
            if (posix_kill($pid, 0)) {
                return false;
            }
        }

        return true;
    }
}
