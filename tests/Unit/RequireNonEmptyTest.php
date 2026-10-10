<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\MissingRequiredKeyException;

/**
 * requireNonEmpty: a required key must end up in $_ENV with a value that is not empty. The value checked is the
 * one load() would write, wherever it comes from; an empty variable of the environment still wins over the file.
 */
class RequireNonEmptyTest extends EnvLoaderTestCase
{
    /**
     * @return array<string, array{string, string|null, string|null, bool}> Content, value in $_ENV (null: none),
     *                                                                      process variable (null: none), overwrite
     */
    public static function emptyValueProvider(): array
    {
        return [
            // Docker Compose turns "JWT_SECRET=${JWT_SECRET}" into an empty variable where the host has none
            'empty process variable over a value of the file' => ['TEST_SECRET=file', null, '', false],
            'empty value in $_ENV over a value of the file' => ['TEST_SECRET=file', '', null, false],
            'empty value of the file' => ['TEST_SECRET=', null, null, false],
            'empty value of the file with overwrite' => ['TEST_SECRET=', null, 'process', true],
            'empty process variable, not in the file' => ['TEST_OTHER=value', null, '', false],
        ];
    }

    #[DataProvider('emptyValueProvider')]
    public function testEmptyRequiredValueThrows(string $content, ?string $env, ?string $process, bool $overwrite): void
    {
        if ($process !== null) {
            $this->setProcessVariable('TEST_SECRET', $process);
        }
        $_ENV = ['TEST_EXISTING' => 'original'] + ($env === null ? [] : ['TEST_SECRET' => $env]);
        $before = $_ENV;
        $path = $this->createEnvFile($content);

        try {
            EnvLoader::load($path, overwrite: $overwrite, required: ['TEST_SECRET'], requireNonEmpty: true);
            $this->fail('Expected MissingRequiredKeyException');
        } catch (MissingRequiredKeyException $e) {
            $this->assertSame('Empty required key: TEST_SECRET', $e->getMessage());
            $this->assertSame($before, $_ENV);
        }

        // Without requireNonEmpty an empty value counts, as in 2.0
        EnvLoader::load($path, overwrite: $overwrite, required: ['TEST_SECRET']);
        $this->assertSame('', $_ENV['TEST_SECRET']);
    }

    public function testRequiredValueThatIsNotEmptyPasses(): void
    {
        $this->setProcessVariable('TEST_FROM_PROCESS', 'process');
        $_ENV = ['TEST_FROM_ENV' => 'env'];

        EnvLoader::load(
            $this->createEnvFile("TEST_FROM_FILE=file\nTEST_SPACE=' '\nTEST_OPTIONAL="),
            required: ['TEST_FROM_PROCESS', 'TEST_FROM_ENV', 'TEST_FROM_FILE', 'TEST_SPACE'],
            requireNonEmpty: true
        );

        // A space is no empty value; a key that is not required may be empty
        $this->assertSame([
            'TEST_FROM_ENV' => 'env',
            'TEST_FROM_FILE' => 'file',
            'TEST_SPACE' => ' ',
            'TEST_OPTIONAL' => '',
            'TEST_FROM_PROCESS' => 'process',
        ], $_ENV);
    }

    public function testEveryProblemIsNamedInOneMessage(): void
    {
        $_ENV = ['TEST_NO_STRING' => 42];

        try {
            EnvLoader::load(
                $this->createEnvFile("TEST_EMPTY_A=\nTEST_EMPTY_B=\"\""),
                required: ['TEST_EMPTY_A', 'TEST_MISSING', 'TEST_NO_STRING', 'TEST_EMPTY_B'],
                requireNonEmpty: true
            );
            $this->fail('Expected MissingRequiredKeyException');
        } catch (MissingRequiredKeyException $e) {
            $this->assertSame(
                'Missing required key: TEST_MISSING; Required key without a string value: TEST_NO_STRING; '
                    . 'Empty required keys: TEST_EMPTY_A, TEST_EMPTY_B',
                $e->getMessage()
            );
            $this->assertSame(['TEST_NO_STRING' => 42], $_ENV);
        }
    }
}
