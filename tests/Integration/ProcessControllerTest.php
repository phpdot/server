<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Process\ProcessController;
use PHPdot\Server\Process\StopResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProcessController contract — the engine behind every CLI command, driven
 * against real child processes. The heart of the suite is pid-recycling
 * safety: a pid file whose mtime PRECEDES the process's start time describes
 * a dead server whose pid the OS re-issued, and signalling it would kill an
 * innocent process — isRunning/signal/stop must all refuse.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class ProcessControllerTest extends TestCase
{
    private string $pidFile = '';

    private int $childPid = 0;

    protected function setUp(): void
    {
        $this->pidFile = sys_get_temp_dir() . '/phpdot_pc_' . getmypid() . '_' . random_int(1000, 9999) . '/server.pid';
        mkdir(dirname($this->pidFile), 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->childPid > 0) {
            @posix_kill($this->childPid, SIGKILL);
            $this->childPid = 0;
        }

        @unlink($this->pidFile);
        @rmdir(dirname($this->pidFile));
    }

    #[Test]
    public function anEmptyPidFileConfigurationThrows(): void
    {
        $controller = new ProcessController(new ServerConfig(pidFile: ''));

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('No pidFile configured');

        $controller->pidFile();
    }

    #[Test]
    public function pidParsesDigitsAndRejectsGarbage(): void
    {
        $controller = $this->controller();

        self::assertNull($controller->pid(), 'missing file → null');

        file_put_contents($this->pidFile, "12345\n");
        self::assertSame(12345, $controller->pid());

        file_put_contents($this->pidFile, 'not-a-pid');
        self::assertNull($controller->pid(), 'malformed content → null');
    }

    #[Test]
    public function aLiveMatchingProcessReportsRunning(): void
    {
        $this->spawnSleeper();
        file_put_contents($this->pidFile, (string) $this->childPid);

        self::assertTrue($this->controller()->isRunning(), 'live child + fresh pid file must report running');
    }

    #[Test]
    public function aRecycledPidReportsNotRunningAndIsNeverSignalled(): void
    {
        $this->requirePs();
        $this->spawnSleeper();
        file_put_contents($this->pidFile, (string) $this->childPid);
        touch($this->pidFile, time() - 600);

        $controller = $this->controller();

        self::assertFalse(
            $controller->isRunning(),
            'a process that started AFTER the pid file was written is not the recorded server',
        );
        self::assertFalse($controller->signal(SIGUSR1), 'signal() must refuse a recycled pid');

        self::assertSame(StopResult::NotRunning, $controller->stop(), 'stop() must refuse a recycled pid');
        self::assertTrue(posix_kill($this->childPid, 0), 'the innocent process must still be alive after stop()');
        self::assertFileDoesNotExist($this->pidFile, 'the stale pid file must be cleaned up');
    }

    #[Test]
    public function stopTermsAWillingProcessGracefully(): void
    {
        $this->spawnSleeper();
        file_put_contents($this->pidFile, (string) $this->childPid);

        self::assertSame(StopResult::Graceful, $this->controller()->stop());
        self::assertFalse(posix_kill($this->childPid, 0), 'the child must be gone');
        self::assertFileDoesNotExist($this->pidFile);
    }

    #[Test]
    public function stopEscalatesToKillWhenTermIsIgnored(): void
    {
        $this->spawn('pcntl_async_signals(true); pcntl_signal(SIGTERM, function (): void {}); while (true) { usleep(50000); }');
        file_put_contents($this->pidFile, (string) $this->childPid);

        self::assertSame(StopResult::Forced, $this->controller(stopTimeout: 1)->stop());
        self::assertFalse(posix_kill($this->childPid, 0), 'the stubborn child must be SIGKILLed');
    }

    /**
     * A controller over this test's pid file.
     *
     * @param int $stopTimeout Seconds before stop() escalates to SIGKILL
     *
     * @return ProcessController
     */
    private function controller(int $stopTimeout = 15): ProcessController
    {
        return new ProcessController(new ServerConfig(pidFile: $this->pidFile, stopTimeout: $stopTimeout));
    }

    /**
     * Spawn a child that sleeps until signalled (dies on SIGTERM by default).
     *
     * @return void
     */
    private function spawnSleeper(): void
    {
        $this->spawn('sleep(60);');
    }

    /**
     * Spawn a DETACHED php child running the given code and record its pid.
     * Detached on purpose: were phpunit the parent, a killed child would
     * linger as an unreaped zombie that still answers kill(pid, 0) and
     * blinds every liveness probe under test.
     *
     * @param string $code The php statements to run
     *
     * @return void
     */
    private function spawn(string $code): void
    {
        $raw = shell_exec(sprintf(
            '%s -r %s > /dev/null 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($code),
        ));

        $pid = is_string($raw) ? (int) trim($raw) : 0;
        self::assertGreaterThan(0, $pid, 'failed to spawn the detached child process');

        $this->childPid = $pid;
        usleep(150_000);
        self::assertTrue(posix_kill($pid, 0), 'the child must be alive after spawn');
    }

    /**
     * Skip identity-specific tests where ps is unavailable (the identity check
     * degrades to permissive there by design).
     *
     * @return void
     */
    private function requirePs(): void
    {
        $ps = shell_exec('command -v ps 2>/dev/null');

        if (!is_string($ps) || trim($ps) === '') {
            self::markTestSkipped('ps unavailable — identity check is permissive on this platform');
        }
    }
}
