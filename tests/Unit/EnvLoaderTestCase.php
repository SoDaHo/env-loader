<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\EnvLoader\EnvLoader;

/**
 * Base of the tests of EnvLoader. Every test gets a directory of its own for its files; $_ENV and the variables
 * a test sets in the process environment are restored after it.
 */
abstract class EnvLoaderTestCase extends TestCase
{
    protected string $tempDir;

    /** @var array<mixed> */
    protected array $envBackup;

    /** @var array<string> */
    protected array $processVariables = [];

    protected function setUp(): void
    {
        // A name nobody else picks, and a directory that is known to be there: a test must not find files of another
        $this->tempDir = sys_get_temp_dir() . '/env-loader-test-' . bin2hex(random_bytes(8));
        if (!mkdir($this->tempDir)) {
            throw new \RuntimeException("Could not create $this->tempDir");
        }
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

    protected function createEnvFile(string $content): string
    {
        $path = $this->tempDir . '/.env';
        $this->writeFile($path, $content);
        return $path;
    }

    /**
     * Writes a file for a test; a failed write would let the test read something else, so it throws.
     */
    protected function writeFile(string $path, string $content, int $flags = 0): void
    {
        if (file_put_contents($path, $content, $flags) !== strlen($content)) {
            throw new \RuntimeException("Could not write $path");
        }
    }

    /**
     * Sets a variable in the process environment (not in $_ENV) until the end of the test.
     */
    protected function setProcessVariable(string $key, string $value): void
    {
        $this->assertFalse(getenv($key), "$key is already set in the process environment");

        $this->processVariables[] = $key;
        putenv("$key=$value");
        unset($_ENV[$key]);
    }

    /**
     * Arguments of the EnvLoader calls in the stack trace, and of everything they called, as text.
     */
    protected function traceArguments(\Throwable $e): string
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

    /**
     * Runs parse() in a child process whose error handler reports what a framework would turn into an exception.
     *
     * @param list<string> $options
     *
     * @return string Warnings, then the parsed values as JSON or the class of the exception
     */
    protected function parseInChildProcess(string $path, array $options): string
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
    protected function runInChildProcess(string $script, string $path, array $options, array $environment = []): string
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
}
