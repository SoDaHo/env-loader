<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The memory parse() and load() need for a file of many keys: load() holds no more than the values and $_ENV, nothing
 * in proportion to the number of keys besides - also where every key is required.
 */
class MemoryTest extends EnvLoaderTestCase
{
    // 100 000 keys in 900 000 bytes, below the limit of 1 MiB
    private const KEYS = 100_000;

    /**
     * @return array<string, array{string}> The arguments of load() besides the path, as PHP code
     */
    public static function loadProvider(): array
    {
        return [
            'no required keys' => [''],
            'every key required' => [', required: $names'],
            'every key required and not empty' => [', required: $names, requireNonEmpty: true'],
        ];
    }

    #[DataProvider('loadProvider')]
    public function testLoadOfManyKeysNeedsLittleMoreThanParse(string $arguments): void
    {
        $path = $this->createEnvFile($this->manyKeys());

        // Measured in a child process; parse() first, then load() with the peak reset. The names are listed before,
        // without sprintf(), whose strings keep a buffer of 240 bytes each
        $script = 'require $argv[1];'
            . 'class_exists(Sodaho\EnvLoader\EnvLoader::class);'
            . '$names = [];'
            . 'for ($i = 0; $i < ' . self::KEYS . '; $i++) {'
            . '    $names[] = "K" . str_pad((string) $i, 5, "0", STR_PAD_LEFT);'
            . '}'
            . '$_ENV = [];'
            . 'memory_reset_peak_usage();'
            . '$before = memory_get_usage();'
            . 'Sodaho\EnvLoader\EnvLoader::parse($argv[2]);'
            . '$parse = memory_get_peak_usage() - $before;'
            . 'memory_reset_peak_usage();'
            . '$before = memory_get_usage();'
            . 'Sodaho\EnvLoader\EnvLoader::load($argv[2]' . $arguments . ');'
            . 'echo json_encode([$parse, memory_get_peak_usage() - $before, count($_ENV)]);';
        $output = $this->runInChildProcess($script, $path, ['-d', 'memory_limit=-1'], ['XDEBUG_MODE' => 'off']);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);
        [$parse, $load, $count] = $result;

        $this->assertSame(self::KEYS, $count);
        $this->assertIsInt($parse);
        $this->assertIsInt($load);
        // load() holds the values and fills $_ENV, so it needs more than parse() (2.0.1: about 1.6 times as much, 1.8
        // with every key required); checked is more than parse() and less than twice as much
        $this->assertGreaterThan($parse, $load);
        $this->assertLessThan(2 * $parse, $load);
    }

    public function testLoadOfManyRequiredKeysRunsWithA32MegabyteMemoryLimit(): void
    {
        // About 25 MB, as with 2.0.1, the list of names included (built without sprintf(), see above)
        $path = $this->createEnvFile($this->manyKeys());
        $script = 'require $argv[1];'
            . '$names = [];'
            . 'for ($i = 0; $i < ' . self::KEYS . '; $i++) {'
            . '    $names[] = "K" . str_pad((string) $i, 5, "0", STR_PAD_LEFT);'
            . '}'
            . '$_ENV = [];'
            . 'Sodaho\EnvLoader\EnvLoader::load($argv[2], required: $names, requireNonEmpty: true);'
            . 'echo count($_ENV);';

        $output = $this->runInChildProcess($script, $path, ['-d', 'memory_limit=32M'], ['XDEBUG_MODE' => 'off']);

        $this->assertSame((string) self::KEYS, $output);
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
