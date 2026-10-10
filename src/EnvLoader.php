<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader;

/**
 * Reads .env files: parse() returns the values of a file, load() also writes them to $_ENV, format() turns values
 * into .env content that parse() reads back unchanged.
 *
 * Invariants: nothing but $_ENV is written (no putenv(), no $_SERVER); a load() that throws leaves $_ENV as it
 * was; messages never carry a value or a raw line of the file, only the path, the line number and, once it is
 * valid, the key, and the arguments in the stack traces of its exceptions hide them (#[\SensitiveParameter]).
 * Not covered: the backtrace PHP prints for a fatal error, such as memory running out, can show the start of a
 * line or of the file (README); with no file larger than MAX_FILE_SIZE read, that takes a memory_limit of up to about
 * 18 MiB.
 */
final class EnvLoader
{
    private const KEY_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    // What PHP takes for the scheme of a URL
    private const SCHEME_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+-.';

    // Bytes asked for with one read
    private const CHUNK_SIZE = 8192;

    // The largest file that is read (1 MiB). It bounds a line as well, and the memory needed to parse the file
    private const MAX_FILE_SIZE = 1_048_576;

    // What trim() removes. Spelled out, because the default of PHP changes: from 8.6 it includes the form feed
    private const WHITESPACE = " \t\n\r\v\f\0";

    // Names under which a web server passes the request (besides every header as HTTP_*, and copies as REDIRECT_*
    // after an internal redirect of Apache): the meta-variables of CGI/1.1 (RFC 3875, 4.1), those nginx and Apache
    // add, and those PHP derives from the request
    private const REQUEST_VARIABLES = [
        'AUTH_TYPE', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'GATEWAY_INTERFACE', 'PATH_INFO', 'PATH_TRANSLATED',
        'QUERY_STRING', 'REMOTE_ADDR', 'REMOTE_HOST', 'REMOTE_IDENT', 'REMOTE_USER', 'REQUEST_METHOD', 'SCRIPT_NAME',
        'SERVER_NAME', 'SERVER_PORT', 'SERVER_PROTOCOL', 'SERVER_SOFTWARE',
        'DOCUMENT_ROOT', 'DOCUMENT_URI', 'FCGI_ROLE', 'HTTPS', 'REMOTE_PORT', 'REQUEST_SCHEME', 'REQUEST_URI',
        'SCRIPT_FILENAME', 'SERVER_ADDR',
        'ORIG_PATH_INFO', 'ORIG_SCRIPT_NAME', 'PHP_AUTH_DIGEST', 'PHP_AUTH_PW', 'PHP_AUTH_USER', 'PHP_SELF',
    ];

