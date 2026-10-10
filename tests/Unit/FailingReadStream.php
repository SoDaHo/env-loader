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
 * - "locked": as "ends", but the stat shows no permission bits: is_readable() says no, although the file
 *             can be opened and read.
 * - "large":  the path is a number: the file is that many bytes of "#", delivered in reads of up to 8192 bytes,
 *             then it ends. The stream tells no size: only counting what is read finds out.
 * - "suspends": as "ends", but the first read suspends the fiber it runs in, as a wrapper on an event loop does.
 * - "throws": as "ends", but closing the stream throws instead of warning.
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

    private int $remaining = 0;

    /**
     * Takes the mode from the host of the URL and the pieces from its path.
     */
    public function stream_open(string $path): bool
    {
        $url = parse_url($path);
        $host = $url['host'] ?? '';
        $this->mode = in_array($host, ['breaks', 'ends', 'closes', 'locked', 'suspends', 'throws'], true) ? $host : 'fails';
        $this->endUnnoticed = $this->mode === 'closes' ? 2 : 0;

        $pieces = substr($url['path'] ?? '', 1);
        if ($host === 'large') {
            $this->mode = 'ends';
            $this->remaining = (int) $pieces;
            $pieces = '';
        }
        $this->pieces = match (true) {
            $host === 'env' => ["TEST_KEY=value\n"],
            $pieces === '' => [],
            default => array_map(rawurldecode(...), explode('/', $pieces)),
        };

        return true;
    }

    /**
     * The next piece; after the last one a failure, or no data, as the mode says.
     */
    public function stream_read(): string|false
    {
        if ($this->mode === 'suspends' && \Fiber::getCurrent() !== null) {
            $this->mode = 'ends';
            \Fiber::suspend();
        }

        if ($this->remaining > 0) {
            $length = min(8192, $this->remaining);
            $this->remaining -= $length;

            return str_repeat('#', $length);
        }

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

    /**
     * Whether the end is reported: after a failure only in the mode "breaks", otherwise once the pieces are read.
     */
    public function stream_eof(): bool
    {
        if ($this->mode === 'fails' || $this->mode === 'breaks') {
            return $this->mode === 'breaks' && $this->failed;
        }
        if ($this->pieces !== [] || $this->remaining > 0) {
            return false;
        }

        return $this->endUnnoticed-- <= 0;
    }

    /**
     * Closing warns, or throws in the mode "throws".
     */
    public function stream_close(): void
    {
        if ($this->mode === 'throws') {
            throw new \RuntimeException('Close failed');
        }

        trigger_error('Close failed', E_USER_WARNING);
    }

    /**
     * A regular file, readable for everyone - or, "locked", for nobody.
     *
     * @return array<string, int>
     */
    public function url_stat(string $path): array
    {
        return ['mode' => (parse_url($path, PHP_URL_HOST) === 'locked') ? 0o100000 : 0o100444];
    }
}
