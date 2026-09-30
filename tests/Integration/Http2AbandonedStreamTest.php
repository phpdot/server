<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * A client that abandons an HTTP/2 request before its answer is written leaves the worker serving: the late
 * response, cookie and all, is not written onto the stream Swoole already ended.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class Http2AbandonedStreamTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/http2_runner.php';
    }

    #[Test]
    public function anAbandonedStreamLeavesTheWorkerServing(): void
    {
        $before = $this->h2('/pid', 3000);

        self::assertMatchesRegularExpression('/^\d+$/', $before);

        $this->h2('/late-cookie', 100);
        usleep(1_000_000);

        self::assertSame($before, $this->h2('/pid', 3000));
    }

    /**
     * One request over cleartext HTTP/2, given up after the timeout: the body answered, or an empty string.
     */
    private function h2(string $path, int $timeoutMs): string
    {
        $curl = curl_init('http://127.0.0.1:' . $this->port . $path);

        curl_setopt_array($curl, [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
        ]);

        $body = curl_exec($curl);

        return is_string($body) ? $body : '';
    }
}
