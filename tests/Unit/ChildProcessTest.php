<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;

/**
 * The helpers that run PHP in a child process report what went wrong there, instead of passing on output alone.
 */
class ChildProcessTest extends EnvLoaderTestCase
{
    public function testChildThatExitsWithAnErrorFailsTheTest(): void
    {
        // The output is what a test would expect; the status says the child failed after it
        $failure = null;
        try {
            $this->runInChildProcess('echo "expected"; exit(7);', '', []);
        } catch (AssertionFailedError $e) {
            $failure = $e->getMessage();
        }

        $this->assertNotNull($failure);
        $this->assertStringContainsString('The child process exited with 7: expected', $failure);
    }

    public function testChildThatDoesNotEndFailsTheTest(): void
    {
        $started = microtime(true);
        $failure = null;
        try {
            $this->runInChildProcess('sleep(30);', '', [], [], 0.5);
        } catch (AssertionFailedError $e) {
            $failure = $e->getMessage();
        }

        $this->assertSame('The child process did not end within 0.5 seconds', $failure);
        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function testChildThatWritesMuchToStderrDoesNotBlock(): void
    {
        // More than a pipe holds: read one pipe after the other, the child would wait for the second forever
        $output = $this->runInChildProcess('fwrite(STDERR, str_repeat("x", 1_000_000)); echo "done";', '', []);

        $this->assertSame('done' . str_repeat('x', 1_000_000), $output);
    }

    public function testFastCgiRequestThatEndsWithAServerErrorIsReported(): void
    {
        $binary = PhpCgi::binary();
        if ($binary === null) {
            $this->markTestSkipped('No php-cgi next to ' . PHP_BINARY);
        }

        // Without display_errors php-cgi answers a fatal error with status 500
        $script = $this->tempDir . '/request.php';
        $this->writeFile($script, '<?php throw new RuntimeException("failed");');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('php-cgi answered with a server error: Status: 500 Internal Server Error');
        PhpCgi::fastCgiRequest($binary, $script, [], ['-d', 'display_errors=0']);
    }
}
