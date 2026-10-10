<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

/**
 * Stands in for the plain file wrapper ("file") for a file that is replaced between a look at its name and the
 * opening: the name is a regular file, what is opened is of the type in $openedType (a FIFO, say). Opened without
 * "n" (O_NONBLOCK) a FIFO would wait for a writer: this stand-in refuses to open instead of hanging.
 */
class SwappedFileStream
{
    // The type of what is opened, as the bits of st_mode
    public static int $openedType = 0o010000;

    /** @var resource|null */
    public $context;

    private bool $read = false;

    public function stream_open(string $path, string $mode): bool
    {
        return str_contains($mode, 'n');
    }

    /**
     * Content for the case that it is read anyway.
     */
    public function stream_read(): string
    {
        if ($this->read) {
            return '';
        }
        $this->read = true;

        return "TEST_KEY=value\n";
    }

    public function stream_eof(): bool
    {
        return $this->read;
    }

    /**
     * What fstat() tells about the opened file.
     *
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['mode' => self::$openedType | 0o644, 'size' => 15];
    }

    /**
     * What a look at the name tells: a regular file.
     *
     * @return array<string, int>
     */
    public function url_stat(): array
    {
        return ['mode' => 0o100644, 'size' => 15];
    }
}
