<?php

class Monitor
{
    private array $config;
    private string $progressLogPath;

    public function __construct(array $config)
    {
        $this->config = $config;
        date_default_timezone_set($config['timezone'] ?? 'UTC');

        $cachePath = (string) ($config['paths']['cache'] ?? (__DIR__ . '/../storage/status-cache.json'));
        $this->progressLogPath = dirname($cachePath) . DIRECTORY_SEPARATOR . 'monitor-progress.log';
        @ini_set('default_socket_timeout', '3');
    }

    public function run(): array
    {
        $targets = $this->loadTargets();
        $history = $this->loadJson($this->config['paths']['history'], []);
        $results = [];
        $totalTargets = count($targets);

        $this->progress('MONITOR START total=' . $totalTargets);

        foreach ($targets as $index => $target) {
            $targetId = (string) ($target['id'] ?? ('target-' . ($index + 1)));
            $targetName = (string) ($target['name'] ?? ($target['host'] ?? $targetId));
            $this->progress('TARGET START ' . ($index + 1) . '/' . $totalTargets . ' ' . $targetId . ' [' . $targetName . ']');

            $targetResult = $this->runTarget($target, $history);
            $results[] = $targetResult;

            $this->progress('TARGET DONE  ' . ($index + 1) . '/' . $totalTargets . ' ' . $targetId . ' status=' . $targetResult['status']);

            $history[] = [
                'timestamp' => date('c'),
                'target_id' => $targetResult['id'],
                'status' => $targetResult['status'],
                'checks' => array_map(function ($check) {
                    return [
                        'name' => $check['name'],
                        'status' => $check['status'],
                        'latency_ms' => $check['latency_ms'] ?? null,
                    ];
                }, $targetResult['checks']),
            ];
        }

        $history = $this->trimHistory($history, (int) ($this->config['history_limit'] ?? 500));
        $summary = $this->buildSummary($results);

        $payload = [
            'app_name' => $this->config['app_name'] ?? 'monIT',
            'dashboard_name' => $this->config['dashboard_name'] ?? 'monIT Dashboard',
            'generated_at' => date('c'),
            'refresh_interval_seconds' => (int) ($this->config['refresh_interval_seconds'] ?? 15),
            'summary' => $summary,
            'targets' => $results,
        ];

        $this->saveJson($this->config['paths']['history'], $history);
        $this->saveJson($this->config['paths']['cache'], $payload);

        $this->progress('MONITOR DONE total=' . $summary['total'] . ' up=' . $summary['up'] . ' warning=' . $summary['warning'] . ' down=' . $summary['down']);

        return $payload;
    }

