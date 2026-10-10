<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use Sodaho\EnvLoader\Exception\RequestVariableException;

/**
 * Under FastCGI with an E in variables_order PHP fills $_ENV with the request, and $_ENV wins over the file:
 * rejectRequestVariables throws where a key named like a request variable would take its value from there.
 *
 * The requests run through php-cgi with variables_order set explicitly; unlike the other request tests, the script
 * leaves $_ENV as PHP filled it.
 */
class RequestChannelTest extends EnvLoaderTestCase
{
    private const FILE = "HTTP_X_TEST=file\nCONTENT_TYPE=file\nTEST_PARAM=file\nTEST_FILE_ONLY=file";

    private const PARAMS = [
        'HTTP_X_TEST' => 'header',
        'HTTP_X_ADMIN_TOKEN' => 'header',
        'CONTENT_TYPE' => 'header',
        'TEST_PARAM' => 'param',
    ];

    public function testRequestWinsOverTheFileWithoutTheGuard(): void
    {
        // The edge the README warns of: a header sets a key of the file, and fulfils a required key
        $response = $this->request('EGPCS', ['required' => ['HTTP_X_ADMIN_TOKEN']]);

        $this->assertSame(['ok', [
            'HTTP_X_TEST' => 'header',
            'CONTENT_TYPE' => 'header',
            'TEST_PARAM' => 'param',
            'TEST_FILE_ONLY' => 'file',
            'HTTP_X_ADMIN_TOKEN' => 'header',
        ]], $response);
    }

    public function testGuardRejectsValuesFromTheRequest(): void
    {
        // A parameter of the web server with another name (fastcgi_param) is trusted configuration and still wins
        $response = $this->request('EGPCS', [
            'required' => ['HTTP_X_ADMIN_TOKEN'],
            'rejectRequestVariables' => true,
        ]);

        // Nothing of the file is written
        $this->assertSame([
            RequestVariableException::class,
            'Values from the request in $_ENV for keys: HTTP_X_TEST, CONTENT_TYPE, HTTP_X_ADMIN_TOKEN',
            ['HTTP_X_TEST' => 'header', 'CONTENT_TYPE' => 'header', 'TEST_PARAM' => 'param', 'HTTP_X_ADMIN_TOKEN' => 'header'],
        ], $response);
    }

    public function testGuardLetsTheFileWinWithOverwrite(): void
    {
        $response = $this->request('EGPCS', ['overwrite' => true, 'rejectRequestVariables' => true]);

        $this->assertSame(['ok', [
            'HTTP_X_TEST' => 'file',
            'CONTENT_TYPE' => 'file',
            'TEST_PARAM' => 'file',
            'TEST_FILE_ONLY' => 'file',
            'HTTP_X_ADMIN_TOKEN' => 'header',
        ]], $response);
    }

    public function testGuardChecksARequiredKeyThatTheFileDoesNotOverwrite(): void
    {
        // With overwrite the file wins for its own keys, HTTP_X_TEST among them; a required key it does not have
        // still takes its value from $_ENV, where the request put it
        $response = $this->request('EGPCS', [
            'overwrite' => true,
            'required' => ['HTTP_X_ADMIN_TOKEN', 'HTTP_X_TEST'],
            'rejectRequestVariables' => true,
        ]);

        $this->assertSame([
            RequestVariableException::class,
            'Value from the request in $_ENV for key: HTTP_X_ADMIN_TOKEN',
            ['HTTP_X_TEST' => 'header', 'CONTENT_TYPE' => 'header', 'TEST_PARAM' => 'param', 'HTTP_X_ADMIN_TOKEN' => 'header'],
        ], $response);
    }

    public function testGuardHasNothingToDoWithoutAnEInVariablesOrder(): void
    {
        // PHP does not fill $_ENV then; what the application put there is no value from the request
        $response = $this->request('GPCS', ['rejectRequestVariables' => true], '$_ENV["HTTP_X_TEST"] = "app";');

        $this->assertSame(['ok', [
            'HTTP_X_TEST' => 'app',
            'CONTENT_TYPE' => 'file',
            'TEST_PARAM' => 'file',
            'TEST_FILE_ONLY' => 'file',
        ]], $response);
    }

