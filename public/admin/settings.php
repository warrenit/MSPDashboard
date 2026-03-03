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
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $settings->set('ip_allowlist', trim((string) ($_POST['ip_allowlist'] ?? '')));
        $settings->set('refresh_interval_sec', (string) max(10, (int) ($_POST['refresh_interval_sec'] ?? 10)));
        $settings->set('cache_interval_sec', (string) max(30, (int) ($_POST['cache_interval_sec'] ?? 30)));

        $settings->set('rss_enabled', isset($_POST['rss_enabled']) ? '1' : '0');
        $settings->set('rss_feed_urls', trim((string) ($_POST['rss_feed_urls'] ?? '')));
        $settings->set('rss_cache_interval_sec', (string) max(60, (int) ($_POST['rss_cache_interval_sec'] ?? 900)));

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

        $logoMode = in_array((string) ($_POST['logo_mode'] ?? 'url'), ['url', 'upload'], true) ? (string) $_POST['logo_mode'] : 'url';
        $settings->set('logo_mode', $logoMode);
        $settings->set('logo_url', trim((string) ($_POST['logo_url'] ?? '')));

        if ($logoMode === 'upload' && isset($_FILES['logo_upload']) && is_array($_FILES['logo_upload']) && (int)($_FILES['logo_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['logo_upload'];
            $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCode !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Logo upload failed.');
            }

            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0 || $size > (2 * 1024 * 1024)) {
                throw new RuntimeException('Logo must be less than 2MB.');
            }

            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('Invalid uploaded file.');
            }

            $mime = (string) (mime_content_type($tmp) ?: '');
            $ext = match ($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/svg+xml', 'text/plain' => 'svg',
                default => '',
            };

            if ($ext === '') {
                throw new RuntimeException('Only PNG, JPG, or SVG logos are allowed.');
            }

            if ($ext === 'svg') {
                $name = strtolower((string) ($file['name'] ?? ''));
                if (!str_ends_with($name, '.svg')) {
                    throw new RuntimeException('Only PNG, JPG, or SVG logos are allowed.');
                }
            }

            $uploadDir = dirname(__DIR__) . '/assets/uploads';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Unable to create uploads directory.');
            }

            $htaccess = $uploadDir . '/.htaccess';
            if (!is_file($htaccess)) {
                file_put_contents($htaccess, "Options -ExecCGI\nAddHandler cgi-script .php .pl .py .jsp .asp .sh .cgi\n<FilesMatch \"\\.(php|phtml|phar|pl|py|jsp|asp|sh|cgi)$\">\nDeny from all\n</FilesMatch>\n", LOCK_EX);
            }

            foreach (['png', 'jpg', 'svg'] as $oldExt) {
                $oldPath = $uploadDir . '/logo.' . $oldExt;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $destPath = $uploadDir . '/logo.' . $ext;
            if (!move_uploaded_file($tmp, $destPath)) {
                throw new RuntimeException('Unable to save logo file.');
            }

            @chmod($destPath, 0644);
            $settings->set('logo_upload_path', '/assets/uploads/logo.' . $ext);
        }

        $success = 'Settings saved.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function val(SettingsRepo $settings, string $key, string $default = ''): string
{
    return (string) $settings->get($key, $default);
}
?><!doctype html>
<html><head><meta charset="utf-8"><title>Admin Settings</title><link rel="stylesheet" href="/assets/dashboard.css"></head>
<body><div class="container"><h1>Admin Settings (Phase 2.1)</h1>
<?php if ($success !== ''): ?><p class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data">
    <h2>Security & Intervals</h2>
    <label>IP allowlist (comma-separated public IPs)
    <input type="text" name="ip_allowlist" value="<?= htmlspecialchars(val($settings, 'ip_allowlist'), ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>Refresh interval (seconds)
    <input type="number" min="10" name="refresh_interval_sec" value="<?= htmlspecialchars(val($settings, 'refresh_interval_sec', '10'), ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>Cache interval (seconds)
    <input type="number" min="30" name="cache_interval_sec" value="<?= htmlspecialchars(val($settings, 'cache_interval_sec', '30'), ENT_QUOTES, 'UTF-8') ?>"></label>

    <h2>Logo</h2>
    <label>Logo mode
        <select name="logo_mode">
            <option value="url" <?= val($settings, 'logo_mode', 'url') === 'url' ? 'selected' : '' ?>>URL</option>
            <option value="upload" <?= val($settings, 'logo_mode', 'url') === 'upload' ? 'selected' : '' ?>>Upload</option>
        </select>
    </label>
    <label>Logo URL
        <input type="text" name="logo_url" value="<?= htmlspecialchars(val($settings, 'logo_url'), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com/logo.png">
    </label>
    <label>Upload logo (PNG/JPG/SVG, max 2MB)
        <input type="file" name="logo_upload" accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml">
    </label>

    <h2>RSS</h2>
    <label><input type="checkbox" name="rss_enabled" value="1" <?= val($settings, 'rss_enabled', '0') === '1' ? 'checked' : '' ?>> Enable RSS ticker</label>
    <label>RSS feed URLs (one per line)
    <textarea name="rss_feed_urls" rows="5"><?= htmlspecialchars(val($settings, 'rss_feed_urls'), ENT_QUOTES, 'UTF-8') ?></textarea></label>
    <label>RSS cache interval (seconds)
    <input type="number" min="60" name="rss_cache_interval_sec" value="<?= htmlspecialchars(val($settings, 'rss_cache_interval_sec', '900'), ENT_QUOTES, 'UTF-8') ?>"></label>

    <h2>Agent Names (for mock charts)</h2>
    <label>Agent names (comma or newline separated)
    <textarea name="agent_names" rows="4"><?= htmlspecialchars(val($settings, 'agent_names', 'Name'), ENT_QUOTES, 'UTF-8') ?></textarea></label>

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
