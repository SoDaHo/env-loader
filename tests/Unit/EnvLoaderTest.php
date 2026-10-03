<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\EnvLoaderException;
use Sodaho\EnvLoader\Exception\FileNotFoundException;
use Sodaho\EnvLoader\Exception\FileNotReadableException;
use Sodaho\EnvLoader\Exception\InvalidKeyException;
use Sodaho\EnvLoader\Exception\InvalidLineException;
use Sodaho\EnvLoader\Exception\InvalidValueException;
use Sodaho\EnvLoader\Exception\MissingRequiredKeyException;
use Sodaho\EnvLoader\Exception\TrailingCharactersException;
use Sodaho\EnvLoader\Exception\UnterminatedQuoteException;

class EnvLoaderTest extends TestCase
{
    private string $tempDir;

    /** @var array<mixed> */
    private array $envBackup;

    /** @var array<string> */
    private array $processVariables = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/env-loader-test-' . uniqid();
        mkdir($this->tempDir);
        $this->envBackup = $_ENV;
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->tempDir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($this->tempDir . '/' . $file);
            }
        }
        rmdir($this->tempDir);

        $_ENV = $this->envBackup;

        foreach ($this->processVariables as $key) {
            putenv($key);
        }
    }

    private function createEnvFile(string $content): string
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Sets a variable in the process environment (not in $_ENV) until the end of the test.
     */
    private function setProcessVariable(string $key, string $value): void
    {
        $this->assertFalse(getenv($key), "$key is already set in the process environment");

        $this->processVariables[] = $key;
        putenv("$key=$value");
        unset($_ENV[$key]);
    }

    /**
     * Arguments of the EnvLoader calls in the stack trace, and of everything they called, as text.
     */
    private function traceArguments(\Throwable $e): string
    {
        $trace = $e->getTrace();
        $outermost = 0;
        foreach ($trace as $index => $frame) {
            if (($frame['class'] ?? null) === EnvLoader::class) {
                $outermost = $index;
            }
        }

        return print_r(array_column(array_slice($trace, 0, $outermost + 1), 'args'), true);
    }

    // ============================================
    // Basic Parsing
    // ============================================

    public function testLoadsSimpleKeyValue(): void
    {
        $path = $this->createEnvFile('TEST_KEY=value');
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_KEY']);
    }

    public function testLoadsFileWithUtf8Bom(): void
    {
        // UTF-8 BOM: \xEF\xBB\xBF (common in Windows-created files)
        $path = $this->createEnvFile("\xEF\xBB\xBFTEST_BOM=value");
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_BOM']);
    }

    public function testLoadsMultipleKeyValues(): void
    {
        $path = $this->createEnvFile("TEST_ONE=first\nTEST_TWO=second");
        EnvLoader::load($path);

        $this->assertSame('first', $_ENV['TEST_ONE']);
        $this->assertSame('second', $_ENV['TEST_TWO']);
    }

    public function testLoadsEmptyValue(): void
    {
        $path = $this->createEnvFile('TEST_EMPTY=');
        EnvLoader::load($path);

        $this->assertSame('', $_ENV['TEST_EMPTY']);
    }

    public function testLoadsValueWithEqualsSign(): void
    {
        $path = $this->createEnvFile('TEST_PASSWORD=val=ue=with=equals');
        EnvLoader::load($path);

        $this->assertSame('val=ue=with=equals', $_ENV['TEST_PASSWORD']);
    }

    public function testUnderscoreStartKeyIsValid(): void
    {
        $path = $this->createEnvFile('_TEST_PRIVATE=secret');
        EnvLoader::load($path);

        $this->assertSame('secret', $_ENV['_TEST_PRIVATE']);
    }

    public function testStripsExportPrefix(): void
    {
        $path = $this->createEnvFile('export TEST_EXPORT=value');
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_EXPORT']);
    }

    public function testStripsExportPrefixWithQuotes(): void
    {
        $path = $this->createEnvFile('export TEST_EXPORT_Q="hello world"');
        EnvLoader::load($path);

        $this->assertSame('hello world', $_ENV['TEST_EXPORT_Q']);
    }

    // ============================================
    // Quotes
    // ============================================

    public function testLoadsDoubleQuotedValue(): void
    {
        $path = $this->createEnvFile('TEST_QUOTED="hello world"');
        EnvLoader::load($path);

        $this->assertSame('hello world', $_ENV['TEST_QUOTED']);
    }

    public function testLoadsSingleQuotedValue(): void
    {
        $path = $this->createEnvFile("TEST_SINGLE='hello world'");
        EnvLoader::load($path);

        $this->assertSame('hello world', $_ENV['TEST_SINGLE']);
    }

    public function testLoadsEscapedQuotes(): void
    {
        $path = $this->createEnvFile('TEST_ESCAPED="hello \"world\""');
        EnvLoader::load($path);

        $this->assertSame('hello "world"', $_ENV['TEST_ESCAPED']);
    }

    public function testLoadsEscapedBackslash(): void
    {
        $path = $this->createEnvFile('TEST_BACKSLASH="path\\\\"');
        EnvLoader::load($path);

        $this->assertSame('path\\', $_ENV['TEST_BACKSLASH']);
    }

    public function testSingleQuoteInDoubleQuotes(): void
    {
        $path = $this->createEnvFile('TEST_MIXED="it\'s ok"');
        EnvLoader::load($path);

        $this->assertSame("it's ok", $_ENV['TEST_MIXED']);
    }

    public function testDoubleQuoteInSingleQuotes(): void
    {
        $path = $this->createEnvFile("TEST_MIXED_REV='say \"hi\"'");
        EnvLoader::load($path);

        $this->assertSame('say "hi"', $_ENV['TEST_MIXED_REV']);
    }

    public function testWindowsPathPreserved(): void
    {
        $path = $this->createEnvFile('TEST_PATH="C:\\Users\\name\\docs"');
        EnvLoader::load($path);

        $this->assertSame('C:\Users\name\docs', $_ENV['TEST_PATH']);
    }

    public function testBackslashNPreservedLiterally(): void
    {
        $path = $this->createEnvFile('TEST_LITERAL="hello\\nworld"');
        EnvLoader::load($path);

        $this->assertSame('hello\nworld', $_ENV['TEST_LITERAL']);
    }

    public function testBackslashTPreservedLiterally(): void
    {
        $path = $this->createEnvFile('TEST_TAB="hello\\tworld"');
        EnvLoader::load($path);

        $this->assertSame('hello\tworld', $_ENV['TEST_TAB']);
    }

    public function testBackslashRPreservedLiterally(): void
    {
        $path = $this->createEnvFile('TEST_CR="hello\\rworld"');
        EnvLoader::load($path);

        $this->assertSame('hello\rworld', $_ENV['TEST_CR']);
    }

    // ============================================
    // Comments
    // ============================================

    public function testIgnoresCommentLines(): void
    {
        $path = $this->createEnvFile("# This is a comment\nTEST_KEY=value");
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_KEY']);
        $this->assertArrayNotHasKey('#', $_ENV);
    }

    public function testHandlesInlineComment(): void
    {
        $path = $this->createEnvFile('TEST_INLINE=value # this is a comment');
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_INLINE']);
    }

    public function testHashWithoutSpaceIsNotComment(): void
    {
        $path = $this->createEnvFile('TEST_HASH=value#notacomment');
        EnvLoader::load($path);

        $this->assertSame('value#notacomment', $_ENV['TEST_HASH']);
    }

    public function testInlineCommentWithQuotes(): void
    {
        $path = $this->createEnvFile('TEST_QUOTED_COMMENT="value with # hash" # comment');
        EnvLoader::load($path);

        $this->assertSame('value with # hash', $_ENV['TEST_QUOTED_COMMENT']);
    }

    // ============================================
    // Whitespace
    // ============================================

    public function testTrimsWhitespaceAroundKey(): void
    {
        $path = $this->createEnvFile('  TEST_SPACED  =value');
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_SPACED']);
    }

    public function testTrimsWhitespaceAroundValue(): void
    {
        $path = $this->createEnvFile('TEST_TRIM=  value  ');
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_TRIM']);
    }

    public function testIgnoresEmptyLines(): void
    {
        $path = $this->createEnvFile("\n\nTEST_EMPTY_LINES=value\n\n");
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_EMPTY_LINES']);
    }

    // ============================================
    // Options (overwrite, required)
    // ============================================

    public function testDoesNotOverwriteByDefault(): void
    {
        $_ENV['TEST_EXISTING'] = 'original';
        $path = $this->createEnvFile('TEST_EXISTING=new');
        EnvLoader::load($path);

        $this->assertSame('original', $_ENV['TEST_EXISTING']);
    }

    public function testOverwriteWhenEnabled(): void
    {
        $_ENV['TEST_OVERWRITE'] = 'original';
        $path = $this->createEnvFile('TEST_OVERWRITE=new');
        EnvLoader::load($path, overwrite: true);

        $this->assertSame('new', $_ENV['TEST_OVERWRITE']);
    }

    public function testRequiredKeysAsArray(): void
    {
        $path = $this->createEnvFile("TEST_REQ_ONE=one\nTEST_REQ_TWO=two");
        EnvLoader::load($path, required: ['TEST_REQ_ONE', 'TEST_REQ_TWO']);

        $this->assertSame('one', $_ENV['TEST_REQ_ONE']);
        $this->assertSame('two', $_ENV['TEST_REQ_TWO']);
    }

    public function testRequiredKeysAsString(): void
    {
        $path = $this->createEnvFile("TEST_STR_ONE=one\nTEST_STR_TWO=two");
        EnvLoader::load($path, required: 'TEST_STR_ONE,TEST_STR_TWO');

        $this->assertSame('one', $_ENV['TEST_STR_ONE']);
        $this->assertSame('two', $_ENV['TEST_STR_TWO']);
    }

    public function testRequiredKeysIgnoresTrailingComma(): void
    {
        $path = $this->createEnvFile("TEST_TRAIL_ONE=one\nTEST_TRAIL_TWO=two");
        EnvLoader::load($path, required: 'TEST_TRAIL_ONE,TEST_TRAIL_TWO,');

        $this->assertSame('one', $_ENV['TEST_TRAIL_ONE']);
        $this->assertSame('two', $_ENV['TEST_TRAIL_TWO']);
    }

    public function testRequiredKeysIgnoresEmptyEntries(): void
    {
        $path = $this->createEnvFile("TEST_EMPTY_ONE=one\nTEST_EMPTY_TWO=two");
        EnvLoader::load($path, required: 'TEST_EMPTY_ONE,,TEST_EMPTY_TWO');

        $this->assertSame('one', $_ENV['TEST_EMPTY_ONE']);
        $this->assertSame('two', $_ENV['TEST_EMPTY_TWO']);
    }

    public function testRequiredKeysEmptyStringIsNoOp(): void
    {
        $path = $this->createEnvFile('TEST_EMPTY_REQ=value');
        EnvLoader::load($path, required: '');

        $this->assertSame('value', $_ENV['TEST_EMPTY_REQ']);
    }

    // ============================================
    // Exceptions
    // ============================================

    public function testThrowsFileNotFoundException(): void
    {
        $this->expectException(FileNotFoundException::class);
        EnvLoader::load('/nonexistent/path/.env');
    }

    public function testThrowsFileNotFoundExceptionForDirectory(): void
    {
        $this->expectException(FileNotFoundException::class);
        EnvLoader::load($this->tempDir);
    }

    public function testThrowsInvalidKeyException(): void
    {
        $path = $this->createEnvFile('123INVALID=value');
        $this->expectException(InvalidKeyException::class);
        EnvLoader::load($path);
    }

    public function testThrowsInvalidKeyExceptionForHyphen(): void
    {
        $path = $this->createEnvFile('INVALID-KEY=value');
        $this->expectException(InvalidKeyException::class);
        EnvLoader::load($path);
    }

    public function testThrowsMissingRequiredKeyException(): void
    {
        $path = $this->createEnvFile('TEST_EXISTS=value');
        $this->expectException(MissingRequiredKeyException::class);
        EnvLoader::load($path, required: ['TEST_MISSING']);
    }

    public function testThrowsFileNotReadableException(): void
    {
        $path = $this->createEnvFile('TEST_KEY=value');
        chmod($path, 0o000);

        // A privileged user (root in CI containers) reads the file anyway: read it as "nobody" instead.
        // Then only the read itself fails: is_readable() checks the real user, not the effective one.
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
            $this->assertSame(($privileged ? 'Could not read file: ' : 'File not readable: ') . $path, $e->getMessage());
        } finally {
            if ($user !== null) {
                $this->assertTrue(posix_seteuid($user), 'Privileges could not be restored');
            }
            chmod($path, 0o644); // Restore for cleanup
        }
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
            EnvLoader::parse($path);
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
            $result = EnvLoader::parse($path);
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
            $result = EnvLoader::parse($path);
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
            EnvLoader::parse($path);
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
            $result = EnvLoader::parse($path);
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

        try {
            $values = EnvLoader::parse($scheme . '://ends/' . implode('/', array_map(rawurlencode(...), $pieces)));
        } finally {
            stream_wrapper_unregister($scheme);
        }

        $this->assertSame(['TEST_A' => '1', 'TEST_B' => '2', 'TEST_C' => '3'], $values);
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

    public function testReadFailureAfterTheFirstLineThrows(): void
    {
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));

        try {
            EnvLoader::parse($scheme . '://env');
            $this->fail('Expected FileNotReadableException');
        } catch (FileNotReadableException $e) {
            $this->assertSame("Could not read file: $scheme://env", $e->getMessage());
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function testSourceCallsNoRegexOrLocaleFunction(): void
    {
        // A warning raised inside a preg call (PCRE cannot allocate JIT memory on macOS or under SELinux) carries
        // its arguments into the stack trace of an error handler that throws. So the parser uses no PCRE at all,
        // and no ctype function either: what those call whitespace follows the locale.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/EnvLoader.php');
        preg_match_all(
            '/preg_\w+|mb_ereg\w*|mb_split|mb_regex\w*|RegexIterator|filter_var|sscanf|fnmatch|ctype_\w+|setlocale|use function/i',
            $source,
            $matches
        );

        $this->assertSame([], $matches[0]);
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
        file_put_contents($path, str_repeat('a', $length), FILE_APPEND);
        file_put_contents($path, $lineEnding . 'TEST_B=', FILE_APPEND);
        file_put_contents($path, str_repeat('b', $length), FILE_APPEND);

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

    /**
     * Runs parse() in a child process whose error handler reports what a framework would turn into an exception.
     *
     * @param list<string> $options
     *
     * @return string Warnings, then the parsed values as JSON or the class of the exception
     */
    private function parseInChildProcess(string $path, array $options): string
    {
        $script = 'require $argv[1];'
            . 'set_error_handler(function (int $level, string $message): bool {'
            . '    if ((error_reporting() & $level) !== 0) { echo "WARNING: $message\n"; }'
            . '    return true;'
            . '});'
            . 'try { echo json_encode(Sodaho\EnvLoader\EnvLoader::parse($argv[2])); }'
            . 'catch (Throwable $e) { echo $e::class; }';

        return $this->runInChildProcess($script, $path, $options);
    }

    /**
     * Runs a script in a child process; it gets the autoloader as $argv[1] and the path as $argv[2].
     *
     * The child always uses the memory manager of PHP: with USE_ZEND_ALLOC=0, memory_limit is not enforced
     * and memory_get_usage() counts nothing, so a test of memory would pass without testing anything. Nor
     * does it get ZEND_MM_DEBUG, whose checks copy a growing string and so count it twice.
     *
     * @param list<string> $options
     * @param array<string, string> $environment Set in the child in addition to the environment of this process
     *
     * @return string What the script wrote to stdout, then to stderr
     */
    private function runInChildProcess(string $script, string $path, array $options, array $environment = []): string
    {
        $process = proc_open(
            [PHP_BINARY, ...$options, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['USE_ZEND_ALLOC' => '1'] + $environment + array_diff_key(getenv(), ['ZEND_MM_DEBUG' => true])
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($process);

        return $output;
    }

    public function testThrowsUnterminatedDoubleQuoteException(): void
    {
        $path = $this->createEnvFile('TEST_KEY="unterminated');
        $this->expectException(UnterminatedQuoteException::class);
        EnvLoader::load($path);
    }

    public function testThrowsUnterminatedSingleQuoteException(): void
    {
        $path = $this->createEnvFile("TEST_KEY='unterminated");
        $this->expectException(UnterminatedQuoteException::class);
        EnvLoader::load($path);
    }

    // ============================================
    // parse() Method
    // ============================================

    public function testParseReturnsArrayWithoutSettingEnv(): void
    {
        $path = $this->createEnvFile("TEST_PARSE_ONE=one\nTEST_PARSE_TWO=two");
        $result = EnvLoader::parse($path);

        $this->assertSame(['TEST_PARSE_ONE' => 'one', 'TEST_PARSE_TWO' => 'two'], $result);
        $this->assertArrayNotHasKey('TEST_PARSE_ONE', $_ENV);
        $this->assertArrayNotHasKey('TEST_PARSE_TWO', $_ENV);
    }

    public function testParseReturnsEmptyArrayForEmptyFile(): void
    {
        $path = $this->createEnvFile('');
        $result = EnvLoader::parse($path);

        $this->assertSame([], $result);
    }

    public function testParseReturnsEmptyArrayForOnlyComments(): void
    {
        $path = $this->createEnvFile("# Comment one\n# Comment two");
        $result = EnvLoader::parse($path);

        $this->assertSame([], $result);
    }

    public function testParseThrowsFileNotFoundException(): void
    {
        $this->expectException(FileNotFoundException::class);
        EnvLoader::parse('/nonexistent/path/.env');
    }

    public function testParseThrowsInvalidKeyException(): void
    {
        $path = $this->createEnvFile('123INVALID=value');
        $this->expectException(InvalidKeyException::class);
        EnvLoader::parse($path);
    }

    public function testParseThrowsUnterminatedQuoteException(): void
    {
        $path = $this->createEnvFile('TEST_KEY="unterminated');
        $this->expectException(UnterminatedQuoteException::class);
        EnvLoader::parse($path);
    }

    // ============================================
    // Syntax Details
    // ============================================

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function syntaxProvider(): array
    {
        return [
            'empty value followed by comment' => ['TEST_A= # comment', ['TEST_A' => '']],
            'empty value, several spaces, comment' => ['TEST_A=   # comment', ['TEST_A' => '']],
            'empty value followed by bare hash' => ['TEST_A= #', ['TEST_A' => '']],
            'hash directly after equals is a value' => ['TEST_A=#fff', ['TEST_A' => '#fff']],
            'tab before hash starts a comment' => ["TEST_A=value\t# text", ['TEST_A' => 'value']],
            'empty value, tab, comment' => ["TEST_A=\t#fff", ['TEST_A' => '']],
            'hash after another character is a value' => ['TEST_A=a#b', ['TEST_A' => 'a#b']],
            'first space-hash starts the comment' => ['TEST_A=one # two # three', ['TEST_A' => 'one']],
            'tab-hash before space-hash' => ["TEST_A=one\t# two # three", ['TEST_A' => 'one']],
            'form feed before hash starts a comment' => ["TEST_A=one\f# two", ['TEST_A' => 'one']],
            'vertical tab before hash starts a comment' => ["TEST_A=one\v# two", ['TEST_A' => 'one']],
            'NUL before hash starts a comment' => ["TEST_A=one\0# two", ['TEST_A' => 'one']],
            'empty value, form feed, comment' => ["TEST_A=\f# comment", ['TEST_A' => '']],
            'hash after a letter, then hash after whitespace' => ['TEST_A=a#b #c', ['TEST_A' => 'a#b']],
            'value of a single hash' => ['TEST_A=#', ['TEST_A' => '#']],
            'space-hash before tab-hash' => ["TEST_A=one # two\t# three", ['TEST_A' => 'one']],
            'trailing space-hash' => ['TEST_A=value #', ['TEST_A' => 'value']],
            'comment line containing =' => ["# TEST_OFF=1\nTEST_A=1", ['TEST_A' => '1']],
            'indented comment line containing =' => ["   # TEST_OFF=1\nTEST_A=1", ['TEST_A' => '1']],
            'whitespace-only line' => ["TEST_A=1\n \t\v\f\0 \nTEST_B=2", ['TEST_A' => '1', 'TEST_B' => '2']],
            'comment line after a form feed' => ["\f# TEST_OFF=1\nTEST_A=1", ['TEST_A' => '1']],
            'form feed around key and value' => ["\fTEST_A\f=\fvalue\f", ['TEST_A' => 'value']],
            'form feed around quoted value' => ["TEST_A=\f\"value\"\f", ['TEST_A' => 'value']],
            'form feed between export and key named export' => ["export \f=1", ['export' => '1']],
            'comment directly after double quote' => ['TEST_A="v"#c', ['TEST_A' => 'v']],
            'comment after single quote' => ["TEST_A='v' # c", ['TEST_A' => 'v']],
            'any whitespace between quote and comment' => ["TEST_A=\"v\" \t\v\f# c", ['TEST_A' => 'v']],
            'whitespace after quote without comment' => ["TEST_A='v' \t\v\f\nTEST_B=1", ['TEST_A' => 'v', 'TEST_B' => '1']],
            'NUL at the end of the line is trimmed, also after a quote' => ["TEST_A=\"v\"\0\nTEST_B=v\0 \0", ['TEST_A' => 'v', 'TEST_B' => 'v']],
            'comment directly after single quote' => ["TEST_A='v'#c", ['TEST_A' => 'v']],
            'double quotes in comment after double-quoted value' => ['TEST_A="a" # "b"', ['TEST_A' => 'a']],
            'single quotes in comment after single-quoted value' => ["TEST_A='a' # 'b'", ['TEST_A' => 'a']],
            'empty double-quoted value' => ['TEST_A=""', ['TEST_A' => '']],
            'empty single-quoted value' => ["TEST_A=''", ['TEST_A' => '']],
            'quotes keep surrounding spaces' => ['TEST_A="  v  "', ['TEST_A' => '  v  ']],
            'whitespace before quoted value' => ["TEST_A= \t\"v\"", ['TEST_A' => 'v']],
            'quotes inside unquoted value' => ['TEST_A=ab"c\'d', ['TEST_A' => 'ab"c\'d']],
            'escaped backslash before closing quote' => ['TEST_A="a\\\\"', ['TEST_A' => 'a\\']],
            'backslash in single quotes' => ["TEST_A='a\\\\b\\\"'", ['TEST_A' => 'a\\\\b\\"']],
            'no variable expansion' => [
                "TEST_A=1\nTEST_B=\${TEST_A}/\$TEST_A\nTEST_C=\"\${TEST_A}\"",
                ['TEST_A' => '1', 'TEST_B' => '${TEST_A}/$TEST_A', 'TEST_C' => '${TEST_A}'],
            ],
            'escaped dollar stays literal' => ['TEST_A="pa\\$word"', ['TEST_A' => 'pa\\$word']],
            'duplicate key: last one wins' => ["TEST_A=1\nTEST_B=2\nTEST_A=3", ['TEST_A' => '3', 'TEST_B' => '2']],
            'CRLF line endings' => [
                "TEST_A=1\r\nTEST_B=\"x y\"\r\n\r\nTEST_C='z'\r\n",
                ['TEST_A' => '1', 'TEST_B' => 'x y', 'TEST_C' => 'z'],
            ],
            'BOM with CRLF' => ["\xEF\xBB\xBFTEST_A=1\r\nTEST_B=2\r\n", ['TEST_A' => '1', 'TEST_B' => '2']],
            'BOM after empty lines' => ["\n\r\n\r\xEF\xBB\xBFTEST_A=1\nTEST_B=2", ['TEST_A' => '1', 'TEST_B' => '2']],
            'CR line endings' => [
                "TEST_A=1\rTEST_B=\"x y\"\r\rTEST_C='z'\r",
                ['TEST_A' => '1', 'TEST_B' => 'x y', 'TEST_C' => 'z'],
            ],
            'CR without final line ending' => ["TEST_A=1\rTEST_B=2", ['TEST_A' => '1', 'TEST_B' => '2']],
            'mixed line endings' => [
                "TEST_A=1\rTEST_B=2\nTEST_C=3\r\nTEST_D=4\r\r\nTEST_E=5",
                ['TEST_A' => '1', 'TEST_B' => '2', 'TEST_C' => '3', 'TEST_D' => '4', 'TEST_E' => '5'],
            ],
            'BOM with CR' => ["\xEF\xBB\xBFTEST_A=1\rTEST_B=2\r", ['TEST_A' => '1', 'TEST_B' => '2']],
            'comment line ended by CR' => ["# TEST_OFF=1\rTEST_A=1", ['TEST_A' => '1']],
            'export followed by tab' => ["export\tTEST_A=1", ['TEST_A' => '1']],
            'export followed by several spaces' => ['export   TEST_A=1', ['TEST_A' => '1']],
            'key named export' => ['export=1', ['export' => '1']],
            'key named export with spaces around =' => ['export = 1', ['export' => '1']],
            'key named export with two spaces before =' => ['export  =1', ['export' => '1']],
            'key named export with tab and NUL before =' => ["export\t\0=1", ['export' => '1']],
            'key starting with export' => ['exportTEST=1', ['exportTEST' => '1']],
            'lowercase letters and digits in key' => ['test_a1=1', ['test_a1' => '1']],
        ];
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('syntaxProvider')]
    public function testParsesSyntax(string $content, array $expected): void
    {
        $this->assertSame($expected, EnvLoader::parse($this->createEnvFile($content)));
    }

    // In a separate process: setting a locale switches PCRE to other character tables for good
    #[RunInSeparateProcess]
    public function testWhitespaceAfterClosingQuoteDoesNotFollowTheLocale(): void
    {
        $path = $this->createEnvFile("TEST_A=\"v\"\xA0# comment");

        // On macOS, UTF-8 locales count the byte A0 as whitespace (1.x accepted it there)
        setlocale(LC_CTYPE, 'en_US.UTF-8', 'C.UTF-8');

        $this->expectException(TrailingCharactersException::class);
        EnvLoader::parse($path);
    }

    public function testLargeDoubleQuotedValue(): void
    {
        // Larger than the PCRE limits (8 KB with JIT, 50 KB without) that once made this fail
        $value = str_repeat('a', 200_000);
        $result = EnvLoader::parse($this->createEnvFile('TEST_BIG="' . $value . '" # comment'));

        $this->assertSame(['TEST_BIG' => $value], $result);
    }

    public function testLargeDoubleQuotedValueWithEscapes(): void
    {
        $path = $this->createEnvFile('TEST_BIG="' . str_repeat('\\"x\\\\\\n', 50_000) . '"');
        $result = EnvLoader::parse($path);

        $this->assertSame(['TEST_BIG' => str_repeat('"x\\\\n', 50_000)], $result);
    }

    public function testLargeSingleQuotedValue(): void
    {
        $value = str_repeat('a', 200_000);
        $result = EnvLoader::parse($this->createEnvFile("TEST_BIG='" . $value . "'"));

        $this->assertSame(['TEST_BIG' => $value], $result);
    }

    // ============================================
    // Error Messages
    // ============================================

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedQuoteProvider(): array
    {
        return [
            'unterminated double quote' => ['TEST_KEY="hunter2', 'Unterminated double quote'],
            'unterminated single quote' => ["TEST_KEY='hunter2", 'Unterminated single quote'],
            'backslash as last character' => ['TEST_KEY="hunter2\\', 'Unterminated double quote'],
            'escaped closing quote' => ['TEST_KEY="hunter2\\"', 'Unterminated double quote'],
            'multiline value' => ["TEST_KEY=\"hunter2\ntail\"", 'Unterminated double quote'],
            'CR inside double quotes' => ["TEST_KEY=\"hunter2\rtail\"", 'Unterminated double quote'],
            'CR inside single quotes' => ["TEST_KEY='hunter2\rtail'", 'Unterminated single quote'],
            'text after double quote' => ['TEST_KEY="hunter2" tail', 'Unexpected characters after closing double quote'],
            'text directly after double quote' => ['TEST_KEY="hunter2"tail', 'Unexpected characters after closing double quote'],
            'text after single quote' => ["TEST_KEY='hunter2' tail", 'Unexpected characters after closing single quote'],
            'doubled single quote' => ["TEST_KEY='hunter2''tail'", 'Unexpected characters after closing single quote'],
            'NUL after double quote' => ["TEST_KEY=\"hunter2\"\0# tail", 'Unexpected characters after closing double quote'],
            'non-breaking space after single quote' => ["TEST_KEY='hunter2'\xC2\xA0# tail", 'Unexpected characters after closing single quote'],
        ];
    }

    #[DataProvider('malformedQuoteProvider')]
    public function testMalformedQuoteNamesKeyAndLineButNeverTheValue(string $line, string $problem): void
    {
        // Blank and comment lines count: the reported line number is the one in the file
        $path = $this->createEnvFile("TEST_FIRST=1\n\n# comment\n" . $line);
        $traceArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            EnvLoader::parse($path);
            $this->fail('Expected UnterminatedQuoteException or TrailingCharactersException');
        } catch (UnterminatedQuoteException | TrailingCharactersException $e) {
            // An unterminated quote and text after a closing one are different errors
            $unterminated = str_starts_with($problem, 'Unterminated');
            $this->assertSame($unterminated ? UnterminatedQuoteException::class : TrailingCharactersException::class, $e::class);
            $this->assertSame("$problem for key \"TEST_KEY\" in $path on line 4", $e->getMessage());

            // The stack trace carries the arguments, but not those holding file content
            $arguments = $this->traceArguments($e);
            $this->assertStringContainsString($path, $arguments);
            $this->assertStringNotContainsString('hunter2', $arguments);
            $this->assertStringNotContainsString('tail', $arguments);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $traceArguments);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeyProvider(): array
    {
        return [
            'starts with a digit' => ['1TEST=hunter2'],
            'hyphen' => ['TEST-KEY=hunter2'],
            'space inside' => ['TEST KEY=hunter2'],
            'value in front of =' => ['TEST_KEY hunter2=x'],
            'empty key' => ['=hunter2'],
            'non-ASCII letter' => ['TEST_KÉY=hunter2'],
            'NUL inside' => ["TEST\0KEY=hunter2"],
            'BOM in a later line' => ["\xEF\xBB\xBFTEST_KEY=hunter2"],
            'invalid key with unterminated quote' => ['TEST-KEY="hunter2'],
        ];
    }

    #[DataProvider('invalidKeyProvider')]
    public function testInvalidKeyNamesOnlyTheLine(string $line): void
    {
        $path = $this->createEnvFile("TEST_FIRST=1\r\n\r\n" . $line);
        $traceArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            $this->assertSame("Invalid key in $path on line 3", $e->getMessage());

            $arguments = $this->traceArguments($e);
            $this->assertStringContainsString($path, $arguments);
            $this->assertStringNotContainsString('hunter2', $arguments);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $traceArguments);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lineWithoutEqualsProvider(): array
    {
        return [
            'key and value separated by a space' => ['TEST_KEY hunter2'],
            'key alone' => ['hunter2'],
            'export without assignment' => ['export hunter2'],
            'export alone' => ['export'],
            'quoted text' => ['"hunter2"'],
            'colon instead of equals' => ['TEST_KEY: hunter2'],
            'second half of a line broken by CR' => ["TEST_KEY=1\rhunter2"],
        ];
    }

    #[DataProvider('lineWithoutEqualsProvider')]
    public function testLineWithoutEqualsThrowsAndNamesOnlyTheLine(string $line): void
    {
        $path = $this->createEnvFile("TEST_FIRST=1\r\n\r\n" . $line . "\nTEST_LAST=1");
        $lineNumber = 3 + substr_count($line, "\r");
        $traceArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidLineException');
        } catch (InvalidLineException $e) {
            $this->assertSame("Missing \"=\" in $path on line $lineNumber", $e->getMessage());

            $arguments = $this->traceArguments($e);
            $this->assertStringContainsString($path, $arguments);
            $this->assertStringNotContainsString('hunter2', $arguments);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $traceArguments);
        }
    }

    public function testLineWithoutEqualsLeavesEnvUntouched(): void
    {
        $_ENV = ['TEST_EXISTING' => 'original'];
        $path = $this->createEnvFile("TEST_OTHER=value\nTEST_FORGOTTEN value");

        try {
            EnvLoader::load($path, overwrite: true);
            $this->fail('Expected InvalidLineException');
        } catch (InvalidLineException) {
            $this->assertSame(['TEST_EXISTING' => 'original'], $_ENV);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function lineNumberProvider(): array
    {
        return [
            'LF' => ["TEST_A=1\n\n# c\n", 4],
            'CRLF' => ["TEST_A=1\r\n\r\n# c\r\n", 4],
            'CR' => ["TEST_A=1\r\r# c\r", 4],
            'CR followed by CRLF are two line endings' => ["TEST_A=1\r\r\n", 3],
            'LF followed by CR are two line endings' => ["TEST_A=1\n\r", 3],
            'mixed' => ["TEST_A=1\r# c\n\r\nTEST_B=2\r", 5],
        ];
    }

    #[DataProvider('lineNumberProvider')]
    public function testEveryLineEndingCountsOnce(string $before, int $lineNumber): void
    {
        $path = $this->createEnvFile($before . 'TEST-KEY=value');

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            $this->assertSame("Invalid key in $path on line $lineNumber", $e->getMessage());
        }
    }

    public function testLineEndingsDoNotDependOnAutoDetectLineEndings(): void
    {
        // With the deprecated setting, PHP ends the blocks of a file whose first line ending is a CR at each CR:
        // a LF is then no line ending for fgets()
        $before = @ini_set('auto_detect_line_endings', '1');
        if ($before === false) {
            $this->markTestSkipped('This PHP has no auto_detect_line_endings');
        }

        try {
            $values = EnvLoader::parse($this->createEnvFile("TEST_A=1\rTEST_B=2\nTEST_C=3\r\nTEST_D=4\r\r\nTEST_E=5\r\n"));
            $this->assertSame(['TEST_A' => '1', 'TEST_B' => '2', 'TEST_C' => '3', 'TEST_D' => '4', 'TEST_E' => '5'], $values);

            $path = $this->createEnvFile("TEST_A=1\rTEST_B=2\n# c\r\nTEST_D=4\r\r\nTEST-KEY=5\r");

            try {
                EnvLoader::parse($path);
                $this->fail('Expected InvalidKeyException');
            } catch (InvalidKeyException $e) {
                $this->assertSame("Invalid key in $path on line 6", $e->getMessage());
            }
        } finally {
            @ini_set('auto_detect_line_endings', $before);
        }
    }

    public function testExceptionsForTheContentOfTheFileAreSiblings(): void
    {
        // Each can be caught on its own; all of them as EnvLoaderException
        foreach ([InvalidLineException::class, InvalidKeyException::class, UnterminatedQuoteException::class, TrailingCharactersException::class] as $class) {
            $this->assertSame(EnvLoaderException::class, get_parent_class($class));
        }
    }

    public function testBomDoesNotShiftLineNumber(): void
    {
        $path = $this->createEnvFile("\xEF\xBB\xBFTEST-KEY=value");

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            $this->assertSame("Invalid key in $path on line 1", $e->getMessage());
        }
    }

    // ============================================
    // load(): Guarantees
    // ============================================

    public function testLoadWritesOnlyToEnv(): void
    {
        $processEnvironment = getenv();
        $server = $_SERVER;
        $path = $this->createEnvFile('TEST_ONLY_ENV=value');

        EnvLoader::load($path, overwrite: true);

        $this->assertSame('value', $_ENV['TEST_ONLY_ENV']);
        $this->assertSame($processEnvironment, getenv());
        $this->assertSame($server, $_SERVER);
    }

    public function testLoadWritesOnlyToEnvWhenTheProcessEnvironmentWins(): void
    {
        $this->setProcessVariable('TEST_ONLY_ENV', 'process');
        $processEnvironment = getenv();
        $server = $_SERVER;
        $path = $this->createEnvFile("TEST_ONLY_ENV=file\nTEST_OTHER=value");

        EnvLoader::load($path, required: ['TEST_ONLY_ENV']);

        $this->assertSame('process', $_ENV['TEST_ONLY_ENV']);
        $this->assertSame($processEnvironment, getenv());
        $this->assertSame($server, $_SERVER);
    }

    public function testLoadReturnsTheValuesOfTheFile(): void
    {
        $path = $this->createEnvFile("TEST_FIRST=one\nTEST_SECOND=\"two\"");

        $this->assertSame(['TEST_FIRST' => 'one', 'TEST_SECOND' => 'two'], EnvLoader::load($path));
    }

    public function testLoadReturnsTheValuesOfTheFileAlsoWhereTheEnvironmentWins(): void
    {
        $_ENV['TEST_IN_ENV'] = 'env';
        $this->setProcessVariable('TEST_IN_PROCESS', 'process');
        $this->setProcessVariable('TEST_REQUIRED_ONLY', 'process');
        $path = $this->createEnvFile("TEST_IN_ENV=file\nTEST_IN_PROCESS=\nTEST_FILE_ONLY=file");

        $values = EnvLoader::load($path, required: ['TEST_REQUIRED_ONLY']);

        $this->assertSame(['TEST_IN_ENV' => 'file', 'TEST_IN_PROCESS' => '', 'TEST_FILE_ONLY' => 'file'], $values);
        $this->assertSame($values, EnvLoader::parse($path));
    }

    public function testLoadReturnsTheValuesOfTheFileWithOverwrite(): void
    {
        $_ENV['TEST_IN_ENV'] = 'env';
        $path = $this->createEnvFile('TEST_IN_ENV=file');

        $this->assertSame(['TEST_IN_ENV' => 'file'], EnvLoader::load($path, overwrite: true));
    }

    public function testLoadOfEmptyFileReturnsEmptyArray(): void
    {
        $this->assertSame([], EnvLoader::load($this->createEnvFile('')));
    }

    public function testClassIsFinal(): void
    {
        $this->assertTrue(new \ReflectionClass(EnvLoader::class)->isFinal());
    }

    // ============================================
    // load(): Environment wins over the file
    // ============================================

    public function testProcessEnvironmentWinsOverTheFile(): void
    {
        $this->setProcessVariable('TEST_PROCESS', 'process');
        EnvLoader::load($this->createEnvFile('TEST_PROCESS=file'));

        $this->assertSame('process', $_ENV['TEST_PROCESS']);
    }

    public function testEmptyProcessVariableWinsOverTheFile(): void
    {
        $this->setProcessVariable('TEST_PROCESS_EMPTY', '');
        EnvLoader::load($this->createEnvFile('TEST_PROCESS_EMPTY=file'));

        $this->assertSame('', $_ENV['TEST_PROCESS_EMPTY']);
    }

    public function testEnvWinsOverTheProcessEnvironment(): void
    {
        $this->setProcessVariable('TEST_BOTH', 'process');
        $_ENV['TEST_BOTH'] = 'env';
        EnvLoader::load($this->createEnvFile('TEST_BOTH=file'));

        $this->assertSame('env', $_ENV['TEST_BOTH']);
    }

    public function testValueInEnvIsKeptAsItIs(): void
    {
        $this->setProcessVariable('TEST_NOT_A_STRING', 'process');
        $this->setProcessVariable('TEST_REQUIRED', 'process');
        $path = $this->createEnvFile('TEST_NOT_A_STRING=file');

        foreach ([42, false, null] as $value) {
            $_ENV = ['TEST_NOT_A_STRING' => $value, 'TEST_REQUIRED' => $value];
            EnvLoader::load($path, required: ['TEST_REQUIRED']);

            $this->assertSame(['TEST_NOT_A_STRING' => $value, 'TEST_REQUIRED' => $value], $_ENV);
        }
    }

    public function testRequiredKeyInEnvCountsWhateverItsValue(): void
    {
        $path = $this->createEnvFile('TEST_FROM_FILE=value');

        foreach ([42, false, null] as $value) {
            $_ENV = ['TEST_REQUIRED' => $value];
            EnvLoader::load($path, required: ['TEST_REQUIRED']);

            $this->assertSame(['TEST_REQUIRED' => $value, 'TEST_FROM_FILE' => 'value'], $_ENV);
        }
    }

    public function testServerSuperglobalIsNotRead(): void
    {
        $_SERVER['TEST_IN_SERVER'] = 'server';
        $path = $this->createEnvFile('TEST_IN_SERVER=file');

        try {
            EnvLoader::load($path);
            $this->assertSame('file', $_ENV['TEST_IN_SERVER']);

            unset($_ENV['TEST_IN_SERVER']);
            $this->expectException(MissingRequiredKeyException::class);
            EnvLoader::load($this->createEnvFile('TEST_OTHER=value'), required: ['TEST_IN_SERVER']);
        } finally {
            unset($_SERVER['TEST_IN_SERVER']);
        }
    }

    public function testFileIsUsedWhereTheEnvironmentHasNoValue(): void
    {
        $this->setProcessVariable('TEST_PROCESS', 'process');
        EnvLoader::load($this->createEnvFile("TEST_PROCESS=file\nTEST_FILE_ONLY=file"));

        $this->assertSame('file', $_ENV['TEST_FILE_ONLY']);
    }

    public function testOverwriteWinsOverTheProcessEnvironment(): void
    {
        $this->setProcessVariable('TEST_PROCESS', 'process');
        EnvLoader::load($this->createEnvFile('TEST_PROCESS=file'), overwrite: true);

        $this->assertSame('file', $_ENV['TEST_PROCESS']);
        $this->assertSame('process', getenv('TEST_PROCESS'));
    }

    public function testOnlyKeysOfTheFileAreCopiedFromTheProcessEnvironment(): void
    {
        $this->setProcessVariable('TEST_UNRELATED', 'process');
        $this->setProcessVariable('TEST_PROCESS', 'process');
        $_ENV = [];
        EnvLoader::load($this->createEnvFile('TEST_PROCESS=file'));

        $this->assertSame(['TEST_PROCESS' => 'process'], $_ENV);
    }

    public function testRequiredKeyMayComeFromTheProcessEnvironment(): void
    {
        $this->setProcessVariable('TEST_REQUIRED', 'process');
        $_ENV = [];
        EnvLoader::load($this->createEnvFile('TEST_FROM_FILE=value'), required: ['TEST_REQUIRED', 'TEST_FROM_FILE']);

        $this->assertSame(['TEST_FROM_FILE' => 'value', 'TEST_REQUIRED' => 'process'], $_ENV);
    }

    public function testNumericRequiredKeyFromTheProcessEnvironmentKeepsItsName(): void
    {
        // PHP turns the array key "123" into the integer 123; it must not be renumbered
        $this->setProcessVariable('123', 'process');
        $_ENV = ['first'];
        EnvLoader::load($this->createEnvFile('TEST_FROM_FILE=value'), required: ['123']);

        $this->assertSame([0 => 'first', 'TEST_FROM_FILE' => 'value', 123 => 'process'], $_ENV);
    }

    public function testRequiredKeyFromTheProcessEnvironmentMayBeEmpty(): void
    {
        $this->setProcessVariable('TEST_REQUIRED', '');
        EnvLoader::load($this->createEnvFile('TEST_FROM_FILE=value'), required: 'TEST_REQUIRED');

        $this->assertSame('', $_ENV['TEST_REQUIRED']);
    }

    public function testRequiredKeyFromTheProcessEnvironmentIsCopiedWithOverwrite(): void
    {
        $this->setProcessVariable('TEST_REQUIRED', 'process');
        EnvLoader::load($this->createEnvFile('TEST_FROM_FILE=value'), overwrite: true, required: ['TEST_REQUIRED']);

        $this->assertSame('process', $_ENV['TEST_REQUIRED']);
    }

    public function testRequiredKeyInEnvIsNotReplacedByTheProcessEnvironment(): void
    {
        $this->setProcessVariable('TEST_REQUIRED', 'process');
        $_ENV['TEST_REQUIRED'] = 'env';
        EnvLoader::load($this->createEnvFile('TEST_FROM_FILE=value'), required: ['TEST_REQUIRED']);

        $this->assertSame('env', $_ENV['TEST_REQUIRED']);
    }

    public function testRequiredKeyOfTheFileIsNotReplacedByTheProcessEnvironmentWithOverwrite(): void
    {
        $this->setProcessVariable('TEST_REQUIRED', 'process');
        EnvLoader::load($this->createEnvFile('TEST_REQUIRED=file'), overwrite: true, required: ['TEST_REQUIRED']);

        $this->assertSame('file', $_ENV['TEST_REQUIRED']);
    }

    public function testMissingRequiredKeyLeavesEnvUntouchedAfterAKeyFromTheProcessEnvironment(): void
    {
        $this->setProcessVariable('TEST_REQUIRED', 'process');
        $_ENV = ['TEST_EXISTING' => 'original'];
        $path = $this->createEnvFile('TEST_OTHER=value');

        try {
            EnvLoader::load($path, required: ['TEST_REQUIRED', 'TEST_MISSING']);
            $this->fail('Expected MissingRequiredKeyException');
        } catch (MissingRequiredKeyException $e) {
            $this->assertSame('Missing required key: TEST_MISSING', $e->getMessage());
            $this->assertSame(['TEST_EXISTING' => 'original'], $_ENV);
        }
    }

    public function testRequestDoesNotWinOverTheFile(): void
    {
        $binary = PhpCgi::binary();
        if ($binary === null) {
            $this->markTestSkipped('No php-cgi next to ' . PHP_BINARY);
        }

        // Under FastCGI, getenv() returns request headers (HTTP_*) and the parameters of the web server
        $path = $this->createEnvFile("HTTP_X_TEST=file\nTEST_PARAM=file");
        $script = $this->tempDir . '/request.php';
        file_put_contents(
            $script,
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$_ENV = [];'
            . 'Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ', required: ["TEST_REQUIRED"]);'
            . 'echo json_encode([$_ENV, getenv("HTTP_X_TEST"), getenv("TEST_PARAM"), getenv("TEST_REQUIRED_PARAM")]);'
        );
        $this->setProcessVariable('TEST_REQUIRED', 'process');

        // Web servers send GATEWAY_INTERFACE as a parameter as well; that is not CGI and must not switch off the process environment
        $response = PhpCgi::fastCgiRequest($binary, $script, [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'HTTP_X_TEST' => 'header',
            'TEST_PARAM' => 'param',
            'TEST_REQUIRED_PARAM' => 'param',
        ]);

        $this->assertSame(
            [['HTTP_X_TEST' => 'file', 'TEST_PARAM' => 'file', 'TEST_REQUIRED' => 'process'], 'header', 'param', 'param'],
            json_decode($response, true)
        );
    }

    public function testRequiredKeyFromARequestIsMissing(): void
    {
        $binary = PhpCgi::binary();
        if ($binary === null) {
            $this->markTestSkipped('No php-cgi next to ' . PHP_BINARY);
        }

        $path = $this->createEnvFile('TEST_OTHER=value');
        $script = $this->tempDir . '/request.php';
        file_put_contents(
            $script,
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$_ENV = [];'
            . 'try { Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ', required: ["HTTP_X_TEST"]); }'
            . 'catch (Throwable $e) { echo json_encode([$e::class, $e->getMessage(), getenv("HTTP_X_TEST"), $_ENV]); }'
        );

        $response = PhpCgi::fastCgiRequest($binary, $script, ['HTTP_X_TEST' => 'header']);

        $this->assertSame(
            [MissingRequiredKeyException::class, 'Missing required key: HTTP_X_TEST', 'header', []],
            json_decode($response, true)
        );
    }

    public function testCgiRequestDoesNotWinOverTheFile(): void
    {
        $binary = PhpCgi::binary();
        if ($binary === null) {
            $this->markTestSkipped('No php-cgi next to ' . PHP_BINARY);
        }

        // Under plain CGI the request is the environment of the process: local_only does not tell them apart
        $path = $this->createEnvFile("HTTP_X_TEST=file\nCONTENT_TYPE=file");
        $script = $this->tempDir . '/request.php';
        file_put_contents(
            $script,
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$_ENV = [];'
            . 'Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ');'
            . 'try { Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ', required: ["HTTP_X_REQUIRED"]); }'
            . 'catch (Throwable $e) { $error = $e::class; }'
            . 'echo json_encode([$_ENV, $error ?? null, getenv("HTTP_X_TEST", true), getenv("HTTP_X_REQUIRED", true)]);'
        );

        $response = PhpCgi::cgiRequest($binary, $script, [
            'HTTP_X_TEST' => 'header',
            'CONTENT_TYPE' => 'header',
            'HTTP_X_REQUIRED' => 'header',
        ]);

        $this->assertSame(
            [['HTTP_X_TEST' => 'file', 'CONTENT_TYPE' => 'file'], MissingRequiredKeyException::class, 'header', 'header'],
            json_decode($response, true)
        );
    }

    public function testProcessEnvironmentIsNotReadWhereItIsACgiRequest(): void
    {
        // The same without php-cgi: CGI sets GATEWAY_INTERFACE for every request
        $this->setProcessVariable('GATEWAY_INTERFACE', 'CGI/1.1');
        $this->setProcessVariable('TEST_PROCESS', 'process');
        $path = $this->createEnvFile('TEST_PROCESS=file');
        $_ENV = [];

        EnvLoader::load($path);
        $this->assertSame(['TEST_PROCESS' => 'file'], $_ENV);

        $_ENV = [];
        $this->expectException(MissingRequiredKeyException::class);
        EnvLoader::load($this->createEnvFile('TEST_OTHER=value'), required: ['TEST_PROCESS']);
    }

    public function testGatewayInterfaceOfARequestDoesNotSwitchOffTheProcessEnvironment(): void
    {
        // Under FastCGI, GATEWAY_INTERFACE is a parameter of the request ($_SERVER), not a variable of the process
        $this->setProcessVariable('TEST_PROCESS', 'process');
        $_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';

        try {
            EnvLoader::load($this->createEnvFile('TEST_PROCESS=file'));
        } finally {
            unset($_SERVER['GATEWAY_INTERFACE']);
        }

        $this->assertSame('process', $_ENV['TEST_PROCESS']);
    }

    public function testProcessEnvironmentIsNotReadWhereGetenvIsDisabled(): void
    {
        $path = $this->createEnvFile('TEST_PROCESS=file');
        $script = 'require $argv[1];'
            . 'Sodaho\EnvLoader\EnvLoader::load($argv[2]);'
            . 'echo json_encode([function_exists("getenv"), $_ENV["TEST_PROCESS"]]);'
            . 'try { Sodaho\EnvLoader\EnvLoader::load($argv[2], required: ["TEST_REQUIRED"]); }'
            . 'catch (Throwable $e) { echo $e::class; }';
        $process = proc_open(
            [
                PHP_BINARY, '-d', 'disable_functions=getenv', '-d', 'variables_order=GPCS', '-d', 'display_errors=1',
                '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $path,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['TEST_PROCESS' => 'process', 'TEST_REQUIRED' => 'process'] + getenv()
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($process);

        // Without getenv() only $_ENV and the file count, as in 1.x
        $this->assertSame('[false,"file"]' . MissingRequiredKeyException::class, $output);
    }

    public function testEveryGetenvCallAsksOnlyTheProcess(): void
    {
        // A syntactic pin for runs without php-cgi: no getenv() call without local_only
        $code = '';
        foreach (\PhpToken::tokenize((string) file_get_contents(dirname(__DIR__, 2) . '/src/EnvLoader.php')) as $token) {
            $code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text;
        }
        preg_match_all('/getenv\s*\([^()]*\)/', $code, $matches);

        $this->assertSame(["getenv('GATEWAY_INTERFACE', true)", 'getenv($key, true)'], $matches[0]);
    }

    public function testRequiredKeyThatIsNoVariableNameIsMissing(): void
    {
        // getenv() must not see such a name: up to PHP 8.5 it cuts the name at the NUL byte and finds TEST_ODD
        // (from 8.6 it throws a ValueError), and with "=" some systems find TEST_ODD as well
        $this->setProcessVariable('TEST_ODD', 'KEY=value');
        $path = $this->createEnvFile('TEST_OTHER=value');

        foreach (['TEST_ODD=KEY', "TEST_ODD\0KEY"] as $key) {
            try {
                EnvLoader::load($path, required: [$key]);
                $this->fail('Expected MissingRequiredKeyException');
            } catch (MissingRequiredKeyException $e) {
                $this->assertSame("Missing required key: $key", $e->getMessage());
            }
        }
    }

    public function testParseLeavesEnvUntouched(): void
    {
        $_ENV = ['TEST_EXISTING' => 'original'];
        $processEnvironment = getenv();
        $server = $_SERVER;

        EnvLoader::parse($this->createEnvFile("TEST_EXISTING=new\nTEST_OTHER=value"));

        $this->assertSame(['TEST_EXISTING' => 'original'], $_ENV);
        $this->assertSame($processEnvironment, getenv());
        $this->assertSame($server, $_SERVER);
    }

    public function testDoesNotOverwriteExistingEmptyValue(): void
    {
        $_ENV['TEST_EXISTING_EMPTY'] = '';
        EnvLoader::load($this->createEnvFile('TEST_EXISTING_EMPTY=new'));

        $this->assertSame('', $_ENV['TEST_EXISTING_EMPTY']);
    }

    public function testMissingRequiredKeyLeavesEnvUntouched(): void
    {
        $_ENV = ['TEST_EXISTING' => 'original'];
        $path = $this->createEnvFile("TEST_EXISTING=new\nTEST_OTHER=value");

        try {
            EnvLoader::load($path, overwrite: true, required: ['TEST_OTHER', 'TEST_MISSING']);
            $this->fail('Expected MissingRequiredKeyException');
        } catch (MissingRequiredKeyException $e) {
            $this->assertSame('Missing required key: TEST_MISSING', $e->getMessage());
            $this->assertSame(['TEST_EXISTING' => 'original'], $_ENV);
        }
    }

    public function testParseErrorLeavesEnvUntouched(): void
    {
        $_ENV = ['TEST_EXISTING' => 'original'];
        $path = $this->createEnvFile("TEST_OTHER=value\nTEST_BROKEN=\"unterminated");

        try {
            EnvLoader::load($path, overwrite: true);
            $this->fail('Expected UnterminatedQuoteException');
        } catch (UnterminatedQuoteException) {
            $this->assertSame(['TEST_EXISTING' => 'original'], $_ENV);
        }
    }

    public function testRequiredKeyMayComeFromExistingEnv(): void
    {
        $_ENV['TEST_FROM_ENV'] = 'preset';
        $path = $this->createEnvFile('TEST_FROM_FILE=value');
        EnvLoader::load($path, required: ['TEST_FROM_ENV', 'TEST_FROM_FILE']);

        $this->assertSame('value', $_ENV['TEST_FROM_FILE']);
    }

    public function testRequiredKeyMayBeEmpty(): void
    {
        $path = $this->createEnvFile('TEST_EMPTY_REQUIRED=');
        EnvLoader::load($path, required: ['TEST_EMPTY_REQUIRED']);

        $this->assertSame('', $_ENV['TEST_EMPTY_REQUIRED']);
    }

    public function testRequiredKeysAsStringAreTrimmed(): void
    {
        $path = $this->createEnvFile("TEST_SPACE_ONE=one\nTEST_SPACE_TWO=two");
        EnvLoader::load($path, required: ' TEST_SPACE_ONE , TEST_SPACE_TWO ');

        $this->assertSame('two', $_ENV['TEST_SPACE_TWO']);
    }

    public function testRequiredKeysAsArrayAreTrimmedAndEmptyEntriesIgnored(): void
    {
        $path = $this->createEnvFile("TEST_ARR_ONE=one\nTEST_ARR_TWO=two");
        EnvLoader::load($path, required: [' TEST_ARR_ONE ', '', 'TEST_ARR_TWO']);

        $this->assertSame('two', $_ENV['TEST_ARR_TWO']);
    }

    public function testRequiredKeysAreTrimmedLikeTheFile(): void
    {
        // The form feed is whitespace in every PHP version (the default of trim() includes it from 8.6);
        // line breaks come with a list that is written over several lines
        $path = $this->createEnvFile("TEST_FORM_FEED=value\nTEST_NEXT_LINE=value");
        EnvLoader::load($path, required: "\fTEST_FORM_FEED\f,\r\n\tTEST_NEXT_LINE\n,\f");

        $this->assertSame('value', $_ENV['TEST_NEXT_LINE']);

        $this->expectExceptionMessage('Missing required key: TEST_MISSING');
        EnvLoader::load($path, required: ["\nTEST_FORM_FEED", "TEST_MISSING\r"]);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function looseRequiredProvider(): array
    {
        return [
            'null' => [['TEST_KEY', null]],
            'integer' => [['TEST_KEY', 123]],
            'false' => [[false, 'TEST_KEY']],
        ];
    }

    /**
     * @param array<string> $required
     */
    #[DataProvider('looseRequiredProvider')]
    public function testRequiredEntryThatIsNotAStringIsReadAsOne(array $required): void
    {
        // As in 1.x: null and false are empty entries, a number is a key
        $_ENV = [123 => 'set'];
        EnvLoader::load($this->createEnvFile('TEST_KEY=value'), required: $required);

        $this->assertSame([123 => 'set', 'TEST_KEY' => 'value'], $_ENV);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function numericRequiredProvider(): array
    {
        return ['integer' => [['TEST_KEY', 123]], 'float' => [[1.5, 'TEST_KEY']]];
    }

    /**
     * @param array<string> $required
     */
    #[DataProvider('numericRequiredProvider')]
    public function testRequiredNumberIsAKeyThatCanBeMissing(array $required): void
    {
        $_ENV = [];

        $this->expectException(MissingRequiredKeyException::class);
        $this->expectExceptionMessageMatches('/^Missing required key: (123|1\.5)$/');
        EnvLoader::load($this->createEnvFile('TEST_KEY=value'), required: $required);
    }

    public function testMissingRequiredKeyFromStringIsNamedTrimmed(): void
    {
        $path = $this->createEnvFile('TEST_EXISTS=value');

        try {
            EnvLoader::load($path, required: 'TEST_EXISTS, TEST_MISSING ');
            $this->fail('Expected MissingRequiredKeyException');
        } catch (MissingRequiredKeyException $e) {
            $this->assertSame('Missing required key: TEST_MISSING', $e->getMessage());
        }
    }

    // ============================================
    // format() Method
    // ============================================

    public function testFormatQuotesEveryValue(): void
    {
        $content = EnvLoader::format([
            'TEST_PLAIN' => 'value',
            'TEST_EMPTY' => '',
            'TEST_ESCAPED' => 'say "hi" \\ there',
        ]);

        $this->assertSame(
            "TEST_PLAIN=\"value\"\nTEST_EMPTY=\"\"\nTEST_ESCAPED=\"say \\\"hi\\\" \\\\ there\"\n",
            $content
        );
    }

    public function testFormatOfEmptyArrayIsEmpty(): void
    {
        $this->assertSame('', EnvLoader::format([]));
    }

    public function testParseReadsFormattedValuesBackUnchanged(): void
    {
        $values = [
            'TEST_BOM_LIKE' => "\xEF\xBB\xBFvalue",
            'TEST_EMPTY' => '',
            'TEST_SPACE' => ' ',
            'TEST_PADDED' => "  \tpadded\t  ",
            'TEST_HASH' => '#',
            'TEST_COMMENT_LIKE' => 'a # b',
            'TEST_QUOTES' => '"\'"\'',
            'TEST_BACKSLASH' => '\\',
            'TEST_BACKSLASH_QUOTE' => '\\"',
            'TEST_TRAILING_BACKSLASHES' => 'a\\\\',
            'TEST_WINDOWS_PATH' => 'C:\\Users\\name\\new',
            'TEST_LITERAL_ESCAPES' => 'a\\nb\\tc\\$d',
            'TEST_DOLLAR' => '$HOME ${TEST_EMPTY}',
            'TEST_ASSIGNMENT' => 'export A=1',
            'TEST_UNICODE' => 'ünïcödé – 日本語',
            'TEST_CONTROL' => "\x01\v\f\x1b\x7f",
            'TEST_INVALID_UTF8' => "\xff\xfe\x80",
            'TEST_LARGE' => str_repeat('x"\\', 20_000),
        ];

        // Deterministic random bytes, without the three bytes format() rejects
        $random = new Randomizer(new Mt19937(20261002));
        $alphabet = str_replace(["\r", "\n", "\0"], '', implode('', array_map('chr', range(0, 255))));
        for ($i = 0; $i < 200; $i++) {
            $value = '';
            for ($length = $random->getInt(0, 40); $length > 0; $length--) {
                // Quotes, backslashes, spaces and hashes are the bytes the syntax cares about
                $value .= $random->getInt(0, 1) === 1 ? '"\'\\ #'[$random->getInt(0, 4)] : $alphabet[$random->getInt(0, 252)];
            }
            $values['TEST_RANDOM_' . $i] = $value;
        }

        $path = $this->createEnvFile(EnvLoader::format($values));

        $this->assertSame($values, EnvLoader::parse($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unformattableValueProvider(): array
    {
        return [
            'line feed' => ["hunter2\nsecond"],
            'carriage return' => ["hunter2\rsecond"],
            'trailing line feed' => ["hunter2\n"],
            'NUL byte' => ["hunter2\0"],
        ];
    }

    #[DataProvider('unformattableValueProvider')]
    public function testFormatRejectsValueThatIsNotASingleLine(string $value): void
    {
        $traceArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            EnvLoader::format(['TEST_FINE' => 'value', 'TEST_SECRET' => $value]);
            $this->fail('Expected InvalidValueException');
        } catch (InvalidValueException $e) {
            $this->assertInstanceOf(EnvLoaderException::class, $e);
            $this->assertSame('Value for key "TEST_SECRET" contains a line break or NUL byte', $e->getMessage());

            $arguments = $this->traceArguments($e);
            $this->assertStringContainsString('SensitiveParameterValue', $arguments);
            $this->assertStringNotContainsString('hunter2', $arguments);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $traceArguments);
        }
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function nonStringValueProvider(): array
    {
        return [
            'integer' => [['TEST_FINE' => 'value', 'TEST_SECRET' => 4212345]],
            'float' => [['TEST_FINE' => 'value', 'TEST_SECRET' => 4212345.5]],
            'null' => [['TEST_FINE' => 'value', 'TEST_SECRET' => null]],
            'boolean' => [['TEST_FINE' => 'value', 'TEST_SECRET' => true]],
            'array' => [['TEST_FINE' => 'value', 'TEST_SECRET' => ['4212345']]],
        ];
    }

    /**
     * @param array<string> $values Declared as the strings format() expects, to get past static analysis
     */
    #[DataProvider('nonStringValueProvider')]
    public function testFormatRejectsValueThatIsNotAString(array $values): void
    {
        $traceArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            EnvLoader::format($values);
            $this->fail('Expected InvalidValueException');
        } catch (InvalidValueException $e) {
            $this->assertSame('Value for key "TEST_SECRET" is not a string', $e->getMessage());

            $arguments = $this->traceArguments($e);
            $this->assertStringContainsString('SensitiveParameterValue', $arguments);
            $this->assertStringNotContainsString('4212345', $arguments);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $traceArguments);
        }
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function unformattableKeyProvider(): array
    {
        return [
            'hyphen' => [['TEST-KEY' => 'value']],
            'empty key' => [['' => 'value']],
            'starts with a digit' => [['1TEST' => 'value']],
            'trailing line feed' => [["TEST_KEY\n" => 'value']],
            'value in the key' => [['TEST_KEY=hunter2' => 'value']],
            'value in the key, checked before its value' => [['TEST_KEY=hunter2' => 4212345]],
            'list instead of key-value pairs' => [['value']],
        ];
    }

    /**
     * @param array<string> $values Declared as the strings format() expects, to get past static analysis
     */
    #[DataProvider('unformattableKeyProvider')]
    public function testFormatRejectsInvalidKeyWithoutNamingIt(array $values): void
    {
        try {
            EnvLoader::format(['TEST_FINE' => 'value'] + $values);
            $this->fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            $this->assertSame('Invalid key at position 2', $e->getMessage());
        }
    }
}
