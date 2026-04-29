(() => {
    'use strict';

    const config = window.MONIT_CONFIG || {};

    const elements = {
        generatedAt: document.getElementById('generatedAt'),
        targetCount: document.getElementById('targetCount'),
        summaryTotal: document.getElementById('summaryTotal'),
        summaryUp: document.getElementById('summaryUp'),
        summaryWarning: document.getElementById('summaryWarning'),
        summaryDown: document.getElementById('summaryDown'),
        summaryGrid: document.getElementById('summaryGrid'),
        targetsContainer: document.getElementById('targetsContainer'),
        targetCardTemplate: document.getElementById('targetCardTemplate'),
        refreshButton: document.getElementById('refreshButton'),
        openTargetsButton: document.getElementById('openTargetsButton'),
        clearCacheButton: document.getElementById('clearCacheButton'),
        connectionState: document.getElementById('connectionState'),
        statusMessage: document.getElementById('statusMessage'),
        streamProgress: document.getElementById('streamProgress'),
        overviewGrid: document.getElementById('overviewGrid'),
        overviewScroll: document.getElementById('overviewScroll'),
        overviewCount: document.getElementById('overviewCount')
    };

    const state = {
        refreshTimerId: null,
        statusMessageTimerId: null,
        statusProgressTimerId: null,
        statusMessages: [],
        statusMessageIndex: 0,
        lastTargetScrollTop: 0,
        latestPayload: null
    };

    function safeNumber(value, fallback = 0) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function getDesktopBreakpoint() {
        return safeNumber(config.targetScrollDesktopBreakpointPx, 1181);
    }

    function isDesktopScrollMode() {
        return window.innerWidth >= getDesktopBreakpoint();
    }

    function statusToDotClass(status) {
        if (status === 'up') {
            return 'ok';
        }
        if (status === 'warning') {
            return 'warning';
        }
        return 'down';
    }

    function statusToTextClass(status) {
        if (status === 'up') {
            return 'status-up';
        }
        if (status === 'warning') {
            return 'status-warning';
        }
        return 'status-down';
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(value) {
        if (!value) {
            return 'Never';
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        try {
            return new Intl.DateTimeFormat(undefined, {
                dateStyle: 'short',
                timeStyle: 'medium'
            }).format(date);
        } catch (error) {
            return date.toLocaleString();
        }
    }

    function formatLatency(value) {
        const latency = safeNumber(value, 0);
        const rounded = Math.round(latency * 10) / 10;
        return `${rounded} ms`;
    }

    function formatPercent(value) {
        const percent = clamp(safeNumber(value, 0), 0, 100);
        return `${percent.toFixed(2)}%`;
    }

    function setConnectionState(text, variant) {
        if (!elements.connectionState) {
            return;
        }

        elements.connectionState.textContent = text;
        elements.connectionState.className = 'pill';
        elements.connectionState.classList.add(variant || 'neutral');
    }

    function buildStreamMessages(payload) {
        const summary = payload?.summary || {};
        const targets = Array.isArray(payload?.targets) ? payload.targets : [];
        const total = safeNumber(summary.total, targets.length);
        const up = safeNumber(summary.up, 0);
        const warning = safeNumber(summary.warning, 0);
        const down = safeNumber(summary.down, 0);
        const criticalTargets = targets.filter((target) => target.status === 'down');
        const warningTargets = targets.filter((target) => target.status === 'warning');
        const healthyTargets = targets.filter((target) => target.status === 'up');
        const messages = [];

        messages.push(`Monitoring cycle completed at ${formatDate(payload?.generated_at)}.`);
        messages.push(`${total} targets checked: ${up} healthy, ${warning} warning, ${down} critical.`);

        if (criticalTargets.length > 0) {
            messages.push(`Critical signal: ${criticalTargets.map((target) => target.name || target.host || target.id).join(', ')} currently down.`);
        }

        if (warningTargets.length > 0) {
            messages.push(`Warning signal: ${warningTargets.map((target) => target.name || target.host || target.id).join(', ')} returned a degraded check result.`);
        }

        if (healthyTargets.length > 0) {
            messages.push(`Healthy signal: ${healthyTargets.length} targets are currently reachable.`);
        }

        const snmpWarnings = targets
            .flatMap((target) => target.checks || [])
            .filter((check) => check.name === 'SNMP' && check.status !== 'up');

        if (snmpWarnings.length > 0) {
            messages.push('SNMP notice: verify snmpget.exe, runtime DLLs, community string, firewall, and OID configuration.');
        }

        messages.push('Use Edit targets.json to adjust monitored hosts, ports, HTTP URLs, and SNMP OIDs.');
        return messages;
    }

    function updateStatusStream(messages) {
        if (!elements.statusMessage || !elements.streamProgress) {
            return;
        }

        if (state.statusMessageTimerId) {
            clearInterval(state.statusMessageTimerId);
            state.statusMessageTimerId = null;
        }

        if (state.statusProgressTimerId) {
            clearInterval(state.statusProgressTimerId);
            state.statusProgressTimerId = null;
        }

        state.statusMessages = Array.isArray(messages) && messages.length > 0
            ? messages
            : ['Waiting for monitoring data...'];
        state.statusMessageIndex = 0;

        const messageDurationMs = 4200;
        let progressStartedAt = Date.now();

        const restartProgress = () => {
            progressStartedAt = Date.now();
            elements.streamProgress.style.width = '0%';
            requestAnimationFrame(() => {
                elements.streamProgress.style.width = '8%';
            });
        };

        const renderMessage = () => {
            const currentMessage = state.statusMessages[state.statusMessageIndex] || 'Waiting for monitoring data...';
            elements.statusMessage.classList.add('slide-out');

            window.setTimeout(() => {
                elements.statusMessage.textContent = currentMessage;
                elements.statusMessage.classList.remove('slide-out');
                elements.statusMessage.classList.add('slide-in');

                requestAnimationFrame(() => {
                    elements.statusMessage.classList.remove('slide-in');
                });

                restartProgress();
                state.statusMessageIndex = (state.statusMessageIndex + 1) % state.statusMessages.length;
            }, 180);
        };

        state.statusProgressTimerId = window.setInterval(() => {
            const elapsed = Date.now() - progressStartedAt;
            const percent = Math.min(100, Math.max(8, (elapsed / messageDurationMs) * 100));
            elements.streamProgress.style.width = `${percent}%`;
        }, 100);

        renderMessage();

        if (state.statusMessages.length > 1) {
            state.statusMessageTimerId = window.setInterval(renderMessage, messageDurationMs);
        }
    }

    function updateSummary(summary) {
        const total = safeNumber(summary?.total, 0);
        const up = safeNumber(summary?.up, 0);
        const warning = safeNumber(summary?.warning, 0);
        const down = safeNumber(summary?.down, 0);

        if (elements.summaryTotal) elements.summaryTotal.textContent = String(total);
        if (elements.summaryUp) elements.summaryUp.textContent = String(up);
        if (elements.summaryWarning) elements.summaryWarning.textContent = String(warning);
        if (elements.summaryDown) elements.summaryDown.textContent = String(down);

        const dangerCard = elements.summaryGrid?.querySelector('.summary-card.danger');
        if (dangerCard) {
            dangerCard.classList.toggle('has-alert', down > 0);
        }
    }

    function buildCheckItemHtml(check) {
        const metrics = [];

        if (check.latency_ms !== undefined && check.latency_ms !== null) {
            metrics.push(`<span class="metric-chip">Latency: ${escapeHtml(formatLatency(check.latency_ms))}</span>`);
        }

        if (check.status_code) {
            metrics.push(`<span class="metric-chip">HTTP ${escapeHtml(check.status_code)}</span>`);
        }

        let snmpHtml = '';
        if (Array.isArray(check.values) && check.values.length > 0) {
            const rows = check.values.map((item) => `
                <div class="snmp-row">
                    <span class="snmp-label">${escapeHtml(item.label || item.oid || 'OID')}</span>
                    <strong>${escapeHtml(item.value || 'n/a')}</strong>
                </div>
            `).join('');
            snmpHtml = `<div class="snmp-values">${rows}</div>`;
        }

        const rawOutput = check.raw
            ? `<pre class="raw-output">${escapeHtml(check.raw)}</pre>`
            : '';

        return `
            <div class="check-item">
                <div class="check-top">
                    <div class="check-title">
                        <span class="status-dot ${escapeHtml(statusToDotClass(check.status || 'down'))}"></span>
                        <span>${escapeHtml(check.name || 'Check')}</span>
                    </div>
                    <div class="check-metrics">${metrics.join('')}</div>
                </div>
                <div class="check-message">${escapeHtml(check.message || 'No details available.')}</div>
                ${snmpHtml}
                ${rawOutput}
            </div>
        `;
    }

    function renderTargets(targets) {
        if (!elements.targetsContainer || !elements.targetCardTemplate) {
            return;
        }

        const fragment = document.createDocumentFragment();
        const list = Array.isArray(targets) ? targets : [];

        list.forEach((target) => {
            const clone = elements.targetCardTemplate.content.cloneNode(true);
            const article = clone.querySelector('.target-card');
            const group = clone.querySelector('.target-group');
            const name = clone.querySelector('.target-name');
            const description = clone.querySelector('.target-description');
            const statusDot = clone.querySelector('.status-dot');
            const statusLabel = clone.querySelector('.target-status-label');
            const hostLine = clone.querySelector('.host-line');
            const uptime = clone.querySelector('.uptime-value');
            const checkCount = clone.querySelector('.check-count');
            const checksList = clone.querySelector('.checks-list');
            const status = target.status || 'down';
            const checks = Array.isArray(target.checks) ? target.checks : [];

            article.classList.add(statusToTextClass(status));
            group.textContent = target.group || 'General';
            name.textContent = target.name || target.host || target.id || 'Unnamed target';
            description.textContent = target.description || 'No description provided.';
            statusDot.classList.add(statusToDotClass(status));
            statusLabel.classList.add(statusToTextClass(status));
            statusLabel.textContent = target.status_label || String(status).toUpperCase();
            hostLine.textContent = `Host: ${target.host || 'n/a'}`;
            uptime.textContent = formatPercent(target.uptime_percent);
            checkCount.textContent = String(checks.length);
            checksList.innerHTML = checks.map(buildCheckItemHtml).join('');

            fragment.appendChild(clone);
        });

        elements.targetsContainer.innerHTML = '';
        elements.targetsContainer.appendChild(fragment);

        if (!list.length) {
            elements.targetsContainer.innerHTML = '<article class="target-card glass-panel"><div class="check-message">No targets are configured yet.</div></article>';
        }

        applyTargetGridLayout();
    }

    function compactLabel(value, fallback = 'Target') {
        const raw = String(value || fallback).trim();
        if (raw.length <= 18) {
            return raw;
        }
        return `${raw.slice(0, 16).trim()}…`;
    }

    function getOverviewLabel(target) {
        return compactLabel(
            target?.overview_label || target?.short_name || target?.name || target?.host || target?.id,
            'Target'
        );
    }

    function renderOverview(targets) {
        if (!elements.overviewGrid || !elements.overviewCount) {
            return;
        }

        const list = Array.isArray(targets) ? targets : [];
        elements.overviewCount.textContent = String(list.length);
        elements.overviewGrid.style.setProperty('--overview-columns', String(Math.max(1, safeNumber(config.overviewGridColumns, 2))));

        if (!list.length) {
            elements.overviewGrid.innerHTML = '<div class="overview-empty muted">No targets configured.</div>';
            return;
        }

        const html = list.map((target) => {
            const status = target.status || 'down';
            const label = getOverviewLabel(target);
            const title = target.name || target.host || target.id || label;

            return `
                <div class="overview-tile ${escapeHtml(statusToTextClass(status))}" title="${escapeHtml(title)}">
                    <span class="status-dot ${escapeHtml(statusToDotClass(status))}" aria-hidden="true"></span>
                    <span class="overview-label">${escapeHtml(label)}</span>
                </div>
            `;
        }).join('');

        elements.overviewGrid.innerHTML = html;
    }

    function applyTargetGridLayout() {
        if (!elements.targetsContainer) {
            return;
        }

        const gap = Math.max(8, safeNumber(config.targetGridGapPx, 18));
        const maxColumns = Math.max(1, safeNumber(config.targetGridMaxColumns, 4));
        const minWidth = Math.max(220, safeNumber(config.targetCardMinWidthPx, 300));
        const containerWidth = Math.max(0, elements.targetsContainer.clientWidth);
        let columns = 1;

        if (containerWidth > 0) {
            columns = Math.floor((containerWidth + gap) / (minWidth + gap));
            columns = clamp(columns, 1, maxColumns);
        }

        elements.targetsContainer.style.setProperty('--target-grid-gap', `${gap}px`);
        elements.targetsContainer.style.gridTemplateColumns = `repeat(${columns}, minmax(0, 1fr))`;
    }

    function getDocumentScrollElement() {
        return document.scrollingElement || document.documentElement || document.body;
    }

    function getBestTargetScrollElement() {
        if (!isDesktopScrollMode()) {
            return null;
        }

        if (elements.targetsContainer && elements.targetsContainer.scrollHeight > elements.targetsContainer.clientHeight + 4) {
            return elements.targetsContainer;
        }

        const fallback = getDocumentScrollElement();
        if (fallback && fallback.scrollHeight > fallback.clientHeight + 4) {
            return fallback;
        }

        return elements.targetsContainer || fallback || null;
    }

    function createAutoScroller(name, resolver, settingsResolver) {
        const scroller = {
            name,
            frameId: 0,
            running: false,
            direction: 1,
            accumulator: 0,
            lastTimestamp: 0,
            pauseUntil: 0,
            hasStartedDelay: false
        };

        function cancel() {
            if (scroller.frameId) {
                cancelAnimationFrame(scroller.frameId);
                scroller.frameId = 0;
            }
            scroller.running = false;
            scroller.lastTimestamp = 0;
            scroller.accumulator = 0;
        }

        function resetPosition(toTop = true) {
            const element = resolver();
            if (!element) {
                return;
            }
            element.scrollTop = toTop ? 0 : Math.max(0, element.scrollHeight - element.clientHeight);
            scroller.direction = toTop ? 1 : -1;
            scroller.accumulator = 0;
            scroller.pauseUntil = performance.now() + safeNumber(settingsResolver().startDelaySeconds, 0) * 1000;
        }

        function step(timestamp) {
            const settings = settingsResolver();
            const element = resolver();

            if (!settings.enabled || !element) {
                cancel();
                return;
            }

            const maxScroll = Math.max(0, element.scrollHeight - element.clientHeight);
            if (maxScroll <= 2) {
                scroller.lastTimestamp = timestamp;
                scroller.accumulator = 0;
                scroller.frameId = requestAnimationFrame(step);
                return;
            }

            if (!scroller.lastTimestamp) {
                scroller.lastTimestamp = timestamp;
                if (!scroller.hasStartedDelay) {
                    scroller.pauseUntil = timestamp + safeNumber(settings.startDelaySeconds, 0) * 1000;
                    scroller.hasStartedDelay = true;
                }
                scroller.frameId = requestAnimationFrame(step);
                return;
            }

            const delta = Math.max(0, timestamp - scroller.lastTimestamp);
            scroller.lastTimestamp = timestamp;

            if (timestamp < scroller.pauseUntil) {
                scroller.frameId = requestAnimationFrame(step);
                return;
            }

            const speed = Math.max(0.01, safeNumber(settings.speed, 0.25));
            scroller.accumulator += (delta / 16.6667) * speed;
            const wholePixels = Math.trunc(scroller.accumulator);

            if (wholePixels !== 0) {
                scroller.accumulator -= wholePixels;
                let nextScrollTop = element.scrollTop + wholePixels * scroller.direction;

                if (nextScrollTop >= maxScroll) {
                    nextScrollTop = maxScroll;
                    scroller.direction = -1;
                    scroller.pauseUntil = timestamp + safeNumber(settings.pauseSeconds, 0) * 1000;
                } else if (nextScrollTop <= 0) {
                    nextScrollTop = 0;
                    scroller.direction = 1;
                    scroller.pauseUntil = timestamp + safeNumber(settings.pauseSeconds, 0) * 1000;
                }

                element.scrollTop = nextScrollTop;
            }

            scroller.frameId = requestAnimationFrame(step);
        }

        function start() {
            const settings = settingsResolver();
            if (!settings.enabled) {
                cancel();
                return;
            }

            if (scroller.running) {
                return;
            }

            scroller.running = true;
            scroller.direction = 1;
            scroller.accumulator = 0;
            scroller.lastTimestamp = 0;
            scroller.hasStartedDelay = false;
            scroller.pauseUntil = 0;
            scroller.frameId = requestAnimationFrame(step);
        }

        return {
            start,
            cancel,
            resetPosition,
            state: scroller
        };
    }

    const targetScroller = createAutoScroller(
        'targets',
        () => getBestTargetScrollElement(),
        () => ({
            enabled: !!config.autoScrollEnabled && isDesktopScrollMode(),
            speed: safeNumber(config.autoScrollSpeed, 0.50),
            pauseSeconds: safeNumber(config.autoScrollPauseSeconds, 3),
            startDelaySeconds: safeNumber(config.autoScrollStartDelaySeconds, 2)
        })
    );

    const overviewScroller = createAutoScroller(
        'overview',
        () => (isDesktopScrollMode() ? elements.overviewScroll : null),
        () => ({
            enabled: !!config.overviewAutoScrollEnabled && isDesktopScrollMode(),
            speed: safeNumber(config.overviewAutoScrollSpeed, 0.36),
            pauseSeconds: safeNumber(config.overviewAutoScrollPauseSeconds, 2),
            startDelaySeconds: safeNumber(config.overviewAutoScrollStartDelaySeconds, 1)
        })
    );

    function rememberTargetScrollPosition() {
        const element = getBestTargetScrollElement();
        if (!element || !!config.autoScrollEnabled) {
            return;
        }
        state.lastTargetScrollTop = element.scrollTop || 0;
    }

    function restoreTargetScrollPosition() {
        const element = getBestTargetScrollElement();
        if (!element || !!config.autoScrollEnabled) {
            return;
        }
        element.scrollTop = state.lastTargetScrollTop || 0;
    }

    async function requestTargetsEditor() {
        if (!config.openTargetsUrl || !elements.openTargetsButton) {
            return;
        }

        const button = elements.openTargetsButton;
        const originalText = button.textContent;

        button.disabled = true;
        button.classList.add('is-busy');
        button.textContent = 'Opening...';

        try {
            const response = await fetch(config.openTargetsUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Failed to open targets.json.');
            }
        } catch (error) {
            window.alert(error.message || 'Failed to open targets.json.');
        } finally {
            window.setTimeout(() => {
                button.disabled = false;
                button.classList.remove('is-busy');
                button.textContent = originalText;
            }, 260);
        }
    }


    async function clearRuntimeCache() {
        if (!config.clearCacheUrl || !elements.clearCacheButton) {
            return;
        }

        const button = elements.clearCacheButton;
        const originalText = button.textContent;

        button.disabled = true;
        button.classList.add('is-busy');
        button.textContent = 'Clearing...';
        setConnectionState('Clearing', 'warning');

        try {
            const response = await fetch(config.clearCacheUrl, {
                method: 'POST',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Failed to clear runtime cache.');
            }

            state.latestPayload = null;
            updateSummary({ total: 0, up: 0, warning: 0, down: 0 });
            renderTargets([]);
            renderOverview([]);

            if (elements.targetCount) {
                elements.targetCount.textContent = '0';
            }
            if (elements.generatedAt) {
                elements.generatedAt.textContent = 'Cache cleared';
            }

            updateStatusStream([payload.message || 'Runtime cache cleared. Waiting for the next polling cycle.']);
            setConnectionState('Cache cleared', 'warning');

            window.setTimeout(() => {
                fetchStatus(false);
            }, 1500);
        } catch (error) {
            console.error('[monIT] Clear runtime cache failed:', error);
            updateStatusStream([`Clear runtime cache failed: ${error.message || 'Unknown error.'}`]);
            setConnectionState('Error', 'down');
            window.alert(error.message || 'Failed to clear runtime cache.');
        } finally {
            window.setTimeout(() => {
                button.disabled = false;
                button.classList.remove('is-busy');
                button.textContent = originalText;
            }, 260);
        }
    }

    async function fetchStatus(forceRefresh = false) {
        rememberTargetScrollPosition();
        setConnectionState('Updating', 'neutral');

        if (elements.refreshButton) {
            elements.refreshButton.disabled = true;
        }

        try {
            const url = `${config.apiUrl || 'api/status.php'}${forceRefresh ? '?refresh=1' : ''}`;
            const response = await fetch(url, {
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`Request failed with status ${response.status}.`);
            }

            const payload = await response.json();
            state.latestPayload = payload;

            if (elements.generatedAt) {
                elements.generatedAt.textContent = formatDate(payload.generated_at);
            }

            const targets = Array.isArray(payload.targets) ? payload.targets : [];
            const targetCount = safeNumber(payload.summary?.total, targets.length);
            if (elements.targetCount) {
                elements.targetCount.textContent = String(targetCount);
            }

            updateSummary(payload.summary || {});
            renderTargets(targets);
            renderOverview(targets);
            updateStatusStream(buildStreamMessages(payload));

            if (!!config.autoScrollResetAfterRefresh) {
                targetScroller.resetPosition(true);
                overviewScroller.resetPosition(true);
            } else {
                restoreTargetScrollPosition();
            }

            scheduleScrollerActivation();
            setConnectionState('Online', 'ok');
        } catch (error) {
            console.error('[monIT] Monitoring update failed:', error);
            updateStatusStream([`Monitoring update failed: ${error.message || 'Unknown error.'}`]);
            setConnectionState('Offline', 'down');
        } finally {
            if (elements.refreshButton) {
                elements.refreshButton.disabled = false;
            }
        }
    }

    function scheduleScrollerActivation() {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                applyTargetGridLayout();
                targetScroller.start();
                overviewScroller.start();
            });
        });
    }

    function installEventHandlers() {
        if (elements.refreshButton) {
            elements.refreshButton.addEventListener('click', () => {
                fetchStatus(true);
            });
        }

        if (elements.openTargetsButton) {
            elements.openTargetsButton.addEventListener('click', () => {
                requestTargetsEditor();
            });
        }

        if (elements.clearCacheButton) {
            elements.clearCacheButton.addEventListener('click', () => {
                clearRuntimeCache();
            });
        }

        window.addEventListener('resize', () => {
            applyTargetGridLayout();
            targetScroller.cancel();
            overviewScroller.cancel();
            scheduleScrollerActivation();
        });

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                targetScroller.cancel();
                overviewScroller.cancel();
            } else {
                scheduleScrollerActivation();
            }
        });
    }

    function startRefreshLoop() {
        const intervalSeconds = Math.max(5, safeNumber(config.refreshIntervalSeconds, 15));
        if (state.refreshTimerId) {
            clearInterval(state.refreshTimerId);
        }
        state.refreshTimerId = window.setInterval(() => {
            fetchStatus(false);
        }, intervalSeconds * 1000);
    }

    function bootstrap() {
        setConnectionState('Starting', 'neutral');
        installEventHandlers();
        applyTargetGridLayout();
        fetchStatus(false);
        startRefreshLoop();

        window.monITDebug = {
            config,
            state,
            targetScroller,
            overviewScroller,
            fetchStatus,
            applyTargetGridLayout,
            getBestTargetScrollElement
        };
    }

    bootstrap();
})();
