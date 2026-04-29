<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);

$basePath = dirname(__DIR__, 2);
$configPath = $basePath . '/config/app.php';
$logPath = $basePath . '/storage/api-error.log';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function isLocalRequest(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($remote, ['127.0.0.1', '::1'], true);
}

try {
    if (!isLocalRequest()) {
        respond([
            'ok' => false,
            'message' => 'Clear cache is only allowed from localhost.',
        ], 403);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond([
            'ok' => false,
            'message' => 'Use POST to clear runtime cache.',
        ], 405);
    }

    if (!is_file($configPath)) {
        throw new RuntimeException('Config file was not found: ' . $configPath);
    }

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Config file did not return an array.');
    }

    $storagePath = realpath($basePath . '/storage') ?: ($basePath . '/storage');
    if (!is_dir($storagePath)) {
        mkdir($storagePath, 0777, true);
    }

    $cachePath = (string) ($config['paths']['cache'] ?? ($storagePath . '/status-cache.json'));
    $historyPath = (string) ($config['paths']['history'] ?? ($storagePath . '/history.json'));
    $progressPath = $storagePath . DIRECTORY_SEPARATOR . 'monitor-progress.log';
    $apiErrorPath = $storagePath . DIRECTORY_SEPARATOR . 'api-error.log';

    $actions = [];

    foreach ([$cachePath, $progressPath, $apiErrorPath] as $filePath) {
        if (is_file($filePath)) {
            if (!@unlink($filePath)) {
                throw new RuntimeException('Failed to delete runtime file: ' . $filePath);
            }
            $actions[] = 'deleted ' . basename($filePath);
        }
    }

    file_put_contents($historyPath, "[]\n");
    $actions[] = 'reset ' . basename($historyPath);

    respond([
        'ok' => true,
        'message' => 'Runtime cache cleared. Waiting for the next polling cycle.',
        'cleared_at' => date('c'),
        'actions' => $actions,
    ]);
} catch (Throwable $e) {
    @file_put_contents(
        $logPath,
        '[' . date('c') . '] Clear cache failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL,
        FILE_APPEND
    );

    respond([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
