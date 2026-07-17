<?php

declare(strict_types=1);

/**
 * ServerException — runtime errors from the Server runner and its transports
 * (no transports attached, not started, HTTP configured as a non-primary port, …).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Exception;

use RuntimeException;

final class ServerException extends RuntimeException {}
