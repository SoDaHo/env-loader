<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown when the .env file exists but cannot be opened, or reading it failed or stopped before its end: the
 * lines read up to there are never returned.
 */
class FileNotReadableException extends EnvLoaderException
{
}
