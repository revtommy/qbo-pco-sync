<?php
declare(strict_types=1);


$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/Auth.php';

// First-run helper: if no .env present, redirect to setup.
if (!file_exists(__DIR__ . '/../config/.env')) {
    header('Location: setup.php');
    exit;
}
Auth::requireLogin();
// ---------------------------------------------------------------
// DB connection
// ---------------------------------------------------------------
try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>QuickBooks / Planning Center Sync</h1>';
    echo '<div style="padding:0.6rem 0.8rem;background:#ffecec;border:1px solid #ffaeae;">';
    echo 'Database error:<br><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre></div>';
    exit;
}

// ---------------------------------------------------------------
// Flash messages from OAuth callback (QBO connect)
// ---------------------------------------------------------------
$flashSuccess = false;
$flashError   = null;

// support either ?qbo_connected=1 or ?connected=1
if (isset($_GET['qbo_connected']) && $_GET['qbo_connected'] === '1') {
    $flashSuccess = true;
}
if (isset($_GET['connected']) && $_GET['connected'] === '1') {
    $flashSuccess = true;
}
if (!empty($_GET['qbo_error'])) {
    $flashError = (string)$_GET['qbo_error'];
}

// ---------------------------------------------------------------
// QBO connection status: look at qbo_tokens table
// ---------------------------------------------------------------
$qboConnected = false;
$qboStatusText = 'Not yet connected to QuickBooks.';
$qboExpiresInMinutes = null;

try {
    $stmt = $pdo->query("SELECT * FROM qbo_tokens ORDER BY id DESC LIMIT 1");
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tokenRow) {
        $realmId   = $tokenRow['realm_id'] ?? '';
        $expiresAt = null;

        if (!empty($tokenRow['expires_at'])) {
            try {
                $expiresAt = new DateTimeImmutable($tokenRow['expires_at']);
            } catch (Throwable $e) {
                $expiresAt = null;
            }
        }

        $now = new DateTimeImmutable('now');

        if ($expiresAt && $expiresAt > $now) {
            $qboConnected  = true;
            $qboStatusText = 'Connected to QuickBooks (realm ' . htmlspecialchars($realmId, ENT_QUOTES, 'UTF-8') . ').';
            $qboExpiresInMinutes = max(0, (int)round(($expiresAt->getTimestamp() - $now->getTimestamp()) / 60));
        } else {
            // We have a row but token is expired or can't parse date
            $qboStatusText = 'QuickBooks token has expired or is invalid. Please reconnect.';
        }
    }
} catch (Throwable $e) {
    $qboStatusText = 'Error checking QuickBooks status: ' . $e->getMessage();
}

// ---------------------------------------------------------------
// PCO status: simple check using config
// ---------------------------------------------------------------
$pcoConnected  = false;
$pcoStatusText = 'Not configured.';

try {
    if (!empty($config['pco']['app_id']) && !empty($config['pco']['secret'])) {
        // Just constructing the client is enough to know config is present.
        $pcoClient     = new PcoClient($config);
        $pcoConnected  = true;
        $pcoStatusText = 'Configured (credentials present).';
    } else {
        $pcoStatusText = 'Missing PCO app_id or secret in config.';
    }
} catch (Throwable $e) {
    $pcoStatusText = 'Error initializing PCO client: ' . $e->getMessage();
}

$mappingStats = null;
if ($pcoConnected) {
    try {
        // Fetch PCO funds and existing mappings to calculate completeness.
        $funds = $pcoClient->listFunds();
        $totalFunds = is_array($funds) ? count($funds) : 0;
        $stmt = $pdo->query("SELECT * FROM fund_mappings");
        $maps = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $maps[(string)$row['pco_fund_id']] = $row;
        }
        $unmapped = 0;
        $missingClass = 0;
        $missingLocation = 0;
        foreach ($funds as $f) {
            $fid = (string)($f['id'] ?? '');
            if ($fid === '') { continue; }
            if (!isset($maps[$fid])) {
                $unmapped++;
                continue;
            }
            $m = $maps[$fid];
            if (trim((string)($m['qbo_class_name'] ?? '')) === '') {
                $missingClass++;
            }
            if (trim((string)($m['qbo_location_name'] ?? '')) === '') {
                $missingLocation++;
            }
        }
        $mapped = $totalFunds - $unmapped;
        $mappingStats = [
            'total'           => $totalFunds,
            'mapped'          => max(0, $mapped),
            'unmapped'        => $unmapped,
            'missing_class'   => $missingClass,
            'missing_location'=> $missingLocation,
        ];
    } catch (Throwable $e) {
        $mappingStats = null;
    }
}

