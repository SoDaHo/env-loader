<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\TrailingCharactersException;

/**
 * What the lines of a file mean: values, quotes, comments, whitespace, export, line endings.
 */
class SyntaxTest extends EnvLoaderTestCase
{
    public function testLoadsSimpleKeyValue(): void
    {
        $path = $this->createEnvFile('TEST_KEY=value');
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_KEY']);
    }

    public function testLoadsFileWithUtf8Bom(): void
    {
        // UTF-8 BOM: \xEF\xBB\xBF (common in Windows-created files)
        $path = $this->createEnvFile("\xEF\xBB\xBFTEST_BOM=value");
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_BOM']);
    }

    public function testLoadsMultipleKeyValues(): void
    {
        $path = $this->createEnvFile("TEST_ONE=first\nTEST_TWO=second");
        EnvLoader::load($path);

        $this->assertSame('first', $_ENV['TEST_ONE']);
        $this->assertSame('second', $_ENV['TEST_TWO']);
    }

    public function testLoadsEmptyValue(): void
    {
        $path = $this->createEnvFile('TEST_EMPTY=');
        EnvLoader::load($path);

        $this->assertSame('', $_ENV['TEST_EMPTY']);
    }

    public function testLoadsValueWithEqualsSign(): void
    {
        $path = $this->createEnvFile('TEST_PASSWORD=val=ue=with=equals');
        EnvLoader::load($path);

        $this->assertSame('val=ue=with=equals', $_ENV['TEST_PASSWORD']);
    }

    public function testUnderscoreStartKeyIsValid(): void
    {
        $path = $this->createEnvFile('_TEST_PRIVATE=secret');
        EnvLoader::load($path);

        $this->assertSame('secret', $_ENV['_TEST_PRIVATE']);
    }

    public function testStripsExportPrefix(): void
    {
        $path = $this->createEnvFile('export TEST_EXPORT=value');
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_EXPORT']);
    }

    public function testStripsExportPrefixWithQuotes(): void
    {
        $path = $this->createEnvFile('export TEST_EXPORT_Q="hello world"');
        EnvLoader::load($path);

        $this->assertSame('hello world', $_ENV['TEST_EXPORT_Q']);
    }

    public function testLoadsDoubleQuotedValue(): void
    {
        $path = $this->createEnvFile('TEST_QUOTED="hello world"');
        EnvLoader::load($path);

        $this->assertSame('hello world', $_ENV['TEST_QUOTED']);
    }

    public function testLoadsSingleQuotedValue(): void
    {
        $path = $this->createEnvFile("TEST_SINGLE='hello world'");
        EnvLoader::load($path);

        $this->assertSame('hello world', $_ENV['TEST_SINGLE']);
    }

    public function testLoadsEscapedQuotes(): void
    {
        $path = $this->createEnvFile('TEST_ESCAPED="hello \"world\""');
        EnvLoader::load($path);

        $this->assertSame('hello "world"', $_ENV['TEST_ESCAPED']);
    }

    public function testLoadsEscapedBackslash(): void
    {
        $path = $this->createEnvFile('TEST_BACKSLASH="path\\\\"');
        EnvLoader::load($path);

        $this->assertSame('path\\', $_ENV['TEST_BACKSLASH']);
    }

    public function testSingleQuoteInDoubleQuotes(): void
    {
        $path = $this->createEnvFile('TEST_MIXED="it\'s ok"');
        EnvLoader::load($path);

        $this->assertSame("it's ok", $_ENV['TEST_MIXED']);
    }

    public function testDoubleQuoteInSingleQuotes(): void
    {
        $path = $this->createEnvFile("TEST_MIXED_REV='say \"hi\"'");
        EnvLoader::load($path);

        $this->assertSame('say "hi"', $_ENV['TEST_MIXED_REV']);
    }

    public function testWindowsPathPreserved(): void
    {
        $path = $this->createEnvFile('TEST_PATH="C:\\Users\\name\\docs"');
        EnvLoader::load($path);

        $this->assertSame('C:\Users\name\docs', $_ENV['TEST_PATH']);
    }

