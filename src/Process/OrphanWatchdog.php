<?php

declare(strict_types=1);

/**
 * OrphanWatchdog — reaps the process tree when the master dies without a
 * graceful teardown (SIGKILL, crash, OOM).
 *
 * macOS has no PR_SET_PDEATHSIG, so a killed master silently orphans the
 * manager and workers: they keep running, keep the port half-alive, and a
 * later boot then churns against the leftovers (worker "abnormal exit
 * signal=9" warnings). This runs as a Swoole user process — the same
 * mechanism as FileWatcher — probing the master every $interval seconds;
 * when the master is gone it SIGTERMs the manager (which tears its workers
 * down), then sweeps any survivors, escalating to SIGKILL, and exits.
 *
 * On a NORMAL shutdown the manager terminates this process together with the
 * other user processes — the reap path never runs.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Process;

use Swoole\Coroutine;
use Swoole\Process;
use Swoole\Server as SwooleServer;

final class OrphanWatchdog
{
    /**
     * Create the orphan watchdog.
     *
     * @param float $interval Master liveness probe cadence, seconds.
     * @param float $grace Seconds to let SIGTERM teardown finish before escalating.
     */
    public function __construct(
        private readonly float $interval = 2.0,
        private readonly float $grace = 3.0,
    ) {}

    /**
     * Probe the master until it disappears, then reap the leftovers. Blocks;
     * runs inside a dedicated Swoole user process.
     *
     * @param SwooleServer $master
     *
     * @return void
     */
    public function run(SwooleServer $master): void
    {
        Process::signal(SIGINT, static function (): void {});

        $masterPid = $master->getMasterPid();
        while ($masterPid <= 0) {
            Coroutine::sleep(0.5);
            $masterPid = $master->getMasterPid();
        }

        while (Process::kill($masterPid, 0) !== false) {
            Coroutine::sleep($this->interval);
        }

        $managerPid = $master->getManagerPid();
        if ($managerPid > 0 && Process::kill($managerPid, 0) !== false) {
            Process::kill($managerPid, SIGTERM);
        }

        Coroutine::sleep($this->grace);

        foreach ($this->leftoverPids($master, $managerPid) as $pid) {
            Process::kill($pid, SIGTERM);
        }

        Coroutine::sleep($this->grace);

        foreach ($this->leftoverPids($master, $managerPid) as $pid) {
            Process::kill($pid, SIGKILL);
        }
    }

    /**
     * Pids of tree members still alive: every event/task worker recorded in
     * shared memory, plus the manager.
     *
     * @param \Swoole\Server $master
     * @param int $managerPid
     *
     * @return list<int>
     */
    private function leftoverPids(SwooleServer $master, int $managerPid): array
    {
        $settings = is_array($master->setting) ? $master->setting : [];
        $workerNum = is_int($settings['worker_num'] ?? null) ? $settings['worker_num'] : 0;
        $taskWorkerNum = is_int($settings['task_worker_num'] ?? null) ? $settings['task_worker_num'] : 0;

        $pids = [];

        for ($i = 0; $i < $workerNum + $taskWorkerNum; $i++) {
            $pid = $master->getWorkerPid($i);
            if (is_int($pid) && $pid > 0 && Process::kill($pid, 0) !== false) {
                $pids[] = $pid;
            }
        }

        if ($managerPid > 0 && Process::kill($managerPid, 0) !== false) {
            $pids[] = $managerPid;
        }

        return $pids;
    }
}
