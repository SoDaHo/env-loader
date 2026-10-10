<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\EnvLoaderException;
use Sodaho\EnvLoader\Exception\InvalidKeyException;
use Sodaho\EnvLoader\Exception\InvalidLineException;
use Sodaho\EnvLoader\Exception\InvalidValueException;
use Sodaho\EnvLoader\Exception\TrailingCharactersException;
use Sodaho\EnvLoader\Exception\UnterminatedQuoteException;

/**
 * Lines the parser rejects, and what the exception tells: the file, the line and a valid key, never a value.
 */
class ParseErrorTest extends EnvLoaderTestCase
{
    /**
     * @return array<string, array{string, class-string<\Throwable>, bool}> Content, exception, through load() or parse()
     */
    public static function rejectedFileProvider(): array
    {
        return [
            'key starting with a digit, load()' => ['123INVALID=value', InvalidKeyException::class, true],
            'key with a hyphen, load()' => ['INVALID-KEY=value', InvalidKeyException::class, true],
            'unterminated double quote, load()' => ['TEST_KEY="unterminated', UnterminatedQuoteException::class, true],
            'unterminated single quote, load()' => ["TEST_KEY='unterminated", UnterminatedQuoteException::class, true],
            'key starting with a digit, parse()' => ['123INVALID=value', InvalidKeyException::class, false],
            'unterminated double quote, parse()' => ['TEST_KEY="unterminated', UnterminatedQuoteException::class, false],
        ];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('rejectedFileProvider')]
    public function testRejectedFileThrows(string $content, string $exception, bool $load): void
    {
        $path = $this->createEnvFile($content);

        $this->expectException($exception);
        if ($load) {
            EnvLoader::load($path);
        } else {
            EnvLoader::parse($path);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedQuoteProvider(): array
    {
        return [
            'unterminated double quote' => ['TEST_KEY="hunter2', 'Unterminated double quote'],
            'unterminated single quote' => ["TEST_KEY='hunter2", 'Unterminated single quote'],
            'backslash as last character' => ['TEST_KEY="hunter2\\', 'Unterminated double quote'],
            'escaped closing quote' => ['TEST_KEY="hunter2\\"', 'Unterminated double quote'],
            'multiline value' => ["TEST_KEY=\"hunter2\ntail\"", 'Unterminated double quote'],
            'CR inside double quotes' => ["TEST_KEY=\"hunter2\rtail\"", 'Unterminated double quote'],
            'CR inside single quotes' => ["TEST_KEY='hunter2\rtail'", 'Unterminated single quote'],
            'text after double quote' => ['TEST_KEY="hunter2" tail', 'Unexpected characters after closing double quote'],
            'text directly after double quote' => ['TEST_KEY="hunter2"tail', 'Unexpected characters after closing double quote'],
            'text after single quote' => ["TEST_KEY='hunter2' tail", 'Unexpected characters after closing single quote'],
            'doubled single quote' => ["TEST_KEY='hunter2''tail'", 'Unexpected characters after closing single quote'],
            'NUL after double quote' => ["TEST_KEY=\"hunter2\"\0# tail", 'Unexpected characters after closing double quote'],
            'non-breaking space after single quote' => ["TEST_KEY='hunter2'\xC2\xA0# tail", 'Unexpected characters after closing single quote'],
        ];
    }

    #[DataProvider('malformedQuoteProvider')]
    public function testMalformedQuoteNamesKeyAndLineButNeverTheValue(string $line, string $problem): void
    {
        // Blank and comment lines count: the reported line number is the one in the file
        $path = $this->createEnvFile("TEST_FIRST=1\n\n# comment\n" . $line);
        $traceArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            EnvLoader::parse($path);
            $this->fail('Expected UnterminatedQuoteException or TrailingCharactersException');
        } catch (UnterminatedQuoteException | TrailingCharactersException $e) {
            // An unterminated quote and text after a closing one are different errors
            $unterminated = str_starts_with($problem, 'Unterminated');
            $this->assertSame($unterminated ? UnterminatedQuoteException::class : TrailingCharactersException::class, $e::class);
            $this->assertSame("$problem for key \"TEST_KEY\" in $path on line 4", $e->getMessage());

            // The stack trace carries the arguments, but not those holding file content
            $arguments = $this->traceArguments($e);
            $this->assertStringContainsString($path, $arguments);
            $this->assertStringNotContainsString('hunter2', $arguments);
            $this->assertStringNotContainsString('tail', $arguments);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $traceArguments);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeyProvider(): array
    {
        return [
            'starts with a digit' => ['1TEST=hunter2'],
            'hyphen' => ['TEST-KEY=hunter2'],
            'space inside' => ['TEST KEY=hunter2'],
            'value in front of =' => ['TEST_KEY hunter2=x'],
            'empty key' => ['=hunter2'],
            'non-ASCII letter' => ['TEST_KÉY=hunter2'],
            'NUL inside' => ["TEST\0KEY=hunter2"],
            'BOM in a later line' => ["\xEF\xBB\xBFTEST_KEY=hunter2"],
            'invalid key with unterminated quote' => ['TEST-KEY="hunter2'],
            // export is followed by a space or a tab, as in a shell; another whitespace makes it part of the key
            'export before a vertical tab' => ["export\vTEST_KEY=hunter2"],
            'export before a form feed' => ["export\fTEST_KEY=hunter2"],
        ];
    }

    #[DataProvider('invalidKeyProvider')]
    public function testInvalidKeyNamesOnlyTheLine(string $line): void
    {
        $path = $this->createEnvFile("TEST_FIRST=1\r\n\r\n" . $line);
        $traceArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            $this->assertSame("Invalid key in $path on line 3", $e->getMessage());

            $arguments = $this->traceArguments($e);
            $this->assertStringContainsString($path, $arguments);
            $this->assertStringNotContainsString('hunter2', $arguments);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $traceArguments);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nulInValueProvider(): array
    {
        return [
            'unquoted' => ["TEST_KEY=hunter2\0tail"],
            'single-quoted' => ["TEST_KEY='hunter2\0tail'"],
            'double-quoted' => ["TEST_KEY=\"hunter2\0tail\""],
            'escaped in double quotes' => ["TEST_KEY=\"hunter2\\\0tail\""],
            'unquoted before a comment' => ["TEST_KEY=hunter2\0tail # comment"],
        ];
    }

    #[DataProvider('nulInValueProvider')]
    public function testNulInsideAValueThrowsAndNamesOnlyKeyAndLine(string $line): void
    {
        // An environment value cannot hold a NUL byte: format() rejects it, and a C API would cut the value there
        $path = $this->createEnvFile("TEST_FIRST=1\n\n" . $line . "\nTEST_LAST=1");
        $traceArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidValueException');
        } catch (InvalidValueException $e) {
            $this->assertSame("Value for key \"TEST_KEY\" contains a NUL byte in $path on line 3", $e->getMessage());

            $arguments = $this->traceArguments($e);
            $this->assertStringContainsString($path, $arguments);
            $this->assertStringNotContainsString('hunter2', $arguments);
            $this->assertStringNotContainsString('tail', $arguments);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $traceArguments);
        }
    }

