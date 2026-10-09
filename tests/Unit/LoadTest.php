<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\InvalidLineException;
use Sodaho\EnvLoader\Exception\MissingRequiredKeyException;
use Sodaho\EnvLoader\Exception\UnterminatedQuoteException;

/**
 * load(): what it writes to $_ENV, how the environment wins over the file, required keys, and that a failed load
 * changes nothing.
 */
class LoadTest extends EnvLoaderTestCase
{
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

    /**
     * @return array<string, array{string, array<string>|string, array<string, string>}> Content, required keys, values
     */
    public static function requiredKeysProvider(): array
    {
        return [
            'array' => [
                "TEST_REQ_ONE=one\nTEST_REQ_TWO=two",
                ['TEST_REQ_ONE', 'TEST_REQ_TWO'],
                ['TEST_REQ_ONE' => 'one', 'TEST_REQ_TWO' => 'two'],
            ],
            'comma-separated string' => [
                "TEST_STR_ONE=one\nTEST_STR_TWO=two",
                'TEST_STR_ONE,TEST_STR_TWO',
                ['TEST_STR_ONE' => 'one', 'TEST_STR_TWO' => 'two'],
            ],
            'string with a trailing comma' => [
                "TEST_TRAIL_ONE=one\nTEST_TRAIL_TWO=two",
                'TEST_TRAIL_ONE,TEST_TRAIL_TWO,',
                ['TEST_TRAIL_ONE' => 'one', 'TEST_TRAIL_TWO' => 'two'],
            ],
            'string with an empty entry' => [
                "TEST_EMPTY_ONE=one\nTEST_EMPTY_TWO=two",
                'TEST_EMPTY_ONE,,TEST_EMPTY_TWO',
                ['TEST_EMPTY_ONE' => 'one', 'TEST_EMPTY_TWO' => 'two'],
            ],
            'empty string' => [
                'TEST_EMPTY_REQ=value',
                '',
                ['TEST_EMPTY_REQ' => 'value'],
            ],
            'empty value' => [
                'TEST_EMPTY_REQUIRED=',
                ['TEST_EMPTY_REQUIRED'],
                ['TEST_EMPTY_REQUIRED' => ''],
            ],
            'string with spaces around the keys' => [
                "TEST_SPACE_ONE=one\nTEST_SPACE_TWO=two",
                ' TEST_SPACE_ONE , TEST_SPACE_TWO ',
                ['TEST_SPACE_ONE' => 'one', 'TEST_SPACE_TWO' => 'two'],
            ],
            'array with spaces and an empty entry' => [
                "TEST_ARR_ONE=one\nTEST_ARR_TWO=two",
                [' TEST_ARR_ONE ', '', 'TEST_ARR_TWO'],
                ['TEST_ARR_ONE' => 'one', 'TEST_ARR_TWO' => 'two'],
            ],
        ];
    }

