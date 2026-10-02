<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown when a value cannot be written as a single .env line.
 */
class InvalidValueException extends EnvLoaderException
{
}
