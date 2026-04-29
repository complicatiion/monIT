<?php
declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
$config = require $basePath . '/config/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respondJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function windowsQuote(string $value): string
{
    return '"' . str_replace('"', '\\"', $value) . '"';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(405, [
        'ok' => false,
        'message' => 'Only POST requests are allowed.'
    ]);
}

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedLocalAddresses = ['127.0.0.1', '::1'];

if (!in_array($remoteAddress, $allowedLocalAddresses, true)) {
    respondJson(403, [
        'ok' => false,
        'message' => 'Editing targets.json is only allowed from the local machine.'
    ]);
}

$targetsPath = realpath((string) $config['paths']['targets']);

if ($targetsPath === false || !is_file($targetsPath)) {
    respondJson(404, [
        'ok' => false,
        'message' => 'targets.json was not found.'
    ]);
}

$isWindows = PHP_OS_FAMILY === 'Windows';

if ($isWindows) {
    // Prefer classic Notepad because it is predictable on locked-down Windows systems.
    // The empty title argument after START is required when the executable/path is quoted.
    $command = 'cmd /c start "" notepad.exe ' . windowsQuote($targetsPath);
    $handle = @popen($command, 'r');

    if (!is_resource($handle)) {
        respondJson(500, [
            'ok' => false,
            'message' => 'Failed to launch Notepad for targets.json.',
            'path' => $targetsPath
        ]);
    }

    @pclose($handle);
} elseif (PHP_OS_FAMILY === 'Darwin') {
    @exec('open ' . escapeshellarg($targetsPath) . ' > /dev/null 2>&1 &');
} else {
    @exec('xdg-open ' . escapeshellarg($targetsPath) . ' > /dev/null 2>&1 &');
}

respondJson(200, [
    'ok' => true,
    'message' => 'targets.json was requested in the local system editor.',
    'path' => $targetsPath
]);
