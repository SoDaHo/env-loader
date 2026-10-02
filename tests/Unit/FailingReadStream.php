<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

/**
 * Stream wrapper for a file that arrives in pieces, and whose reading may fail.
 *
 * The host of the URL says what happens, the path holds the pieces (rawurlencoded, separated by "/"; an empty
 * piece is a read that brings no data without the file having ended):
 *
 * - "fails":  after the pieces, reading fails; the stream does not report its end.
 * - "breaks": as "fails", but the stream reports its end after the failure, as PHP does for a file on a disk
 *             that could not be read.
 * - "ends":   the file ends after the pieces.
 * - "closes": as "ends", but the end is reported only at the third time of asking, as by a connection that is
 *             closed while it is being read.
 *
 * "scheme://env" stands for "fails" after one whole line.
 */
class FailingReadStream
{
    /** @var resource|null */
    public $context;

    /** @var list<string> */
    private array $pieces = [];

    private string $mode = 'fails';

    private int $endUnnoticed = 0;

    private bool $failed = false;

    public function stream_open(string $path): bool
    {
        $url = parse_url($path);
        $host = $url['host'] ?? '';
        $this->mode = in_array($host, ['breaks', 'ends', 'closes'], true) ? $host : 'fails';
        $this->endUnnoticed = $this->mode === 'closes' ? 2 : 0;

        $pieces = substr($url['path'] ?? '', 1);
        $this->pieces = match (true) {
            $host === 'env' => ["TEST_KEY=value\n"],
            $pieces === '' => [],
            default => array_map(rawurldecode(...), explode('/', $pieces)),
        };

        return true;
    }

    public function stream_read(): string|false
    {
        if ($this->pieces !== []) {
            return array_shift($this->pieces);
        }

        if ($this->mode === 'fails' || $this->mode === 'breaks') {
            $this->failed = true;
            trigger_error('Read failed', E_USER_WARNING);

            return false;
        }

        return '';
    }

    public function stream_eof(): bool
    {
        if ($this->mode === 'fails' || $this->mode === 'breaks') {
            return $this->mode === 'breaks' && $this->failed;
        }
        if ($this->pieces !== []) {
            return false;
        }

        return $this->endUnnoticed-- <= 0;
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
