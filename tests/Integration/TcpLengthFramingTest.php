<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Parity harness — LENGTH framing (4-byte 'N' body-length prefix) end-to-end on
 * the wire against a standalone TcpServer. Today only TcpServerConfigTest covers
 * the emitted Swoole keys; this locks the actual codec behaviour a client sees.
 * SR-M3 rewrite target.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class TcpLengthFramingTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/tcp_length_runner.php';
    }

    #[Test]
    public function lengthFramedMessageRoundTrips(): void
    {
        self::assertSame('LEN: hello', $this->sendFramed('hello'));
    }

    #[Test]
    public function handlesAFrameLargerThan126Bytes(): void
    {
        $payload = str_repeat('x', 300);

        self::assertSame('LEN: ' . $payload, $this->sendFramed($payload));
    }

    /**
     * Send one length-prefixed frame and read one length-prefixed reply.
     */
    private function sendFramed(string $message): string
    {
        $fp = fsockopen('127.0.0.1', $this->port, $errno, $errstr, 5.0);
        self::assertIsResource($fp, "tcp connect failed: {$errstr}");
        stream_set_timeout($fp, 5);

        fwrite($fp, pack('N', strlen($message)) . $message);

        $header = $this->readExactly($fp, 4);
        self::assertSame(4, strlen($header), 'incomplete length header');
        $unpacked = unpack('N', $header);
        $length = is_array($unpacked) ? (int) $unpacked[1] : 0;

        $body = $length === 0 ? '' : $this->readExactly($fp, $length);
        fclose($fp);

        return $body;
    }

    /**
     * @param resource $fp
     */
    private function readExactly($fp, int $count): string
    {
        $buffer = '';
        while (strlen($buffer) < $count) {
            $chunk = fread($fp, $count - strlen($buffer));
            if ($chunk === '' || $chunk === false || stream_get_meta_data($fp)['timed_out'] === true) {
                break;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