    public function testValueOfTheProcessEnvironmentIsNoValueFromTheRequest(): void
    {
        // With an E, PHP also copies the process environment into $_ENV, and a header of the same name overrides it
        $this->setProcessVariable('HTTP_X_TEST', 'process');

        $arguments = ['rejectRequestVariables' => true];

        // No header of that name: the value in $_ENV is the one of the process
        $response = $this->request('EGPCS', $arguments, '', ['HTTP_X_TEST' => 'process'] + self::PARAMS);
        $this->assertSame([
            RequestVariableException::class,
            'Value from the request in $_ENV for key: CONTENT_TYPE',
            ['HTTP_X_TEST' => 'process', 'CONTENT_TYPE' => 'header', 'TEST_PARAM' => 'param', 'HTTP_X_ADMIN_TOKEN' => 'header'],
        ], $response);

        // The header overrides the process environment
        $response = $this->request('EGPCS', $arguments, '', array_diff_key(self::PARAMS, ['CONTENT_TYPE' => true]));
        $this->assertSame([
            RequestVariableException::class,
            'Value from the request in $_ENV for key: HTTP_X_TEST',
            ['HTTP_X_TEST' => 'header', 'TEST_PARAM' => 'param', 'HTTP_X_ADMIN_TOKEN' => 'header'],
        ], $response);
    }

