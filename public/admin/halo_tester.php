<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\CacheRepo;
use App\Core\Config;
use App\Core\Database;
use App\Core\InstallGate;
use App\Core\IpAllowlist;
use App\Core\SettingsRepo;
use App\Services\HaloClient;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/halo_tester.php', PHP_URL_PATH) ?: '/admin/halo_tester.php';
InstallGate::enforce($path);

$config = Config::load();
$pdo = Database::connect($config);
$settings = new SettingsRepo($pdo);
IpAllowlist::enforce($settings, $path);
Auth::requireLogin();

$relativePath = trim((string) ($_GET['path'] ?? '/tickets'));
$queryString = trim((string) ($_GET['query'] ?? 'page_no=1&page_size=1'));
$result = null;
$error = '';

$decoded = null;
$tickets = [];
$recordCount = null;
$pageNo = null;
$pageSize = null;
$rawPreview = '';

if (isset($_GET['run']) && $_GET['run'] === '1') {
    try {
        $haloClient = new HaloClient($settings, new CacheRepo($pdo));
        $result = $haloClient->diagnosticRequest($relativePath, $queryString);

        $rawPreview = (string) ($result['preview'] ?? '');
        $rawPreview = mb_substr($rawPreview, 0, 5000);

        $decodedCandidate = json_decode($rawPreview, true);
        if (is_array($decodedCandidate)) {
            $decoded = $decodedCandidate;

            $ticketContainer = null;
            foreach (['tickets', 'Tickets'] as $key) {
                if (isset($decoded[$key]) && is_array($decoded[$key]) && array_is_list($decoded[$key])) {
                    $ticketContainer = $decoded[$key];
                    break;
                }
            }

            if (is_array($ticketContainer)) {
                $tickets = $ticketContainer;
                $recordCount = $decoded['record_count'] ?? $decoded['recordCount'] ?? null;
                $pageNo = $decoded['page_no'] ?? $decoded['pageNo'] ?? null;
                $pageSize = $decoded['page_size'] ?? $decoded['pageSize'] ?? null;
            }
        }
    } catch (Throwable $e) {
        $error = 'Request failed: ' . preg_replace('/[^a-zA-Z0-9 .:_\-\/]/', '', $e->getMessage());
    }
}

function tval(array $ticket, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $ticket)) {
            $v = $ticket[$key];
            if (is_scalar($v) || $v === null) {
                return trim((string) $v);
            }
        }
    }
    return '';
}

function summaryCell(array $ticket): string
{
    $s = tval($ticket, ['summary', 'title', 'subject']);
    if ($s === '') {
        return '';
    }
    return mb_strlen($s) > 80 ? mb_substr($s, 0, 80) . '…' : $s;
}
?><!doctype html>
<html><head><meta charset="utf-8"><title>Halo API Tester</title><link rel="stylesheet" href="/assets/dashboard.css"></head>
<body>
<div class="container">
    <h1>Halo API Tester</h1>
    <p class="error"><strong>Diagnostics only:</strong> use this page carefully. Do not share sensitive output.</p>
    <form method="get">
        <label>Relative path
            <input type="text" name="path" value="<?= htmlspecialchars($relativePath, ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label>Querystring
            <input type="text" name="query" value="<?= htmlspecialchars($queryString, ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <input type="hidden" name="run" value="1">
        <button type="submit">Run request</button>
    </form>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if (is_array($result)): ?>
        <h2>Result</h2>
        <p>Status: <?= (int) ($result['status'] ?? 0) ?></p>
        <?php if (!empty($result['error'])): ?><p class="error"><?= htmlspecialchars((string)$result['error'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

        <?php if ($tickets !== []): ?>
            <p>
                <strong>record_count:</strong> <?= htmlspecialchars((string) ($recordCount ?? 'n/a'), ENT_QUOTES, 'UTF-8') ?> |
                <strong>page_no:</strong> <?= htmlspecialchars((string) ($pageNo ?? 'n/a'), ENT_QUOTES, 'UTF-8') ?> |
                <strong>page_size:</strong> <?= htmlspecialchars((string) ($pageSize ?? 'n/a'), ENT_QUOTES, 'UTF-8') ?>
            </p>

            <div style="overflow:auto; border:1px solid #30405e; border-radius:8px; margin-bottom:10px;">
                <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                    <thead>
                    <tr style="background:#0b1424;">
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #30405e;">id</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #30405e;">status_id</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #30405e;">tickettype_id</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #30405e;">team_id</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #30405e;">agent_id</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #30405e;">dateoccurred</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #30405e;">summary</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tickets as $ticket): if (!is_array($ticket)) { continue; } ?>
                        <tr>
                            <td style="padding:8px;border-bottom:1px solid #243551;"><?= htmlspecialchars(tval($ticket, ['id']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px;border-bottom:1px solid #243551;"><?= htmlspecialchars(tval($ticket, ['status_id', 'statusid']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px;border-bottom:1px solid #243551;"><?= htmlspecialchars(tval($ticket, ['tickettype_id', 'tickettypeid']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px;border-bottom:1px solid #243551;"><?= htmlspecialchars(tval($ticket, ['team_id', 'teamid']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px;border-bottom:1px solid #243551;"><?= htmlspecialchars(tval($ticket, ['agent_id', 'agentid']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px;border-bottom:1px solid #243551;"><?= htmlspecialchars(tval($ticket, ['dateoccurred', 'dateOccurred']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px;border-bottom:1px solid #243551;"><?= htmlspecialchars(summaryCell($ticket), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <details>
                <summary>Show raw JSON (first 5000 chars)</summary>
                <pre style="white-space:pre-wrap;max-height:520px;overflow:auto;background:#0b1424;padding:10px;border-radius:8px;border:1px solid #30405e;"><?= htmlspecialchars($rawPreview, ENT_QUOTES, 'UTF-8') ?></pre>
            </details>
        <?php else: ?>
            <pre style="white-space:pre-wrap;max-height:520px;overflow:auto;background:#0b1424;padding:10px;border-radius:8px;border:1px solid #30405e;"><?= htmlspecialchars($rawPreview, ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>
    <?php endif; ?>

    <p><a href="/admin/settings.php?section=halo">Back to Halo settings</a></p>
</div>
</body></html>
