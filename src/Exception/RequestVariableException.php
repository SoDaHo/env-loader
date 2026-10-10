<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Exception;

/**
 * Thrown by load() with rejectRequestVariables, before it writes anything to $_ENV, where a key named like a request
 * variable (HTTP_*, a CGI meta-variable) would take its value from $_ENV while PHP fills $_ENV with the request
 * (variables_order with E under PHP-FPM or CGI), and that value is not the one of the process environment.
 */
class RequestVariableException extends EnvLoaderException
{
}
