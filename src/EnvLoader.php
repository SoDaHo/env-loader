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
 * line or of the file (README); with no file larger than MAX_FILE_SIZE read, that takes a memory_limit of a few MiB.
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

    /**
     * Load a .env file into $_ENV and return the values of the file.
     *
     * Without overwrite, the environment wins over the file: a key keeps the value it has in $_ENV,
     * otherwise it takes the value of the process environment, otherwise the one of the file.
     *
     * @param array<string>|string $required Required keys - array or comma-separated string
     * @param bool $allowStreamWrappers Open a URL (phar://, file://, ...) with its stream wrapper, see parse()
     *
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     * @throws Exception\InvalidLineException
     * @throws Exception\InvalidKeyException
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     * @throws Exception\InvalidValueException
     * @throws Exception\MissingRequiredKeyException
     * @throws \InvalidArgumentException An entry of $required has no string form
     *
     * @return array<string, string> The values of the file, whether or not they were written to $_ENV
     */
    public static function load(
        string $path,
        bool $overwrite = false,
        array|string $required = [],
        bool $allowStreamWrappers = false,
    ): array {
        $values = self::parse($path, $allowStreamWrappers);

        // Handle required keys - normalize to a list of trimmed names
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

        // Check before writing, so a failed load leaves $_ENV untouched. Every missing key is collected first:
        // one run names all of them
        $requiredFromProcess = [];
        $missing = [];
        foreach ($names as $key) {
            if (array_key_exists($key, $values) || array_key_exists($key, $_ENV)) {
                continue;
            }

            $value = self::processVariable($key);
            if ($value === false) {
                $missing[$key] = $key;
                continue;
            }
            $requiredFromProcess[$key] = $value;
        }

        if ($missing !== []) {
            // The message for a single key stays as it was in 2.0.0
            throw new Exception\MissingRequiredKeyException(
                count($missing) === 1
                    ? 'Missing required key: ' . implode('', $missing)
                    : 'Missing required keys: ' . implode(', ', $missing)
            );
        }

        foreach ($values as $key => $value) {
            if ($overwrite) {
                $_ENV[$key] = $value;
            } elseif (!array_key_exists($key, $_ENV)) {
                $fromProcess = self::processVariable($key);
                $_ENV[$key] = $fromProcess === false ? $value : $fromProcess;
            }
        }

        // A required key that only the process environment defines is copied: what is required can be read from $_ENV
        $_ENV += $requiredFromProcess;

        return $values;
    }

    /**
     * The value of a variable in the environment of the PHP process, false if it is not set or not to be read.
     *
     * Only the process itself is asked (local_only). The plain getenv() also returns what the web server
     * passes with a request - under FastCGI every request header as HTTP_* - and a request must not
     * overrule the file.
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
     * The path is a local file. A URL that PHP would open with a stream wrapper is rejected before anything is
     * done with it, unless $allowStreamWrappers says otherwise: a wrapper can reach other hosts (ftp://) or read an
     * archive (phar://), which a path to a configuration file is not expected to do.
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
    public static function parse(string $path, bool $allowStreamWrappers = false): array
    {
        $result = [];
        $bomPossible = true;

        foreach (self::lines(self::read($path, $allowStreamWrappers)) as $lineNumber => $line) {
            // Strip one UTF-8 BOM from the first non-empty line (common in Windows-created files). Empty as the
            // parser sees it: a line of whitespace is skipped like an empty one, so it keeps the BOM possible. Only
            // the whole sequence: single bytes of it, or a second BOM, stay and make the key invalid
            if ($bomPossible && trim($line, self::WHITESPACE) !== '') {
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
    private static function read(string $path, bool $allowStreamWrappers): string
    {
        $streamUrl = self::isStreamUrl($path);
        if ($streamUrl && !$allowStreamWrappers) {
            throw new Exception\FileNotFoundException("Stream wrapper not allowed: $path");
        }

        // Suppress the open_basedir warning: every failure is reported as an exception
        if (!@file_exists($path)) {
            throw new Exception\FileNotFoundException("File not found: $path");
        }

        // A URL is checked with the stat of its wrapper before it is opened, as in 2.0: not every wrapper can stat
        // an open stream. A local file is checked after opening, on the handle (below)
        if ($streamUrl && !is_file($path)) {
            throw new Exception\FileNotFoundException("Not a file: $path");
        }

        // Whether the file can be read is decided by opening it, not by asking first: is_readable() checks the
        // real user instead of the effective one, and the file may change between the question and the opening.
        // A local file is opened without blocking ("n", O_NONBLOCK of the plain file wrapper): a FIFO would wait for
        // a writer before it could be rejected. The warning is suppressed, the failure reported as an exception
        $handle = @fopen($path, $streamUrl ? 'rb' : 'rbn');

        if ($handle === false) {
            throw new Exception\FileNotReadableException("Could not read file: $path");
        }

        try {
            // Checked on what was opened, not on the name: by now the name may lead elsewhere (a FIFO, a device)
            if (!$streamUrl && !self::isRegularFile($handle)) {
                throw new Exception\FileNotFoundException("Not a file: $path");
            }

            // fread() tells a failed read (false) from one without data (''), unlike fgets(); feof() then tells the
            // end of the file from a stream that has stopped delivering (a timeout, say)
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
        } finally {
            @fclose($handle);
        }
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
     * Format key-value pairs as .env content that parse() reads back unchanged.
     *
     * Every value is double-quoted. Writing the file is left to the caller.
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
