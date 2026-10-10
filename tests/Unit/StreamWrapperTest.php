<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\FileNotFoundException;

/**
 * Paths that PHP opens with a stream wrapper: opened with it, as in 2.0, unless localOnly asks for a local file.
 */
class StreamWrapperTest extends EnvLoaderTestCase
{
    /**
     * @return array<string, array{string, string}> The path ({plain}: a local .env file, {phar}: a phar archive), and
     *                                              the values as JSON or the message of the exception without localOnly
     */
    public static function streamUrlProvider(): array
    {
        return [
            'phar' => ['phar://{phar}/inner.env', '{"TEST_KEY":"from-phar"}'],
            'file' => ['file://{plain}', '{"TEST_KEY":"plain"}'],
            'file in upper case' => ['FILE://{plain}', '{"TEST_KEY":"plain"}'],
            // The stat of the wrapper is asked before opening
            'directory in a phar' => ['phar://{phar}/directory', 'Not a file: {path}'],
            // Wrappers that cannot stat a path are not found
            'php://filter' => ['php://filter/read=string.toupper/resource={plain}', 'File not found: {path}'],
            'compress.zlib' => ['compress.zlib://{plain}', 'File not found: {path}'],
            'glob' => ['glob://{plain}', 'File not found: {path}'],
            'data' => ['data:text/plain,TEST_KEY=1', 'File not found: {path}'],
            'data with slashes' => ['data://text/plain,TEST_KEY=1', 'File not found: {path}'],
            'http' => ['http://127.0.0.1:9/.env', 'File not found: {path}'],
            'scheme without a wrapper' => ['no-such.wrapper+1://{plain}', 'File not found: {path}'],
        ];
    }

    #[DataProvider('streamUrlProvider')]
    public function testStreamUrlIsOpenedWithItsWrapper(string $path, string $expected): void
    {
        $path = $this->expand($path);

        try {
            $result = (string) json_encode(EnvLoader::parse($path));
        } catch (FileNotFoundException $e) {
            $result = $e->getMessage();
        }

        $this->assertSame(str_replace('{path}', $path, $expected), $result);
    }

    #[DataProvider('streamUrlProvider')]
    public function testStreamUrlIsRejectedWhereOnlyALocalFileMayBeRead(string $path): void
    {
        // Not opened at all, whatever its wrapper would do
        $path = $this->expand($path);

        foreach ([false, true] as $load) {
            try {
                $load ? EnvLoader::load($path, localOnly: true) : EnvLoader::parse($path, localOnly: true);
                $this->fail('Expected FileNotFoundException');
            } catch (FileNotFoundException $e) {
                $this->assertSame("Not a local file: $path", $e->getMessage());
            }
        }
    }

    public function testLoadOpensAStreamUrl(): void
    {
        $values = EnvLoader::load($this->expand('phar://{phar}/inner.env'));

        $this->assertSame(['TEST_KEY' => 'from-phar'], $values);
        $this->assertSame('from-phar', $_ENV['TEST_KEY']);
    }

    /**
     * @return array<string, array{string}> A local path relative to the directory of the test
     */
    public static function localPathLikeAUrlProvider(): array
    {
        return [
            // PHP takes a scheme only from the first characters, and only of two or more
            '"://" further on' => ['./a://b.env'],
            'scheme of one character' => ['a://b.env'],
        ];
    }

    #[DataProvider('localPathLikeAUrlProvider')]
    public function testLocalPathThatLooksLikeAUrlIsRead(string $path): void
    {
        $directory = $this->tempDir . '/a:';
        $this->assertTrue(mkdir($directory));
        $workingDirectory = (string) getcwd();

        try {
            $this->writeFile($directory . '/b.env', 'TEST_KEY=value');
            $this->assertTrue(chdir($this->tempDir));
            $this->assertSame(['TEST_KEY' => 'value'], EnvLoader::parse($path, localOnly: true));
        } finally {
            chdir($workingDirectory);
            @unlink($directory . '/b.env');
            rmdir($directory);
        }
    }

    /**
     * Replaces {plain} with a local .env file and {phar} with an archive holding inner.env and directory/inner.env,
     * built where needed.
     */
    private function expand(string $path): string
    {
        if (str_contains($path, '{phar}')) {
            // phar.readonly cannot be switched off here, so the archive is built in a child process
            $phar = $this->tempDir . '/archive.phar';
            $script = '$phar = new Phar($argv[2]);'
                . '$phar->addFromString("inner.env", "TEST_KEY=from-phar");'
                . '$phar->addFromString("directory/inner.env", "TEST_KEY=from-phar");'
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
