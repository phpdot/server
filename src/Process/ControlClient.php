<?php

declare(strict_types=1);

/**
 * ControlClient — queries a running server's ControlSocket from the CLI.
 *
 * Connects to the unix socket beside the pid file, sends one line-framed
 * command, and decodes the one-line JSON reply. Every failure — no socket,
 * refused connect, timeout, malformed reply — degrades to null so callers
 * report "could not observe" instead of exploding mid-command.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Process;

final class ControlClient
{
    /**
     * The running server's live stats, or null when the socket cannot answer.
     *
     * @param string $socket Absolute socket-file path ('' returns null)
     * @param float $timeout Connect + read patience, seconds
     *
     * @return array<mixed>|null
     */
    public static function stats(string $socket, float $timeout = 1.0): array|null
    {
        if ($socket === '' || !file_exists($socket)) {
            return null;
        }

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('unix://' . $socket, $errno, $errstr, $timeout);

        if (!is_resource($fp)) {
            return null;
        }

        stream_set_timeout($fp, (int) floor($timeout), (int) (fmod($timeout, 1.0) * 1_000_000));
        fwrite($fp, "stats\n");
        $raw = fgets($fp);
        fclose($fp);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