    public function testBackslashNPreservedLiterally(): void
    {
        $path = $this->createEnvFile('TEST_LITERAL="hello\\nworld"');
        EnvLoader::load($path);

        $this->assertSame('hello\nworld', $_ENV['TEST_LITERAL']);
    }

    public function testBackslashTPreservedLiterally(): void
    {
        $path = $this->createEnvFile('TEST_TAB="hello\\tworld"');
        EnvLoader::load($path);

        $this->assertSame('hello\tworld', $_ENV['TEST_TAB']);
    }

    public function testBackslashRPreservedLiterally(): void
    {
        $path = $this->createEnvFile('TEST_CR="hello\\rworld"');
        EnvLoader::load($path);

        $this->assertSame('hello\rworld', $_ENV['TEST_CR']);
    }

    public function testIgnoresCommentLines(): void
    {
        $path = $this->createEnvFile("# This is a comment\nTEST_KEY=value");
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_KEY']);
        $this->assertArrayNotHasKey('#', $_ENV);
    }

    public function testHandlesInlineComment(): void
    {
        $path = $this->createEnvFile('TEST_INLINE=value # this is a comment');
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_INLINE']);
    }

    public function testHashWithoutSpaceIsNotComment(): void
    {
        $path = $this->createEnvFile('TEST_HASH=value#notacomment');
        EnvLoader::load($path);

        $this->assertSame('value#notacomment', $_ENV['TEST_HASH']);
    }

    public function testInlineCommentWithQuotes(): void
    {
        $path = $this->createEnvFile('TEST_QUOTED_COMMENT="value with # hash" # comment');
        EnvLoader::load($path);

        $this->assertSame('value with # hash', $_ENV['TEST_QUOTED_COMMENT']);
    }

    public function testTrimsWhitespaceAroundKey(): void
    {
        $path = $this->createEnvFile('  TEST_SPACED  =value');
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_SPACED']);
    }

    public function testTrimsWhitespaceAroundValue(): void
    {
        $path = $this->createEnvFile('TEST_TRIM=  value  ');
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_TRIM']);
    }

    public function testIgnoresEmptyLines(): void
    {
        $path = $this->createEnvFile("\n\nTEST_EMPTY_LINES=value\n\n");
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_EMPTY_LINES']);
    }

    public function testParseReturnsEmptyArrayForEmptyFile(): void
    {
        $path = $this->createEnvFile('');
        $result = EnvLoader::parse($path);

        $this->assertSame([], $result);
    }

