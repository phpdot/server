<?php

declare(strict_types=1);

/**
 * ControlSocket — the CLI's private line into the running server.
 *
 * A unix-domain listener beside the pid file answering line-framed control
 * queries ("stats\n" → the master's live shared-memory stats() as one JSON
 * line). Deliberately NOT an HTTP endpoint: operational introspection is CLI
 * territory, never a public webpage — a socket file is invisible to the
 * network and gated by filesystem permissions, where a loopback TCP port is
 * reachable by anything local. Works for every transport mix, including
 * TCP-only servers that never enable HTTP.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Process;

use PHPdot\Server\Exception\ServerException;
use Swoole\Server as SwooleServer;

final class ControlSocket
{
    /**
     * Attach the control listener to the master. Call before start(); any
     * stale socket file from a SIGKILLed run is removed first (a bound unix
     * socket never rebinds over its own corpse).
     *
     * @param SwooleServer $master The Swoole master
     * @param string $path Absolute socket-file path
     *
     * @throws ServerException When the listener cannot be added.
     *
     * @return void
     */
    public static function attach(SwooleServer $master, string $path): void
    {
        if (file_exists($path)) {
            @unlink($path);
        }

        $port = $master->listen($path, 0, SWOOLE_SOCK_UNIX_STREAM);

        if ($port === false) {
            throw new ServerException(sprintf(
                'Control socket failed to listen on %s (%d bytes) — unix socket paths cap at '
                . '~104 bytes and the directory must exist; shorten or relocate pidFile.',
                $path,
                strlen($path),
            ));
        }

        $port->set([
            'open_http_protocol' => false,
            'open_http2_protocol' => false,
            'open_websocket_protocol' => false,
            'open_eof_check' => true,
            'package_eof' => "\n",
        ]);

        $port->on('connect', static function (): void {});
        $port->on('close', static function (): void {});
        $port->on('receive', static function (SwooleServer $server, int $fd, int $reactorId, string $data): void {
            if (trim($data) === 'stats') {
                $stats = $server->stats();
                $stats['answering_worker_pid'] = getmypid();
                $server->send($fd, json_encode($stats) . "\n");
            } else {
                $server->send($fd, json_encode(['error' => 'unknown command']) . "\n");
            }

            $server->close($fd);
        });
    }
}
