<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

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

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/env-loader-test-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/{,.}*', GLOB_BRACE);
        $files = array_filter($files, fn ($f) => !in_array(basename($f), ['.', '..'], true));
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);

        // Reset $_ENV
        foreach (array_keys($_ENV) as $key) {
            if (str_starts_with($key, 'TEST_') || str_starts_with($key, '_TEST_')) {
                unset($_ENV[$key]);
            }
        }
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

        $this->assertEquals('value', $_ENV['TEST_KEY']);
    }

    public function testLoadsFileWithUtf8Bom(): void
    {
        // UTF-8 BOM: \xEF\xBB\xBF (common in Windows-created files)
        $path = $this->createEnvFile("\xEF\xBB\xBFTEST_BOM=value");
        EnvLoader::load($path);

        $this->assertEquals('value', $_ENV['TEST_BOM']);
    }

    public function testLoadsMultipleKeyValues(): void
    {
        $path = $this->createEnvFile("TEST_ONE=first\nTEST_TWO=second");
        EnvLoader::load($path);

        $this->assertEquals('first', $_ENV['TEST_ONE']);
        $this->assertEquals('second', $_ENV['TEST_TWO']);
    }

    public function testLoadsEmptyValue(): void
    {
        $path = $this->createEnvFile('TEST_EMPTY=');
        EnvLoader::load($path);

        $this->assertEquals('', $_ENV['TEST_EMPTY']);
    }

    public function testLoadsValueWithEqualsSign(): void
    {
        $path = $this->createEnvFile('TEST_PASSWORD=val=ue=with=equals');
        EnvLoader::load($path);

        $this->assertEquals('val=ue=with=equals', $_ENV['TEST_PASSWORD']);
    }

    public function testIgnoresLineWithoutEquals(): void
    {
        $path = $this->createEnvFile("INVALID_LINE\nTEST_VALID=value");
        EnvLoader::load($path);

        $this->assertEquals('value', $_ENV['TEST_VALID']);
        $this->assertArrayNotHasKey('INVALID_LINE', $_ENV);
    }

    public function testUnderscoreStartKeyIsValid(): void
    {
        $path = $this->createEnvFile('_TEST_PRIVATE=secret');
        EnvLoader::load($path);

        $this->assertEquals('secret', $_ENV['_TEST_PRIVATE']);
    }

    public function testStripsExportPrefix(): void
    {
        $path = $this->createEnvFile('export TEST_EXPORT=value');
        EnvLoader::load($path);

        $this->assertEquals('value', $_ENV['TEST_EXPORT']);
    }

    public function testStripsExportPrefixWithQuotes(): void
    {
        $path = $this->createEnvFile('export TEST_EXPORT_Q="hello world"');
        EnvLoader::load($path);

        $this->assertEquals('hello world', $_ENV['TEST_EXPORT_Q']);
    }

    // ============================================
    // Quotes
    // ============================================

    public function testLoadsDoubleQuotedValue(): void
    {
        $path = $this->createEnvFile('TEST_QUOTED="hello world"');
        EnvLoader::load($path);

        $this->assertEquals('hello world', $_ENV['TEST_QUOTED']);
    }

    public function testLoadsSingleQuotedValue(): void
    {
        $path = $this->createEnvFile("TEST_SINGLE='hello world'");
        EnvLoader::load($path);

        $this->assertEquals('hello world', $_ENV['TEST_SINGLE']);
    }

    public function testLoadsEscapedQuotes(): void
    {
        $path = $this->createEnvFile('TEST_ESCAPED="hello \"world\""');
        EnvLoader::load($path);

        $this->assertEquals('hello "world"', $_ENV['TEST_ESCAPED']);
    }

    public function testLoadsEscapedBackslash(): void
    {
        $path = $this->createEnvFile('TEST_BACKSLASH="path\\\\"');
        EnvLoader::load($path);

        $this->assertEquals('path\\', $_ENV['TEST_BACKSLASH']);
    }

    public function testSingleQuoteInDoubleQuotes(): void
    {
        $path = $this->createEnvFile('TEST_MIXED="it\'s ok"');
        EnvLoader::load($path);

        $this->assertEquals("it's ok", $_ENV['TEST_MIXED']);
    }

    public function testDoubleQuoteInSingleQuotes(): void
    {
        $path = $this->createEnvFile("TEST_MIXED_REV='say \"hi\"'");
        EnvLoader::load($path);

        $this->assertEquals('say "hi"', $_ENV['TEST_MIXED_REV']);
    }

    public function testWindowsPathPreserved(): void
    {
        $path = $this->createEnvFile('TEST_PATH="C:\\Users\\name\\docs"');
        EnvLoader::load($path);

        $this->assertEquals('C:\Users\name\docs', $_ENV['TEST_PATH']);
    }

    public function testBackslashNPreservedLiterally(): void
    {
        $path = $this->createEnvFile('TEST_LITERAL="hello\\nworld"');
        EnvLoader::load($path);

        $this->assertEquals('hello\nworld', $_ENV['TEST_LITERAL']);
    }

    public function testBackslashTPreservedLiterally(): void
    {
        $path = $this->createEnvFile('TEST_TAB="hello\\tworld"');
        EnvLoader::load($path);

        $this->assertEquals('hello\tworld', $_ENV['TEST_TAB']);
    }

    public function testBackslashRPreservedLiterally(): void
    {
        $path = $this->createEnvFile('TEST_CR="hello\\rworld"');
        EnvLoader::load($path);

        $this->assertEquals('hello\rworld', $_ENV['TEST_CR']);
    }

    // ============================================
    // Comments
    // ============================================

    public function testIgnoresCommentLines(): void
    {
        $path = $this->createEnvFile("# This is a comment\nTEST_KEY=value");
        EnvLoader::load($path);

        $this->assertEquals('value', $_ENV['TEST_KEY']);
        $this->assertArrayNotHasKey('#', $_ENV);
    }

    public function testHandlesInlineComment(): void
    {
        $path = $this->createEnvFile('TEST_INLINE=value # this is a comment');
        EnvLoader::load($path);

        $this->assertEquals('value', $_ENV['TEST_INLINE']);
    }

    public function testHashWithoutSpaceIsNotComment(): void
    {
        $path = $this->createEnvFile('TEST_HASH=value#notacomment');
        EnvLoader::load($path);

        $this->assertEquals('value#notacomment', $_ENV['TEST_HASH']);
    }

    public function testInlineCommentWithQuotes(): void
    {
        $path = $this->createEnvFile('TEST_QUOTED_COMMENT="value with # hash" # comment');
        EnvLoader::load($path);

        $this->assertEquals('value with # hash', $_ENV['TEST_QUOTED_COMMENT']);
    }

    // ============================================
    // Whitespace
    // ============================================

    public function testTrimsWhitespaceAroundKey(): void
    {
        $path = $this->createEnvFile('  TEST_SPACED  =value');
        EnvLoader::load($path);

        $this->assertEquals('value', $_ENV['TEST_SPACED']);
    }

    public function testTrimsWhitespaceAroundValue(): void
    {
        $path = $this->createEnvFile('TEST_TRIM=  value  ');
        EnvLoader::load($path);

        $this->assertEquals('value', $_ENV['TEST_TRIM']);
    }

    public function testIgnoresEmptyLines(): void
    {
        $path = $this->createEnvFile("\n\nTEST_EMPTY_LINES=value\n\n");
        EnvLoader::load($path);

        $this->assertEquals('value', $_ENV['TEST_EMPTY_LINES']);
    }

    // ============================================
    // Options (overwrite, required)
    // ============================================

    public function testDoesNotOverwriteByDefault(): void
    {
        $_ENV['TEST_EXISTING'] = 'original';
        $path = $this->createEnvFile('TEST_EXISTING=new');
        EnvLoader::load($path);

        $this->assertEquals('original', $_ENV['TEST_EXISTING']);
    }

    public function testOverwriteWhenEnabled(): void
    {
        $_ENV['TEST_OVERWRITE'] = 'original';
        $path = $this->createEnvFile('TEST_OVERWRITE=new');
        EnvLoader::load($path, overwrite: true);

        $this->assertEquals('new', $_ENV['TEST_OVERWRITE']);
    }

    public function testRequiredKeysAsArray(): void
    {
        $path = $this->createEnvFile("TEST_REQ_ONE=one\nTEST_REQ_TWO=two");
        EnvLoader::load($path, required: ['TEST_REQ_ONE', 'TEST_REQ_TWO']);

        $this->assertEquals('one', $_ENV['TEST_REQ_ONE']);
        $this->assertEquals('two', $_ENV['TEST_REQ_TWO']);
    }

    public function testRequiredKeysAsString(): void
    {
        $path = $this->createEnvFile("TEST_STR_ONE=one\nTEST_STR_TWO=two");
        EnvLoader::load($path, required: 'TEST_STR_ONE,TEST_STR_TWO');

        $this->assertEquals('one', $_ENV['TEST_STR_ONE']);
        $this->assertEquals('two', $_ENV['TEST_STR_TWO']);
    }

    public function testRequiredKeysIgnoresTrailingComma(): void
    {
        $path = $this->createEnvFile("TEST_TRAIL_ONE=one\nTEST_TRAIL_TWO=two");
        EnvLoader::load($path, required: 'TEST_TRAIL_ONE,TEST_TRAIL_TWO,');

        $this->assertEquals('one', $_ENV['TEST_TRAIL_ONE']);
        $this->assertEquals('two', $_ENV['TEST_TRAIL_TWO']);
    }

    public function testRequiredKeysIgnoresEmptyEntries(): void
    {
        $path = $this->createEnvFile("TEST_EMPTY_ONE=one\nTEST_EMPTY_TWO=two");
        EnvLoader::load($path, required: 'TEST_EMPTY_ONE,,TEST_EMPTY_TWO');

        $this->assertEquals('one', $_ENV['TEST_EMPTY_ONE']);
        $this->assertEquals('two', $_ENV['TEST_EMPTY_TWO']);
    }

    public function testRequiredKeysEmptyStringIsNoOp(): void
    {
        $path = $this->createEnvFile('TEST_EMPTY_REQ=value');
        EnvLoader::load($path, required: '');

        $this->assertEquals('value', $_ENV['TEST_EMPTY_REQ']);
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

        $this->assertEquals(['TEST_PARSE_ONE' => 'one', 'TEST_PARSE_TWO' => 'two'], $result);
        $this->assertArrayNotHasKey('TEST_PARSE_ONE', $_ENV);
        $this->assertArrayNotHasKey('TEST_PARSE_TWO', $_ENV);
    }

    public function testParseReturnsEmptyArrayForEmptyFile(): void
    {
        $path = $this->createEnvFile('');
        $result = EnvLoader::parse($path);

        $this->assertEquals([], $result);
    }

    public function testParseReturnsEmptyArrayForOnlyComments(): void
    {
        $path = $this->createEnvFile("# Comment one\n# Comment two");
        $result = EnvLoader::parse($path);

        $this->assertEquals([], $result);
    }
}
