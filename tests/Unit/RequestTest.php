<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\MissingRequiredKeyException;

/**
 * Under FastCGI and CGI a request must not overrule the file through getenv(): what the web server passes is no
 * process environment. (Through $_ENV, with an E in variables_order, see RequestChannelTest.)
 *
 * The requests run through php-cgi. CI installs it and fails on a skipped test, so these tests guard that every
 * getenv() call asks only the process (local_only): without it, the FastCGI tests see the request.
 */
class RequestTest extends EnvLoaderTestCase
{
    public function testRequestDoesNotWinOverTheFile(): void
    {
        $binary = PhpCgi::binary();
        if ($binary === null) {
            $this->markTestSkipped('No php-cgi next to ' . PHP_BINARY);
        }

        // Under FastCGI, getenv() returns request headers (HTTP_*) and the parameters of the web server
        $path = $this->createEnvFile("HTTP_X_TEST=file\nTEST_PARAM=file");
        $script = $this->tempDir . '/request.php';
        $this->writeFile(
            $script,
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$_ENV = [];'
            . 'Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ', required: ["TEST_REQUIRED"]);'
            . 'echo json_encode([$_ENV, getenv("HTTP_X_TEST"), getenv("TEST_PARAM"), getenv("TEST_REQUIRED_PARAM")]);'
        );
        $this->setProcessVariable('TEST_REQUIRED', 'process');

        // Web servers send GATEWAY_INTERFACE as a parameter as well; that is not CGI and must not switch off the process environment
        $response = PhpCgi::fastCgiRequest($binary, $script, [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'HTTP_X_TEST' => 'header',
            'TEST_PARAM' => 'param',
            'TEST_REQUIRED_PARAM' => 'param',
        ]);

        $this->assertSame(
            [['HTTP_X_TEST' => 'file', 'TEST_PARAM' => 'file', 'TEST_REQUIRED' => 'process'], 'header', 'param', 'param'],
            json_decode($response, true)
        );
    }

    public function testRequiredKeyFromARequestIsMissing(): void
    {
        $binary = PhpCgi::binary();
        if ($binary === null) {
            $this->markTestSkipped('No php-cgi next to ' . PHP_BINARY);
        }

        $path = $this->createEnvFile('TEST_OTHER=value');
        $script = $this->tempDir . '/request.php';
        $this->writeFile(
            $script,
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$_ENV = [];'
            . 'try { Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ', required: ["HTTP_X_TEST"]); }'
            . 'catch (Throwable $e) { echo json_encode([$e::class, $e->getMessage(), getenv("HTTP_X_TEST"), $_ENV]); }'
        );

        $response = PhpCgi::fastCgiRequest($binary, $script, ['HTTP_X_TEST' => 'header']);

        $this->assertSame(
            [MissingRequiredKeyException::class, 'Missing required key: HTTP_X_TEST', 'header', []],
            json_decode($response, true)
        );
    }

    public function testCgiRequestDoesNotWinOverTheFile(): void
    {
        $binary = PhpCgi::binary();
        if ($binary === null) {
            $this->markTestSkipped('No php-cgi next to ' . PHP_BINARY);
        }

        // Under plain CGI the request is the environment of the process: local_only does not tell them apart
        $path = $this->createEnvFile("HTTP_X_TEST=file\nCONTENT_TYPE=file");
        $script = $this->tempDir . '/request.php';
        $this->writeFile(
            $script,
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$_ENV = [];'
            . 'Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ');'
            . 'try { Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ', required: ["HTTP_X_REQUIRED"]); }'
            . 'catch (Throwable $e) { $error = $e::class; }'
            . 'echo json_encode([$_ENV, $error ?? null, getenv("HTTP_X_TEST", true), getenv("HTTP_X_REQUIRED", true)]);'
        );

        $response = PhpCgi::cgiRequest($binary, $script, [
            'HTTP_X_TEST' => 'header',
            'CONTENT_TYPE' => 'header',
            'HTTP_X_REQUIRED' => 'header',
        ]);

        $this->assertSame(
            [['HTTP_X_TEST' => 'file', 'CONTENT_TYPE' => 'file'], MissingRequiredKeyException::class, 'header', 'header'],
            json_decode($response, true)
        );
    }

    public function testProcessEnvironmentIsNotReadWhereItIsACgiRequest(): void
    {
        // The same without php-cgi: CGI sets GATEWAY_INTERFACE for every request
        $this->setProcessVariable('GATEWAY_INTERFACE', 'CGI/1.1');
        $this->setProcessVariable('TEST_PROCESS', 'process');
        $path = $this->createEnvFile('TEST_PROCESS=file');
        $_ENV = [];

        EnvLoader::load($path);
        $this->assertSame(['TEST_PROCESS' => 'file'], $_ENV);

        $_ENV = [];
        $this->expectException(MissingRequiredKeyException::class);
        EnvLoader::load($this->createEnvFile('TEST_OTHER=value'), required: ['TEST_PROCESS']);
    }

    public function testGatewayInterfaceOfARequestDoesNotSwitchOffTheProcessEnvironment(): void
    {
        // Under FastCGI, GATEWAY_INTERFACE is a parameter of the request ($_SERVER), not a variable of the process
        $this->setProcessVariable('TEST_PROCESS', 'process');
        $_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';

        try {
            EnvLoader::load($this->createEnvFile('TEST_PROCESS=file'));
        } finally {
            unset($_SERVER['GATEWAY_INTERFACE']);
        }

        $this->assertSame('process', $_ENV['TEST_PROCESS']);
    }

    public function testProcessEnvironmentIsNotReadWhereGetenvIsDisabled(): void
    {
        $path = $this->createEnvFile('TEST_PROCESS=file');
        $script = 'require $argv[1];'
            . 'Sodaho\EnvLoader\EnvLoader::load($argv[2]);'
            . 'echo json_encode([function_exists("getenv"), $_ENV["TEST_PROCESS"]]);'
            . 'try { Sodaho\EnvLoader\EnvLoader::load($argv[2], required: ["TEST_REQUIRED"]); }'
            . 'catch (Throwable $e) { echo $e::class; }';
        $output = $this->runInChildProcess(
            $script,
            $path,
            ['-d', 'disable_functions=getenv', '-d', 'variables_order=GPCS', '-d', 'display_errors=1'],
            ['TEST_PROCESS' => 'process', 'TEST_REQUIRED' => 'process']
        );

        // Without getenv() only $_ENV and the file count, as in 1.x
        $this->assertSame('[false,"file"]' . MissingRequiredKeyException::class, $output);
    }
}
