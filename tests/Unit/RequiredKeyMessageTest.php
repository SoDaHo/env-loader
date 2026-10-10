<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\MissingRequiredKeyException;

/**
 * How a message names required keys: the caller may pass any name, so control characters are escaped and a name
 * cannot add a line to a log.
 */
class RequiredKeyMessageTest extends EnvLoaderTestCase
{
    /**
     * @return array<string, array{string, string}> Required key, as the message names it
     */
    public static function requiredKeyProvider(): array
    {
        return [
            'line feed inside' => ["TEST_KEY\nINFO login ok", 'TEST_KEY\x0AINFO login ok'],
            'carriage return inside' => ["TEST_KEY\rINFO", 'TEST_KEY\x0DINFO'],
            'tab inside' => ["TEST\tKEY", 'TEST\x09KEY'],
            'escape sequence' => ["TEST_\e[31mKEY", 'TEST_\x1B[31mKEY'],
            'delete' => ["TEST_\x7FKEY", 'TEST_\x7FKEY'],
            'valid key' => ['TEST_KEY', 'TEST_KEY'],
            'other bytes stay' => ["TEST_K\xC3\x89Y", "TEST_K\xC3\x89Y"],
        ];
    }

    #[DataProvider('requiredKeyProvider')]
    public function testControlCharactersOfARequiredKeyAreEscaped(string $key, string $named): void
    {
        $path = $this->createEnvFile('TEST_OTHER=value');

        foreach ([[$key], [$key, 'TEST_SECOND']] as $required) {
            try {
                EnvLoader::load($path, required: $required);
                $this->fail('Expected MissingRequiredKeyException');
            } catch (MissingRequiredKeyException $e) {
                $expected = count($required) === 1
                    ? "Missing required key: $named"
                    : "Missing required keys: $named, TEST_SECOND";
                $this->assertSame($expected, $e->getMessage());
            }
        }
    }

    public function testEmptyRequiredKeyIsNamedEscapedAsWell(): void
    {
        $_ENV = ["TEST_\nKEY" => ''];

        $this->expectExceptionMessage('Empty required key: TEST_\x0AKEY');
        EnvLoader::load($this->createEnvFile('TEST_OTHER=value'), required: ["TEST_\nKEY"], requireNonEmpty: true);
    }
}
