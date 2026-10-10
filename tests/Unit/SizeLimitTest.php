<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\FileNotReadableException;

/**
 * The largest file that is read: 1 MiB, counted while reading, whatever the file or the stream tells about its size.
 * A line is bounded by it, not by a limit of its own.
 */
class SizeLimitTest extends EnvLoaderTestCase
{
    private const LIMIT = 1_048_576;

    public function testFileOfTheLimitIsRead(): void
    {
        // One line as long as the file
        $value = str_repeat('a', self::LIMIT - strlen('TEST_KEY='));
        $path = $this->createEnvFile("TEST_KEY=$value");

        $this->assertSame(self::LIMIT, filesize($path));
        $this->assertSame(['TEST_KEY' => $value], EnvLoader::parse($path));
    }

    public function testFileLargerThanTheLimitThrows(): void
    {
        $path = $this->createEnvFile('TEST_KEY=' . str_repeat('a', self::LIMIT - strlen('TEST_KEY=') + 1));
        $_ENV = ['TEST_EXISTING' => 'original'];

        foreach ([false, true] as $load) {
            try {
                $load ? EnvLoader::load($path) : EnvLoader::parse($path);
                $this->fail('Expected FileNotReadableException');
            } catch (FileNotReadableException $e) {
                $this->assertSame("File too large: $path", $e->getMessage());
                $this->assertSame(['TEST_EXISTING' => 'original'], $_ENV);
            }
        }
    }

    public function testStreamThatTellsNoSizeIsCountedWhileItIsRead(): void
    {
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));

        try {
            $this->assertSame([], EnvLoader::parse("$scheme://large/" . self::LIMIT));

            $path = "$scheme://large/" . (self::LIMIT + 1);
            try {
                EnvLoader::parse($path);
                $this->fail('Expected FileNotReadableException');
            } catch (FileNotReadableException $e) {
                $this->assertSame("File too large: $path", $e->getMessage());
            }
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function testFileFarLargerThanTheMemoryLimitThrowsInsteadOfAFatalError(): void
    {
        // 12 MB, read with a memory limit of 8 MB: reading stops at the limit of the file, long before memory runs out.
        // A fatal error would end the process instead, its backtrace possibly showing the start of a line
        $path = $this->createEnvFile(str_repeat(str_repeat('#', 119) . "\n", 100_000) . 'TEST_KEY=value');

        $output = $this->parseInChildProcess($path, ['-d', 'memory_limit=8M']);

        $this->assertSame(FileNotReadableException::class, $output);
    }
}
