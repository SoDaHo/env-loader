<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\FileNotFoundException;
use Sodaho\EnvLoader\Exception\FileNotReadableException;

/**
 * Error handlers of the application. For a local file, one that throws even for warnings suppressed with @ never
 * replaces what parse() returns or throws: the warnings of opening and reading do not reach it. A stream wrapper is
 * read as in 2.0, with @ and no handler of the library, so a wrapper that suspends a fiber leaves the handlers alone.
 */
class ErrorHandlerTest extends EnvLoaderTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function localWarningProvider(): array
    {
        return [
            // fopen() warns that the file cannot be opened
            'file that cannot be opened' => ['unreadable'],
            // On Linux this file can be opened, and fread() warns of an I/O error
            'read that fails' => ['/proc/self/mem'],
        ];
    }

    #[DataProvider('localWarningProvider')]
    public function testThrowingErrorHandlerDoesNotSeeTheWarningsOfALocalFile(string $path): void
    {
        if ($path === 'unreadable') {
            $path = $this->unreadableFile([EnvLoader::class, FileNotReadableException::class]);
        } elseif (!@is_file($path) || !is_readable($path)) {
            $this->markTestSkipped("No $path to read here");
        }

        $handler = static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        };
        set_error_handler($handler);

        error_clear_last();
        $warning = null;
        try {
            try {
                EnvLoader::parse($path);
                $result = 'read';
            } catch (FileNotReadableException $e) {
                $result = $e->getMessage();
                $warning = $e->getPrevious();
            }

            // The handler of the application is in place again
            $current = set_error_handler(null);
            restore_error_handler();
        } finally {
            restore_error_handler();
            $this->restorePrivileges();
        }

        $this->assertSame("Could not read file: $path", $result);
        $this->assertSame($handler, $current);
        // What PHP reported is kept: as the previous of the exception, and for error_get_last() as in 2.0
        // (a warning for fopen(), a notice for fread())
        $this->assertInstanceOf(\ErrorException::class, $warning);
        $this->assertContains($warning->getSeverity(), [E_WARNING, E_NOTICE]);
        $this->assertSame($warning->getMessage(), error_get_last()['message'] ?? null);
    }

    public function testThrowingErrorHandlerDoesNotSeeTheWarningsOutsideOpenBasedir(): void
    {
        // file_exists() warns about open_basedir; open_basedir cannot be lifted again, hence a child process
        $path = $this->createEnvFile('TEST_KEY=value');
        $root = dirname(__DIR__, 2);
        $script = 'require $argv[1];'
            . 'set_error_handler(function (int $level, string $message): never {'
            . '    throw new ErrorException($message, 0, $level);'
            . '});'
            . 'try { echo json_encode(Sodaho\EnvLoader\EnvLoader::parse($argv[2])); }'
            . 'catch (Throwable $e) {'
            . '    echo json_encode([$e::class, $e->getPrevious()?->getMessage(), error_get_last()["message"] ?? null]);'
            . '}';
        $allowed = $root . '/src/' . PATH_SEPARATOR . $root . '/vendor/';

        $output = $this->runInChildProcess($script, $path, ['-d', 'open_basedir=' . $allowed]);
        $result = json_decode($output, true);

        $this->assertIsArray($result, $output);
        [$class, $previous, $last] = $result;
        $this->assertSame(FileNotFoundException::class, $class);
        $this->assertIsString($previous);
        $this->assertStringContainsString('open_basedir restriction in effect', $previous);
        $this->assertSame($previous, $last);
    }

    public function testThrowingErrorHandlerThrowsFromAStreamWrapperAsIn20(): void
    {
        // The read fails with a warning, and closing the stream warns as well: the exception of the handler for the
        // second replaces the first, which stays as its previous
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });

        try {
            EnvLoader::parse("$scheme://fails/" . rawurlencode("TEST_KEY=value\n"));
            $this->fail('Expected ErrorException');
        } catch (\ErrorException $e) {
            $this->assertSame('Close failed', $e->getMessage());
            $this->assertSame('Read failed', $e->getPrevious()?->getMessage());
        } finally {
            restore_error_handler();
            stream_wrapper_unregister($scheme);
        }
    }

    public function testExceptionOfAStreamWrapperReachesTheCaller(): void
    {
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));

        try {
            EnvLoader::parse("$scheme://throws/" . rawurlencode("TEST_KEY=value\n"));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Close failed', $e->getMessage());
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function testReplacedPlainFileWrapperThatSuspendsAFiberKeepsTheHandlerInstalledMeanwhile(): void
    {
        // A replaced plain file wrapper runs while the handler of the library stands over a call: a warning of the
        // application raised while the fiber waits goes to that handler (README). A handler the application installs
        // meanwhile is still in place when parse() has returned
        class_exists(EnvLoader::class);
        class_exists(SwappedFileStream::class);
        SwappedFileStream::$openedType = 0o100000;
        SwappedFileStream::$suspends = true;
        $seen = [];
        $first = static function (int $level, string $message) use (&$seen): bool {
            $seen[] = "first: $message";

            return true;
        };
        $second = static function (int $level, string $message) use (&$seen): bool {
            $seen[] = "second: $message";

            return true;
        };
        set_error_handler($first);
        $path = $this->tempDir . '/.env';
        $replaced = false;
        $values = null;
        $current = null;

        try {
            $replaced = stream_wrapper_unregister('file') && stream_wrapper_register('file', SwappedFileStream::class);
            $fiber = new \Fiber(static fn (): array => EnvLoader::parse($path));
            $fiber->start();
            trigger_error('while suspended', E_USER_WARNING);
            set_error_handler($second);

            $fiber->resume();
            $values = $fiber->getReturn();
            $current = set_error_handler(null);
            restore_error_handler();
            trigger_error('after', E_USER_WARNING);
        } finally {
            stream_wrapper_restore('file');
            SwappedFileStream::$suspends = false;
            SwappedFileStream::$openedType = 0o010000;
            restore_error_handler();
            restore_error_handler();
        }

        $this->assertTrue($replaced);
        $this->assertSame(['TEST_KEY' => 'value'], $values);
        $this->assertSame($second, $current);
        $this->assertSame(['second: after'], $seen);
    }

    public function testReplacedPlainFileWrapperThatSuspendsAFiberCanTurnAWarningOfOtherCodeIntoThePrevious(): void
    {
        // The limit (README): a warning raised while the read waits goes to the handler of the library; where the read
        // then fails, it becomes the previous of the exception, whatever its text
        class_exists(EnvLoader::class);
        class_exists(FileNotReadableException::class);
        class_exists(SwappedFileStream::class);
        SwappedFileStream::$openedType = 0o100000;
        SwappedFileStream::$suspends = true;
        SwappedFileStream::$stops = true;
        $path = $this->tempDir . '/.env';
        $result = null;

        try {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', SwappedFileStream::class);
            $fiber = new \Fiber(static function () use ($path): ?FileNotReadableException {
                try {
                    EnvLoader::parse($path);
                } catch (FileNotReadableException $e) {
                    return $e;
                }

                return null;
            });
            $fiber->start();
            trigger_error('a warning of other code', E_USER_WARNING);
            $fiber->resume();
            $result = $fiber->getReturn();
        } finally {
            stream_wrapper_restore('file');
            SwappedFileStream::$suspends = false;
            SwappedFileStream::$stops = false;
            SwappedFileStream::$openedType = 0o010000;
        }

        $this->assertInstanceOf(FileNotReadableException::class, $result);
        $this->assertSame("Could not read file: $path", $result->getMessage());
        $this->assertSame('a warning of other code', $result->getPrevious()?->getMessage());
    }

    public function testReplacedPlainFileWrapperThatSuspendsAFiberLeavesItsHandlerUnderTwoInstalledMeanwhile(): void
    {
        // The limit (README): with two handlers installed meanwhile, the second stays on top, but the handler of the
        // library stays under the first
        class_exists(EnvLoader::class);
        class_exists(SwappedFileStream::class);
        SwappedFileStream::$openedType = 0o100000;
        SwappedFileStream::$suspends = true;
        $first = static fn (): bool => true;
        $second = static fn (): bool => true;
        $third = static fn (): bool => true;
        set_error_handler($first);
        $path = $this->tempDir . '/.env';
        $stack = [];

        try {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', SwappedFileStream::class);
            $fiber = new \Fiber(static fn (): array => EnvLoader::parse($path));
            $fiber->start();
            set_error_handler($second);
            set_error_handler($third);
            $fiber->resume();
        } finally {
            stream_wrapper_restore('file');
            SwappedFileStream::$suspends = false;
            SwappedFileStream::$openedType = 0o010000;
            // The handlers from the top down, each taken off
            for ($i = 0; $i < 4; $i++) {
                $stack[] = set_error_handler(null);
                restore_error_handler();
                restore_error_handler();
            }
        }

        $this->assertSame($third, $stack[0]);
        $this->assertSame($second, $stack[1]);
        $this->assertNotContains($stack[2], [$first, $second, $third]);
        $this->assertSame($first, $stack[3]);
    }

    public function testFiberSuspendedInAStreamWrapperLeavesTheErrorHandlersAlone(): void
    {
        // While parse() waits in a suspended fiber, the application goes on: its warnings reach its handler, and a
        // handler it installs meanwhile stays installed when parse() has returned. (The fiber is suspended inside an
        // expression with @, which PHP does not undo for the others: error_reporting() says "suppressed" meanwhile.)
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));
        $seen = [];
        $first = static function (int $level, string $message) use (&$seen): bool {
            $seen[] = "first: $message";

            return true;
        };
        $second = static function (int $level, string $message) use (&$seen): bool {
            $seen[] = "second: $message";

            return true;
        };
        set_error_handler($first);

        try {
            $path = "$scheme://suspends/" . rawurlencode("TEST_KEY=value\n");
            $fiber = new \Fiber(static fn (): array => EnvLoader::parse($path));
            $fiber->start();
            trigger_error('while suspended', E_USER_WARNING);
            set_error_handler($second);

            $fiber->resume();
            trigger_error('after', E_USER_WARNING);

            $this->assertTrue($fiber->isTerminated());
            $this->assertSame(['TEST_KEY' => 'value'], $fiber->getReturn());
            $this->assertSame($second, set_error_handler(null));
            restore_error_handler();
        } finally {
            restore_error_handler();
            restore_error_handler();
            stream_wrapper_unregister($scheme);
        }

        // The warning of closing the stream, suppressed with @, reaches the handler as in 2.0
        $this->assertSame(['first: while suspended', 'second: Close failed', 'second: after'], $seen);
    }
}
