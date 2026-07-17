<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end WebSocket test against a real Server process (Fixtures/server_ws_runner.php).
 * Speaks RFC 6455 over a raw socket (WebSocketClientTrait): HTTP upgrade handshake,
 * masked client frames out, and the server's unmasked frames back. Covers the WS
 * transport that STAYS in server (the Hub is a separate concern in phpdot/realtime).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class WebSocketTest extends ServerTestCase
{
    use WebSocketClientTrait;

    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_ws_runner.php';
    }

    #[Test]
    public function handshakeUpgradesAndEchoesMessage(): void
    {
        $socket = $this->openWebSocket($this->port);

        fwrite($socket, $this->encodeMaskedTextFrame('ping'));
        self::assertSame('echo:ping', $this->readTextFrame($socket));

        fclose($socket);
    }

    #[Test]
    public function echoesMultipleSequentialTextFrames(): void
    {
        $socket = $this->openWebSocket($this->port);

        fwrite($socket, $this->encodeMaskedTextFrame('a'));
        self::assertSame('echo:a', $this->readTextFrame($socket));

        fwrite($socket, $this->encodeMaskedTextFrame('bb'));
        self::assertSame('echo:bb', $this->readTextFrame($socket));

        fwrite($socket, $this->encodeMaskedTextFrame('ccc'));
        self::assertSame('echo:ccc', $this->readTextFrame($socket));

        fclose($socket);
    }

    #[Test]
    public function echoesBinaryFrameAsBinary(): void
    {
        $socket = $this->openWebSocket($this->port);

        fwrite($socket, $this->encodeMaskedBinaryFrame('xy'));
        [$opcode, $payload] = $this->readFrame($socket);

        self::assertSame(0x82, $opcode, 'expected an unmasked FIN+binary frame back');
        self::assertSame('xy', $payload);

        fclose($socket);
    }
}