    /**
     * Load a .env file into $_ENV and return the values of the file.
     *
     * Without overwrite, the environment wins over the file: a key keeps the value it has in $_ENV,
     * otherwise it takes the value of the process environment, otherwise the one of the file.
     *
     * @param array<string>|string $required Required keys - array or comma-separated string. Each has to end up
     *                                       in $_ENV with a string value
     * @param bool $requireNonEmpty The value of a required key must not be empty either
     * @param bool $rejectRequestVariables Throw where a key named like a request variable (HTTP_*, CGI) would take
     *                                     its value from $_ENV while PHP fills $_ENV with the request
     * @param bool $localOnly Read a local file only, no URL of a stream wrapper (phar://, file://, ...), see parse()
     *
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     * @throws Exception\InvalidLineException
     * @throws Exception\InvalidKeyException
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     * @throws Exception\InvalidValueException
     * @throws Exception\MissingRequiredKeyException
     * @throws Exception\RequestVariableException
     * @throws \InvalidArgumentException An entry of $required has no string form
     *
     * @return array<string, string> The values of the file, whether or not they were written to $_ENV
     */
    public static function load(
        string $path,
        bool $overwrite = false,
        array|string $required = [],
        bool $requireNonEmpty = false,
        bool $rejectRequestVariables = false,
        bool $localOnly = false,
    ): array {
        $values = self::parse($path, $localOnly);
        $names = self::requiredNames($required);

        if ($rejectRequestVariables) {
            self::rejectRequestVariables($values, $names, $overwrite);
        }

        // Checked before writing, so a failed load leaves $_ENV untouched. Every key is checked before throwing:
        // one run names all of them. Only $_ENV can hold a value that is no string, set there by the application.
        // Nothing is kept per key but the values of the process environment the check has read, which are written
        // as they were checked: memory stays as low as without required keys
        $fromProcess = [];
        $missing = [];
        $notString = [];
        $empty = [];
        foreach ($names as $key) {
            $inFile = array_key_exists($key, $values);

            if ($overwrite && $inFile) {
                $value = $values[$key];
            } elseif (array_key_exists($key, $_ENV)) {
                $value = $_ENV[$key];
            } elseif ($inFile && !$requireNonEmpty) {
                // A string either way, from the process environment or the file: nothing to check
                continue;
            } else {
                $value = self::processVariable($key);
                if ($value !== false) {
                    $fromProcess[$key] = $value;
                } elseif ($inFile) {
                    $value = $values[$key];
                } else {
                    $missing[$key] = $key;
                    continue;
                }
            }

            if (!is_string($value)) {
                $notString[$key] = $key;
            } elseif ($requireNonEmpty && $value === '') {
                $empty[$key] = $key;
            }
        }

        $problems = array_filter([
            // The message for missing keys alone stays as it was in 2.0
            self::keyList('Missing required key', 'Missing required keys', $missing),
            self::keyList('Required key without a string value', 'Required keys without a string value', $notString),
            self::keyList('Empty required key', 'Empty required keys', $empty),
        ]);
        if ($problems !== []) {
            throw new Exception\MissingRequiredKeyException(implode('; ', $problems));
        }

        // A key in $_ENV keeps its value. A value the check took from the process environment is written as it was
        // checked; for the other keys the process environment is asked here (again, for a required key of the file
        // that it has no value for under requireNonEmpty)
        foreach ($values as $key => $value) {
            if ($overwrite) {
                $_ENV[$key] = $value;
            } elseif (!array_key_exists($key, $_ENV)) {
                $process = $fromProcess[$key] ?? self::processVariable($key);
                $_ENV[$key] = $process === false ? $value : $process;
            }
        }

        // A required key that only the process environment has is copied: what is required can be read from $_ENV
        $_ENV += $fromProcess;

        return $values;
    }

    /**
     * The required keys as a list of trimmed names, empty entries left out.
     *
     * @param array<mixed>|string $required
     *
     * @throws \InvalidArgumentException An entry has no string form
     *
     * @return list<string>
     */
    private static function requiredNames(array|string $required): array
    {
        if (is_string($required)) {
            $required = explode(',', $required);
        }

        $names = [];
        $position = 0;
        foreach ($required as $key) {
            $position++;

            // As in 1.x, a scalar or null is read as a string (null and false are empty entries, a number is a key).
            // Anything else has no name to require: an array would become the key "Array", after a warning
            if (!is_scalar($key) && $key !== null && !$key instanceof \Stringable) {
                throw new \InvalidArgumentException(
                    sprintf('Required key at position %d must be a string, %s given', $position, get_debug_type($key))
                );
            }

            $key = trim((string) $key, self::WHITESPACE);
            if ($key !== '') {
                $names[] = $key;
            }
        }

        return $names;
    }

    /**
     * Throws for the keys that would take their value from $_ENV where the request may have put it there.
     *
     * PHP fills $_ENV itself where variables_order has an E, and under a web SAPI (PHP-FPM, CGI) that includes the
     * request: a client sets HTTP_* with a header. Only names a request can bring count, so a fastcgi_param of the
     * web server with another name stays trusted; and a value that is the one of the process environment is no
     * value from the request (under plain CGI the process environment is the request, and processVariable() says
     * false there). The CLI has no request.
     *
     * Checked are the keys of the file, unless the file overwrites them, and the required keys.
     *
     * @param array<string, string> $values
     * @param list<string> $names
     *
     * @throws Exception\RequestVariableException
     */
    private static function rejectRequestVariables(
        #[\SensitiveParameter]
        array $values,
        array $names,
        bool $overwrite
    ): void {
        $requestInEnv = !in_array(PHP_SAPI, ['cli', 'phpdbg'], true)
            && stripos((string) ini_get('variables_order'), 'E') !== false;
        if (!$requestInEnv) {
            return;
        }

        $fromRequest = [];
        foreach ($overwrite ? [] : $values as $key => $value) {
            if (self::isFromRequest($key)) {
                $fromRequest[$key] = $key;
            }
        }
        foreach ($names as $key) {
            if (!($overwrite && array_key_exists($key, $values)) && self::isFromRequest($key)) {
                $fromRequest[$key] = $key;
            }
        }

        $message = self::keyList(
            'Value from the request in $_ENV for key',
            'Values from the request in $_ENV for keys',
            $fromRequest
        );
        if ($message !== null) {
            throw new Exception\RequestVariableException($message);
        }
    }

