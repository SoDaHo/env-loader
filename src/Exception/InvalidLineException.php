<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown when a line is neither empty, a comment nor an assignment.
 */
class InvalidLineException extends EnvLoaderException
{
}