    private function progress(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;

        if (PHP_SAPI === 'cli') {
            echo $line . PHP_EOL;
            flush();
        }

        $directory = dirname($this->progressLogPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        @file_put_contents($this->progressLogPath, $line . PHP_EOL, FILE_APPEND);
    }

    private function loadTargets(): array
    {
        $path = $this->config['paths']['targets'];

        if (!is_file($path)) {
            throw new RuntimeException('Targets file was not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Targets file is empty: ' . $path);
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Targets JSON is invalid: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Targets JSON must be a JSON array.');
        }

        return $decoded;
    }

    private function runTarget(array $target, array $history): array
    {
        $checks = [];
        $statuses = [];
        $host = $target['host'] ?? '';
        $checkConfig = $target['checks'] ?? [];
        $targetId = (string) ($target['id'] ?? $host);

        if (!empty($checkConfig['icmp']['enabled'])) {
            $this->progress('CHECK START ' . $targetId . ' ICMP host=' . $host);
            $check = $this->checkIcmp($host, (int) ($checkConfig['icmp']['timeout_ms'] ?? 1000));
            $this->progress('CHECK DONE  ' . $targetId . ' ICMP status=' . $check['status'] . ' latency=' . ($check['latency_ms'] ?? 'n/a') . 'ms');
            $checks[] = $check;
            $statuses[] = $check['status'];
        }

        if (!empty($checkConfig['tcp']['enabled'])) {
            $port = (int) ($checkConfig['tcp']['port'] ?? 80);
            $this->progress('CHECK START ' . $targetId . ' TCP/' . $port . ' host=' . $host);
            $check = $this->checkTcp(
                $host,
                $port,
                (int) ($checkConfig['tcp']['timeout_ms'] ?? 1000)
            );
            $this->progress('CHECK DONE  ' . $targetId . ' TCP/' . $port . ' status=' . $check['status'] . ' latency=' . ($check['latency_ms'] ?? 'n/a') . 'ms');
            $checks[] = $check;
            $statuses[] = $check['status'];
        }

        if (!empty($checkConfig['http']['enabled'])) {
            $url = (string) ($checkConfig['http']['url'] ?? '');
            $this->progress('CHECK START ' . $targetId . ' HTTP url=' . $url);
            $check = $this->checkHttp(
                $url,
                (int) ($checkConfig['http']['timeout_ms'] ?? 2000),
                (array) ($checkConfig['http']['expected_status'] ?? [200])
            );
            $this->progress('CHECK DONE  ' . $targetId . ' HTTP status=' . $check['status'] . ' code=' . ($check['status_code'] ?? 0) . ' latency=' . ($check['latency_ms'] ?? 'n/a') . 'ms');
            $checks[] = $check;
            $statuses[] = $check['status'];
        }

        if (!empty($checkConfig['snmp']['enabled'])) {
            $this->progress('CHECK START ' . $targetId . ' SNMP host=' . $host);
            $check = $this->checkSnmp($host, (array) $checkConfig['snmp']);
            $this->progress('CHECK DONE  ' . $targetId . ' SNMP status=' . $check['status'] . ' latency=' . ($check['latency_ms'] ?? 'n/a') . 'ms');
            $checks[] = $check;
            $statuses[] = $check['status'];
        }

        $overall = $this->mergeStatus($statuses);
        $targetHistory = array_values(array_filter($history, function ($item) use ($target) {
            return ($item['target_id'] ?? null) === ($target['id'] ?? null);
        }));

        return [
            'id' => $target['id'] ?? uniqid('target-', true),
            'name' => $target['name'] ?? $host,
            'short_name' => $target['short_name'] ?? null,
            'overview_label' => $target['overview_label'] ?? ($target['short_name'] ?? null),
            'host' => $host,
            'group' => $target['group'] ?? 'General',
            'description' => $target['description'] ?? '',
            'status' => $overall,
            'status_label' => strtoupper($overall),
            'uptime_percent' => $this->calculateUptime($targetHistory, $overall),
            'checks' => $checks,
            'checked_at' => date('c'),
        ];
    }

    private function checkIcmp(string $host, int $timeoutMs): array
    {
        $start = microtime(true);
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            $timeout = max(1, $timeoutMs);
            $command = 'ping -n 1 -w ' . (int) $timeout . ' ' . escapeshellarg($host);
        } else {
            $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
            $command = 'ping -c 1 -W ' . (int) $timeoutSeconds . ' ' . escapeshellarg($host);
        }

        $output = [];
        $code = 1;
        @exec($command, $output, $code);
        $duration = (microtime(true) - $start) * 1000;
        $raw = implode("\n", $output);
        $latency = $this->extractPingLatency($raw) ?? round($duration, 1);

        return [
            'name' => 'ICMP',
            'status' => $code === 0 ? 'up' : 'down',
            'latency_ms' => round((float) $latency, 1),
            'message' => $code === 0 ? 'ICMP response received.' : 'ICMP request failed.',
            'raw' => trim($raw),
        ];
    }

    private function checkTcp(string $host, int $port, int $timeoutMs): array
    {
        $start = microtime(true);
        $timeoutSeconds = max(0.05, min(5.0, $timeoutMs / 1000));
        $errno = 0;
        $errstr = '';

        if (trim($host) === '') {
            return [
                'name' => 'TCP/' . $port,
                'status' => 'down',
                'latency_ms' => 0,
                'message' => 'TCP host is empty.',
                'raw' => 'No host value was provided.',
            ];
        }

        $target = 'tcp://' . $host . ':' . $port;

        $socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        $duration = (microtime(true) - $start) * 1000;
        $ok = is_resource($socket);

        if ($ok) {
            fclose($socket);
        }

        return [
            'name' => 'TCP/' . $port,
            'status' => $ok ? 'up' : 'down',
            'latency_ms' => round($duration, 1),
            'message' => $ok ? 'TCP port is reachable.' : 'TCP port is not reachable.',
            'raw' => $ok ? 'Connection successful.' : trim($errstr ?: ('Error ' . $errno)),
        ];
    }