    /**
     * Whether load() would take the value of the key from $_ENV, where a request may have put it: the key is named
     * like a request variable, and its value there is not the one of the process environment.
     */
    private static function isFromRequest(string $key): bool
    {
        $requestName = str_starts_with($key, 'HTTP_') || str_starts_with($key, 'REDIRECT_')
            || in_array($key, self::REQUEST_VARIABLES, true);

        return $requestName && array_key_exists($key, $_ENV) && $_ENV[$key] !== self::processVariable($key);
    }

    /**
     * "Label: A" for one key, "Labels: A, B" for several, null for none.
     *
     * @param array<string> $keys
     */
    private static function keyList(string $one, string $several, array $keys): ?string
    {
        $keys = array_map(self::printable(...), $keys);

        return match (count($keys)) {
            0 => null,
            1 => "$one: " . implode('', $keys),
            default => "$several: " . implode(', ', $keys),
        };
    }

    /**
     * A key as a message shows it: control characters escaped (a line break as \x0A). A required key is any name
     * the caller passes, and it must not add a line to a log or hide a NUL byte; a valid key stays as it is.
     */
    private static function printable(string $key): string
    {
        $escapes = [];
        foreach ([...range(0, 31), 127] as $byte) {
            $escapes[chr($byte)] = sprintf('\x%02X', $byte);
        }

        return strtr($key, $escapes);
    }

    /**
     * The value of a variable in the environment of the PHP process, false if it is not set or not to be read.
     *
     * Only the process itself is asked (local_only). The plain getenv() also returns what the web server
     * passes with a request - under FastCGI every request header as HTTP_* - and that must not count as process
     * environment. (Where PHP puts the request into $_ENV itself, variables_order with E, see
     * rejectRequestVariables().)
     */
    private static function processVariable(string $key): string|false
    {
        // No environment holds a name with "=" or a NUL byte; getenv() throws for NUL from PHP 8.6
        if (strpbrk($key, "=\0") !== false) {
            return false;
        }

        // getenv() can be disabled (disable_functions). And under CGI, which sets GATEWAY_INTERFACE,
        // the environment of the process is the request itself
        if (!function_exists('getenv') || getenv('GATEWAY_INTERFACE', true) !== false) {
            return false;
        }

        return getenv($key, true);
    }