    public function testUtf16FileIsNotReadAsValuesWithNulBytes(): void
    {
        // In UTF-16 every ASCII character has a NUL byte next to it: a key of one character passes as valid,
        // its value would keep a NUL byte between its letters
        $path = $this->createEnvFile("K\0=\0v\0a\0\n\0");

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage("Value for key \"K\" contains a NUL byte in $path on line 1");
        EnvLoader::parse($path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lineWithoutEqualsProvider(): array
    {
        return [
            'key and value separated by a space' => ['TEST_KEY hunter2'],
            'key alone' => ['hunter2'],
            'export without assignment' => ['export hunter2'],
            'export alone' => ['export'],
            'quoted text' => ['"hunter2"'],
            'colon instead of equals' => ['TEST_KEY: hunter2'],
            'second half of a line broken by CR' => ["TEST_KEY=1\rhunter2"],
            'export before a comment' => ['export # hunter2'],
        ];
    }

    #[DataProvider('lineWithoutEqualsProvider')]
    public function testLineWithoutEqualsThrowsAndNamesOnlyTheLine(string $line): void
    {
        $path = $this->createEnvFile("TEST_FIRST=1\r\n\r\n" . $line . "\nTEST_LAST=1");
        $lineNumber = 3 + substr_count($line, "\r");
        $traceArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidLineException');
        } catch (InvalidLineException $e) {
            $this->assertSame("Missing \"=\" in $path on line $lineNumber", $e->getMessage());

            $arguments = $this->traceArguments($e);
            $this->assertStringContainsString($path, $arguments);
            $this->assertStringNotContainsString('hunter2', $arguments);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $traceArguments);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function lineNumberProvider(): array
    {
        return [
            'LF' => ["TEST_A=1\n\n# c\n", 4],
            'CRLF' => ["TEST_A=1\r\n\r\n# c\r\n", 4],
            'CR' => ["TEST_A=1\r\r# c\r", 4],
            'CR followed by CRLF are two line endings' => ["TEST_A=1\r\r\n", 3],
            'LF followed by CR are two line endings' => ["TEST_A=1\n\r", 3],
            'mixed' => ["TEST_A=1\r# c\n\r\nTEST_B=2\r", 5],
        ];
    }

    #[DataProvider('lineNumberProvider')]
    public function testEveryLineEndingCountsOnce(string $before, int $lineNumber): void
    {
        $path = $this->createEnvFile($before . 'TEST-KEY=value');

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            $this->assertSame("Invalid key in $path on line $lineNumber", $e->getMessage());
        }
    }

    public function testLineEndingsDoNotDependOnAutoDetectLineEndings(): void
    {
        // With the deprecated setting, PHP ends the blocks of a file whose first line ending is a CR at each CR:
        // a LF is then no line ending for fgets()
        $before = @ini_set('auto_detect_line_endings', '1');
        if ($before === false) {
            $this->markTestSkipped('This PHP has no auto_detect_line_endings');
        }

        try {
            $values = EnvLoader::parse($this->createEnvFile("TEST_A=1\rTEST_B=2\nTEST_C=3\r\nTEST_D=4\r\r\nTEST_E=5\r\n"));
            $this->assertSame(['TEST_A' => '1', 'TEST_B' => '2', 'TEST_C' => '3', 'TEST_D' => '4', 'TEST_E' => '5'], $values);

            $path = $this->createEnvFile("TEST_A=1\rTEST_B=2\n# c\r\nTEST_D=4\r\r\nTEST-KEY=5\r");

            try {
                EnvLoader::parse($path);
                $this->fail('Expected InvalidKeyException');
            } catch (InvalidKeyException $e) {
                $this->assertSame("Invalid key in $path on line 6", $e->getMessage());
            }
        } finally {
            @ini_set('auto_detect_line_endings', $before);
        }
    }

    public function testExceptionsForTheContentOfTheFileAreSiblings(): void
    {
        // Each can be caught on its own; all of them as EnvLoaderException. InvalidValueException is one of them since
        // a NUL byte in a value throws it
        $classes = [
            InvalidLineException::class,
            InvalidKeyException::class,
            UnterminatedQuoteException::class,
            TrailingCharactersException::class,
            InvalidValueException::class,
        ];
        foreach ($classes as $class) {
            $this->assertSame(EnvLoaderException::class, get_parent_class($class));
        }
    }

    public function testBomDoesNotShiftLineNumber(): void
    {
        $path = $this->createEnvFile("\xEF\xBB\xBFTEST-KEY=value");

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            $this->assertSame("Invalid key in $path on line 1", $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function partOfABomProvider(): array
    {
        return [
            'last byte of a BOM' => ["\xBFTEST_KEY=value"],
            'last two bytes of a BOM' => ["\xBB\xBFTEST_KEY=value"],
            'first two bytes of a BOM' => ["\xEF\xBBTEST_KEY=value"],
            'two BOMs' => ["\xEF\xBB\xBF\xEF\xBB\xBFTEST_KEY=value"],
            'BOM of UTF-16' => ["\xFF\xFET\0E\0S\0T\0=\0v\0"],
        ];
    }

    #[DataProvider('partOfABomProvider')]
    public function testOnlyOneWholeBomIsSkipped(string $content): void
    {
        // Bytes of a BOM that do not form one belong to the key: the file is rejected, not read as if they were not there
        $path = $this->createEnvFile($content);

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            $this->assertSame("Invalid key in $path on line 1", $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function partOfABomBeforeACommentProvider(): array
    {
        return [
            'last byte of a BOM' => ["\xBF# comment\nTEST_KEY=value"],
            'first two bytes of a BOM' => ["\xEF\xBB# comment\nTEST_KEY=value"],
            'two BOMs' => ["\xEF\xBB\xBF\xEF\xBB\xBF# comment\nTEST_KEY=value"],
        ];
    }

    #[DataProvider('partOfABomBeforeACommentProvider')]
    public function testPartOfABomBeforeACommentLineThrowsInvalidLineException(string $content): void
    {
        // The stray bytes keep the line from being a comment; without "=" it is no assignment either
        $path = $this->createEnvFile($content);

        try {
            EnvLoader::parse($path);
            $this->fail('Expected InvalidLineException');
        } catch (InvalidLineException $e) {
            $this->assertSame("Missing \"=\" in $path on line 1", $e->getMessage());
        }
    }

    public function testSourceCallsNoRegexOrLocaleFunction(): void
    {
        // A warning raised inside a preg call (PCRE cannot allocate JIT memory on macOS or under SELinux) carries
        // its arguments into the stack trace of an error handler that throws. So the parser uses no PCRE at all,
        // and no ctype function either: what those call whitespace follows the locale.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/EnvLoader.php');
        preg_match_all(
            '/preg_\w+|mb_ereg\w*|mb_split|mb_regex\w*|RegexIterator|filter_var|sscanf|fnmatch|ctype_\w+|setlocale|use function/i',
            $source,
            $matches
        );

        $this->assertSame([], $matches[0]);
    }
}
