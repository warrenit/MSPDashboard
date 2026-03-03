<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\InstallGate;
use App\Core\IpAllowlist;
use App\Core\SettingsRepo;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/settings.php', PHP_URL_PATH) ?: '/admin/settings.php';
InstallGate::enforce($path);

$config = Config::load();
$pdo = Database::connect($config);
$settings = new SettingsRepo($pdo);
IpAllowlist::enforce($settings, $path);
Auth::requireLogin();

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings->set('ip_allowlist', trim((string) ($_POST['ip_allowlist'] ?? '')));
    $settings->set('refresh_interval_sec', (string) max(10, (int) ($_POST['refresh_interval_sec'] ?? 10)));
    $settings->set('cache_interval_sec', (string) max(30, (int) ($_POST['cache_interval_sec'] ?? 30)));

    $settings->set('rss_enabled', isset($_POST['rss_enabled']) ? '1' : '0');
    $settings->set('rss_feed_urls', trim((string) ($_POST['rss_feed_urls'] ?? '')));

    $settings->set('agent_names', trim((string) ($_POST['agent_names'] ?? '')));
    $settings->set('important_ticket_types', trim((string) ($_POST['important_ticket_types'] ?? '')));
    $settings->set('status_waiting_on_customer', trim((string) ($_POST['status_waiting_on_customer'] ?? '')));
    $settings->set('status_customer_responded', trim((string) ($_POST['status_customer_responded'] ?? '')));
    $settings->set('status_open_or_closed_exclusions', trim((string) ($_POST['status_open_or_closed_exclusions'] ?? '')));

    $settings->set('health_threshold_unassigned', trim((string) ($_POST['health_threshold_unassigned'] ?? '')));
    $settings->set('health_threshold_important_alerts', trim((string) ($_POST['health_threshold_important_alerts'] ?? '')));
    $settings->set('health_threshold_sla_overdue', trim((string) ($_POST['health_threshold_sla_overdue'] ?? '')));
    $settings->set('health_threshold_sla_due_soon', trim((string) ($_POST['health_threshold_sla_due_soon'] ?? '')));
    $settings->set('health_threshold_customer_responded', trim((string) ($_POST['health_threshold_customer_responded'] ?? '')));
    $settings->set('health_threshold_kuma_down', trim((string) ($_POST['health_threshold_kuma_down'] ?? '')));

    $success = 'Settings saved.';
}

function val(SettingsRepo $settings, string $key, string $default = ''): string
{
    return (string) $settings->get($key, $default);
}
?><!doctype html>
<html><head><meta charset="utf-8"><title>Admin Settings</title><link rel="stylesheet" href="/assets/dashboard.css"></head>
<body><div class="container"><h1>Admin Settings (Phase 2)</h1>
<?php if ($success !== ''): ?><p class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
    <h2>Security & Intervals</h2>
    <label>IP allowlist (comma-separated public IPs)
    <input type="text" name="ip_allowlist" value="<?= htmlspecialchars(val($settings, 'ip_allowlist'), ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>Refresh interval (seconds)
    <input type="number" min="10" name="refresh_interval_sec" value="<?= htmlspecialchars(val($settings, 'refresh_interval_sec', '10'), ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>Cache interval (seconds)
    <input type="number" min="30" name="cache_interval_sec" value="<?= htmlspecialchars(val($settings, 'cache_interval_sec', '30'), ENT_QUOTES, 'UTF-8') ?>"></label>

    <h2>RSS</h2>
    <label><input type="checkbox" name="rss_enabled" value="1" <?= val($settings, 'rss_enabled', '0') === '1' ? 'checked' : '' ?>> Enable RSS ticker</label>
    <label>RSS feed URLs (one per line)
    <textarea name="rss_feed_urls" rows="5"><?= htmlspecialchars(val($settings, 'rss_feed_urls'), ENT_QUOTES, 'UTF-8') ?></textarea></label>

    <h2>Agent Names (for mock charts)</h2>
    <label>Agent names (comma or newline separated)
    <textarea name="agent_names" rows="4"><?= htmlspecialchars(val($settings, 'agent_names', "Name"), ENT_QUOTES, 'UTF-8') ?></textarea></label>

    <h2>Ticket Type / Status Mappings (stored for Phase 3)</h2>
    <label>Important ticket types (comma or newline separated)
    <textarea name="important_ticket_types" rows="3"><?= htmlspecialchars(val($settings, 'important_ticket_types'), ENT_QUOTES, 'UTF-8') ?></textarea></label>
    <label>Waiting on Customer statuses (comma or newline separated)
    <textarea name="status_waiting_on_customer" rows="3"><?= htmlspecialchars(val($settings, 'status_waiting_on_customer'), ENT_QUOTES, 'UTF-8') ?></textarea></label>
    <label>Customer Responded statuses (comma or newline separated)
    <textarea name="status_customer_responded" rows="3"><?= htmlspecialchars(val($settings, 'status_customer_responded'), ENT_QUOTES, 'UTF-8') ?></textarea></label>
    <label>Open/Closed exclusion statuses (comma or newline separated)
    <textarea name="status_open_or_closed_exclusions" rows="3"><?= htmlspecialchars(val($settings, 'status_open_or_closed_exclusions'), ENT_QUOTES, 'UTF-8') ?></textarea></label>

    <h2>Helpdesk Health Thresholds (simple numeric values)</h2>
    <label>Unassigned threshold <input type="text" name="health_threshold_unassigned" value="<?= htmlspecialchars(val($settings, 'health_threshold_unassigned', '5'), ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>Important Alerts threshold <input type="text" name="health_threshold_important_alerts" value="<?= htmlspecialchars(val($settings, 'health_threshold_important_alerts', '3'), ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>SLA Overdue threshold <input type="text" name="health_threshold_sla_overdue" value="<?= htmlspecialchars(val($settings, 'health_threshold_sla_overdue', '1'), ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>SLA Due Soon threshold <input type="text" name="health_threshold_sla_due_soon" value="<?= htmlspecialchars(val($settings, 'health_threshold_sla_due_soon', '5'), ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>Customer Responded threshold <input type="text" name="health_threshold_customer_responded" value="<?= htmlspecialchars(val($settings, 'health_threshold_customer_responded', '5'), ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>Kuma Down threshold <input type="text" name="health_threshold_kuma_down" value="<?= htmlspecialchars(val($settings, 'health_threshold_kuma_down', '1'), ENT_QUOTES, 'UTF-8') ?>"></label>

    <button type="submit">Save settings</button>
</form>
<p><a href="/admin/logout.php">Logout</a></p>
</div></body></html>
