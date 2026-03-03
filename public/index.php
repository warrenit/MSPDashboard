<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\InstallGate;
use App\Core\IpAllowlist;
use App\Core\SettingsRepo;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
InstallGate::enforce($path);

$config = Config::load();
$settingsRepo = null;
$refreshInterval = 10;
$rssEnabled = false;
$logoMode = 'url';
$logoUrl = '';
$logoUploadPath = '';
$resolvedLogoSrc = '';

try {
    $pdo = Database::connect($config);
    $settingsRepo = new SettingsRepo($pdo);
    IpAllowlist::enforce($settingsRepo, $path);
    $refreshInterval = max(10, (int) $settingsRepo->get('refresh_interval_sec', '10'));
    $rssEnabled = ((string) $settingsRepo->get('rss_enabled', '0')) === '1';
    $logoMode = (string) $settingsRepo->get('logo_mode', 'url');
    $logoUrl = trim((string) $settingsRepo->get('logo_url', ''));
    $logoUploadPath = trim((string) $settingsRepo->get('logo_upload_path', ''));

    if ($logoMode === 'upload' && $logoUploadPath !== '') {
        $resolvedLogoSrc = $logoUploadPath;
    } elseif ($logoUrl !== '') {
        $resolvedLogoSrc = $logoUrl;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Configuration error.';
    exit;
}
?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Helpdesk Dashboard</title>
    <link rel="stylesheet" href="/assets/dashboard.css">
</head>
<body data-refresh-interval="<?= $refreshInterval ?>" data-rss-enabled="<?= $rssEnabled ? '1' : '0' ?>">
<div class="dashboard-wrap">
    <header class="header-row">
        <div class="logo-box">
            <?php if ($resolvedLogoSrc !== ''): ?>
                <img src="<?= htmlspecialchars($resolvedLogoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Dashboard logo" style="max-width:100%;max-height:84px;object-fit:contain;">
            <?php else: ?>
                LOGO
            <?php endif; ?>
        </div>
        <div class="title-box">
            <h1>Helpdesk Dashboard</h1>
            <div id="lastUpdated">Last updated: --</div>
        </div>
        <div class="clock-box">
            <div id="clockDay"></div>
            <div id="clockDate"></div>
            <div id="clockTime"></div>
        </div>
    </header>

    <section class="status-strip" id="apiStatusStrip"></section>

    <section class="rss-row" id="rssRow" style="display:none;">
        <div class="rss-label">RSS</div>
        <div class="rss-ticker" id="rssTicker">No headlines.</div>
    </section>

    <section class="tiles-grid">
        <article class="tile"><h2>Unassigned (combined)</h2><div id="unassignedCount" class="tile-value">0</div></article>
        <article class="tile"><h2>Important Alerts</h2><div id="importantAlertsCount" class="tile-value">0</div></article>
        <article class="tile"><h2>Total Open</h2><div id="totalOpenCount" class="tile-value">0</div></article>
        <article class="tile"><h2>Waiting on Customer</h2><div id="waitingOnCustomerCount" class="tile-value">0</div></article>
        <article class="tile"><h2>Customer Responded</h2><div id="customerRespondedCount" class="tile-value">0</div></article>
        <article class="tile"><h2>SLA Due Soon</h2><div id="slaDueSoonCount" class="tile-value">0</div></article>
        <article class="tile"><h2>SLA Overdue</h2><div id="slaOverdueCount" class="tile-value">0</div></article>
        <article class="tile"><h2>Project Tickets Open</h2><div id="projectOpenCount" class="tile-value">0</div></article>
        <article class="tile"><h2>Datto Open Alerts</h2><div id="dattoOpenAlertsCount" class="tile-value">0</div></article>
        <article class="tile"><h2>Kuma Down</h2><div id="kumaDownCount" class="tile-value">0</div></article>
        <article class="tile"><h2>Kuma Flapped</h2><div id="kumaFlapCount" class="tile-value">0</div></article>
        <article class="tile"><h2>Helpdesk Health</h2><div id="healthState" class="tile-value state-green">GREEN</div></article>
    </section>

    <section class="charts-grid">
        <div class="panel">
            <h2>Closed tickets by agent (this week)</h2>
            <div id="closedChart" class="bar-chart"></div>
        </div>
        <div class="panel">
            <h2>Open tickets by agent (now)</h2>
            <div id="openChart" class="bar-chart"></div>
        </div>
    </section>

    <section class="panel">
        <h2>Exceptions: Kuma down list</h2>
        <ul id="exceptionsList" class="exceptions"></ul>
    </section>
</div>

<script>
(function () {
    const refreshInterval = parseInt(document.body.dataset.refreshInterval || '10', 10) * 1000;
    const rssEnabledBySetting = document.body.dataset.rssEnabled === '1';
    const ukDateTimeFormatter = new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    });
    const ukDayFormatter = new Intl.DateTimeFormat('en-GB', { weekday: 'long' });
    const ukDateFormatter = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
    const ukTimeFormatter = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });

    function formatUkDateTime(value) {
        const d = value ? new Date(value) : new Date();
        if (Number.isNaN(d.getTime())) {
            return '--';
        }
        return ukDateTimeFormatter.format(d).replace(',', '');
    }

    function updateClock() {
        const now = new Date();
        document.getElementById('clockDay').textContent = ukDayFormatter.format(now);
        document.getElementById('clockDate').textContent = ukDateFormatter.format(now);
        document.getElementById('clockTime').textContent = ukTimeFormatter.format(now);
    }

    function statusClass(state) {
        switch ((state || '').toLowerCase()) {
            case 'green': return 'pill-green';
            case 'amber': return 'pill-amber';
            case 'red': return 'pill-red';
            default: return 'pill-grey';
        }
    }

    function renderApiStatus(apiStatus) {
        const strip = document.getElementById('apiStatusStrip');
        strip.innerHTML = '';
        ['halo', 'datto', 'kuma', 'rss'].forEach(function (name) {
            const item = apiStatus && apiStatus[name] ? apiStatus[name] : {state: 'grey', message: 'Unknown'};
            const el = document.createElement('div');
            el.className = 'status-pill ' + statusClass(item.state);
            el.textContent = name.toUpperCase() + ': ' + item.message;
            strip.appendChild(el);
        });
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function renderBars(containerId, rows) {
        const container = document.getElementById(containerId);
        container.innerHTML = '';
        const safeRows = Array.isArray(rows) ? rows : [];
        const max = Math.max(1, ...safeRows.map(r => Number(r.count || 0)));

        safeRows.forEach(function (row) {
            const wrap = document.createElement('div');
            wrap.className = 'bar-row';
            const label = document.createElement('div');
            label.className = 'bar-label';
            label.textContent = row.agent + ' (' + row.count + ')';
            const track = document.createElement('div');
            track.className = 'bar-track';
            const bar = document.createElement('div');
            bar.className = 'bar-fill';
            bar.style.width = ((Number(row.count || 0) / max) * 100) + '%';
            track.appendChild(bar);
            wrap.appendChild(label);
            wrap.appendChild(track);
            container.appendChild(wrap);
        });
    }

    function formatDuration(seconds) {
        const s = Number(seconds || 0);
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        return h + 'h ' + m + 'm ' + sec + 's';
    }

    function renderExceptions(rows) {
        const list = document.getElementById('exceptionsList');
        list.innerHTML = '';
        const safeRows = Array.isArray(rows) ? rows : [];
        if (safeRows.length === 0) {
            const li = document.createElement('li');
            li.textContent = 'No monitors down.';
            list.appendChild(li);
            return;
        }
        safeRows.forEach(function (row) {
            const li = document.createElement('li');
            li.textContent = row.name + ' - ' + formatDuration(row.durationSeconds);
            list.appendChild(li);
        });
    }

    function renderHealth(health) {
        const el = document.getElementById('healthState');
        const state = (health && health.state ? health.state : 'green').toLowerCase();
        el.textContent = state.toUpperCase();
        el.classList.remove('state-green', 'state-amber', 'state-red', 'state-grey');
        el.classList.add('state-' + (['green', 'amber', 'red'].includes(state) ? state : 'grey'));
    }

    function renderRss(payload) {
        const row = document.getElementById('rssRow');
        const ticker = document.getElementById('rssTicker');
        const enabled = rssEnabledBySetting && payload.rssTicker && payload.rssTicker.enabled;
        row.style.display = enabled ? 'grid' : 'none';
        if (!enabled) return;
        const items = Array.isArray(payload.rssTicker.items) ? payload.rssTicker.items : [];
        ticker.textContent = items.length ? items.map(item => item.title).join('  •  ') : 'No headlines.';
    }

    async function loadDashboard() {
        try {
            const response = await fetch('/api/dashboard.php', { cache: 'no-store' });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const data = await response.json();

            renderApiStatus(data.apiStatus || {});
            const t = data.tiles || {};
            setText('unassignedCount', t.unassignedCount ?? 0);
            setText('importantAlertsCount', t.importantAlertsCount ?? 0);
            setText('totalOpenCount', t.totalOpenCount ?? 0);
            setText('waitingOnCustomerCount', t.waitingOnCustomerCount ?? 0);
            setText('customerRespondedCount', t.customerRespondedCount ?? 0);
            setText('slaDueSoonCount', t.slaDueSoonCount ?? 0);
            setText('slaOverdueCount', t.slaOverdueCount ?? 0);
            setText('projectOpenCount', t.projectOpenCount ?? 0);
            setText('dattoOpenAlertsCount', t.dattoOpenAlertsCount ?? 0);
            setText('kumaDownCount', t.kumaDownCount ?? 0);
            setText('kumaFlapCount', t.kumaFlapCount ?? 0);

            renderHealth(data.health || {state: 'grey'});
            renderBars('closedChart', data.charts && data.charts.closedThisWeekByAgent ? data.charts.closedThisWeekByAgent : []);
            renderBars('openChart', data.charts && data.charts.openByAgent ? data.charts.openByAgent : []);
            renderExceptions(data.exceptions && data.exceptions.kumaDown ? data.exceptions.kumaDown : []);
            renderRss(data);

            const updatedIso = data.updatedAt && data.updatedAt.overall ? data.updatedAt.overall : null;
            document.getElementById('lastUpdated').textContent = 'Last updated: ' + formatUkDateTime(updatedIso);
        } catch (err) {
            renderApiStatus({
                halo: {state: 'red', message: 'API error'},
                datto: {state: 'red', message: 'API error'},
                kuma: {state: 'red', message: 'API error'},
                rss: {state: 'red', message: 'API error'}
            });
        }
    }

    updateClock();
    setInterval(updateClock, 1000);
    loadDashboard();
    setInterval(loadDashboard, refreshInterval);
})();
</script>
</body>
</html>
