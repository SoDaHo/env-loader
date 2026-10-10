<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown for a value that is not allowed: parse() and load() reject a NUL byte inside a value of the file,
 * format() a value that is not a string or contains a line break or NUL byte.
 */
class InvalidValueException extends EnvLoaderException
{
}