// Composer/vendor presence
$composerMissing = !file_exists(__DIR__ . '/../vendor/autoload.php');

// ---------------------------------------------------------------
// Optional: notification email from sync_settings (if present)
// ---------------------------------------------------------------
$notificationEmail = null;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM sync_settings WHERE setting_key = 'notification_email' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['setting_value'])) {
        $notificationEmail = $row['setting_value'];
    }
} catch (Throwable $e) {
    // Don't break the page if this fails; just omit the email display.
    $notificationEmail = null;
}

$syncSummaries = [];
function load_sync_summary(PDO $pdo, string $key): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM sync_settings WHERE setting_key = :key ORDER BY id DESC LIMIT 1');
        $stmt->execute([':key' => $key]);
        $val = $stmt->fetchColumn();
        if ($val) {
            $decoded = json_decode((string)$val, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

$syncSummaries['stripe']         = load_sync_summary($pdo, 'last_stripe_sync_summary');
$syncSummaries['batch']          = load_sync_summary($pdo, 'last_batch_sync_summary');
$syncSummaries['registrations']  = load_sync_summary($pdo, 'last_registrations_sync_summary');

// Config hardening check (simple heuristics)
$configWarnings = [];
$sampleStrings = [
    'your_pco_app_id',
    'your_pco_secret',
    'changeme',
];
foreach ($sampleStrings as $needle) {
    if (str_contains(strtolower($config['pco']['app_id'] ?? ''), $needle) ||
        str_contains(strtolower($config['pco']['secret'] ?? ''), $needle) ||
        str_contains(strtolower($config['db']['pass'] ?? ''), $needle)
    ) {
        $configWarnings[] = 'Configuration still contains sample/placeholder values. Please update .env with real credentials.';
        break;
    }
}

// Sync health alerts
$healthAlerts = [];
foreach (['stripe' => 'Stripe donations', 'batch' => 'Committed batches', 'registrations' => 'Registrations payments'] as $k => $label) {
    $s = $syncSummaries[$k] ?? null;
    if ($s && isset($s['status']) && in_array($s['status'], ['error', 'partial'], true)) {
        $healthAlerts[] = $label . ' last run status: ' . strtoupper((string)$s['status']);
    }
}

// Mail health (last send)
$mailStatus = null;
$mailStatusText = 'No recent mail sends logged.';
$mailStatusFile = __DIR__ . '/../logs/mail-status.json';
if (file_exists($mailStatusFile)) {
    $raw = @file_get_contents($mailStatusFile);
    $decoded = $raw ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $mailStatus = $decoded;
        $mailStatusText = strtoupper((string)($decoded['status'] ?? 'unknown')) .
            ' @ ' . htmlspecialchars((string)($decoded['ts'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (!empty($decoded['error'])) {
            $mailStatusText .= ' (Error: ' . htmlspecialchars((string)$decoded['error'], ENT_QUOTES, 'UTF-8') . ')';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QuickBooks / Planning Center Sync</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Manrope:wght@500;700&display=swap');
        :root {
            --bg: #0b1224;
            --panel: rgba(15, 25, 46, 0.78);
            --card: rgba(22, 32, 55, 0.9);
            --border: rgba(255, 255, 255, 0.08);
            --text: #e9eef7;
            --muted: #9daccc;
            --accent: #2ea8ff;
            --accent-strong: #0d7adf;
            --success: #39d98a;
            --warn: #f2c94c;
            --error: #ff7a7a;
        }
        body {
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            margin: 0;
            background: radial-gradient(circle at 15% 20%, rgba(46, 168, 255, 0.12), transparent 25%),
                        radial-gradient(circle at 85% 10%, rgba(57, 217, 138, 0.15), transparent 22%),
                        radial-gradient(circle at 70% 70%, rgba(242, 201, 76, 0.08), transparent 30%),
                        var(--bg);
            color: var(--text);
            min-height: 100vh;
        }
        * { box-sizing: border-box; }
        .page {
            max-width: 1150px;
            margin: 0 auto;
            padding: 2.5rem 1.25rem 3rem;
        }
        .hero {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1.6rem 1.8rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
            position: relative;
            overflow: hidden;
        }
        .hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 30% 40%, rgba(46,168,255,0.15), transparent 35%),
                        radial-gradient(circle at 80% 10%, rgba(57,217,138,0.12), transparent 35%);
            pointer-events: none;
        }
        .hero > * { position: relative; z-index: 1; }
        .eyebrow {
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--muted);
            font-size: 0.8rem;
            margin-bottom: 0.35rem;
        }
        h1 {
            margin: 0 0 0.4rem;
            font-size: 2.1rem;
            letter-spacing: -0.01em;
        }
        .lede {
            color: var(--muted);
            margin: 0;
            max-width: 56ch;
            line-height: 1.6;
        }
        .flash {
            padding: 0.85rem 1rem;
            border-radius: 10px;
            border: 1px solid var(--border);
            margin-top: 1.1rem;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.75rem;
            align-items: center;
        }
        .flash.success {
            background: rgba(57, 217, 138, 0.12);
            border-color: rgba(57, 217, 138, 0.35);
        }
        .flash.error {
            background: rgba(255, 122, 122, 0.12);
            border-color: rgba(255, 122, 122, 0.35);
        }
        .flash .tag {
            background: rgba(255, 255, 255, 0.06);
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.85rem;
            border: 1px solid var(--border);
        }
        .section-grid {
            margin-top: 1.5rem;
            display: grid;
            gap: 1rem;
        }
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 0.85rem;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1rem 1.1rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18);
        }
        .card.section {
            padding: 1.3rem 1.35rem;
        }
        .status {
            display: grid;
            gap: 0.15rem;
        }
        .status .label {
            font-size: 0.9rem;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 0.4rem;
            letter-spacing: 0.01em;
        }
        .status .value {
            font-size: 1.05rem;
            font-weight: 700;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #0f172a;
        }
        .pill.ok { background: rgba(57, 217, 138, 0.9); }
        .pill.warn { background: rgba(242, 201, 76, 0.9); }
        .pill.error { background: rgba(255, 122, 122, 0.9); }
        .pill.info { background: rgba(46, 168, 255, 0.9); }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 0.85rem;
        }
        .section-title {
            margin: 0;
            font-size: 1.1rem;
            letter-spacing: -0.01em;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.65rem 1rem;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #0b1324;
            font-weight: 700;
            text-decoration: none;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 10px 25px rgba(13,122,223,0.35);
            transition: transform 120ms ease, box-shadow 120ms ease, background 150ms ease;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 30px rgba(13,122,223,0.4);
        }
        .btn.secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
            box-shadow: none;
        }
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 0.8rem;
        }
        .sync-columns {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 0.85rem;
        }
        .tile-stack {
            display: grid;
            gap: 0.8rem;
        }
        .tile {
            display: grid;
            gap: 0.3rem;
            padding: 0.9rem 1rem;
            border-radius: 12px;
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border);
            text-decoration: none;
            color: var(--text);
            transition: border-color 120ms ease, transform 120ms ease, background 120ms ease;
        }
        .tile:hover {
            border-color: rgba(46, 168, 255, 0.45);
            background: rgba(46,168,255,0.06);
            transform: translateY(-1px);
        }
        .tile .title {
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .tile .desc {
            color: var(--muted);
            font-size: 0.92rem;
            line-height: 1.4;
        }
        .list {
            display: grid;
            gap: 0.45rem;
            padding: 0.2rem 0 0.1rem;
        }
        .list a {
            color: var(--text);
            text-decoration: none;
            border-bottom: 1px dashed rgba(255,255,255,0.12);
            padding-bottom: 0.15rem;
        }
        .list a:hover {
            border-color: rgba(46,168,255,0.55);
            color: #dff1ff;
        }
        .small {
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.5;
        }
        .footnote {
            margin-top: 1.4rem;
        }
        .footer {
            margin-top: 1.4rem;
            text-align: center;
            color: var(--muted);
            font-size: 0.9rem;
        }
        .footer a { color: var(--accent); text-decoration: none; }
        .footer a:hover { color: #dff1ff; }
        .centered {
            display: flex;
            justify-content: center;
            margin-top: 0.9rem;
        }
        @media (max-width: 720px) {
            .hero { padding: 1.35rem 1.15rem; }
            .section-header { align-items: flex-start; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <div class="eyebrow">Control Center</div>
        <h1>QuickBooks &harr; Planning Center Sync</h1>
        <p class="lede">Keep credentials connected, run one-off syncs, and quickly jump to mapping and configuration screens.</p>

        <?php if ($flashSuccess): ?>
            <div class="flash success">
                <span class="tag">Success</span>
                <div>Successfully connected to QuickBooks and stored tokens.</div>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="flash error">
                <span class="tag">Issue</span>
                <div>Error during QuickBooks connection:<br><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="section-grid" style="margin-top: 1.1rem;">
        <div class="status-grid">
            <div class="card status">
                <div class="label">QuickBooks</div>
                <div class="value"><?= $qboStatusText ?></div>
                <div class="pill <?= $qboConnected ? 'ok' : 'warn' ?>">
                    <?= $qboConnected ? 'Connected' : 'Needs attention' ?>
                </div>
                <?php if ($qboConnected && $qboExpiresInMinutes !== null): ?>
                    <div class="small">Token expires in ~<?= (int)$qboExpiresInMinutes ?> minutes.</div>
                <?php endif; ?>
            </div>
            <div class="card status">
                <div class="label">Planning Center</div>
                <div class="value"><?= htmlspecialchars($pcoStatusText, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="pill <?= $pcoConnected ? 'ok' : 'warn' ?>">
                    <?= $pcoConnected ? 'Configured' : 'Not ready' ?>
                </div>
            </div>
            <?php if ($mappingStats !== null): ?>
                <?php
                    $totalFunds      = max(0, (int)($mappingStats['total'] ?? 0));
                    $mappedFunds     = max(0, (int)($mappingStats['mapped'] ?? 0));
                    $unmappedFunds   = max(0, (int)($mappingStats['unmapped'] ?? 0));
                    $missingClass    = max(0, (int)($mappingStats['missing_class'] ?? 0));
                    $missingLocation = max(0, (int)($mappingStats['missing_location'] ?? 0));
                    $issues          = $unmappedFunds + $missingClass + $missingLocation;
                    $pct             = $totalFunds > 0 ? (int)round(($mappedFunds / $totalFunds) * 100) : 0;
                    $pillClass       = $totalFunds === 0 ? 'warn' : ($issues === 0 ? 'ok' : 'warn');
                    $pillLabel       = $totalFunds === 0 ? 'No funds' : ($issues === 0 ? 'Complete' : 'Needs mapping');
                ?>
                <div class="card status">
                    <div class="label">Fund mapping health</div>
                    <div class="value">
                        <?php if ($totalFunds === 0): ?>
                            No PCO funds returned
                        <?php else: ?>
                            <?= $mappedFunds ?>/<?= $totalFunds ?> mapped (<?= $pct ?>%)
                        <?php endif; ?>
                    </div>
                    <div class="pill <?= $pillClass ?>"><?= $pillLabel ?></div>
                    <div class="small">
                        <?php if ($issues === 0 && $totalFunds > 0): ?>
                            All funds have class and location mappings.
                        <?php else: ?>
                            Unmapped: <?= $unmappedFunds ?> | Missing class: <?= $missingClass ?> | Missing location: <?= $missingLocation ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($notificationEmail): ?>
                <div class="card status">
                    <div class="label">Notification Email</div>
                    <div class="value"><?= htmlspecialchars($notificationEmail, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="pill info">Active</div>
                </div>
            <?php endif; ?>
            <div class="card status">
                <div class="label">Mail status</div>
                <div class="value"><?= htmlspecialchars($mailStatusText, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="pill <?= ($mailStatus && str_contains($mailStatus['status'] ?? '', 'success')) ? 'ok' : 'warn' ?>">
                    <?= ($mailStatus && str_contains($mailStatus['status'] ?? '', 'success')) ? 'OK' : 'Check' ?>
                </div>
            </div>
            <div class="card status">
                <div class="label">Composer / vendor</div>
                <div class="value"><?= $composerMissing ? 'vendor/autoload.php missing (run composer install)' : 'Dependencies present' ?></div>
                <div class="pill <?= $composerMissing ? 'warn' : 'ok' ?>">
                    <?= $composerMissing ? 'Needs action' : 'Ready' ?>
                </div>
            </div>
        </div>

        <?php if (!empty($configWarnings)): ?>
            <div class="card" style="border:1px solid rgba(255,122,122,0.35); background: rgba(255,122,122,0.08);">
                <p class="section-title" style="margin:0;">Configuration warnings</p>
                <ul>
                    <?php foreach ($configWarnings as $w): ?>
                        <li><?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($healthAlerts)): ?>
            <div class="card" style="border:1px solid rgba(255,122,122,0.35); background: rgba(255,122,122,0.08);">
                <p class="section-title" style="margin:0;">Sync alerts</p>
                <ul>
                    <?php foreach ($healthAlerts as $w): ?>
                        <li><?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="muted" style="margin-top:0.5rem;">Check sync logs or rerun the affected sync.</p>
            </div>
        <?php endif; ?>

        <div class="card section">
            <div class="section-header">
                <div>
                    <div class="eyebrow">Connections</div>
                    <p class="section-title">Manage credentials and mappings</p>
                </div>
                <a href="oauth-start.php" class="btn <?= $qboConnected ? 'secondary disabled' : '' ?>" style="<?= $qboConnected ? 'pointer-events: none; opacity: 0.6;' : '' ?>">
                    <?= $qboConnected ? 'Reconnect QuickBooks' : 'Connect QuickBooks' ?>
                </a>
            </div>
            <div class="action-grid">
                <a class="tile" href="settings.php">
                    <span class="title">Account setup</span>
                    <span class="desc">Edit QBO accounts and notification email preferences.</span>
                </a>
                <a class="tile" href="fund-mapping.php">
                    <span class="title">Fund &rarr; Class/Location/Income</span>
                    <span class="desc">Maintain mapping rules so deposits land in the right QBO buckets.</span>
                </a>
            </div>
        </div>

        <div class="card section">
            <div class="section-header">
                <div>
                    <div class="eyebrow">Sync actions</div>
                    <p class="section-title">Run or inspect data flows</p>
                </div>
            </div>
            <div class="sync-columns">
                <div class="tile-stack">
                    <a class="tile" href="run-sync-preview.php">
                        <span class="title">Preview Stripe donations</span>
                        <span class="desc">Check upcoming donations with the current completed_at filters.</span>
                    </a>
                    <a class="tile" href="run-sync.php">
                        <span class="title">Immediate Stripe Sync</span>
                        <span class="desc">Push completed PCO Stripe payouts into QuickBooks.</span>
                    </a>
                </div>
                <div class="tile-stack">
                    <a class="tile" href="run-batch-preview.php">
                        <span class="title">Batch preview</span>
                        <span class="desc">Inspect committed batches and totals before syncing to QBO.</span>
                    </a>
                    <a class="tile" href="run-batch-sync.php">
                        <span class="title">Immediate Batch Sync</span>
                        <span class="desc">Push committed PCO batches into QuickBooks</span>
                    </a>
                </div>
            </div>
            <div class="centered">
                <a class="btn secondary" href="logs.php">View sync logs</a>
            </div>
        </div>

        <div class="card section">
            <div class="section-header">
                <div>
                    <div class="eyebrow">Registrations</div>
                    <p class="section-title">Event payments</p>
                </div>
            </div>
            <div class="action-grid">
                <a class="tile" href="run-registrations-preview.php">
                    <span class="title">Preview registrations payments</span>
                    <span class="desc">Inspect recent PCO Registrations payments before syncing.</span>
                </a>
                <a class="tile" href="run-registrations-sync.php">
                    <span class="title">Run registrations sync</span>
                    <span class="desc">Push registrations payments into QuickBooks deposits.</span>
                </a>
            </div>
            <p class="muted" style="margin-top: 0.65rem;">
                Registrations payments use a legacy PCO API version and may stop working without notice.
            </p>
        </div>

        <div class="card section">
            <div class="section-header">
                <div>
                    <div class="eyebrow">Status</div>
                    <p class="section-title">Recent sync snapshots</p>
                </div>
            </div>
            <div class="list">
                <?php
                $labels = [
                    'stripe' => 'Stripe donations',
                    'batch'  => 'Committed batches',
                    'registrations' => 'Registrations payments',
                ];
                foreach ($labels as $key => $label):
                    $s = $syncSummaries[$key] ?? null;
                    if (!$s) { continue; }
                    $ts = htmlspecialchars((string)($s['ts'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $statusTxt = htmlspecialchars((string)($s['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8');
                ?>
                <div>
                    <strong><?= $label ?></strong>
                    <span class="muted">? <?= $statusTxt ?> @ <?= $ts ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty(array_filter($syncSummaries))): ?>
                    <div class="muted">No recent sync summaries yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card section">
            <div class="section-header">
                <div>
                    <div class="eyebrow">Admin</div>
                    <p class="section-title">Session & housekeeping</p>
                </div>
            </div>
            <div class="list">
                <a href="create_admin.php">Create another user</a>
                <a href="logout.php">Log out</a>
                <a href="test-pco.php">Test PCO connection</a>
                <a href="self-check.php">System self-check</a>
                <a href="logs-diagnostics.php">Diagnostics logs</a>
            </div>
        </div>

        <p class="small footnote">
            This dashboard helps trigger manual PCO Stripe payout syncs, review logs, adjust fund mapping, and keep notifications flowing.
        </p>
        <div class="footer">
            &copy; <?= date('Y') ?> Rev. Tommy Sheppard ? <a href="help.php">Help</a>
        </div>
    </div>
</div>
</body>
</html>
