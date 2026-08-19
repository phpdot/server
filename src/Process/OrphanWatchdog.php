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
 * The probe is pid-based only in PROCESS mode. In BASE mode master_pid is a
 * shared-memory slot that workers overwrite with their own pid, so pid
 * probing is disabled there and reparenting is the sole death signal.
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
     * Watch for the tree's death, then reap the leftovers. Blocks; runs inside
     * a dedicated Swoole user process. Two death signals, neither captured
     * across a fork (pre-captured pids go stale the moment daemonize forks —
     * a stale capture once made this watchdog reap its own healthy daemon):
     * reparenting (getppid changes when the parent manager/master dies) and,
     * where $allowPidProbe permits, a liveness probe of the master pid.
     *
     * @param SwooleServer $master
     * @param int $masterPid Explicit master pid override; 0 resolves it per $allowPidProbe.
     * @param bool $allowPidProbe Permit pid-based probing — PROCESS mode only. Swoole keeps
     *                            master_pid in shared memory and BASE workers write their own
     *                            pid into that slot, so the property AND getMasterPid() both
     *                            resolve to a WORKER pid inside a BASE user process. Workers
     *                            die by design on every reload, and probing one made this
     *                            watchdog reap the whole healthy tree two seconds after each
     *                            USR1. BASE relies on the reparenting guard alone.
     *
     * @return void
     */
    public function run(SwooleServer $master, int $masterPid = 0, bool $allowPidProbe = true): void
    {
        Process::signal(SIGINT, static function (): void {});

        $guardPpid = function_exists('posix_getppid') ? posix_getppid() : 0;

        if ($masterPid <= 0 && $allowPidProbe) {
            $masterPid = $this->propertyPid($master);
        }

        while (true) {
            Coroutine::sleep($this->interval);

            if ($guardPpid > 0 && posix_getppid() !== $guardPpid) {
                break;
            }

            if ($masterPid <= 0 && $allowPidProbe) {
                $fromProperty = $this->propertyPid($master);
                $masterPid = $fromProperty > 0 ? $fromProperty : $master->getMasterPid();
            }

            if ($masterPid > 0 && Process::kill($masterPid, 0) === false) {
                break;
            }
        }

        $managerPid = $guardPpid;
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
     * The master pid from Swoole's post-start master_pid property, 0 when unset.
     *
     * @param SwooleServer $master
     *
     * @return int
     */
    private function propertyPid(SwooleServer $master): int
    {
        return $master->master_pid;
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
