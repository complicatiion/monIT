<?php
$basePath = dirname(__DIR__);
$config = require $basePath . '/config/app.php';
$appName = $config['app_name'] ?? 'monIT';
$dashboardName = $config['dashboard_name'] ?? 'monIT Dashboard';
$refresh = (int) ($config['refresh_interval_seconds'] ?? 15);
$version = (string) ($config['version'] ?? '1.1.1');
$githubUrl = (string) ($config['github_url'] ?? 'https://github.com/complicatiion/monIT');

function monITAssetVersion(string $basePath, string $relativePath, string $fallback): string
{
    $fullPath = $basePath . '/public/' . ltrim($relativePath, '/');
    return is_file($fullPath) ? $fallback . '-' . filemtime($fullPath) : $fallback;
}

$cssVersion = monITAssetVersion($basePath, 'assets/css/style.css', $version);
$jsVersion = monITAssetVersion($basePath, 'assets/js/app.js', $version);
$imgVersion = monITAssetVersion($basePath, 'assets/img/logo.png', $version);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= htmlspecialchars($dashboardName, ENT_QUOTES) ?></title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png?v=<?= htmlspecialchars($imgVersion, ENT_QUOTES) ?>">
    <link rel="shortcut icon" href="assets/img/favicon.ico?v=<?= htmlspecialchars($imgVersion, ENT_QUOTES) ?>">
    <link rel="apple-touch-icon" href="assets/img/favicon.png?v=<?= htmlspecialchars($imgVersion, ENT_QUOTES) ?>">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES) ?>">
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar glass-panel">
            <div class="brand-block brand-logo-block">
                <a href="./" class="brand-logo-link" aria-label="<?= htmlspecialchars($appName, ENT_QUOTES) ?> Dashboard home">
                    <img src="assets/img/logo.png?v=<?= htmlspecialchars($imgVersion, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($appName, ENT_QUOTES) ?>" class="brand-logo">
                </a>
            </div>

            <div class="side-section">
                <h2>Live control</h2>
                <div class="control-row">
                    <div class="button-stack">
                        <button id="refreshButton" class="btn btn-primary" type="button">Refresh now</button>
                        <button id="openTargetsButton" class="btn btn-secondary" type="button">Edit targets.json</button>
                        <button id="clearCacheButton" class="btn btn-secondary btn-danger-soft" type="button">Clear runtime cache</button>
                    </div>
                    <div class="control-status-stack">
                        <span id="connectionState" class="pill neutral">Waiting</span>
                        <div class="auto-refresh-chip">
                            <span class="meta-label">Auto refresh</span>
                            <strong><?= $refresh ?>s</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="side-section status-stream-panel">
                <div class="section-title-row compact-title-row">
                    <h2>Status stream</h2>
                </div>
                <div class="status-slider" aria-live="polite">
                    <div id="statusMessage" class="status-message">Loading dashboard signals...</div>
                </div>
                <div class="stream-progress"><span id="streamProgress"></span></div>
            </div>

            <div class="side-section overview-panel">
                <div class="section-title-row compact-title-row">
                    <h2>Status overview</h2>
                    <span id="overviewCount" class="mini-counter">0</span>
                </div>
                <div id="overviewScroll" class="overview-scroll">
                    <div id="overviewGrid" class="overview-grid"></div>
                </div>
            </div>

            <div class="sidebar-footer side-section">
                <span>v <?= htmlspecialchars($version, ENT_QUOTES) ?></span>
                <a href="<?= htmlspecialchars($githubUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">GitHub Repo</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar glass-panel">
                <div>
                    <div class="eyebrow"> </div>
                    <h2><?= htmlspecialchars($dashboardName, ENT_QUOTES) ?></h2>
                </div>
                <div class="topbar-meta">
                    <div class="meta-card">
                        <span class="meta-label">Last update</span>
                        <strong id="generatedAt">Never</strong>
                    </div>
                    <div class="meta-card">
                        <span class="meta-label">Targets</span>
                        <strong id="targetCount">0</strong>
                    </div>
                </div>
            </header>

            <section class="summary-grid" id="summaryGrid">
                <article class="summary-card glass-panel">
                    <span class="meta-label">Total targets</span>
                    <strong id="summaryTotal">0</strong>
                </article>
                <article class="summary-card glass-panel success">
                    <span class="meta-label">Healthy</span>
                    <strong id="summaryUp">0</strong>
                </article>
                <article class="summary-card glass-panel warning-card">
                    <span class="meta-label">Warning</span>
                    <strong id="summaryWarning">0</strong>
                </article>
                <article class="summary-card glass-panel danger">
                    <span class="meta-label">Critical</span>
                    <strong id="summaryDown">0</strong>
                </article>
            </section>

            <section class="targets-section">
                <div class="section-header">
                    <div>
                        <div class="eyebrow">Monitored endpoints</div>
                        <h3>Target overview</h3>
                    </div>
                    <div class="filter-info glass-chip">Live health, latency, uptime, and SNMP values</div>
                </div>
                <div id="targetsContainer" class="targets-grid"></div>
            </section>
        </main>
    </div>

    <template id="targetCardTemplate">
        <article class="target-card glass-panel">
            <div class="target-header">
                <div>
                    <div class="target-group"></div>
                    <h4 class="target-name"></h4>
                    <p class="target-description muted"></p>
                </div>
                <div class="target-status-wrap">
                    <span class="status-dot"></span>
                    <span class="target-status-label pill"></span>
                </div>
            </div>

            <div class="host-line"></div>

            <div class="kpi-row">
                <div class="kpi-box">
                    <span class="meta-label">Uptime</span>
                    <strong class="uptime-value"></strong>
                </div>
                <div class="kpi-box">
                    <span class="meta-label">Checks</span>
                    <strong class="check-count"></strong>
                </div>
            </div>

            <div class="checks-list"></div>
        </article>
    </template>

    <script>
        window.MONIT_CONFIG = {
            apiUrl: 'api/status.php',
            openTargetsUrl: 'api/open-targets.php',
            clearCacheUrl: 'api/clear-cache.php',
            refreshIntervalSeconds: <?= (int) $refresh ?>,
            autoScrollEnabled: <?= !empty($config['kiosk']['auto_scroll_enabled']) ? 'true' : 'false' ?>,
            autoScrollSpeed: <?= (float) ($config['kiosk']['auto_scroll_speed'] ?? 0.50) ?>,
            autoScrollPauseSeconds: <?= (int) ($config['kiosk']['auto_scroll_pause_seconds'] ?? 3) ?>,
            autoScrollStartDelaySeconds: <?= (int) ($config['kiosk']['auto_scroll_start_delay_seconds'] ?? 2) ?>,
            autoScrollResetAfterRefresh: <?= !empty($config['kiosk']['auto_scroll_reset_after_refresh']) ? 'true' : 'false' ?>,
            overviewAutoScrollEnabled: <?= !empty($config['kiosk']['overview_auto_scroll_enabled']) ? 'true' : 'false' ?>,
            overviewAutoScrollSpeed: <?= (float) ($config['kiosk']['overview_auto_scroll_speed'] ?? 0.36) ?>,
            overviewAutoScrollPauseSeconds: <?= (int) ($config['kiosk']['overview_auto_scroll_pause_seconds'] ?? 2) ?>,
            overviewAutoScrollStartDelaySeconds: <?= (int) ($config['kiosk']['overview_auto_scroll_start_delay_seconds'] ?? 1) ?>,
            targetGridMaxColumns: <?= (int) ($config['layout']['target_grid_max_columns'] ?? 4) ?>,
            targetCardMinWidthPx: <?= (int) ($config['layout']['target_card_min_width_px'] ?? 300) ?>,
            targetGridGapPx: <?= (int) ($config['layout']['target_grid_gap_px'] ?? 18) ?>,
            targetScrollDesktopBreakpointPx: <?= (int) ($config['layout']['target_scroll_desktop_breakpoint_px'] ?? 1181) ?>,
            overviewGridColumns: <?= (int) ($config['layout']['overview_grid_columns'] ?? 2) ?>,
            overviewCardMaxHeightPx: <?= (int) ($config['layout']['overview_card_max_height_px'] ?? 360) ?>
        };
    </script>
    <script src="assets/js/app.js?v=<?= htmlspecialchars($jsVersion, ENT_QUOTES) ?>"></script>
</body>
</html>
