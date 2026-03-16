<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown when the .env file exists but is not readable.
 */
class FileNotReadableException extends EnvLoaderException
{
}
