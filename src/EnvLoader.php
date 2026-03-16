<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader;

class EnvLoader
{
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

        foreach ($values as $key => $value) {
            if ($overwrite || !array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }

        // Handle required keys - normalize to array
        if (is_string($required)) {
            $required = $required !== ''
                ? array_filter(array_map('trim', explode(',', $required)), fn ($key) => $key !== '')
                : [];
        }

        foreach ($required as $key) {
            if (!array_key_exists($key, $_ENV)) {
                throw new Exception\MissingRequiredKeyException("Missing required key: $key");
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
        if (!file_exists($path)) {
            throw new Exception\FileNotFoundException("File not found: $path");
        }

        if (!is_file($path)) {
            throw new Exception\FileNotFoundException("Not a file: $path");
        }

        if (!is_readable($path)) {
            throw new Exception\FileNotReadableException("File not readable: $path");
        }

        // Suppress warning and handle failure explicitly (TOCTOU protection)
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // @codeCoverageIgnoreStart
        if ($lines === false) {
            // Race condition: file was deleted/changed between checks and read
            throw new Exception\FileNotReadableException("Could not read file: $path");
        }
        // @codeCoverageIgnoreEnd

        // Strip UTF-8 BOM from first line (common in Windows-created files)
        if (isset($lines[0])) {
            $lines[0] = ltrim($lines[0], "\xEF\xBB\xBF");
        }

        $result = [];

        foreach ($lines as $line) {
            $parsed = self::parseLine($line);

            if ($parsed !== null) {
                [$key, $value] = $parsed;
                self::validateKey($key);
                $result[$key] = $value;
            }
        }

        return $result;
    }


    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $line = trim($line);

        // Skip empty lines and comments
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        // Must contain =
        if (!str_contains($line, '=')) {
            return null;
        }

        // Split only on first = (str_contains check above guarantees this succeeds)
        $pos = (int) strpos($line, '=');
        $key = trim(substr($line, 0, $pos));
        $value = substr($line, $pos + 1);

        $value = self::parseValue($value);

        return [$key, $value];
    }

    /**
     * @throws Exception\UnterminatedQuoteException
     */
    private static function parseValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Double quoted
        if (str_starts_with($value, '"')) {
            if (preg_match('/^"(.*?)"\s*(#.*)?$/', $value, $matches)) {
                return self::unescapeDoubleQuoted($matches[1]);
            }
            throw new Exception\UnterminatedQuoteException("Unterminated double quote: $value");
        }

        // Single quoted
        if (str_starts_with($value, "'")) {
            if (preg_match("/^'(.*?)'\s*(#.*)?$/", $value, $matches)) {
                return $matches[1];
            }
            throw new Exception\UnterminatedQuoteException("Unterminated single quote: $value");
        }

        // Unquoted - remove inline comment
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = trim(substr($value, 0, $commentPos));
        }

        return $value;
    }

    /**
     * @throws Exception\InvalidKeyException
     */
    private static function validateKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            throw new Exception\InvalidKeyException("Invalid key: $key");
        }
    }

    /**
     * Only unescapes \\ and \" — other sequences like \n are preserved
     * literally to prevent data corruption with Windows paths.
     */
    private static function unescapeDoubleQuoted(string $value): string
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
