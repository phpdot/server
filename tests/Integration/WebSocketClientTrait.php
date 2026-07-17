<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

/**
 * Minimal RFC 6455 client over a raw socket for integration tests: HTTP upgrade
 * handshake, masked client frames out, unmasked server frames back. Every read
 * is bounded by a stream timeout so a test can never hang.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
trait WebSocketClientTrait
{
    /**
     * Open a connection and complete the RFC 6455 upgrade handshake.
     *
     * @return resource
     */
    private function openWebSocket(int $port, string $path = '/ws')
    {
        // Accepting the TCP port (what setUp waits for) does not guarantee the
        // WebSocket upgrade path is ready: in a brief post-boot window the reactor
        // accepts the connection but closes it before the handshake, leaving an
        // empty response. Retry the whole handshake — the assertion below is
        // unchanged, it must still see a real 101 — until the upgrade completes.
        $lastHandshake = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $socket = fsockopen('127.0.0.1', $port, $errno, $errstr, 5.0);
            self::assertIsResource($socket, "connect failed: {$errstr}");
            stream_set_timeout($socket, 5);

            $key = base64_encode(random_bytes(16));
            fwrite(
                $socket,
                "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n",
            );

            $handshake = $this->readHandshake($socket);
            if (str_contains($handshake, '101')) {
                $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                self::assertStringContainsStringIgnoringCase("Sec-WebSocket-Accept: {$accept}", $handshake);

                return $socket;
            }

            $lastHandshake = $handshake;
            fclose($socket);
            usleep(100_000);
        }

        self::fail('WebSocket upgrade never returned 101 after retries; last response: ' . var_export($lastHandshake, true));
    }

    /**
     * @param resource $socket
     */
    private function readHandshake($socket): string
    {
        $buffer = '';
        while (!str_contains($buffer, "\r\n\r\n")) {
            $byte = fread($socket, 1);
            if ($byte === '' || $byte === false || stream_get_meta_data($socket)['timed_out'] === true) {
                break;
            }
            $buffer .= $byte;
        }

        return $buffer;
    }

    private function encodeMaskedTextFrame(string $payload): string
    {
        return $this->encodeMaskedFrame(0x81, $payload); // FIN + text
    }

    private function encodeMaskedBinaryFrame(string $payload): string
    {
        return $this->encodeMaskedFrame(0x82, $payload); // FIN + binary
    }

    private function encodeMaskedFrame(int $firstByte, string $payload): string
    {
        $length = strlen($payload);
        $mask = random_bytes(4);

        $frame = chr($firstByte);
        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } else {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        }
        $frame .= $mask;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        return $frame;
    }

    /**
     * @param resource $socket
     */
    private function readTextFrame($socket): string
    {
        return $this->readFrame($socket)[1];
    }

    /**
     * Read one server frame (unmasked). Returns [firstByte, payload].
     *
     * @param resource $socket
     * @return array{0: int, 1: string}
     */
    private function readFrame($socket): array
    {
        $header = $this->readBytes($socket, 2);
        self::assertSame(2, strlen($header), 'incomplete WebSocket frame header');

        $firstByte = ord($header[0]);
        $length = ord($header[1]) & 0x7F; // server frames are not masked
        if ($length === 126) {
            $extended = unpack('n', $this->readBytes($socket, 2));
            $length = is_array($extended) ? (int) $extended[1] : 0;
        }

        $payload = $length === 0 ? '' : $this->readBytes($socket, $length);

        return [$firstByte, $payload];
    }

    /**
     * @param resource $socket
     */
    private function readBytes($socket, int $count): string
    {
        $buffer = '';
        while (strlen($buffer) < $count) {
            $chunk = fread($socket, $count - strlen($buffer));
            if ($chunk === '' || $chunk === false || stream_get_meta_data($socket)['timed_out'] === true) {
                break;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
