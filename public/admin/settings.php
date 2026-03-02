<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
$ipMiddleware->enforce();

if (!$auth->check()) {
    header('Location: /admin/login.php');
    exit;
}

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingsRepo->upsert('ip_allowlist', trim((string) ($_POST['ip_allowlist'] ?? '')));
    $settingsRepo->upsert('halo_resource_base_url', trim((string) ($_POST['halo_resource_base_url'] ?? '')));
    $settingsRepo->upsert('halo_auth_base_url', trim((string) ($_POST['halo_auth_base_url'] ?? '')));
    $settingsRepo->upsert('halo_tenant', trim((string) ($_POST['halo_tenant'] ?? '')));
    $settingsRepo->upsert('halo_client_id', trim((string) ($_POST['halo_client_id'] ?? '')));

    $clientSecret = (string) ($_POST['halo_client_secret'] ?? '');
    if ($clientSecret !== '') {
        $settingsRepo->upsert('halo_client_secret', $encryption->encrypt($clientSecret));
    }

    $settingsRepo->upsert('cache_interval', (string) max(10, (int) ($_POST['cache_interval'] ?? 60)));
    $settingsRepo->upsert('frontend_refresh_interval', (string) max(10, (int) ($_POST['frontend_refresh_interval'] ?? 10)));

    $message = 'Settings saved.';
}

$settings = $settingsRepo->getAll();
?>
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Dashboard Settings</title>
<style>body{font-family:Arial,sans-serif;max-width:860px;margin:30px auto;padding:0 16px}label{display:block;margin:12px 0 4px;font-weight:600}input,textarea{width:100%;padding:8px}button{margin-top:16px;padding:10px 15px}small{color:#374151} .row{margin-bottom:10px}</style>
</head>
<body>
<h1>Dashboard Settings</h1>
<p><a href="/">View Dashboard</a> | <a href="/admin/logout.php">Logout</a></p>
<?php if ($message): ?><p style="color:#166534"><?= htmlspecialchars($message, ENT_QUOTES) ?></p><?php endif; ?>
<form method="post">
    <div class="row">
        <label for="ip_allowlist">IP Allowlist (comma or newline separated)</label>
        <textarea id="ip_allowlist" name="ip_allowlist" rows="3"><?= htmlspecialchars($settings['ip_allowlist'] ?? '', ENT_QUOTES) ?></textarea>
    </div>
    <div class="row"><label for="halo_resource_base_url">Halo Resource Base URL</label><input id="halo_resource_base_url" name="halo_resource_base_url" value="<?= htmlspecialchars($settings['halo_resource_base_url'] ?? '', ENT_QUOTES) ?>" required></div>
    <div class="row"><label for="halo_auth_base_url">Halo Auth Base URL</label><input id="halo_auth_base_url" name="halo_auth_base_url" value="<?= htmlspecialchars($settings['halo_auth_base_url'] ?? '', ENT_QUOTES) ?>" required></div>
    <div class="row"><label for="halo_tenant">Halo Tenant</label><input id="halo_tenant" name="halo_tenant" value="<?= htmlspecialchars($settings['halo_tenant'] ?? '', ENT_QUOTES) ?>" required></div>
    <div class="row"><label for="halo_client_id">Halo Client ID</label><input id="halo_client_id" name="halo_client_id" value="<?= htmlspecialchars($settings['halo_client_id'] ?? '', ENT_QUOTES) ?>" required></div>
    <div class="row"><label for="halo_client_secret">Halo Client Secret</label><input id="halo_client_secret" type="password" name="halo_client_secret" placeholder="Leave blank to keep existing"></div>
    <div class="row"><label for="cache_interval">Cache Interval (seconds)</label><input id="cache_interval" type="number" min="10" name="cache_interval" value="<?= htmlspecialchars($settings['cache_interval'] ?? '60', ENT_QUOTES) ?>"></div>
    <div class="row"><label for="frontend_refresh_interval">Frontend Refresh Interval (seconds)</label><input id="frontend_refresh_interval" type="number" min="10" name="frontend_refresh_interval" value="<?= htmlspecialchars($settings['frontend_refresh_interval'] ?? '10', ENT_QUOTES) ?>"></div>
    <button type="submit">Save Settings</button>
    <p><small>Client secret is encrypted at rest using APP_KEY_FILE outside webroot.</small></p>
</form>
</body>
</html>
