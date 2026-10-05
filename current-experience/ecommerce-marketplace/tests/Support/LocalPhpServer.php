<?php

declare(strict_types=1);

namespace Portfolio\Commerce\Tests\Support;

use RuntimeException;

/** A disposable real HTTP boundary for integration tests, never an external endpoint. */
final class LocalPhpServer
{
    /** @var resource */
    private $process;
    private string $log;
    public readonly string $url;

    /** @param array<string, string> $environment */
    public function __construct(string $documentRoot, ?string $router = null, array $environment = [])
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Cannot reserve a loopback port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false) {
            throw new RuntimeException('Cannot read the reserved port.');
        }
        $this->url = 'http://' . $address;
        $log = tempnam(sys_get_temp_dir(), 'portfolio-http-');
        if ($log === false) {
            throw new RuntimeException('Cannot create server log.');
        }
        $this->log = $log;
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', $documentRoot];
        if ($router !== null) {
            $command[] = $router;
        }
        $inherited = getenv();
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ], $pipes, null, array_merge($inherited, $environment), ['bypass_shell' => true]);
        if (!is_resource($process)) {
            unlink($log);
            throw new RuntimeException('Cannot start disposable PHP server.');
        }
        $this->process = $process;
        fclose($pipes[0]);
        for ($i = 0; $i < 100; $i++) {
            if (!proc_get_status($process)['running']) {
                break;
            }
            $probe = @stream_socket_client('tcp://' . $address, timeout: 0.05);
            if ($probe !== false) {
                fclose($probe);
                return;
            }
            usleep(20_000);
        }
        $this->stop();
        throw new RuntimeException('Disposable PHP server failed to start.');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if (is_file($this->log)) {
            unlink($this->log);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
