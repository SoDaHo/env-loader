<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\FileNotFoundException;
use Sodaho\EnvLoader\Exception\FileNotReadableException;
use Sodaho\EnvLoader\Exception\InvalidKeyException;

/**
 * Which paths are read, and how: missing and unreadable files, stream wrappers, reads that fail or stop, chunks,
 * memory.
 */
class FileTest extends EnvLoaderTestCase
{
    /**
     * @return array<string, array{string, bool}> The path ("directory": the directory of the test), through load() or parse()
     */
    public static function missingFileProvider(): array
    {
        return [
            'missing file, load()' => ['/nonexistent/path/.env', true],
            'missing file, parse()' => ['/nonexistent/path/.env', false],
            'directory, load()' => ['directory', true],
        ];
    }

    #[DataProvider('missingFileProvider')]
    public function testThrowsFileNotFoundException(string $path, bool $load): void
    {
        $path = $path === 'directory' ? $this->tempDir : $path;

        $this->expectException(FileNotFoundException::class);
        if ($load) {
            EnvLoader::load($path);
        } else {
            EnvLoader::parse($path);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noRegularFileProvider(): array
    {
        return ['device' => ['/dev/null'], 'FIFO' => ['fifo']];
    }

    #[DataProvider('noRegularFileProvider')]
    public function testPathThatIsNoRegularFileThrowsFileNotFoundException(string $name): void
    {
        // Only a regular file is read: a FIFO would block, a device has no end
        $path = $name === 'fifo' ? $this->tempDir . '/fifo' : $name;
        if ($name === 'fifo' && !(function_exists('posix_mkfifo') && posix_mkfifo($path, 0o600))) {
            $this->markTestSkipped('Cannot create a FIFO here');
        }
        if (!file_exists($path)) {
            $this->markTestSkipped("No $path here");
        }

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage("Not a file: $path");
        EnvLoader::parse($path);
    }

    public function testSymlinkToAFileIsRead(): void
    {
        $link = $this->tempDir . '/link.env';
        if (!@symlink($this->createEnvFile('TEST_KEY=value'), $link)) {
            $this->markTestSkipped('Cannot create a symbolic link here');
        }

        $this->assertSame(['TEST_KEY' => 'value'], EnvLoader::parse($link));
    }

    public function testThrowsFileNotReadableException(): void
    {
        $path = $this->createEnvFile('TEST_KEY=value');
        chmod($path, 0o000);

        // A privileged user (root in CI containers) reads the file anyway: read it as "nobody" instead.
        // Either way it is the opening of the file that fails, and the message is the same
        $privileged = @file_get_contents($path) !== false;
        $user = null;

        try {
            if ($privileged) {
                // Load the classes first: "nobody" may not be allowed to read the source files
                class_exists(EnvLoader::class);
                class_exists(FileNotReadableException::class);

                chmod($this->tempDir, 0o755);
                $nobody = function_exists('posix_getpwnam') ? posix_getpwnam('nobody') : false;
                if ($nobody === false) {
                    $this->markTestSkipped('File is readable despite chmod 000, and privileges cannot be dropped');
                }

                $user = posix_geteuid();
                if (!posix_seteuid($nobody['uid']) || !is_file($path)) {
                    $this->markTestSkipped('Cannot read the file as an unprivileged user');
                }
            }

            EnvLoader::load($path);
            $this->fail('Expected FileNotReadableException');
        } catch (FileNotReadableException $e) {
            $this->assertSame("Could not read file: $path", $e->getMessage());
        } finally {
            if ($user !== null) {
                $this->assertTrue(posix_seteuid($user), 'Privileges could not be restored');
            }
            chmod($path, 0o644); // Restore for cleanup
        }
    }

    public function testOpeningDecidesWhetherAFileCanBeRead(): void
    {
        // The stat of this stream shows no permission bits, so is_readable() says no; opening and reading work.
        // Asking is_readable() before opening would reject a file that can be read (and, under seteuid(), ask
        // for the wrong user)
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));
        $path = $scheme . '://locked/' . rawurlencode("TEST_KEY=value\n");

        try {
            $this->assertFalse(is_readable($path));
            $this->assertSame(['TEST_KEY' => 'value'], EnvLoader::parse($path, allowStreamWrappers: true));
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function testOpenBasedirRestrictionThrowsWithoutWarning(): void
    {
        $path = $this->createEnvFile('TEST_KEY=value');
        $root = dirname(__DIR__, 2);

        // open_basedir cannot be lifted again, so the restricted call runs in a child process
        $allowed = $root . '/src/' . PATH_SEPARATOR . $root . '/vendor/';
        $output = $this->parseInChildProcess($path, ['-d', 'open_basedir=' . $allowed]);

        $this->assertSame(FileNotFoundException::class, $output);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function partOfALineProvider(): array
    {
        return [
            'cut in the key' => ["TEST_A=1\nTEST_B"],
            'cut in the key after a CR' => ["TEST_A=1\rTEST_B"],
            'cut in a quoted value' => ["TEST_A=1\nTEST_B=\"hunter2"],
            'cut in an unquoted value' => ["TEST_A=1\nTEST_B=hunter2"],
            'cut in the first line' => ['TEST'],
        ];
    }

    #[DataProvider('partOfALineProvider')]
    public function testReadFailureInTheMiddleOfALineThrowsForTheFileNotForTheLine(string $part): void
    {
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));
        $path = $scheme . '://fails/' . rawurlencode($part);

        try {
            EnvLoader::parse($path, allowStreamWrappers: true);
            $this->fail('Expected FileNotReadableException');
        } catch (FileNotReadableException $e) {
            // The part that was read is no line: it must not be reported as malformed
            $this->assertSame("Could not read file: $path", $e->getMessage());
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    /**
     * @return array<string, array{list<string>, array<string, string>|null}>
     */
    public static function readWithoutDataProvider(): array
    {
        return [
            'in the key' => [['TEST_', '', "KEY=value\n"], null],
            'in the value' => [['TEST_KEY=val', '', 'ue'], null],
            'between two lines' => [["TEST_A=1\n", '', "TEST_KEY=value\n"], null],
            'before a block with several lines' => [['TEST_WITH_A_LONG_NAME=', '', "1\rTEST_B=2\rTEST_C=3\n"], null],
            'at the very beginning' => [['', "TEST_KEY=value\n"], null],
            'twice in one line' => [['TEST', '', '', "_KEY=value\n"], null],
            'between CR and LF' => [["TEST_A=1\r", '', "\nTEST_KEY=value"], null],
            // Here the file ended with the read that brought no data
            'after the last line' => [['TEST_KEY=value', ''], ['TEST_KEY' => 'value']],
        ];
    }

    /**
     * @param list<string> $pieces
     * @param array<string, string>|null $expected The values, or null where the file counts as not readable
     */
    #[DataProvider('readWithoutDataProvider')]
    public function testReadWithoutDataNeverLeadsToWrongValues(array $pieces, ?array $expected): void
    {
        // A read that brings no data although the file has not ended (a timeout, say) is not retried. The result
        // is the content of the file or an exception - never a part of it, never a key joined from two reads
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));
        $path = $scheme . '://ends/' . implode('/', array_map(rawurlencode(...), $pieces));

        try {
            $result = EnvLoader::parse($path, allowStreamWrappers: true);
        } catch (FileNotReadableException $e) {
            $this->assertSame("Could not read file: $path", $e->getMessage());
            $result = null;
        } finally {
            stream_wrapper_unregister($scheme);
        }

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function failedReadProvider(): array
    {
        return [
            'after a whole line' => ["TEST_A=1\n"],
            'in the middle of a line' => ["TEST_A=1\nTEST_B="],
            'before the first byte' => [''],
        ];
    }

    #[DataProvider('failedReadProvider')]
    public function testFailedReadThatLooksLikeTheEndOfTheFileThrows(string $delivered): void
    {
        // After a failed read PHP reports the end of the file, and so does this stream: the lines read
        // so far must not pass as the whole file
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));
        $path = $scheme . '://breaks/' . rawurlencode($delivered);

        try {
            $result = EnvLoader::parse($path, allowStreamWrappers: true);
        } catch (FileNotReadableException $e) {
            $result = $e->getMessage();
        } finally {
            stream_wrapper_unregister($scheme);
        }

        $this->assertSame("Could not read file: $path", $result);
    }

