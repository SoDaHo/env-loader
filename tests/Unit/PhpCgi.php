<?php

declare(strict_types=1);

namespace Sodaho\EnvLoader\Tests\Unit;

/**
 * Runs a script through php-cgi as a web server would, over FastCGI or as a plain CGI process.
 *
 * Only there getenv() and the process environment differ - a CLI process has no request.
 */
class PhpCgi
{
    private const BEGIN_REQUEST = 1;
    private const END_REQUEST = 3;
    private const PARAMS = 4;
    private const STDIN = 5;
    private const STDOUT = 6;

    private const TIMEOUT_SECONDS = 20;

    /**
     * The php-cgi binary that belongs to the running PHP, null if there is none.
     */
    public static function binary(): ?string
    {
        $directory = dirname(PHP_BINARY);
        $suffix = substr(basename(PHP_BINARY), 3);

        foreach (["$directory/php-cgi$suffix", "$directory/php-cgi"] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Sends one request over FastCGI: the parameters reach PHP as a web server sends them, not as environment.
     *
     * @param array<string, string> $params
     *
     * @return string Body of the response
     */
    public static function fastCgiRequest(string $binary, string $script, array $params): string
    {
        $address = self::freeAddress();
        $process = proc_open(
            [$binary, '-d', 'display_errors=1', '-b', $address],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if ($process === false) {
            throw new \RuntimeException("Could not start $binary");
        }

        try {
            $deadline = microtime(true) + self::TIMEOUT_SECONDS;
            $socket = self::connect($address, $deadline);
            $params += ['SCRIPT_FILENAME' => $script, 'REQUEST_METHOD' => 'GET'];
            $pairs = '';
            foreach ($params as $name => $value) {
                $pairs .= self::length($name) . self::length($value) . $name . $value;
            }

            fwrite(
                $socket,
                self::record(self::BEGIN_REQUEST, pack('nCx5', 1, 0))
                . self::record(self::PARAMS, $pairs) . self::record(self::PARAMS, '')
                . self::record(self::STDIN, '')
            );

            $output = '';
            $ended = false;
            while (!$ended) {
                $fields = unpack('Cversion/Ctype/nid/nlength/Cpadding', self::read($socket, 8, $deadline));
                if ($fields === false) {
                    throw new \RuntimeException('Invalid FastCGI record');
                }

                $content = self::read($socket, $fields['length'], $deadline);
                self::read($socket, $fields['padding'], $deadline);

                if ($fields['type'] === self::STDOUT) {
                    $output .= $content;
                }
                $ended = $fields['type'] === self::END_REQUEST;
            }
            fclose($socket);
        } finally {
            self::stop($process);
        }

        return self::body($output);
    }

    /**
     * Runs one request as a plain CGI process: the request is the environment of the process.
     *
     * @param array<string, string> $environment
     *
     * @return string Body of the response
     */
    public static function cgiRequest(string $binary, string $script, array $environment): string
    {
        $environment += [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REDIRECT_STATUS' => '200',
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_FILENAME' => $script,
        ];
        $process = proc_open(
            [$binary, '-d', 'display_errors=1'],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $environment
        );
        if ($process === false) {
            throw new \RuntimeException("Could not start $binary");
        }

        $finished = false;
        try {
            $deadline = microtime(true) + self::TIMEOUT_SECONDS;
            stream_set_blocking($pipes[1], false);
            $output = '';
            while (!feof($pipes[1])) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('php-cgi did not answer in time');
                }

                // Wait up to a second for output, so that the deadline is checked
                $read = [$pipes[1]];
                $write = $except = null;
                if (stream_select($read, $write, $except, 1) > 0) {
                    $output .= (string) fread($pipes[1], 8192);
                }
            }
            $finished = true;
        } finally {
            if (!$finished) {
                self::stop($process);
            }
        }

        // The output has ended, so the process is about to exit
        $status = proc_close($process);
        if ($status !== 0) {
            throw new \RuntimeException("php-cgi exited with status $status");
        }

        return self::body($output);
    }

    /**
     * Headers and body are separated by an empty line.
     */
    private static function body(string $output): string
    {
        return explode("\r\n\r\n", $output, 2)[1] ?? '';
    }

    private static function freeAddress(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        if ($server === false) {
            throw new \RuntimeException('Could not find a free port');
        }

        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return $address;
    }

    /**
     * @return resource
     */
    private static function connect(string $address, float $deadline)
    {
        // php-cgi needs a moment until it listens
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://$address", $code, $message, 1);
            if ($socket !== false) {
                stream_set_timeout($socket, 1);

                return $socket;
            }
            usleep(50_000);
        }

        throw new \RuntimeException("php-cgi does not listen on $address");
    }

    /**
     * @param resource $process
     */
    private static function stop($process): void
    {
        proc_terminate($process);

        // Do not wait forever for a process that ignores the signal
        for ($attempt = 0; $attempt < 100 && proc_get_status($process)['running']; $attempt++) {
            usleep(20_000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }

        proc_close($process);
    }

    private static function record(int $type, string $content): string
    {
        return pack('CCnnCx', 1, $type, 1, strlen($content), 0) . $content;
    }

    private static function length(string $text): string
    {
        $length = strlen($text);

        return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
    }

    /**
     * Reads exactly $length bytes; a response that ends early or takes too long is an error.
     *
     * @param resource $socket
     */
    private static function read($socket, int $length, float $deadline): string
    {
        $data = '';
        while (($missing = $length - strlen($data)) > 0) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('php-cgi did not answer in time');
            }

            // The socket times out after a second, so that the deadline is checked: nothing read is no error yet
            $chunk = (string) fread($socket, $missing);
            if ($chunk === '' && feof($socket)) {
                throw new \RuntimeException('php-cgi closed the connection before the end of the response');
            }
            $data .= $chunk;
        }

        return $data;
    }
}