    /**
     * @param array<string>|string $required
     * @param array<string, string> $expected
     */
    #[DataProvider('requiredKeysProvider')]
    public function testRequiredKeysThatArePresent(string $content, array|string $required, array $expected): void
    {
        EnvLoader::load($this->createEnvFile($content), required: $required);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $_ENV[$key]);
        }
    }

    public function testThrowsMissingRequiredKeyException(): void
    {
        $path = $this->createEnvFile('TEST_EXISTS=value');
        $this->expectException(MissingRequiredKeyException::class);
        EnvLoader::load($path, required: ['TEST_MISSING']);
    }

    public function testParseReturnsArrayWithoutSettingEnv(): void
    {
        $path = $this->createEnvFile("TEST_PARSE_ONE=one\nTEST_PARSE_TWO=two");
        $result = EnvLoader::parse($path);

        $this->assertSame(['TEST_PARSE_ONE' => 'one', 'TEST_PARSE_TWO' => 'two'], $result);
        $this->assertArrayNotHasKey('TEST_PARSE_ONE', $_ENV);
        $this->assertArrayNotHasKey('TEST_PARSE_TWO', $_ENV);
    }

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

    public function testSecondLoadMixesWithTheFirst(): void
    {
        // $_ENV belongs to the process: another file loaded later does not replace the first one (README:
        // load once per worker; parse() for values per tenant)
        $_ENV = [];
        $first = $this->tempDir . '/first.env';
        $second = $this->tempDir . '/second.env';
        $this->assertNotFalse(file_put_contents($first, "TEST_NAME=first\nTEST_ONLY_FIRST=1"));
        $this->assertNotFalse(file_put_contents($second, 'TEST_NAME=second'));

        EnvLoader::load($first);
        EnvLoader::load($second);
        $this->assertSame(['TEST_NAME' => 'first', 'TEST_ONLY_FIRST' => '1'], $_ENV);

        EnvLoader::load($second, overwrite: true);
        $this->assertSame(['TEST_NAME' => 'second', 'TEST_ONLY_FIRST' => '1'], $_ENV);
    }

    public function testClassIsFinal(): void
    {
        $this->assertTrue(new \ReflectionClass(EnvLoader::class)->isFinal());
    }

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

    public function testRequiredKeyMayComeFromExistingEnv(): void
    {
        $_ENV['TEST_FROM_ENV'] = 'preset';
        $path = $this->createEnvFile('TEST_FROM_FILE=value');
        EnvLoader::load($path, required: ['TEST_FROM_ENV', 'TEST_FROM_FILE']);

        $this->assertSame('value', $_ENV['TEST_FROM_FILE']);
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
            'object with a string form' => [[new class () implements \Stringable {
                public function __toString(): string
                {
                    return 'TEST_KEY';
                }
            }]],
        ];
    }

    /**
     * @param array<string> $required
     */
    #[DataProvider('looseRequiredProvider')]
    public function testRequiredEntryThatIsNotAStringIsReadAsOne(array $required): void
    {
        // As in 1.x: null and false are empty entries, a number or an object with a string form is a key
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

    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function requiredEntryWithoutAStringFormProvider(): array
    {
        return [
            'array' => [['TEST_KEY', ['TEST_KEY']], 'Required key at position 2 must be a string, array given'],
            'object' => [[new \stdClass(), 'TEST_KEY'], 'Required key at position 1 must be a string, stdClass given'],
        ];
    }

    /**
     * @param array<string> $required Declared as the strings load() expects, to get past static analysis
     */
    #[DataProvider('requiredEntryWithoutAStringFormProvider')]
    public function testRequiredEntryWithoutAStringFormIsRejected(array $required, string $message): void
    {
        // Read as a string, an array would be required as the key "Array", after a warning
        $_ENV = ['TEST_EXISTING' => 'original'];

        try {
            EnvLoader::load($this->createEnvFile('TEST_KEY=value'), required: $required);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(['TEST_EXISTING' => 'original'], $_ENV);
        }
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

    /**
     * @return array<string, array{array<string>|string, string}>
     */
    public static function missingRequiredKeysProvider(): array
    {
        return [
            'in the order given' => [
                ['TEST_C', 'TEST_IN_FILE', 'TEST_A', 'TEST_IN_ENV', 'TEST_B', 'TEST_IN_PROCESS'],
                'Missing required keys: TEST_C, TEST_A, TEST_B',
            ],
            'a key given twice is named once' => [['TEST_A', 'TEST_B', 'TEST_A'], 'Missing required keys: TEST_A, TEST_B'],
            'one key given twice' => [['TEST_A', 'TEST_A'], 'Missing required key: TEST_A'],
            'as a string' => ['TEST_A, TEST_IN_FILE ,TEST_B', 'Missing required keys: TEST_A, TEST_B'],
        ];
    }

    /**
     * @param array<string>|string $required
     */
    #[DataProvider('missingRequiredKeysProvider')]
    public function testEveryMissingRequiredKeyIsNamed(array|string $required, string $message): void
    {
        // One run names all of them, so a broken setup is fixed in one go; $_ENV stays as it was
        $this->setProcessVariable('TEST_IN_PROCESS', 'process');
        $_ENV = ['TEST_EXISTING' => 'original', 'TEST_IN_ENV' => 'env'];
        $path = $this->createEnvFile('TEST_IN_FILE=value');

        try {
            EnvLoader::load($path, required: $required);
            $this->fail('Expected MissingRequiredKeyException');
        } catch (MissingRequiredKeyException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(['TEST_EXISTING' => 'original', 'TEST_IN_ENV' => 'env'], $_ENV);
        }
    }
}