    public function testParseReturnsEmptyArrayForOnlyComments(): void
    {
        $path = $this->createEnvFile("# Comment one\n# Comment two");
        $result = EnvLoader::parse($path);

        $this->assertSame([], $result);
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function syntaxProvider(): array
    {
        return [
            'empty value followed by comment' => ['TEST_A= # comment', ['TEST_A' => '']],
            'empty value, several spaces, comment' => ['TEST_A=   # comment', ['TEST_A' => '']],
            'empty value followed by bare hash' => ['TEST_A= #', ['TEST_A' => '']],
            'hash directly after equals is a value' => ['TEST_A=#fff', ['TEST_A' => '#fff']],
            'tab before hash starts a comment' => ["TEST_A=value\t# text", ['TEST_A' => 'value']],
            'empty value, tab, comment' => ["TEST_A=\t#fff", ['TEST_A' => '']],
            'hash after another character is a value' => ['TEST_A=a#b', ['TEST_A' => 'a#b']],
            'first space-hash starts the comment' => ['TEST_A=one # two # three', ['TEST_A' => 'one']],
            'tab-hash before space-hash' => ["TEST_A=one\t# two # three", ['TEST_A' => 'one']],
            'form feed before hash starts a comment' => ["TEST_A=one\f# two", ['TEST_A' => 'one']],
            'vertical tab before hash starts a comment' => ["TEST_A=one\v# two", ['TEST_A' => 'one']],
            'NUL before hash starts a comment' => ["TEST_A=one\0# two", ['TEST_A' => 'one']],
            'empty value, form feed, comment' => ["TEST_A=\f# comment", ['TEST_A' => '']],
            'hash after a letter, then hash after whitespace' => ['TEST_A=a#b #c', ['TEST_A' => 'a#b']],
            'value of a single hash' => ['TEST_A=#', ['TEST_A' => '#']],
            'space-hash before tab-hash' => ["TEST_A=one # two\t# three", ['TEST_A' => 'one']],
            'trailing space-hash' => ['TEST_A=value #', ['TEST_A' => 'value']],
            'comment line containing =' => ["# TEST_OFF=1\nTEST_A=1", ['TEST_A' => '1']],
            'indented comment line containing =' => ["   # TEST_OFF=1\nTEST_A=1", ['TEST_A' => '1']],
            'whitespace-only line' => ["TEST_A=1\n \t\v\f\0 \nTEST_B=2", ['TEST_A' => '1', 'TEST_B' => '2']],
            'comment line after a form feed' => ["\f# TEST_OFF=1\nTEST_A=1", ['TEST_A' => '1']],
            'form feed around key and value' => ["\fTEST_A\f=\fvalue\f", ['TEST_A' => 'value']],
            'form feed around quoted value' => ["TEST_A=\f\"value\"\f", ['TEST_A' => 'value']],
            'form feed between export and key named export' => ["export \f=1", ['export' => '1']],
            'comment directly after double quote' => ['TEST_A="v"#c', ['TEST_A' => 'v']],
            'comment after single quote' => ["TEST_A='v' # c", ['TEST_A' => 'v']],
            'any whitespace between quote and comment' => ["TEST_A=\"v\" \t\v\f# c", ['TEST_A' => 'v']],
            'whitespace after quote without comment' => ["TEST_A='v' \t\v\f\nTEST_B=1", ['TEST_A' => 'v', 'TEST_B' => '1']],
            'NUL at the end of the line is trimmed, also after a quote' => ["TEST_A=\"v\"\0\nTEST_B=v\0 \0", ['TEST_A' => 'v', 'TEST_B' => 'v']],
            'comment directly after single quote' => ["TEST_A='v'#c", ['TEST_A' => 'v']],
            'double quotes in comment after double-quoted value' => ['TEST_A="a" # "b"', ['TEST_A' => 'a']],
            'single quotes in comment after single-quoted value' => ["TEST_A='a' # 'b'", ['TEST_A' => 'a']],
            'empty double-quoted value' => ['TEST_A=""', ['TEST_A' => '']],
            'empty single-quoted value' => ["TEST_A=''", ['TEST_A' => '']],
            'quotes keep surrounding spaces' => ['TEST_A="  v  "', ['TEST_A' => '  v  ']],
            'whitespace before quoted value' => ["TEST_A= \t\"v\"", ['TEST_A' => 'v']],
            'quotes inside unquoted value' => ['TEST_A=ab"c\'d', ['TEST_A' => 'ab"c\'d']],
            'escaped backslash before closing quote' => ['TEST_A="a\\\\"', ['TEST_A' => 'a\\']],
            'backslash in single quotes' => ["TEST_A='a\\\\b\\\"'", ['TEST_A' => 'a\\\\b\\"']],
            'no variable expansion' => [
                "TEST_A=1\nTEST_B=\${TEST_A}/\$TEST_A\nTEST_C=\"\${TEST_A}\"",
                ['TEST_A' => '1', 'TEST_B' => '${TEST_A}/$TEST_A', 'TEST_C' => '${TEST_A}'],
            ],
            'escaped dollar stays literal' => ['TEST_A="pa\\$word"', ['TEST_A' => 'pa\\$word']],
            'duplicate key: last one wins' => ["TEST_A=1\nTEST_B=2\nTEST_A=3", ['TEST_A' => '3', 'TEST_B' => '2']],
            'CRLF line endings' => [
                "TEST_A=1\r\nTEST_B=\"x y\"\r\n\r\nTEST_C='z'\r\n",
                ['TEST_A' => '1', 'TEST_B' => 'x y', 'TEST_C' => 'z'],
            ],
            'BOM with CRLF' => ["\xEF\xBB\xBFTEST_A=1\r\nTEST_B=2\r\n", ['TEST_A' => '1', 'TEST_B' => '2']],
            'BOM after empty lines' => ["\n\r\n\r\xEF\xBB\xBFTEST_A=1\nTEST_B=2", ['TEST_A' => '1', 'TEST_B' => '2']],
            'CR line endings' => [
                "TEST_A=1\rTEST_B=\"x y\"\r\rTEST_C='z'\r",
                ['TEST_A' => '1', 'TEST_B' => 'x y', 'TEST_C' => 'z'],
            ],
            'CR without final line ending' => ["TEST_A=1\rTEST_B=2", ['TEST_A' => '1', 'TEST_B' => '2']],
            'mixed line endings' => [
                "TEST_A=1\rTEST_B=2\nTEST_C=3\r\nTEST_D=4\r\r\nTEST_E=5",
                ['TEST_A' => '1', 'TEST_B' => '2', 'TEST_C' => '3', 'TEST_D' => '4', 'TEST_E' => '5'],
            ],
            'BOM with CR' => ["\xEF\xBB\xBFTEST_A=1\rTEST_B=2\r", ['TEST_A' => '1', 'TEST_B' => '2']],
            'comment line ended by CR' => ["# TEST_OFF=1\rTEST_A=1", ['TEST_A' => '1']],
            'export followed by tab' => ["export\tTEST_A=1", ['TEST_A' => '1']],
            'export followed by several spaces' => ['export   TEST_A=1', ['TEST_A' => '1']],
            'key named export' => ['export=1', ['export' => '1']],
            'key named export with spaces around =' => ['export = 1', ['export' => '1']],
            'key named export with two spaces before =' => ['export  =1', ['export' => '1']],
            'key named export with tab and NUL before =' => ["export\t\0=1", ['export' => '1']],
            'key starting with export' => ['exportTEST=1', ['exportTEST' => '1']],
            'lowercase letters and digits in key' => ['test_a1=1', ['test_a1' => '1']],
            'space inside an unquoted value' => ['TEST_A=hello world', ['TEST_A' => 'hello world']],
            'equals sign at the start of a value' => ['TEST_A==value', ['TEST_A' => '=value']],
            'unquoted backslash sequence stays literal' => ['TEST_A=C:\new', ['TEST_A' => 'C:\new']],
            'form feed inside a value is kept' => ["TEST_A=a\fb", ['TEST_A' => "a\fb"]],
            'non-breaking space is no whitespace' => ["TEST_A=\xC2\xA0value\xC2\xA0", ['TEST_A' => "\xC2\xA0value\xC2\xA0"]],
            'byte of Latin-1 is returned as it is' => ["TEST_A=caf\xE9", ['TEST_A' => "caf\xE9"]],
        ];
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('syntaxProvider')]
    public function testParsesSyntax(string $content, array $expected): void
    {
        $this->assertSame($expected, EnvLoader::parse($this->createEnvFile($content)));
    }

    // In a separate process: setting a locale switches PCRE to other character tables for good
    #[RunInSeparateProcess]
    public function testWhitespaceAfterClosingQuoteDoesNotFollowTheLocale(): void
    {
        $path = $this->createEnvFile("TEST_A=\"v\"\xA0# comment");

        // On macOS, UTF-8 locales count the byte A0 as whitespace (1.x accepted it there)
        setlocale(LC_CTYPE, 'en_US.UTF-8', 'C.UTF-8');

        $this->expectException(TrailingCharactersException::class);
        EnvLoader::parse($path);
    }

    public function testLargeDoubleQuotedValue(): void
    {
        // Larger than the PCRE limits (8 KB with JIT, 50 KB without) that once made this fail
        $value = str_repeat('a', 200_000);
        $result = EnvLoader::parse($this->createEnvFile('TEST_BIG="' . $value . '" # comment'));

        $this->assertSame(['TEST_BIG' => $value], $result);
    }

    public function testLargeDoubleQuotedValueWithEscapes(): void
    {
        $path = $this->createEnvFile('TEST_BIG="' . str_repeat('\\"x\\\\\\n', 50_000) . '"');
        $result = EnvLoader::parse($path);

        $this->assertSame(['TEST_BIG' => str_repeat('"x\\\\n', 50_000)], $result);
    }

    public function testLargeSingleQuotedValue(): void
    {
        $value = str_repeat('a', 200_000);
        $result = EnvLoader::parse($this->createEnvFile("TEST_BIG='" . $value . "'"));

        $this->assertSame(['TEST_BIG' => $value], $result);
    }
}
