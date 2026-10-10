<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\FileNotFoundException;
use Sodaho\EnvLoader\Exception\FileNotReadableException;

/**
 * An error handler of the application that throws, even for warnings suppressed with @, never replaces what
 * parse() returns or throws: the warnings of opening and reading do not reach it, and it is in place again after.
 */
class ErrorHandlerTest extends EnvLoaderTestCase
{
    /**
     * @return array<string, array{string, string}> The path, and the values as JSON or the exception
     */
    public static function warningProvider(): array
    {
        $unreadable = FileNotReadableException::class . ': Could not read file: {path}';
        $line = rawurlencode("TEST_KEY=value\n");

        return [
            // fopen() warns that a socket cannot be opened
            'socket that cannot be opened' => ['socket', $unreadable],
            // The stream warns when a read fails, and when it is closed
            'read that fails' => ["{scheme}://fails/$line", $unreadable],
            'stream that warns when it is closed' => ["{scheme}://ends/$line", '{"TEST_KEY":"value"}'],
        ];
    }

    #[DataProvider('warningProvider')]
    public function testThrowingErrorHandlerDoesNotReplaceTheResult(string $path, string $expected): void
    {
        $scheme = 'failing-read-' . getmypid();
        $this->assertTrue(stream_wrapper_register($scheme, FailingReadStream::class));
        $server = null;
        if ($path === 'socket') {
            $path = $this->tempDir . '/env.sock';
            $server = @stream_socket_server("unix://$path");
            if ($server === false) {
                stream_wrapper_unregister($scheme);
                $this->markTestSkipped('Cannot create a Unix socket here');
            }
        }
        $path = str_replace('{scheme}', $scheme, $path);

        $handler = static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        };
        set_error_handler($handler);

        try {
            try {
                $result = (string) json_encode(EnvLoader::parse($path));
            } catch (FileNotReadableException $e) {
                $result = $e::class . ': ' . $e->getMessage();
            }

            // The handler of the application is in place again
            $this->assertSame($handler, set_error_handler(null));
            restore_error_handler();
        } finally {
            restore_error_handler();
            stream_wrapper_unregister($scheme);
            if ($server !== null) {
                fclose($server);
            }
        }

        $this->assertSame(str_replace('{path}', $path, $expected), $result);
    }

    public function testThrowingErrorHandlerDoesNotReplaceTheExceptionOutsideOpenBasedir(): void
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
}
