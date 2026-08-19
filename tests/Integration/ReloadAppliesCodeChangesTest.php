<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * The reload PROMISE: after SIGUSR1, workers must serve the code that is on
 * disk NOW. This once silently broke — the pre-fork listener scan loaded the
 * whole app into the master, so every re-forked worker inherited boot-time
 * classes and edits never applied, while the reload log lines looked
 * perfectly healthy. The fixture autoloads its payload class lazily per
 * worker, exactly like real app code that must stay hot-reloadable.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class ReloadAppliesCodeChangesTest extends ServerTestCase
{
    private string $payloadFile = '';

    protected function setUp(): void
    {
        $this->payloadFile = sys_get_temp_dir() . '/phpdot_hotcode_' . getmypid() . '_' . random_int(1000, 9999) . '.php';
        $this->writePayload('alpha');
        putenv('PHPDOT_TEST_PAYLOAD_FILE=' . $this->payloadFile);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('PHPDOT_TEST_PAYLOAD_FILE');
        @unlink($this->payloadFile);
        parent::tearDown();
    }

    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_hotcode_runner.php';
    }

    #[Test]
    public function workersServeTheCodeOnDiskAfterReload(): void
    {
        self::assertIsResource($this->process);

        $response = $this->rawRequest("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertSame('alpha', $this->bodyOf($response), 'boot-time code must serve before the edit');

        $this->writePayload('beta');
        posix_kill(proc_get_status($this->process)['pid'], SIGUSR1);

        $deadline = microtime(true) + 8.0;
        $body = '';

        while (microtime(true) < $deadline) {
            $body = $this->bodyOf($this->rawRequest("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n"));

            if ($body === 'beta') {
                break;
            }

            usleep(200_000);
        }

        self::assertSame(
            'beta',
            $body,
            'reload must re-fork workers that load CURRENT disk code — stale responses mean '
            . 'something loaded the app pre-fork and froze it into worker memory',
        );

        self::assertTrue(proc_get_status($this->process)['running'], 'the server must survive the reload');
    }

    /**
     * Write the payload class file with the given marker return value.
     *
     * @param string $marker The string HotPayload::marker() must return
     *
     * @return void
     */
    private function writePayload(string $marker): void
    {
        $code = <<<PHP
        <?php

        declare(strict_types=1);

        final class HotPayload
        {
            public function marker(): string
            {
                return '{$marker}';
            }
        }

        PHP;

        file_put_contents($this->payloadFile, $code);
    }
}
