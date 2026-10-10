<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown when the .env file exists but cannot be opened, is larger than 1 MiB ("File too large"), or reading it
 * failed or stopped before its end: the lines read up to there are never returned.
 */
class FileNotReadableException extends EnvLoaderException
{
}
