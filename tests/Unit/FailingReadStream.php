<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

/**
 * Stream wrapper for a file that can be read only partly: one line arrives, then reading fails.
 *
 * With the host "stalls" the read does not fail, but brings no data. With the host "ends" the stream
 * answers the read after its line with false and reports its end, as some stream wrappers do at the end
 * of their data. With the host "closes" the stream reports its end only at the second time of asking
 * after the failed read. Otherwise the stream never reports the end.
 */
class FailingReadStream
{
    /** @var resource|null */
    public $context;

    private bool $delivered = false;

    private bool $ends = false;

    private int $endUnnoticed = -1;

    private bool $stalls = false;

    private bool $failed = false;

    public function stream_open(string $path): bool
    {
        $this->ends = parse_url($path, PHP_URL_HOST) === 'ends';
        $this->endUnnoticed = parse_url($path, PHP_URL_HOST) === 'closes' ? 1 : -1;
        $this->stalls = parse_url($path, PHP_URL_HOST) === 'stalls';

        return true;
    }

    public function stream_read(): string|false
    {
        if (!$this->delivered) {
            $this->delivered = true;

            return "TEST_KEY=value\n";
        }

        if ($this->stalls) {
            return '';
        }

        $this->failed = true;
        if (!$this->ends) {
            trigger_error('Read failed', E_USER_WARNING);
        }

        return false;
    }

    public function stream_eof(): bool
    {
        if ($this->failed && $this->endUnnoticed >= 0) {
            return $this->endUnnoticed-- <= 0;
        }

        return $this->ends && $this->failed;
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
