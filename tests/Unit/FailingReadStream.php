<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

/**
 * Stream wrapper for a file that can be read only partly: one line arrives, then reading fails.
 */
class FailingReadStream
{
    /** @var resource|null */
    public $context;

    private bool $delivered = false;

    public function stream_open(): bool
    {
        return true;
    }

    public function stream_read(): string|false
    {
        if (!$this->delivered) {
            $this->delivered = true;

            return "TEST_KEY=value\n";
        }

        trigger_error('Read failed', E_USER_WARNING);

        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        trigger_error('Close failed', E_USER_WARNING);
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(): array
    {
        // A regular file, readable for everyone
        return ['mode' => 0o100444];
    }
}