    /**
     * Parse a .env file and return key-value pairs without setting $_ENV.
     *
     * A URL is opened with its stream wrapper, as in 2.0. With $localOnly it is rejected before anything is done with
     * it: a wrapper can reach other hosts (ftp://) or read an archive (phar://), which a path to a configuration file
     * is not expected to do.
     *
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     * @throws Exception\InvalidLineException
     * @throws Exception\InvalidKeyException
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     * @throws Exception\InvalidValueException
     *
     * @return array<string, string>
     */
    public static function parse(string $path, bool $localOnly = false): array
    {
        $result = [];
        $bomPossible = true;

        foreach (self::lines(self::read($path, $localOnly)) as $lineNumber => $line) {
            // Strip one UTF-8 BOM from the first non-empty line (common in Windows-created files). Empty as the
            // parser sees it: a line of whitespace is skipped like an empty one, so it keeps the BOM possible. Only
            // the whole sequence: single bytes of it, or a second BOM, stay and make the key invalid
            if ($bomPossible && strspn($line, self::WHITESPACE) !== strlen($line)) {
                if (str_starts_with($line, "\xEF\xBB\xBF")) {
                    $line = substr($line, 3);
                }
                $bomPossible = false;
            }

            $parsed = self::parseLine($line, "$path on line $lineNumber");

            if ($parsed !== null) {
                [$key, $value] = $parsed;
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * The whole content of the file, at most MAX_FILE_SIZE bytes.
     *
     * The file is read to its end before a line is parsed: a read that fails or stops makes the whole file
     * unreadable, so no part of it passes as complete, and no line cut off by it is reported as malformed.
     *
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     */
    private static function read(string $path, bool $localOnly): string
    {
        $streamUrl = self::isStreamUrl($path);
        if ($streamUrl && $localOnly) {
            throw new Exception\FileNotFoundException("Not a local file: $path");
        }

        // A URL is read as in 2.0: the warnings are suppressed with @, and an error handler that throws anyway
        // throws from here, as an exception of the wrapper does. No handler of this library may stand over the code
        // of a wrapper: written in PHP, it can suspend a fiber, and the error handler belongs to the whole process -
        // one that another fiber installed meanwhile would be removed when this one is restored
        if ($streamUrl) {
            return self::readFile($path, true);
        }

        // A local file runs no code of the application (unless it replaced the plain file wrapper). Its warnings
        // (open_basedir, a failed open or read) do not reach an error handler of the application: a handler that
        // throws even for suppressed warnings would replace the exception of this library with its own
        set_error_handler(static fn (): bool => true);

        try {
            return self::readFile($path, false);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Opens, reads and closes the file. Every warning is suppressed with @: a URL reaches here without a handler of
     * this library (read()).
     *
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     */
    private static function readFile(string $path, bool $streamUrl): string
    {
        $handle = self::open($path, $streamUrl);

        try {
            return self::contents($handle, $path, $streamUrl);
        } finally {
            @fclose($handle);
        }
    }

    /**
     * The open file. Whether it can be read is decided by opening it, not by asking first: is_readable() checks
     * the real user instead of the effective one, and the file may change between the question and the opening.
     *
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     *
     * @return resource
     */
    private static function open(string $path, bool $streamUrl)
    {
        // Also false outside open_basedir
        if (!@file_exists($path)) {
            throw new Exception\FileNotFoundException("File not found: $path");
        }

        // Only a regular file is opened, as in 2.0: opening a device or a FIFO can have effects of its own. A local
        // file is checked once more after opening, on the handle (contents()): its name may lead elsewhere by then
        if (!is_file($path)) {
            throw new Exception\FileNotFoundException("Not a file: $path");
        }

        // A local file is opened without blocking ("n", O_NONBLOCK of the plain file wrapper): a FIFO that replaced
        // it after the look at its name would wait for a writer before it could be rejected
        $handle = @fopen($path, $streamUrl ? 'rb' : 'rbn');

        if ($handle === false) {
            throw new Exception\FileNotReadableException("Could not read file: $path");
        }

        return $handle;
    }

    /**
     * What the open file holds, read to its end.
     *
     * @param resource $handle
     *
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     */
    private static function contents($handle, string $path, bool $streamUrl): string
    {
        // Checked on what was opened, not only on the name: the name may lead elsewhere by now (a FIFO, a device)
        if (!$streamUrl && !self::isRegularFile($handle)) {
            throw new Exception\FileNotFoundException("Not a file: $path");
        }

        // fread() tells a failed read (false) from one without data (''), unlike fgets(); feof() then tells the end
        // of the file from a stream that has stopped delivering (a timeout, say)
        $content = '';
        while (true) {
            $chunk = @fread($handle, self::CHUNK_SIZE);

            if ($chunk === false) {
                throw new Exception\FileNotReadableException("Could not read file: $path");
            }
            if ($chunk === '') {
                break;
            }

            // Counted while reading, not taken from the size of the file: a stream may not know its size, and a
            // file may grow while it is read. Memory stays bounded whatever the file holds
            $content .= $chunk;
            if (strlen($content) > self::MAX_FILE_SIZE) {
                throw new Exception\FileNotReadableException("File too large: $path");
            }
        }

        if (!feof($handle)) {
            throw new Exception\FileNotReadableException("Could not read file: $path");
        }

        return $content;
    }

    /**
     * Whether an open local file is a regular file: a directory, FIFO or device has no end to read to, or none
     * that is a file.
     *
     * @param resource $handle
     */
    private static function isRegularFile($handle): bool
    {
        $stat = fstat($handle);

        return $stat !== false && ($stat['mode'] & 0o170000) === 0o100000;
    }

    /**
     * Whether PHP opens the path with a stream wrapper, decided as PHP decides it: a scheme of at least two
     * characters followed by "://" (php://, phar://, file://, also one no wrapper is registered for), or "data:".
     * Only the start of the path counts, so a local path may contain "://" further on.
     */
    private static function isStreamUrl(string $path): bool
    {
        $scheme = strspn($path, self::SCHEME_CHARACTERS);

        return ($scheme > 1 && substr($path, $scheme, 3) === '://') || str_starts_with($path, 'data:');
    }

    /**
     * The lines of the content, numbered from 1. LF, CRLF and a single CR each end a line; the last line needs
     * none. Cut one by one instead of into an array, so empty lines count for the line number but cost no memory.
     *
     * @return \Generator<int, string>
     */
    private static function lines(#[\SensitiveParameter] string $content): \Generator
    {
        $length = strlen($content);
        $offset = 0;
        $lineNumber = 0;

        while ($offset < $length) {
            $end = $offset + strcspn($content, "\r\n", $offset);

            yield ++$lineNumber => substr($content, $offset, $end - $offset);

            // A CRLF is one line ending; at the end of the content, $end + 1 ends the loop
            $offset = $end + (substr($content, $end, 2) === "\r\n" ? 2 : 1);
        }
    }

    /**
     * Format key-value pairs as .env content that parse() reads back unchanged, as long as it is at most 1 MiB.
     *
     * Every value is double-quoted. Writing the file is left to the caller. The content is meant for parse(), not
     * for a shell: $, backticks and ${...} stay as they are (README).
     *
     * @param array<string> $values Key-value pairs
     *
     * @throws Exception\InvalidKeyException
     * @throws Exception\InvalidValueException
     */
    public static function format(#[\SensitiveParameter] array $values): string
    {
        $content = '';
        $position = 0;

        foreach ($values as $key => $value) {
            $position++;

            // PHP turns numeric string keys into integers
            $key = (string) $key;

            // An invalid key is not named: it may be a misplaced value
            if (!self::isValidKey($key)) {
                throw new Exception\InvalidKeyException("Invalid key at position $position");
            }

            if (!is_string($value)) {
                throw new Exception\InvalidValueException("Value for key \"$key\" is not a string");
            }

            // Line breaks cannot be represented; NUL is never valid in an environment value
            if (strpbrk($value, "\r\n\0") !== false) {
                throw new Exception\InvalidValueException(
                    "Value for key \"$key\" contains a line break or NUL byte"
                );
            }

            $content .= $key . '="' . strtr($value, ['\\' => '\\\\', '"' => '\\"']) . "\"\n";
        }

        return $content;
    }

    /**
     * One line of the file: null for an empty line or a comment, otherwise key and value.
     *
     * A line that is neither must be an assignment, so a typo cannot make a key vanish. The key is checked
     * before the value is parsed: only a valid key may appear in a message, never a value or the line itself.
     *
     * @param string $location File and line for error messages - messages never contain file content
     *
     * @throws Exception\InvalidLineException
     * @throws Exception\InvalidKeyException
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     * @throws Exception\InvalidValueException
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parseLine(#[\SensitiveParameter] string $line, string $location): ?array
    {
        $line = trim($line, self::WHITESPACE);

        // Skip empty lines and comments
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        // Strip optional "export" prefix (bash compatibility), unless it is the key itself ("export = 1")
        if (str_starts_with($line, 'export')) {
            $rest = substr($line, 6);

            if (strspn($rest, " \t", 0, 1) === 1 && !str_starts_with(ltrim($rest, self::WHITESPACE), '=')) {
                $line = $rest;
            }
        }

        // Anything else has to be an assignment: a forgotten "=" must not make a key vanish
        $pos = strpos($line, '=');
        if ($pos === false) {
            throw new Exception\InvalidLineException("Missing \"=\" in $location");
        }

        // Split only on first =
        $key = trim(substr($line, 0, $pos), self::WHITESPACE);

        // Validate first: only a valid key may appear in the error messages for its value
        if (!self::isValidKey($key)) {
            throw new Exception\InvalidKeyException("Invalid key in $location");
        }

        $value = self::parseValue(substr($line, $pos + 1), $key, $location);

        // Checked on the finished value, so it holds for every form of quoting. NUL is never part of an environment
        // value (format() rejects it too): a C API would cut the value there. At the ends of a value it is whitespace
        if (str_contains($value, "\0")) {
            throw new Exception\InvalidValueException("Value for key \"$key\" contains a NUL byte in $location");
        }

        return [$key, $value];
    }

    /**
     * Letters, digits and underscores, not starting with a digit.
     *
     * Checked without a regex: a warning raised inside a PCRE call would
     * expose its subject in the stack trace.
     */
    private static function isValidKey(string $key): bool
    {
        return $key !== ''
            && strspn($key, self::KEY_CHARACTERS) === strlen($key)
            && strspn($key, '0123456789', 0, 1) === 0;
    }

    /**
     * The value after the first "=", as it is meant: a quoted value goes to the parser for its quotes, an
     * unquoted one loses its inline comment and the whitespace around it.
     *
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     */
    private static function parseValue(
        #[\SensitiveParameter]
        string $value,
        string $key,
        string $location
    ): string {
        $trimmed = trim($value, self::WHITESPACE);

        if (str_starts_with($trimmed, '"')) {
            return self::parseDoubleQuoted($trimmed, $key, $location);
        }

        if (str_starts_with($trimmed, "'")) {
            return self::parseSingleQuoted($trimmed, $key, $location);
        }

        // Unquoted - remove inline comment: the first "#" after whitespace.
        // Scan the untrimmed value: "KEY= # comment" is empty
        $offset = 0;
        while (($pos = strpos($value, '#', $offset)) !== false) {
            if ($pos > 0 && str_contains(self::WHITESPACE, $value[$pos - 1])) {
                $value = substr($value, 0, $pos);
                break;
            }
            $offset = $pos + 1;
        }

        return trim($value, self::WHITESPACE);
    }

    /**
     * Only unescapes \\ and \" — other sequences like \n are preserved
     * literally to prevent data corruption with Windows paths.
     *
     * Scans linearly instead of using a regex: PCRE limits made large
     * values fail as "unterminated".
     *
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     */
    private static function parseDoubleQuoted(
        #[\SensitiveParameter]
        string $value,
        string $key,
        string $location
    ): string {
        $length = strlen($value);
        $result = '';
        $pos = 1;

        while (true) {
            $span = strcspn($value, '"\\', $pos);
            $result .= substr($value, $pos, $span);
            $pos += $span;

            // No closing quote, or a backslash as last character
            if ($pos >= $length || ($value[$pos] === '\\' && $pos + 1 >= $length)) {
                throw new Exception\UnterminatedQuoteException(
                    "Unterminated double quote for key \"$key\" in $location"
                );
            }

            if ($value[$pos] === '"') {
                break;
            }

            $escaped = $value[$pos + 1];
            $result .= $escaped === '"' || $escaped === '\\' ? $escaped : '\\' . $escaped;
            $pos += 2;
        }

        self::ensureOnlyCommentFollows(substr($value, $pos + 1), 'double', $key, $location);

        return $result;
    }

    /**
     * No escape processing, no single quotes inside.
     *
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     */
    private static function parseSingleQuoted(
        #[\SensitiveParameter]
        string $value,
        string $key,
        string $location
    ): string {
        $end = strpos($value, "'", 1);

        if ($end === false) {
            throw new Exception\UnterminatedQuoteException(
                "Unterminated single quote for key \"$key\" in $location"
            );
        }

        self::ensureOnlyCommentFollows(substr($value, $end + 1), 'single', $key, $location);

        return substr($value, 1, $end - 1);
    }

    /**
     * After the closing quote only whitespace and a comment are allowed.
     *
     * Whitespace is ASCII whitespace in every locale, and it is skipped without a regex:
     * a warning raised inside a PCRE call would expose its subject in the stack trace.
     *
     * @throws Exception\TrailingCharactersException
     */
    private static function ensureOnlyCommentFollows(
        #[\SensitiveParameter]
        string $rest,
        string $quote,
        string $key,
        string $location
    ): void {
        $rest = substr($rest, strspn($rest, " \t\v\f"));

        if ($rest !== '' && $rest[0] !== '#') {
            throw new Exception\TrailingCharactersException(
                "Unexpected characters after closing $quote quote for key \"$key\" in $location"
            );
        }
    }
}
