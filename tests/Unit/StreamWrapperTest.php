<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\FileNotFoundException;

/**
 * Paths that PHP would open with a stream wrapper: rejected, unless allowStreamWrappers lets the wrapper open them.
 */
class StreamWrapperTest extends EnvLoaderTestCase
{
    /**
     * @return array<string, array{string}> The path: {plain} stands for a local .env file, {phar} for a phar archive
     */
    public static function streamUrlProvider(): array
    {
        return [
            'phar' => ['phar://{phar}/inner.env'],
            'file' => ['file://{plain}'],
            'file in upper case' => ['FILE://{plain}'],
            'php://filter' => ['php://filter/read=string.toupper/resource={plain}'],
            'compress.zlib' => ['compress.zlib://{plain}'],
            'glob' => ['glob://{plain}'],
            'data' => ['data:text/plain,TEST_KEY=1'],
            'data with slashes' => ['data://text/plain,TEST_KEY=1'],
            'http' => ['http://127.0.0.1:9/.env'],
            'scheme without a wrapper' => ['no-such.wrapper+1://{plain}'],
        ];
    }

    #[DataProvider('streamUrlProvider')]
    public function testStreamUrlIsRejected(string $path): void
    {
        // Only a local file is read: a URL is not opened at all, whatever its wrapper would do
        $path = $this->expand($path);

        foreach ([false, true] as $load) {
            try {
                $load ? EnvLoader::load($path) : EnvLoader::parse($path);
                $this->fail('Expected FileNotFoundException');
            } catch (FileNotFoundException $e) {
                $this->assertSame("Stream wrapper not allowed: $path", $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{string, string}> The path, and the values as JSON or the message of the exception
     */
    public static function allowedStreamUrlProvider(): array
    {
        return [
            'phar' => ['phar://{phar}/inner.env', '{"TEST_KEY":"from-phar"}'],
            'file' => ['file://{plain}', '{"TEST_KEY":"plain"}'],
            // Wrappers that cannot stat a path are not found, as in 2.0
            'php://filter' => ['php://filter/read=string.toupper/resource={plain}', 'File not found: {path}'],
            'data' => ['data://text/plain,TEST_KEY=1', 'File not found: {path}'],
        ];
    }

    #[DataProvider('allowedStreamUrlProvider')]
    public function testAllowedStreamUrlIsOpenedWithItsWrapper(string $path, string $expected): void
    {
        $path = $this->expand($path);

        try {
            $result = (string) json_encode(EnvLoader::parse($path, allowStreamWrappers: true));
        } catch (FileNotFoundException $e) {
            $result = $e->getMessage();
        }

        $this->assertSame(str_replace('{path}', $path, $expected), $result);
    }

    public function testLoadOpensAnAllowedStreamUrl(): void
    {
        $values = EnvLoader::load($this->expand('phar://{phar}/inner.env'), allowStreamWrappers: true);

        $this->assertSame(['TEST_KEY' => 'from-phar'], $values);
        $this->assertSame('from-phar', $_ENV['TEST_KEY']);
    }

    public function testSchemeIsOnlyAtTheStartOfThePath(): void
    {
        // "://" further on is part of a local path: PHP takes a scheme only from the first characters
        $directory = $this->tempDir . '/a:';
        $this->assertTrue(mkdir($directory));

        try {
            $this->writeFile($directory . '/b.env', 'TEST_KEY=value');
            $this->assertSame(['TEST_KEY' => 'value'], EnvLoader::parse($this->tempDir . '/a://b.env'));
        } finally {
            @unlink($directory . '/b.env');
            rmdir($directory);
        }
    }

    /**
     * Replaces {plain} with a local .env file and {phar} with an archive holding inner.env, built where needed.
     */
    private function expand(string $path): string
    {
        if (str_contains($path, '{phar}')) {
            // phar.readonly cannot be switched off here, so the archive is built in a child process
            $phar = $this->tempDir . '/archive.phar';
            $script = '$phar = new Phar($argv[2]);'
                . '$phar->addFromString("inner.env", "TEST_KEY=from-phar");'
                . '$phar->setStub("<?php __HALT_COMPILER();");'
                . 'echo "built";';
            if (!is_file($phar)) {
                $this->assertSame('built', $this->runInChildProcess($script, $phar, ['-d', 'phar.readonly=0']));
            }
            $path = str_replace('{phar}', $phar, $path);
        }

        return str_replace('{plain}', $this->createEnvFile('TEST_KEY=plain'), $path);
    }
}
