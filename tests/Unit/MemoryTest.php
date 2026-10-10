<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

/**
 * The memory parse() and load() need for a file of many keys: load() holds no more than the values and $_ENV, nothing
 * in proportion to the number of keys besides.
 */
class MemoryTest extends EnvLoaderTestCase
{
    // 100 000 keys in 900 000 bytes, below the limit of 1 MiB
    private const KEYS = 100_000;

    public function testLoadOfManyKeysNeedsLittleMoreThanParse(): void
    {
        $path = $this->createEnvFile($this->manyKeys());

        // Measured in a child process; parse() first, then load() with the peak reset
        $script = 'require $argv[1];'
            . 'class_exists(Sodaho\EnvLoader\EnvLoader::class);'
            . '$_ENV = [];'
            . 'memory_reset_peak_usage();'
            . '$before = memory_get_usage();'
            . 'Sodaho\EnvLoader\EnvLoader::parse($argv[2]);'
            . '$parse = memory_get_peak_usage() - $before;'
            . 'memory_reset_peak_usage();'
            . '$before = memory_get_usage();'
            . 'Sodaho\EnvLoader\EnvLoader::load($argv[2]);'
            . 'echo json_encode([$parse, memory_get_peak_usage() - $before, count($_ENV)]);';
        $output = $this->runInChildProcess($script, $path, ['-d', 'memory_limit=-1'], ['XDEBUG_MODE' => 'off']);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);
        [$parse, $load, $count] = $result;

        $this->assertSame(self::KEYS, $count);
        $this->assertIsInt($parse);
        $this->assertIsInt($load);
        // load() holds the values and fills $_ENV, so it needs more than parse(); a pair of source and value for
        // every key on top made it four times as much
        $this->assertGreaterThan($parse, $load);
        $this->assertLessThan(2 * $parse, $load);
    }

    public function testLoadOfManyKeysRunsWithA24MegabyteMemoryLimit(): void
    {
        $path = $this->createEnvFile($this->manyKeys());
        $script = 'require $argv[1];'
            . '$_ENV = [];'
            . 'Sodaho\EnvLoader\EnvLoader::load($argv[2]);'
            . 'echo count($_ENV);';

        $output = $this->runInChildProcess($script, $path, ['-d', 'memory_limit=24M'], ['XDEBUG_MODE' => 'off']);

        $this->assertSame((string) self::KEYS, $output);
    }

    private function manyKeys(): string
    {
        $lines = [];
        for ($i = 0; $i < self::KEYS; $i++) {
            $lines[] = sprintf("K%05d=1\n", $i);
        }

        return implode('', $lines);
    }
}