    public function testGuardRejectsValuesFromACgiRequest(): void
    {
        // Under plain CGI the request is the process environment as well: no value of it counts as trusted
        $binary = PhpCgi::binary();
        if ($binary === null) {
            $this->markTestSkipped('No php-cgi next to ' . PHP_BINARY);
        }

        $path = $this->createEnvFile(self::FILE);
        $script = $this->tempDir . '/request.php';
        $this->writeFile(
            $script,
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . 'try { Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ', rejectRequestVariables: true); }'
            . 'catch (Throwable $e) { echo json_encode([$e::class, $e->getMessage()]); }'
        );

        $response = PhpCgi::cgiRequest($binary, $script, ['HTTP_X_TEST' => 'header'], ['-d', 'variables_order=EGPCS']);

        $this->assertSame(
            [RequestVariableException::class, 'Value from the request in $_ENV for key: HTTP_X_TEST'],
            json_decode($response, true)
        );
    }

    public function testGuardIsOffOnTheCommandLine(): void
    {
        // The CLI has no request: what is in $_ENV there comes from the process or the application
        $path = $this->createEnvFile(self::FILE);
        $script = 'require $argv[1];'
            . '$_ENV["HTTP_X_TEST"] = "app";'
            . 'try { Sodaho\EnvLoader\EnvLoader::load($argv[2], rejectRequestVariables: true); }'
            . 'catch (Throwable $e) { echo $e::class; }'
            . 'echo $_ENV["HTTP_X_TEST"];';

        $this->assertSame('app', $this->runInChildProcess($script, $path, ['-d', 'variables_order=EGPCS']));
    }

    public function testGuardKnowsEveryNameOfARequestVariable(): void
    {
        // Every name the README lists, a header and a copy of Apache after an internal redirect; variables_order in
        // lower case, which PHP accepts as well. php-cgi itself needs SCRIPT_FILENAME, PATH_TRANSLATED and
        // REQUEST_METHOD: the request brings them with the values it needs
        $names = [
            'AUTH_TYPE', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'GATEWAY_INTERFACE', 'PATH_INFO', 'PATH_TRANSLATED',
            'QUERY_STRING', 'REMOTE_ADDR', 'REMOTE_HOST', 'REMOTE_IDENT', 'REMOTE_USER', 'REQUEST_METHOD', 'SCRIPT_NAME',
            'SERVER_NAME', 'SERVER_PORT', 'SERVER_PROTOCOL', 'SERVER_SOFTWARE',
            'DOCUMENT_ROOT', 'DOCUMENT_URI', 'FCGI_ROLE', 'HTTPS', 'REMOTE_PORT', 'REQUEST_SCHEME', 'REQUEST_URI',
            'SCRIPT_FILENAME', 'SERVER_ADDR',
            'ORIG_PATH_INFO', 'ORIG_SCRIPT_NAME', 'PHP_AUTH_DIGEST', 'PHP_AUTH_PW', 'PHP_AUTH_USER', 'PHP_SELF',
            'HTTP_X_ANY', 'REDIRECT_HTTP_X_ANY', 'REDIRECT_URL',
        ];
        $params = array_fill_keys(array_diff($names, ['SCRIPT_FILENAME', 'REQUEST_METHOD', 'FCGI_ROLE']), 'header');
        $params['PATH_TRANSLATED'] = $this->tempDir . '/request.php';
        $content = implode("\n", array_map(static fn (string $name): string => "$name=file", $names)) . "\nTEST_PLAIN=file";

        $response = $this->request('egpcs', ['rejectRequestVariables' => true], '', $params, $content);

        $this->assertSame(RequestVariableException::class, $response[0]);
        $this->assertSame('Values from the request in $_ENV for keys: ' . implode(', ', $names), $response[1]);
    }

    public function testGuardThrowsBeforeTheCheckOfRequiredKeys(): void
    {
        $response = $this->request('EGPCS', [
            'required' => ['TEST_MISSING', 'HTTP_X_ADMIN_TOKEN'],
            'rejectRequestVariables' => true,
        ]);

        $this->assertSame(RequestVariableException::class, $response[0]);
    }

    public function testGuardIsOffUnderPhpdbg(): void
    {
        // phpdbg runs scripts from the command line as well: no request
        $phpdbg = PhpCgi::phpdbg();
        if ($phpdbg === null) {
            $this->markTestSkipped('No phpdbg next to ' . PHP_BINARY);
        }

        $path = $this->createEnvFile(self::FILE);
        $script = $this->tempDir . '/script.php';
        $this->writeFile(
            $script,
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$_ENV["HTTP_X_TEST"] = "app";'
            . 'try { Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ', rejectRequestVariables: true); }'
            . 'catch (Throwable $e) { echo $e::class; }'
            . 'echo PHP_SAPI, " ", $_ENV["HTTP_X_TEST"];'
        );

        $output = $this->runCommand([$phpdbg, '-qrr', '-d', 'variables_order=EGPCS', $script]);

        $this->assertSame('phpdbg app', $output);
    }

    /**
     * Runs load() with the file of this test in a FastCGI request.
     *
     * @param array<string, mixed> $arguments Named arguments of load() besides the path
     * @param array<string, string> $params The request
     * @param string $content The file
     *
     * @return list<mixed> "ok" or the class and message of the exception, then the keys of interest in $_ENV
     */
    private function request(
        string $variablesOrder,
        array $arguments,
        string $prelude = '',
        array $params = self::PARAMS,
        string $content = self::FILE
    ): array {
        $binary = PhpCgi::binary();
        if ($binary === null) {
            $this->markTestSkipped('No php-cgi next to ' . PHP_BINARY);
        }

        $path = $this->createEnvFile($content);
        $script = $this->tempDir . '/request.php';
        $keys = ['HTTP_X_TEST', 'CONTENT_TYPE', 'TEST_PARAM', 'TEST_FILE_ONLY', 'HTTP_X_ADMIN_TOKEN'];
        $this->writeFile(
            $script,
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . $prelude
            . '$arguments = ' . var_export($arguments, true) . ';'
            . 'try { Sodaho\EnvLoader\EnvLoader::load(' . var_export($path, true) . ', ...$arguments); $result = ["ok"]; }'
            . 'catch (Throwable $e) { $result = [$e::class, $e->getMessage()]; }'
            . '$env = [];'
            . 'foreach (' . var_export($keys, true) . ' as $key) {'
            . '    if (array_key_exists($key, $_ENV)) { $env[$key] = $_ENV[$key]; }'
            . '}'
            . '$result[] = $env;'
            . 'echo json_encode($result);'
        );

        // Without fix_pathinfo, php-cgi leaves PATH_INFO and PATH_TRANSLATED as the request brings them
        $options = ['-d', "variables_order=$variablesOrder", '-d', 'cgi.fix_pathinfo=0'];
        $response = PhpCgi::fastCgiRequest($binary, $script, $params, $options);
        $result = json_decode($response, true);
        $this->assertIsArray($result, $response);

        return array_values($result);
    }
}
