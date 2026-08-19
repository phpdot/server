<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Streaming self-protection: a handler that speaks SSE/WS holds long-lived
 * streams, so the server must derive the protections itself — max_request
 * forced to 0 (recycling a worker mid-stream kills every stream it carries)
 * and the drain window floor raised to 30s — with no config knob to remember
 * or mistype. The override() escape hatch outranking the auto-guard is proven
 * by the whole WorkerRecycleTest suite, whose fixture restores recycling
 * through override() and still observes worker churn.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class StreamingAutoProtectTest extends ServerTestCase
{
    protected function setUp(): void
    {
        putenv('PHPDOT_TEST_MAX_REQUEST');
        putenv('PHPDOT_TEST_PID_FILE');
        parent::setUp();
    }

    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_recycle_runner.php';
    }

    #[Test]
    public function anSseCapableHandlerDisablesRecyclingAndRaisesTheDrainFloor(): void
    {
        $response = $this->rawRequest("GET /settings HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        $settings = json_decode($this->bodyOf($response), true);

        self::assertIsArray($settings, 'the /settings route must expose the effective Swoole settings');
        self::assertSame(0, $settings['max_request'] ?? null, 'streaming handler → recycling must be off');
        self::assertSame(30, $settings['max_wait_time'] ?? null, 'the configured 10s drain must rise to the 30s floor');
    }
}
