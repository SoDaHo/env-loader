<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader;

final class EnvLoader
{
    private const KEY_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    /**
     * Load a .env file into $_ENV and return the values of the file.
     *
     * Without overwrite, the environment wins over the file: a key keeps the value it has in $_ENV,
     * otherwise it takes the value of the process environment, otherwise the one of the file.
     *
     * @param array<string>|string $required Required keys - array or comma-separated string
     *
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     * @throws Exception\InvalidLineException
     * @throws Exception\InvalidKeyException
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     * @throws Exception\MissingRequiredKeyException
     *
     * @return array<string, string> The values of the file, whether or not they were written to $_ENV
     */
    public static function load(
        string $path,
        bool $overwrite = false,
        array|string $required = []
    ): array {
        $values = self::parse($path);

        // Handle required keys - normalize to array
        if (is_string($required)) {
            $required = explode(',', $required);
        }
        $required = array_filter(array_map('trim', $required), fn ($key) => $key !== '');

        // Check before writing, so a failed load leaves $_ENV untouched
        $requiredFromProcess = [];
        foreach ($required as $key) {
            if (array_key_exists($key, $values) || array_key_exists($key, $_ENV)) {
                continue;
            }

            $value = self::processVariable($key);
            if ($value === false) {
                throw new Exception\MissingRequiredKeyException("Missing required key: $key");
            }
            $requiredFromProcess[$key] = $value;
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
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     * @throws Exception\InvalidLineException
     * @throws Exception\InvalidKeyException
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     *
     * @return array<string, string>
     */
    public static function parse(string $path): array
    {
        // Suppress the open_basedir warning: every failure is reported as an exception
        if (!@file_exists($path)) {
            throw new Exception\FileNotFoundException("File not found: $path");
        }

        if (!is_file($path)) {
            throw new Exception\FileNotFoundException("Not a file: $path");
        }

        if (!is_readable($path)) {
            throw new Exception\FileNotReadableException("File not readable: $path");
        }

        // Suppress warnings and handle failure explicitly (TOCTOU protection)
        $handle = @fopen($path, 'rb');

        // @codeCoverageIgnoreStart
        if ($handle === false) {
            // Race condition: file was deleted/changed between checks and read
            throw new Exception\FileNotReadableException("Could not read file: $path");
        }
        // @codeCoverageIgnoreEnd

        $result = [];
        $lineNumber = 0;
        $bomPossible = true;

        try {
            // Read line by line: empty lines count for the line number, but cost no memory
            while (($line = @fgets($handle)) !== false) {
                $lineNumber++;

                // Remove the line ending: LF, CRLF, or a CR that PHP was configured to detect
                if (str_ends_with($line, "\n")) {
                    $line = substr($line, 0, -1);
                }
                if (str_ends_with($line, "\r")) {
                    $line = substr($line, 0, -1);
                }

                // Strip UTF-8 BOM from the first non-empty line (common in Windows-created files)
                if ($bomPossible && $line !== '') {
                    $line = ltrim($line, "\xEF\xBB\xBF");
                    $bomPossible = false;
                }

                $parsed = self::parseLine($line, "$path on line $lineNumber");

                if ($parsed !== null) {
                    [$key, $value] = $parsed;
                    $result[$key] = $value;
                }
            }

            // fgets() also returns false when reading fails. Where the stream reports that instead of
            // the end of the file, a partly read file must not pass as complete.
            if (!feof($handle)) {
                throw new Exception\FileNotReadableException("Could not read file: $path");
            }
        } finally {
            @fclose($handle);
        }

        return $result;
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
     * @param string $location File and line for error messages - messages never contain file content
     *
     * @throws Exception\InvalidLineException
     * @throws Exception\InvalidKeyException
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parseLine(#[\SensitiveParameter] string $line, string $location): ?array
    {
        $line = trim($line);

        // Skip empty lines and comments
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        // Strip optional "export" prefix (bash compatibility), unless it is the key itself ("export = 1")
        if (str_starts_with($line, 'export')) {
            $rest = substr($line, 6);

            if (strspn($rest, " \t", 0, 1) === 1 && !str_starts_with(ltrim($rest), '=')) {
                $line = $rest;
            }
        }

        // Anything else has to be an assignment: a forgotten "=" must not make a key vanish
        $pos = strpos($line, '=');
        if ($pos === false) {
            throw new Exception\InvalidLineException("Missing \"=\" in $location");
        }

        // Split only on first =
        $key = trim(substr($line, 0, $pos));

        // Validate first: only a valid key may appear in the error messages for its value
        if (!self::isValidKey($key)) {
            throw new Exception\InvalidKeyException("Invalid key in $location");
        }

        return [$key, self::parseValue(substr($line, $pos + 1), $key, $location)];
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
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\TrailingCharactersException
     */
    private static function parseValue(
        #[\SensitiveParameter]
        string $value,
        string $key,
        string $location
    ): string {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '"')) {
            return self::parseDoubleQuoted($trimmed, $key, $location);
        }

        if (str_starts_with($trimmed, "'")) {
            return self::parseSingleQuoted($trimmed, $key, $location);
        }

        // Unquoted - remove inline comment. Scan the untrimmed value: "KEY= # comment" is empty
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = substr($value, 0, $commentPos);
        }

        return trim($value);
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
     * @throws Exception\TrailingCharactersException
     */
    private static function ensureOnlyCommentFollows(
        #[\SensitiveParameter]
        string $rest,
        string $quote,
        string $key,
        string $location
    ): void {
        $rest = substr($rest, strspn($rest, " \t\n\r\v\f"));

        // Something else follows: 1.0.0 skipped whitespace with the regex \s, which follows the locale.
        // PCRE is asked which bytes that is instead of being handed the rest of the line: a warning
        // raised inside a PCRE call would expose its subject in the stack trace.
        if ($rest !== '' && $rest[0] !== '#') {
            $whitespace = (string) @preg_replace('/\S/', '', implode('', array_map('chr', range(0, 255))));
            $rest = substr($rest, strspn($rest, $whitespace));
        }

        if ($rest !== '' && $rest[0] !== '#') {
            throw new Exception\TrailingCharactersException(
                "Unexpected characters after closing $quote quote for key \"$key\" in $location"
            );
        }
    }
}
