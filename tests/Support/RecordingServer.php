<?php

declare(strict_types=1);

namespace MarginFuse\Tests\Support;

/**
 * A throwaway MarginFuse for one test to talk to.
 *
 * What a call was billed as leaves this SDK on the wire and nowhere else, so
 * the only honest place to assert it is the server that received it. PHP's
 * built-in server runs in its own process because curl_exec blocks this one.
 */
final class RecordingServer
{
    private const READY_TIMEOUT_SECONDS = 5.0;

    /** @var null|resource */
    private mixed $process;

    /** @param resource $process */
    private function __construct(
        public readonly string $baseUrl,
        private readonly string $dir,
        mixed $process,
    ) {
        $this->process = $process;
    }

    /**
     * Starts a server that answers /v1/decisions with `$decision`.
     *
     * @param mixed $decision the decoded wire response, including malformed JSON values
     */
    public static function start(mixed $decision): self
    {
        $dir = sys_get_temp_dir() . '/marginfuse-recording-' . bin2hex(random_bytes(8));
        if (!mkdir($dir) && !is_dir($dir)) {
            throw new \RuntimeException("recording server: could not create {$dir}");
        }
        file_put_contents($dir . '/decision.json', json_encode($decision, JSON_THROW_ON_ERROR));

        $port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/recording-router.php'],
            [
                // The log goes to a file rather than a pipe nobody reads: a
                // full pipe buffer would stall the server mid-test.
                1 => ['file', $dir . '/server.log', 'a'],
                2 => ['file', $dir . '/server.log', 'a'],
            ],
            $pipes,
            null,
            ['MF_RECORDING_DIR' => $dir] + getenv(),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('recording server: could not start php -S');
        }

        $server = new self("http://127.0.0.1:{$port}", $dir, $process);
        $server->waitUntilReady();

        return $server;
    }

    /** @return list<array<string, mixed>> every event the SDK sent, in order */
    public function events(): array
    {
        $events = [];
        foreach ($this->requests('/v1/events') as $body) {
            /** @var list<array<string, mixed>> $batch */
            $batch = is_array($body['events'] ?? null) ? $body['events'] : [];
            foreach ($batch as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** @return list<string> the acknowledgments sent for one decision, in order */
    public function acknowledgments(string $decisionId): array
    {
        $sent = [];
        foreach ($this->requests('/v1/decisions/' . rawurlencode($decisionId) . '/ack') as $body) {
            $acknowledgment = $body['acknowledgment'] ?? null;
            if (is_string($acknowledgment)) {
                $sent[] = $acknowledgment;
            }
        }

        return $sent;
    }

    /** Stops the server and takes its recording with it. Safe to call twice. */
    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;

        foreach (['decision.json', 'requests.jsonl', 'server.log'] as $name) {
            $file = $this->dir . '/' . $name;
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /** @return list<array<string, mixed>> the JSON bodies posted to one path, in order */
    private function requests(string $path): array
    {
        $log = $this->dir . '/requests.jsonl';
        if (!is_file($log)) {
            return [];
        }

        $bodies = [];
        foreach (explode("\n", trim((string) file_get_contents($log))) as $line) {
            if ($line === '') {
                continue;
            }
            /** @var array{path: string, body: string} $entry */
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($entry['path'] !== $path) {
                continue;
            }
            /** @var array<string, mixed> $body */
            $body = json_decode($entry['body'], true, 512, JSON_THROW_ON_ERROR);
            $bodies[] = $body;
        }

        return $bodies;
    }

    /**
     * The port is claimed and released before php -S takes it. A racing
     * process could win it in between; nothing else here is listening.
     */
    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($probe === false) {
            throw new \RuntimeException("recording server: no free port ({$error})");
        }
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    /** Curl, not fsockopen: a refused connection here must not raise a warning. */
    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + self::READY_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            $handle = curl_init($this->baseUrl . '/ready');
            if ($handle !== false) {
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT_MS => 250,
                    CURLOPT_CONNECTTIMEOUT_MS => 250,
                ]);
                if (is_string(curl_exec($handle))) {
                    return;
                }
            }
            usleep(20_000);
        }

        $this->stop();

        throw new \RuntimeException('recording server: never came up');
    }
}
