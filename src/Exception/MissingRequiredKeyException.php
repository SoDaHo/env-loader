<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown by load() before it writes anything to $_ENV, when a required key is neither in the file, nor in $_ENV,
 * nor in the process environment, when its value in $_ENV is no string, or, with requireNonEmpty, when its value
 * is empty. The message names every such key.
 */
class MissingRequiredKeyException extends EnvLoaderException
{
}
