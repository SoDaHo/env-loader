<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\EnvLoaderException;
use Sodaho\EnvLoader\Exception\InvalidKeyException;
use Sodaho\EnvLoader\Exception\InvalidValueException;

/**
 * format(): .env content that parse() reads back unchanged, and the values and keys it rejects.
 */
class FormatTest extends EnvLoaderTestCase
{
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
            'export' => 'a key named export',
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
