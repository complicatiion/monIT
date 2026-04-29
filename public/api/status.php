<?php
declare(strict_types=1);

/*
 * monIT status API
 *
 * This endpoint is intentionally cache-first.
 * Reason: the PHP built-in web server is single-threaded. If /api/status.php runs
 * all checks synchronously, the whole dashboard can block while DNS/TCP/HTTP/SNMP
 * checks are still running.
 *
 * Recommended operation:
 * - start_server.bat serves the dashboard
 * - poll_loop.bat updates storage/status-cache.json in the background
 * - this API serves the latest cache quickly
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);

$basePath = dirname(__DIR__, 2);
$configPath = $basePath . '/config/app.php';
$monitorPath = $basePath . '/src/Monitor.php';
$logPath = $basePath . '/storage/api-error.log';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function monITRespond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function monITLog(string $path, string $message): void
{
    @file_put_contents(
        $path,
        '[' . date('c') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

function monITEmptyPayload(string $message): array
{
    return [
        'app_name' => 'monIT',
        'dashboard_name' => 'monIT Dashboard',
        'generated_at' => date('c'),
        'cache_only' => true,
        'error' => false,
        'message' => $message,
        'summary' => [
            'total' => 0,
            'up' => 0,
            'warning' => 0,
            'down' => 0
        ],
        'targets' => []
    ];
}

try {
    if (!is_file($configPath)) {
        throw new RuntimeException('Config file was not found: ' . $configPath);
    }

    $config = require $configPath;

    if (!is_array($config)) {
        throw new RuntimeException('Config file did not return an array.');
    }

    $cachePath = (string) ($config['paths']['cache'] ?? ($basePath . '/storage/status-cache.json'));
    $cacheTtl = (int) ($config['cache_ttl_seconds'] ?? 20);
    $cacheExists = is_file($cachePath);
    $cacheAge = $cacheExists ? (time() - filemtime($cachePath)) : null;

    /*
     * Normal dashboard requests should NOT run checks inline.
     * To force an inline run manually, call:
     * /api/status.php?refresh=1&inline=1
     */
    $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    $allowInlineRefresh = isset($_GET['inline']) && $_GET['inline'] === '1';
    $shouldRunInline = $forceRefresh && $allowInlineRefresh;

    if (!$shouldRunInline && $cacheExists) {
        $raw = file_get_contents($cachePath);
        $payload = $raw ? json_decode($raw, true) : null;

        if (is_array($payload)) {
            $payload['cache_only'] = true;
            $payload['cache_age_seconds'] = $cacheAge;
            $payload['cache_stale'] = $cacheAge !== null && $cacheAge > $cacheTtl;
            monITRespond($payload, 200);
        }

        monITLog($logPath, 'Cache file exists but contains invalid JSON: ' . $cachePath);
        monITRespond(monITEmptyPayload('Cache file exists but contains invalid JSON.'), 200);
    }

    if (!$shouldRunInline && !$cacheExists) {
        monITRespond(monITEmptyPayload('No cache exists yet. Start poll_loop.bat to generate monitoring data.'), 200);
    }

    if (!is_file($monitorPath)) {
        throw new RuntimeException('Monitor class file was not found: ' . $monitorPath);
    }

    require_once $monitorPath;

    if (!class_exists('Monitor')) {
        throw new RuntimeException('Monitor class was not found after loading Monitor.php.');
    }

    @set_time_limit(120);

    $monitor = new Monitor($config);
    $payload = $monitor->run();

    if (!is_array($payload)) {
        throw new RuntimeException('Monitor did not return a valid payload.');
    }

    $payload['cache_only'] = false;
    $payload['cache_age_seconds'] = 0;
    $payload['cache_stale'] = false;

    monITRespond($payload, 200);
} catch (Throwable $e) {
    monITLog($logPath, $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    /*
     * Try cache fallback even if inline refresh failed.
     */
    if (isset($cachePath) && is_file($cachePath)) {
        $raw = file_get_contents($cachePath);
        $payload = $raw ? json_decode($raw, true) : null;

        if (is_array($payload)) {
            $payload['cache_only'] = true;
            $payload['cache_error'] = $e->getMessage();
            $payload['cache_age_seconds'] = time() - filemtime($cachePath);
            $payload['cache_stale'] = true;
            monITRespond($payload, 200);
        }
    }

    monITRespond([
        'app_name' => 'monIT',
        'dashboard_name' => 'monIT Dashboard',
        'generated_at' => date('c'),
        'error' => true,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'summary' => [
            'total' => 0,
            'up' => 0,
            'warning' => 0,
            'down' => 0
        ],
        'targets' => []
    ], 200);
}
