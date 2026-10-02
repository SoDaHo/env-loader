<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader;

class EnvLoader
{
    private const KEY_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    /**
     * @param array<string>|string $required Required keys - array or comma-separated string
     *
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     * @throws Exception\InvalidKeyException
     * @throws Exception\UnterminatedQuoteException
     * @throws Exception\MissingRequiredKeyException
     */
    public static function load(
        string $path,
        bool $overwrite = false,
        array|string $required = []
    ): void {
        $values = self::parse($path);

        // Handle required keys - normalize to array
        if (is_string($required)) {
            $required = explode(',', $required);
        }
        $required = array_filter(array_map('trim', $required), fn ($key) => $key !== '');

        // Check before writing, so a failed load leaves $_ENV untouched
        foreach ($required as $key) {
            if (!array_key_exists($key, $values) && !array_key_exists($key, $_ENV)) {
                throw new Exception\MissingRequiredKeyException("Missing required key: $key");
            }
        }

        foreach ($values as $key => $value) {
            if ($overwrite || !array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
    }


    /**
     * Parse a .env file and return key-value pairs without setting $_ENV.
     *
     * @throws Exception\FileNotFoundException
     * @throws Exception\FileNotReadableException
     * @throws Exception\InvalidKeyException
     * @throws Exception\UnterminatedQuoteException
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
        } finally {
            @fclose($handle);
        }

        return $result;
    }


    /**
     * @param string $location File and line for error messages - messages never contain file content
     *
     * @throws Exception\InvalidKeyException
     * @throws Exception\UnterminatedQuoteException
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parseLine(#[\SensitiveParameter] string $line, string $location): ?array
    {
        $line = trim($line);

        // Skip comments
        if (str_starts_with($line, '#')) {
            return null;
        }

        // Strip optional "export" prefix (bash compatibility), unless it is the key itself ("export = 1")
        if (str_starts_with($line, 'export')) {
            $rest = substr($line, 6);

            if (strspn($rest, " \t", 0, 1) === 1 && !str_starts_with(ltrim($rest), '=')) {
                $line = $rest;
            }
        }

        // Skip empty lines and lines without =
        $pos = strpos($line, '=');
        if ($pos === false) {
            return null;
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
     */
    private static function parseValue(
        #[\SensitiveParameter]
        string $value,
        string $key,
        string $location
    ): string {
        $trimmed = trim($value);

        // Double quoted: allow only non-quote/non-backslash chars or escape sequences
        if (str_starts_with($trimmed, '"')) {
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(#.*)?$/', $trimmed, $matches)) {
                return self::unescapeDoubleQuoted($matches[1]);
            }
            throw new Exception\UnterminatedQuoteException(
                "Unterminated double quote for key \"$key\" in $location"
            );
        }

        // Single quoted: no escape processing, no single quotes inside
        if (str_starts_with($trimmed, "'")) {
            if (preg_match("/^'([^']*)'\s*(#.*)?$/", $trimmed, $matches)) {
                return $matches[1];
            }
            throw new Exception\UnterminatedQuoteException(
                "Unterminated single quote for key \"$key\" in $location"
            );
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
     */
    private static function unescapeDoubleQuoted(#[\SensitiveParameter] string $value): string
    {
        return preg_replace_callback(
            '/\\\\(.)/',
            fn (array $m): string => match ($m[1]) {
                '\\', '"' => $m[1],
                default => '\\' . $m[1],
            },
            $value
        ) ?? $value;
    }
}