    public function testFailedReadOfARealFileThrows(): void
    {
        // On Linux this file can be opened, but reading it at offset 0 fails with an I/O error
        $path = '/proc/self/mem';
        if (!@is_file($path) || !is_readable($path)) {
            $this->markTestSkipped("No $path to read here");
        }

        $this->expectException(FileNotReadableException::class);
        $this->expectExceptionMessage("Could not read file: $path");
        EnvLoader::parse($path);
    }

    public function testLineEndingSplitBetweenTwoReadsCountsOnce(): void
    {
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));

        $path = $scheme . '://ends/' . rawurlencode("TEST_A=1\r") . '/' . rawurlencode("\nTEST-KEY=1\n");

        try {
            EnvLoader::parse($path, allowStreamWrappers: true);
            $this->fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            $this->assertSame("Invalid key in $path on line 2", $e->getMessage());
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function testEndOfFileIsDecidedWhereReadingStops(): void
    {
        // The last read brought no data and the stream did not report its end, so the file counts as not
        // read to its end. Asked again a moment later, the stream reports its end: that must not make the
        // lines read before pass as the whole file
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));
        $path = $scheme . '://closes/' . rawurlencode("TEST_A=1\nTEST_B=2") . '/';

        try {
            $result = EnvLoader::parse($path, allowStreamWrappers: true);
        } catch (FileNotReadableException $e) {
            $result = $e->getMessage();
        } finally {
            stream_wrapper_unregister($scheme);
        }

        $this->assertSame("Could not read file: $path", $result);
    }

    /**
     * @return array<string, array{string, array<string, string>, int}> Content, values, number of the line after it
     */
    public static function chunkBoundaryProvider(): array
    {
        // The file is read in chunks of 8192 bytes; the first line ends where a chunk does
        $comment = static fn (int $length): string => str_repeat('#', $length);
        $long = str_repeat('x', 20_000);

        return [
            'LF is the last byte of the chunk' => [$comment(8191) . "\nTEST_A=1\n", ['TEST_A' => '1'], 3],
            'LF is the first byte of the next chunk' => [$comment(8192) . "\nTEST_A=1\n", ['TEST_A' => '1'], 3],
            'CRLF split between two chunks' => [$comment(8191) . "\r\nTEST_A=1\r\n", ['TEST_A' => '1'], 3],
            'CR ends the chunk, no LF follows' => [$comment(8191) . "\rTEST_A=1\r", ['TEST_A' => '1'], 3],
            'CR ends the chunk and the file' => ['TEST_A=' . str_repeat('x', 8184) . "\r", ['TEST_A' => str_repeat('x', 8184)], 2],
            'CR ends the chunk, another CR follows' => [$comment(8191) . "\r\rTEST_A=1", ['TEST_A' => '1'], 4],
            'file is exactly one chunk' => ['TEST_A=' . str_repeat('x', 8185), ['TEST_A' => str_repeat('x', 8185)], 2],
            'line longer than two chunks' => ["TEST_A=$long\nTEST_B=\"$long\"\n", ['TEST_A' => $long, 'TEST_B' => $long], 3],
            'key split between two chunks' => [$comment(8188) . "\nTEST_A=1", ['TEST_A' => '1'], 3],
        ];
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('chunkBoundaryProvider')]
    public function testLinesAreTheSameWhereverAChunkEnds(string $content, array $expected, int $nextLine): void
    {
        $this->assertSame($expected, EnvLoader::parse($this->createEnvFile($content)));

        // The line after the content (one line further where the content has no final line ending)
        // has the number an editor would show
        $path = $this->createEnvFile($content . (preg_match('/[\r\n]$/', $content) === 1 ? '' : "\n") . 'TEST-KEY=1');

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            $this->assertSame("Invalid key in $path on line $nextLine", $e->getMessage());
        }
    }

    public function testStreamThatDeliversInPiecesIsRead(): void
    {
        // Pieces of any size are fine, as long as every read brings data until the file ends
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));
        $pieces = ['TEST_', "A=1\r", "\nTEST", "_B=2\nTEST_C=", '3'];
        $path = $scheme . '://ends/' . implode('/', array_map(rawurlencode(...), $pieces));

        try {
            $values = EnvLoader::parse($path, allowStreamWrappers: true);
        } finally {
            stream_wrapper_unregister($scheme);
        }

        $this->assertSame(['TEST_A' => '1', 'TEST_B' => '2', 'TEST_C' => '3'], $values);
    }

    public function testReadFailureAfterTheFirstLineThrows(): void
    {
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));

        try {
            EnvLoader::parse($scheme . '://env', allowStreamWrappers: true);
            $this->fail('Expected FileNotReadableException');
        } catch (FileNotReadableException $e) {
            $this->assertSame("Could not read file: $scheme://env", $e->getMessage());
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lineEndingProvider(): array
    {
        return ['LF' => ["\n"], 'CRLF' => ["\r\n"], 'CR' => ["\r"]];
    }

    #[DataProvider('lineEndingProvider')]
    public function testEmptyLinesCostNoMemory(string $lineEnding): void
    {
        $path = $this->createEnvFile(str_repeat($lineEnding, 400_000) . 'TEST_KEY=value');

        // An array of all lines would need more than the 8 MB the child process gets
        $output = $this->parseInChildProcess($path, ['-d', 'memory_limit=8M']);

        $this->assertSame('{"TEST_KEY":"value"}', $output);
    }

    #[DataProvider('lineEndingProvider')]
    public function testLineIsNotHeldOnceTheNextIsHandedOut(string $lineEnding): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // There PHP grows a large string in steps of 2 MB by copying it, which counts it twice for a moment
            $this->markTestSkipped('Windows counts a growing string twice');
        }

        // While the last line is parsed, the memory holds the value of the first line, the last line and its
        // value: three times the length of a line. Holding the first line as well would make it four
        $length = 8 * 1024 * 1024;
        // Written in pieces, so the test itself needs no more memory than one line
        $path = $this->createEnvFile('TEST_A=');
        $this->writeFile($path, str_repeat('a', $length), FILE_APPEND);
        $this->writeFile($path, $lineEnding . 'TEST_B=', FILE_APPEND);
        $this->writeFile($path, str_repeat('b', $length), FILE_APPEND);

        // Measured in a child process with a memory limit of its own
        $script = 'require $argv[1];'
            . 'class_exists(Sodaho\EnvLoader\EnvLoader::class);'
            . 'memory_reset_peak_usage();'
            . '$before = memory_get_usage();'
            . '$values = Sodaho\EnvLoader\EnvLoader::parse($argv[2]);'
            . 'echo json_encode([array_map("strlen", array_values($values)), memory_get_peak_usage() - $before]);';
        $output = $this->runInChildProcess(
            $script,
            $path,
            ['-d', 'max_memory_limit=-1', '-d', 'memory_limit=256M'],
            ['XDEBUG_MODE' => 'off']
        );
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);
        [$lengths, $peak] = $result;

        $this->assertSame([$length, $length], $lengths);
        $this->assertIsInt($peak);
        // The two values alone take twice the length: less would mean the measurement missed the parsing
        $this->assertGreaterThan(2 * $length, $peak);
        $this->assertLessThan(3.5 * $length, $peak);
    }

    #[DataProvider('lineEndingProvider')]
    public function testFileLargerThanTheMemoryLimitIsRead(string $lineEnding): void
    {
        // 12 MB of comment lines: read in chunks, none of it stays in memory
        $path = $this->createEnvFile(str_repeat(str_repeat('#', 119) . $lineEnding, 100_000) . 'TEST_KEY=value');

        $output = $this->parseInChildProcess($path, ['-d', 'memory_limit=8M']);

        $this->assertSame('{"TEST_KEY":"value"}', $output);
    }
}
