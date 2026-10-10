<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

/**
 * Stands in for the plain file wrapper ("file") for a file that is replaced between a look at its name and the
 * opening: the name is a regular file, what is opened is of the type in $openedType (a FIFO, say). Opened without
 * "n" (O_NONBLOCK) a FIFO would wait for a writer: this stand-in refuses to open instead of hanging. With $suspends it
 * stands for a replaced plain file wrapper that suspends a fiber while it reads, with $stops for one whose read
 * then stops without the end of the file.
 */
class SwappedFileStream
{
    // The type of what is opened, as the bits of st_mode
    public static int $openedType = 0o010000;

    // Whether the first read suspends the fiber it runs in, as a wrapper on an event loop does
    public static bool $suspends = false;

    // Whether reading stops after the suspension: no data, and no end of the file
    public static bool $stops = false;

    /** @var resource|null */
    public $context;

    private bool $read = false;

    private bool $suspended = false;

    /**
     * Opens only without blocking.
     */
    public function stream_open(string $path, string $mode): bool
    {
        return str_contains($mode, 'n');
    }

    /**
     * The content, for the case that it is read.
     */
    public function stream_read(): string
    {
        if (self::$suspends && !$this->suspended && \Fiber::getCurrent() !== null) {
            $this->suspended = true;
            \Fiber::suspend();
        }

        if ($this->read || self::$stops) {
            return '';
        }
        $this->read = true;

        return "TEST_KEY=value\n";
    }

    /**
     * The end, once the content is read.
     */
    public function stream_eof(): bool
    {
        return $this->read && !self::$stops;
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
