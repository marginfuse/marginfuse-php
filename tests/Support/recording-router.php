<?php

declare(strict_types=1);

/**
 * The router behind {@see \MarginFuse\Tests\Support\RecordingServer}.
 *
 * Records every request as one JSON line and answers /v1/decisions with the
 * verdict the test asked for. Everything else gets an empty 200: what matters
 * about events and acknowledgments is what they carried, not what came back.
 */

$dir = (string) getenv('MF_RECORDING_DIR');
$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '';

header('content-type: application/json');

// The readiness probe is the harness talking to itself, not the SDK.
if ($path === '/ready') {
    echo '{}';

    return true;
}

file_put_contents(
    $dir . '/requests.jsonl',
    (string) json_encode(
        ['path' => $path, 'body' => (string) file_get_contents('php://input')],
        JSON_THROW_ON_ERROR,
    ) . "\n",
    FILE_APPEND | LOCK_EX,
);

echo $path === '/v1/decisions'
    ? (string) file_get_contents($dir . '/decision.json')
    : '{}';

return true;
