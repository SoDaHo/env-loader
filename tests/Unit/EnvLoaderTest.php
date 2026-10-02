<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\FileNotFoundException;
use Sodaho\EnvLoader\Exception\FileNotReadableException;
use Sodaho\EnvLoader\Exception\InvalidKeyException;
use Sodaho\EnvLoader\Exception\MissingRequiredKeyException;
use Sodaho\EnvLoader\Exception\UnterminatedQuoteException;

class EnvLoaderTest extends TestCase
{
    private string $tempDir;

    /** @var array<mixed> */
    private array $envBackup;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/env-loader-test-' . uniqid();
        mkdir($this->tempDir);
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
    }

    private function createEnvFile(string $content): string
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, $content);
        return $path;
    }

    // ============================================
    // Basic Parsing
    // ============================================

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

    public function testIgnoresLineWithoutEquals(): void
    {
        $path = $this->createEnvFile("INVALID_LINE\nTEST_VALID=value");
        EnvLoader::load($path);

        $this->assertSame('value', $_ENV['TEST_VALID']);
        $this->assertArrayNotHasKey('INVALID_LINE', $_ENV);
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

    // ============================================
    // Quotes
    // ============================================

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

    // ============================================
    // Comments
    // ============================================

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

    // ============================================
    // Whitespace
    // ============================================

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

    // ============================================
    // Options (overwrite, required)
    // ============================================

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

    public function testRequiredKeysAsArray(): void
    {
        $path = $this->createEnvFile("TEST_REQ_ONE=one\nTEST_REQ_TWO=two");
        EnvLoader::load($path, required: ['TEST_REQ_ONE', 'TEST_REQ_TWO']);

        $this->assertSame('one', $_ENV['TEST_REQ_ONE']);
        $this->assertSame('two', $_ENV['TEST_REQ_TWO']);
    }

    public function testRequiredKeysAsString(): void
    {
        $path = $this->createEnvFile("TEST_STR_ONE=one\nTEST_STR_TWO=two");
        EnvLoader::load($path, required: 'TEST_STR_ONE,TEST_STR_TWO');

        $this->assertSame('one', $_ENV['TEST_STR_ONE']);
        $this->assertSame('two', $_ENV['TEST_STR_TWO']);
    }

    public function testRequiredKeysIgnoresTrailingComma(): void
    {
        $path = $this->createEnvFile("TEST_TRAIL_ONE=one\nTEST_TRAIL_TWO=two");
        EnvLoader::load($path, required: 'TEST_TRAIL_ONE,TEST_TRAIL_TWO,');

        $this->assertSame('one', $_ENV['TEST_TRAIL_ONE']);
        $this->assertSame('two', $_ENV['TEST_TRAIL_TWO']);
    }

    public function testRequiredKeysIgnoresEmptyEntries(): void
    {
        $path = $this->createEnvFile("TEST_EMPTY_ONE=one\nTEST_EMPTY_TWO=two");
        EnvLoader::load($path, required: 'TEST_EMPTY_ONE,,TEST_EMPTY_TWO');

        $this->assertSame('one', $_ENV['TEST_EMPTY_ONE']);
        $this->assertSame('two', $_ENV['TEST_EMPTY_TWO']);
    }

    public function testRequiredKeysEmptyStringIsNoOp(): void
    {
        $path = $this->createEnvFile('TEST_EMPTY_REQ=value');
        EnvLoader::load($path, required: '');

        $this->assertSame('value', $_ENV['TEST_EMPTY_REQ']);
    }

    // ============================================
    // Exceptions
    // ============================================

    public function testThrowsFileNotFoundException(): void
    {
        $this->expectException(FileNotFoundException::class);
        EnvLoader::load('/nonexistent/path/.env');
    }

    public function testThrowsFileNotFoundExceptionForDirectory(): void
    {
        $this->expectException(FileNotFoundException::class);
        EnvLoader::load($this->tempDir);
    }

    public function testThrowsInvalidKeyException(): void
    {
        $path = $this->createEnvFile('123INVALID=value');
        $this->expectException(InvalidKeyException::class);
        EnvLoader::load($path);
    }

    public function testThrowsInvalidKeyExceptionForHyphen(): void
    {
        $path = $this->createEnvFile('INVALID-KEY=value');
        $this->expectException(InvalidKeyException::class);
        EnvLoader::load($path);
    }

    public function testThrowsMissingRequiredKeyException(): void
    {
        $path = $this->createEnvFile('TEST_EXISTS=value');
        $this->expectException(MissingRequiredKeyException::class);
        EnvLoader::load($path, required: ['TEST_MISSING']);
    }

    public function testThrowsFileNotReadableException(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod not supported on Windows');
        }

        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Root can read any file regardless of permissions');
        }

        $path = $this->createEnvFile('TEST_KEY=value');
        chmod($path, 0o000);

        $this->expectException(FileNotReadableException::class);

        try {
            EnvLoader::load($path);
        } finally {
            chmod($path, 0o644); // Restore for cleanup
        }
    }

    public function testThrowsUnterminatedDoubleQuoteException(): void
    {
        $path = $this->createEnvFile('TEST_KEY="unterminated');
        $this->expectException(UnterminatedQuoteException::class);
        EnvLoader::load($path);
    }

    public function testThrowsUnterminatedSingleQuoteException(): void
    {
        $path = $this->createEnvFile("TEST_KEY='unterminated");
        $this->expectException(UnterminatedQuoteException::class);
        EnvLoader::load($path);
    }

    // ============================================
    // parse() Method
    // ============================================

    public function testParseReturnsArrayWithoutSettingEnv(): void
    {
        $path = $this->createEnvFile("TEST_PARSE_ONE=one\nTEST_PARSE_TWO=two");
        $result = EnvLoader::parse($path);

        $this->assertSame(['TEST_PARSE_ONE' => 'one', 'TEST_PARSE_TWO' => 'two'], $result);
        $this->assertArrayNotHasKey('TEST_PARSE_ONE', $_ENV);
        $this->assertArrayNotHasKey('TEST_PARSE_TWO', $_ENV);
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

    public function testParseThrowsFileNotFoundException(): void
    {
        $this->expectException(FileNotFoundException::class);
        EnvLoader::parse('/nonexistent/path/.env');
    }

    public function testParseThrowsInvalidKeyException(): void
    {
        $path = $this->createEnvFile('123INVALID=value');
        $this->expectException(InvalidKeyException::class);
        EnvLoader::parse($path);
    }

    public function testParseThrowsUnterminatedQuoteException(): void
    {
        $path = $this->createEnvFile('TEST_KEY="unterminated');
        $this->expectException(UnterminatedQuoteException::class);
        EnvLoader::parse($path);
    }

    // ============================================
    // Syntax Details
    // ============================================

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
            'tab before hash is not a comment' => ["TEST_A=value\t# text", ['TEST_A' => "value\t# text"]],
            'tab and hash after equals is a value' => ["TEST_A=\t#fff", ['TEST_A' => '#fff']],
            'first space-hash starts the comment' => ['TEST_A=one # two # three', ['TEST_A' => 'one']],
            'trailing space-hash' => ['TEST_A=value #', ['TEST_A' => 'value']],
            'export followed by tab' => ["export\tTEST_A=1", ['TEST_A' => '1']],
            'export followed by several spaces' => ['export   TEST_A=1', ['TEST_A' => '1']],
            'key named export' => ['export=1', ['export' => '1']],
            'key named export with spaces around =' => ['export = 1', ['export' => '1']],
            'key named export with two spaces before =' => ['export  =1', ['export' => '1']],
            'key named export with tab and NUL before =' => ["export\t\0=1", ['export' => '1']],
            'key starting with export' => ['exportTEST=1', ['exportTEST' => '1']],
            'export without assignment is ignored' => ["export TEST_A\nTEST_B=1", ['TEST_B' => '1']],
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
    public function testWhitespaceAfterClosingQuoteFollowsTheLocale(): void
    {
        $path = $this->createEnvFile("TEST_A=\"v\"\xA0# comment");

        // As in 1.0.0, whitespace is what PCRE's \s matches: on macOS, UTF-8 locales include the byte A0
        setlocale(LC_CTYPE, 'en_US.UTF-8', 'C.UTF-8');
        $expected = preg_match('/\s/', "\xA0") === 1 ? ['TEST_A' => 'v'] : UnterminatedQuoteException::class;

        try {
            $result = EnvLoader::parse($path);
        } catch (UnterminatedQuoteException $e) {
            $result = $e::class;
        }

        $this->assertSame($expected, $result);
    }

    // ============================================
    // load(): Guarantees
    // ============================================

    public function testRequiredKeysAsStringAreTrimmed(): void
    {
        $path = $this->createEnvFile("TEST_SPACE_ONE=one\nTEST_SPACE_TWO=two");
        EnvLoader::load($path, required: ' TEST_SPACE_ONE , TEST_SPACE_TWO ');

        $this->assertSame('two', $_ENV['TEST_SPACE_TWO']);
    }

    public function testRequiredKeysAsArrayAreTrimmedAndEmptyEntriesIgnored(): void
    {
        $path = $this->createEnvFile("TEST_ARR_ONE=one\nTEST_ARR_TWO=two");
        EnvLoader::load($path, required: [' TEST_ARR_ONE ', '', 'TEST_ARR_TWO']);

        $this->assertSame('two', $_ENV['TEST_ARR_TWO']);
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
}
