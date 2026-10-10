<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown by load() before it writes anything to $_ENV, when a required key is neither in the file, nor in $_ENV,
 * nor in the process environment. The message names every missing key.
 */
class MissingRequiredKeyException extends EnvLoaderException
{
}
