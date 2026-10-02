<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown when something other than a comment follows the closing quote of a value.
 */
class TrailingCharactersException extends EnvLoaderException
{
}
