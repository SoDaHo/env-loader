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
            // fopen() warns that a socket cannot be opened
            'socket that cannot be opened' => ['socket'],
            // On Linux this file can be opened, and fread() warns of an I/O error
            'read that fails' => ['/proc/self/mem'],
        ];
    }

    #[DataProvider('localWarningProvider')]
    public function testThrowingErrorHandlerDoesNotSeeTheWarningsOfALocalFile(string $path): void
    {
        $server = null;
        if ($path === 'socket') {
            $path = $this->tempDir . '/env.sock';
            $server = @stream_socket_server("unix://$path");
            if ($server === false) {
                $this->markTestSkipped('Cannot create a Unix socket here');
            }
        } elseif (!@is_file($path) || !is_readable($path)) {
            $this->markTestSkipped("No $path to read here");
        }

        $handler = static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        };
        set_error_handler($handler);

        try {
            try {
                EnvLoader::parse($path);
                $this->fail('Expected FileNotReadableException');
            } catch (FileNotReadableException $e) {
                $this->assertSame("Could not read file: $path", $e->getMessage());
            }

            // The handler of the application is in place again
            $this->assertSame($handler, set_error_handler(null));
            restore_error_handler();
        } finally {
            restore_error_handler();
            if ($server !== null) {
                fclose($server);
            }
        }
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
            . 'catch (Throwable $e) { echo $e::class; }';
        $allowed = $root . '/src/' . PATH_SEPARATOR . $root . '/vendor/';

        $output = $this->runInChildProcess($script, $path, ['-d', 'open_basedir=' . $allowed]);

        $this->assertSame(FileNotFoundException::class, $output);
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
