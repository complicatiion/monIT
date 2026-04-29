<?php
declare(strict_types=1);

/*
 * monIT CLI poller
 *
 * This script is intended to be started from the monIT project root:
 *
 *   C:\php\php.exe cli\poll.php
 *
 * It validates config/targets.json, runs one monitoring cycle and writes
 * storage/status-cache.json for the dashboard API.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

$basePath = dirname(__DIR__);
$configPath = $basePath . '/config/app.php';
$monitorPath = $basePath . '/src/Monitor.php';

function cliLine(string $message): void
{
    if (PHP_SAPI === 'cli') {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        flush();
    }
}

function fail(string $message, int $code = 1): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message . PHP_EOL);
        exit($code);
    }

    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    cliLine('monIT poller started.');
    cliLine('Project root: ' . $basePath);

    if (!is_file($configPath)) {
        fail('Config file was not found: ' . $configPath);
    }

    if (!is_file($monitorPath)) {
        fail('Monitor class file was not found: ' . $monitorPath);
    }

    $config = require $configPath;

    if (!is_array($config)) {
        fail('Config file did not return an array.');
    }

    $targetsPath = (string) ($config['paths']['targets'] ?? ($basePath . '/config/targets.json'));
    $cachePath = (string) ($config['paths']['cache'] ?? ($basePath . '/storage/status-cache.json'));
    $historyPath = (string) ($config['paths']['history'] ?? ($basePath . '/storage/history.json'));

    cliLine('Targets file: ' . $targetsPath);
    cliLine('Cache file: ' . $cachePath);
    cliLine('History file: ' . $historyPath);

    if (!is_file($targetsPath)) {
        fail('Targets file was not found: ' . $targetsPath);
    }

    $targetsRaw = file_get_contents($targetsPath);
    if ($targetsRaw === false) {
        fail('Failed to read targets file: ' . $targetsPath);
    }

    $targets = json_decode($targetsRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fail('Targets JSON is invalid: ' . json_last_error_msg());
    }

    if (!is_array($targets)) {
        fail('Targets JSON must contain an array.');
    }

    cliLine('Targets JSON valid. Target count: ' . count($targets));

    if (!is_dir(dirname($cachePath))) {
        mkdir(dirname($cachePath), 0777, true);
    }

    if (!is_dir(dirname($historyPath))) {
        mkdir(dirname($historyPath), 0777, true);
    }

    require_once $monitorPath;

    if (!class_exists('Monitor')) {
        fail('Monitor class was not found after loading Monitor.php.');
    }

    cliLine('Running monitoring cycle...');
    $startedAt = microtime(true);

    $monitor = new Monitor($config);
    $payload = $monitor->run();

    if (!is_array($payload)) {
        fail('Monitor returned an invalid payload.');
    }

    $duration = round(microtime(true) - $startedAt, 2);
    $summary = $payload['summary'] ?? [];

    cliLine('Monitoring cycle finished in ' . $duration . ' seconds.');
    cliLine('Summary: total=' . ($summary['total'] ?? 'n/a') . ', up=' . ($summary['up'] ?? 'n/a') . ', warning=' . ($summary['warning'] ?? 'n/a') . ', down=' . ($summary['down'] ?? 'n/a'));

    file_put_contents(
        $cachePath,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    cliLine('Cache written successfully.');

    if (PHP_SAPI !== 'cli') {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    exit(0);
} catch (Throwable $e) {
    fail($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}
