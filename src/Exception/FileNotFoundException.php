<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown when the .env file does not exist, is not a regular file (a directory, FIFO or device) or cannot be
 * reached (open_basedir, the permissions of a directory); the message does not tell these cases apart. Also when
 * the path is a URL of a stream wrapper, unless stream wrappers are allowed ("Stream wrapper not allowed").
 */
class FileNotFoundException extends EnvLoaderException
{
}