    private function checkHttp(string $url, int $timeoutMs, array $expectedCodes): array
    {
        $start = microtime(true);
        $statusCode = 0;
        $bodySnippet = '';
        $error = '';
        $timeoutMs = max(500, min($timeoutMs, 5000));

        if (trim($url) === '') {
            return [
                'name' => 'HTTP',
                'status' => 'down',
                'latency_ms' => 0,
                'message' => 'HTTP URL is empty.',
                'status_code' => 0,
                'raw' => 'No URL was provided.',
            ];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_NOBODY => true,
                CURLOPT_USERAGENT => 'monIT/1.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_DNS_CACHE_TIMEOUT => 60,
                CURLOPT_FAILONERROR => false,
            ]);

            $response = curl_exec($ch);
            if ($response !== false) {
                $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $bodySnippet = 'HTTP HEAD request completed.';
            } else {
                $error = (string) curl_error($ch);
            }

            // curl_close() is deprecated as of PHP 8.5 and has had no effect since PHP 8.0.
            // The cURL handle is released automatically when it goes out of scope.
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'HEAD',
                    'timeout' => max(1, (int) ceil($timeoutMs / 1000)),
                    'ignore_errors' => true,
                    'header' => "User-Agent: monIT/1.0\r\n",
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];
            foreach ($headers as $header) {
                if (preg_match('/HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                    $statusCode = (int) $match[1];
                    break;
                }
            }
            if ($response !== false || $statusCode > 0) {
                $bodySnippet = 'HTTP HEAD request completed.';
            } else {
                $error = 'HTTP request failed.';
            }
        }

        $duration = (microtime(true) - $start) * 1000;
        $ok = in_array($statusCode, $expectedCodes, true);
        $status = $ok ? 'up' : ($statusCode > 0 ? 'warning' : 'down');
        $message = $ok
            ? 'HTTP endpoint returned an expected response.'
            : ($statusCode > 0 ? 'Unexpected HTTP response received.' : 'HTTP endpoint is not reachable.');

        return [
            'name' => 'HTTP',
            'status' => $status,
            'latency_ms' => round($duration, 1),
            'message' => $message,
            'status_code' => $statusCode,
            'raw' => $error ?: ($bodySnippet !== '' ? $bodySnippet : 'No response body captured.'),
        ];
    }

    private function checkSnmp(string $host, array $snmpConfig): array
    {
        $binary = $snmpConfig['binary'] ?? ($this->config['snmp']['binary'] ?? 'snmpget.exe');
        $binary = $this->normalizeBinaryPath($binary);
        $version = (string) ($snmpConfig['version'] ?? $this->config['snmp']['default_version'] ?? '2c');
        $community = (string) ($snmpConfig['community'] ?? 'public');
        $timeout = (int) ($snmpConfig['timeout_seconds'] ?? $this->config['snmp']['default_timeout_seconds'] ?? 1);
        $retries = (int) ($snmpConfig['retries'] ?? $this->config['snmp']['default_retries'] ?? 0);
        $oids = (array) ($snmpConfig['oids'] ?? []);

        if (!is_file($binary)) {
            return [
                'name' => 'SNMP',
                'status' => 'warning',
                'latency_ms' => null,
                'message' => 'Net-SNMP binary was not found. Place snmpget.exe into the bin directory or configure the binary path in app.php.',
                'raw' => $binary,
                'values' => [],
            ];
        }

        $runtimePaths = $this->getSnmpRuntimePaths($binary);
        $this->prepareSnmpRuntimeEnvironment($runtimePaths);

        $values = [];
        $start = microtime(true);
        $hasFailure = false;
        $details = [];

        foreach ($oids as $oidItem) {
            $label = $oidItem['label'] ?? ($oidItem['oid'] ?? 'OID');
            $oid = $oidItem['oid'] ?? null;
            if (!$oid) {
                continue;
            }

            $commandCore = sprintf(
                '"%s" -v %s -c %s -t %d -r %d -Oqv %s %s',
                $binary,
                escapeshellarg($version),
                escapeshellarg($community),
                $timeout,
                $retries,
                escapeshellarg($host),
                escapeshellarg($oid)
            );
            $command = $this->wrapCommandWithRuntimePath($commandCore, $runtimePaths) . ' 2>&1';

            $output = [];
            $code = 1;
            @exec($command, $output, $code);
            $raw = trim(implode("\n", $output));
            if ($code === 0 && $raw !== '') {
                $values[] = [
                    'label' => $label,
                    'oid' => $oid,
                    'value' => $raw,
                ];
            } else {
                $hasFailure = true;
                $details[] = $label . ': ' . ($raw !== '' ? $raw : 'query failed');
            }
        }

        $duration = (microtime(true) - $start) * 1000;

        return [
            'name' => 'SNMP',
            'status' => $hasFailure ? (count($values) > 0 ? 'warning' : 'down') : 'up',
            'latency_ms' => round($duration, 1),
            'message' => $hasFailure ? 'One or more SNMP OIDs could not be read.' : 'SNMP query completed successfully.',
            'raw' => $hasFailure ? implode('; ', $details) : 'SNMP values collected.',
            'values' => $values,
        ];
    }

    private function getSnmpRuntimePaths(string $binary): array
    {
        $paths = [];
        $binaryDirectory = dirname($binary);

        $candidates = [
            $binaryDirectory,
            $binaryDirectory . DIRECTORY_SEPARATOR . 'bin',
            $binaryDirectory . DIRECTORY_SEPARATOR . 'lib',
        ];

        foreach ((array) ($this->config['snmp']['runtime_paths'] ?? []) as $configuredPath) {
            if (is_string($configuredPath) && trim($configuredPath) !== '') {
                $candidates[] = $configuredPath;
            }
        }

        foreach ($candidates as $candidate) {
            $realPath = realpath($candidate);
            if ($realPath !== false && is_dir($realPath)) {
                $paths[$realPath] = $realPath;
            }
        }

        return array_values($paths);
    }

    private function prepareSnmpRuntimeEnvironment(array $runtimePaths): void
    {
        if (count($runtimePaths) === 0) {
            return;
        }

        $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
        $currentPath = getenv('PATH') ?: '';
        $parts = array_filter(explode($separator, $currentPath), static function ($part) {
            return trim($part) !== '';
        });

        foreach (array_reverse($runtimePaths) as $runtimePath) {
            if (!in_array($runtimePath, $parts, true)) {
                array_unshift($parts, $runtimePath);
            }
        }

        putenv('PATH=' . implode($separator, $parts));
    }

    private function wrapCommandWithRuntimePath(string $command, array $runtimePaths): string
    {
        if (count($runtimePaths) === 0) {
            return $command;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $pathValue = implode(';', $runtimePaths);
            return 'cmd /c set "PATH=' . $pathValue . ';%PATH%" && ' . $command;
        }

        $pathValue = implode(':', array_map('escapeshellarg', $runtimePaths));
        return 'PATH=' . $pathValue . ':$PATH ' . $command;
    }

    private function normalizeBinaryPath(string $path): string
    {
        if (str_contains($path, '/') || str_contains($path, '\\')) {
            return $path;
        }

        return $this->config['snmp']['binary'] ?? $path;
    }

    private function calculateUptime(array $targetHistory, string $currentStatus): float
    {
        if (count($targetHistory) === 0) {
            return $currentStatus === 'up' ? 100.0 : 0.0;
        }

        $valid = array_filter($targetHistory, function ($entry) {
            return isset($entry['status']);
        });

        if (count($valid) === 0) {
            return $currentStatus === 'up' ? 100.0 : 0.0;
        }

        $upCount = 0;
        foreach ($valid as $entry) {
            if (($entry['status'] ?? 'down') === 'up') {
                $upCount++;
            }
        }

        return round(($upCount / count($valid)) * 100, 2);
    }

    private function buildSummary(array $results): array
    {
        $summary = [
            'total' => count($results),
            'up' => 0,
            'warning' => 0,
            'down' => 0,
        ];

        foreach ($results as $result) {
            $status = $result['status'] ?? 'down';
            if (!array_key_exists($status, $summary)) {
                $summary[$status] = 0;
            }
            $summary[$status]++;
        }

        return $summary;
    }

    private function mergeStatus(array $statuses): string
    {
        if (in_array('down', $statuses, true)) {
            return 'down';
        }

        if (in_array('warning', $statuses, true)) {
            return 'warning';
        }

        if (in_array('up', $statuses, true)) {
            return 'up';
        }

        return 'warning';
    }

    private function trimHistory(array $history, int $limit): array
    {
        if ($limit <= 0 || count($history) <= $limit) {
            return $history;
        }

        return array_slice($history, -$limit);
    }

    private function extractPingLatency(string $raw): ?float
    {
        if (preg_match('/time[=<]([0-9]+(?:\.[0-9]+)?)\s*ms/i', $raw, $match)) {
            return (float) $match[1];
        }

        if (preg_match('/Average = ([0-9]+)ms/i', $raw, $match)) {
            return (float) $match[1];
        }

        return null;
    }

    private function loadJson(string $path, $fallback)
    {
        if (!is_file($path)) {
            return $fallback;
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return $fallback;
        }

        $decoded = json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
    }

    private function saveJson(string $path, $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
