<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/QboClient.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/SyncLogger.php';
require_once __DIR__ . '/../src/Mailer.php';
require_once __DIR__ . '/../src/PcoClient.php';

function get_synced_items(PDO $pdo, string $type): array
{
    $stmt = $pdo->prepare('SELECT item_id FROM synced_items WHERE item_type = :t');
    $stmt->execute([':t' => $type]);
    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ids[] = (string)$row['item_id'];
    }
    return $ids;
}

function mark_synced_item(PDO $pdo, string $type, string $itemId, array $meta = []): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO synced_items (item_type, item_id, meta, created_at) VALUES (:t, :id, :meta, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE meta = VALUES(meta)'
    );
    $stmt->execute([
        ':t'   => $type,
        ':id'  => $itemId,
        ':meta'=> empty($meta) ? null : json_encode($meta),
    ]);
}

$webhookSecretValid = false;
$incomingSecret     = $_GET['webhook_secret'] ?? null;
if ($incomingSecret !== null && !empty($config['webhook_secrets']) && is_array($config['webhook_secrets'])) {
    foreach ($config['webhook_secrets'] as $s) {
        if (!empty($s) && hash_equals((string)$s, (string)$incomingSecret)) {
            $webhookSecretValid = true;
            break;
        }
    }
}

if (!$webhookSecretValid) {
    Auth::requireLogin();
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function acquire_lock(string $name, int $ttlSeconds = 900): bool
{
    $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qbo_pco_' . $name . '.lock';
    if (file_exists($lockFile)) {
        $age = time() - (int)filemtime($lockFile);
        if ($age < $ttlSeconds) {
            return false;
        }
    }
    @touch($lockFile);
    register_shutdown_function(static function () use ($lockFile) {
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    });
    return true;
}

function acquire_db_lock(PDO $pdo, string $name, int $ttlSeconds = 900): ?string
{
    $owner = uniqid($name . '_', true);
    $key   = 'lock_' . $name;

    $stmt = $pdo->prepare('INSERT IGNORE INTO sync_settings (setting_key, setting_value) VALUES (:key, \'\')');
    $stmt->execute([':key' => $key]);

    $stmt = $pdo->prepare(
        'UPDATE sync_settings
            SET setting_value = :owner_set, updated_at = NOW()
          WHERE setting_key = :key
            AND (TIMESTAMPDIFF(SECOND, updated_at, NOW()) > :ttl OR setting_value = :empty OR setting_value = :owner_match)'
    );
    $stmt->execute([
        ':owner_set'   => $owner,
        ':key'         => $key,
        ':ttl'         => $ttlSeconds,
        ':empty'       => '',
        ':owner_match' => $owner,
    ]);

    if ($stmt->rowCount() === 1) {
        return $owner;
    }
    return null;
}

function release_db_lock(PDO $pdo, string $name, string $owner): void
{
    $key = 'lock_' . $name;
    $stmt = $pdo->prepare(
        'UPDATE sync_settings SET setting_value = \'\', updated_at = NOW()
         WHERE setting_key = :key AND setting_value = :owner'
    );
    $stmt->execute([':key' => $key, ':owner' => $owner]);
}

function map_payment_method_name(?string $raw): ?string
{
    if ($raw === null) { return null; }
    $m = strtolower(trim($raw));
    if ($m === '') { return null; }
    if (in_array($m, ['card', 'credit_card', 'credit card'], true)) { return 'Credit Card'; }
    if ($m === 'ach') { return 'ACH'; }
    if ($m === 'eft') { return 'EFT'; }
    if ($m === 'cash') { return 'cash'; }
    if (in_array($m, ['check', 'cheque'], true)) { return 'check'; }
    return null;
}

function renderLayout(string $title, string $heroTitle, string $lede, string $content): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
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
                max-width: 1200px;
                margin: 0 auto;
                padding: 2.4rem 1.25rem 3rem;
            }
            .hero {
                background: var(--panel);
                border: 1px solid var(--border);
                border-radius: 18px;
                padding: 1.4rem 1.5rem;
                box-shadow: 0 10px 40px rgba(0,0,0,0.25);
                position: relative;
                overflow: hidden;
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 1rem;
                flex-wrap: wrap;
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
                margin-bottom: 0.25rem;
            }
            h1 {
                margin: 0 0 0.35rem;
                font-size: 2rem;
                letter-spacing: -0.01em;
            }
            .lede {
                color: var(--muted);
                margin: 0;
                max-width: 64ch;
                line-height: 1.6;
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
                cursor: pointer;
                border: none;
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
            .flash {
                margin-top: 1rem;
                padding: 0.85rem 1rem;
                border-radius: 10px;
                border: 1px solid var(--border);
                display: grid;
                grid-template-columns: auto 1fr;
                gap: 0.75rem;
                align-items: center;
            }
            .flash.success { background: rgba(57, 217, 138, 0.12); border-color: rgba(57, 217, 138, 0.35); }
            .flash.error { background: rgba(255, 122, 122, 0.12); border-color: rgba(255, 122, 122, 0.35); }
            .flash .tag {
                background: rgba(255, 255, 255, 0.06);
                padding: 0.35rem 0.65rem;
                border-radius: 999px;
                font-size: 0.85rem;
                border: 1px solid var(--border);
            }
            .card {
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 14px;
                padding: 1.2rem 1.25rem;
                box-shadow: 0 8px 30px rgba(0,0,0,0.18);
                margin-top: 1rem;
            }
            .section-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                flex-wrap: wrap;
                margin-bottom: 0.85rem;
            }
            .section-title { margin: 0; font-size: 1.1rem; letter-spacing: -0.01em; }
            .section-sub { margin: 0; color: var(--muted); font-size: 0.95rem; }
            .metrics-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 0.75rem;
            }
            .metric {
                padding: 0.95rem 1rem;
                border-radius: 12px;
                border: 1px solid var(--border);
                background: rgba(255,255,255,0.02);
            }
            .metric .label { color: var(--muted); font-size: 0.9rem; margin-bottom: 0.2rem; }
            .metric .value { font-size: 1.25rem; font-weight: 700; }
            .table-wrap {
                overflow: auto;
                border-radius: 10px;
                border: 1px solid var(--border);
                margin-top: 0.5rem;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                min-width: 820px;
                background: rgba(255,255,255,0.01);
            }
            th, td {
                border-bottom: 1px solid rgba(255,255,255,0.08);
                padding: 0.65rem 0.75rem;
                vertical-align: top;
                font-size: 0.95rem;
                text-align: left;
            }
            th {
                color: var(--muted);
                font-weight: 700;
                background: rgba(255,255,255,0.03);
                position: sticky;
                top: 0;
                backdrop-filter: blur(8px);
                z-index: 1;
            }
            tr:hover td { background: rgba(46,168,255,0.03); }
            .muted { color: var(--muted); font-size: 0.95rem; line-height: 1.5; }
            .actions { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem; }
            .footer {
                margin-top: 1.4rem;
                text-align: center;
                color: var(--muted);
                font-size: 0.9rem;
            }
            .footer a { color: var(--accent); text-decoration: none; }
            .footer a:hover { color: #dff1ff; }
            @media (max-width: 720px) {
                .hero { padding: 1.2rem 1.1rem; }
                .section-header { align-items: flex-start; }
                .btn.secondary { width: 100%; justify-content: center; }
            }
        </style>
    </head>
    <body>
    <div class="page">
        <div class="hero">
            <div>
                <div class="eyebrow">Batch Sync</div>
                <h1><?= htmlspecialchars($heroTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="lede"><?= htmlspecialchars($lede, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a class="btn secondary" href="index.php">&larr; Back to dashboard</a>
        </div>

        <?= $content ?>
    </div>
    </body>
    </html>
    <?php
}

function get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare(
        'SELECT setting_value FROM sync_settings WHERE setting_key = :key ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['setting_value'])) {
        return (string)$row['setting_value'];
    }
    return null;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('DELETE FROM sync_settings WHERE setting_key = :key');
    $stmt->execute([':key' => $key]);

    $stmt = $pdo->prepare(
        'INSERT INTO sync_settings (setting_key, setting_value) VALUES (:key, :value)'
    );
    $stmt->execute([
        ':key'   => $key,
        ':value' => $value,
    ]);
}

function get_display_timezone(PDO $pdo): DateTimeZone
{
    $tz = null;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM sync_settings WHERE setting_key = 'display_timezone' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val) {
            $tz = new DateTimeZone((string)$val);
        }
    } catch (Throwable $e) {
        $tz = null;
    }

    return $tz ?? new DateTimeZone('UTC');
}

function fmt_dt(DateTimeInterface $dt, DateTimeZone $tz): string
{
    return $dt->setTimezone($tz)->format('Y-m-d h:i A T');
}

function has_synced_batch(PDO $pdo, string $batchId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM synced_batches WHERE batch_id = :id LIMIT 1');
    $stmt->execute([':id' => $batchId]);
    return (bool)$stmt->fetchColumn();
}

function mark_synced_batch(PDO $pdo, string $batchId): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO synced_batches (batch_id, created_at) VALUES (:id, :created_at)'
    );
    $stmt->execute([
        ':id'         => $batchId,
        ':created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
    ]);
}

function send_sync_email_if_needed(
    PDO $pdo,
    array $config,
    string $syncLabel,
    string $status,
    string $summary,
    ?string $details
): void {
    $notificationEmail = get_setting($pdo, 'notification_email');
    if (!$notificationEmail || !in_array($status, ['error', 'partial'], true)) {
        return;
    }

    $from   = $config['mail']['from'] ?? null;
    $mailer = new Mailer($from, $config['mail'] ?? []);

    $nowUtc  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $subject = "[PCO->QBO] {$syncLabel} sync " . strtoupper($status);
    $bodyLines = [
        "{$syncLabel} sync run on " . $nowUtc->format('Y-m-d H:i:s T'),
        '',
        'Status: ' . $status,
        $summary,
    ];

    if (!empty($details)) {
        $bodyLines[] = '';
        $bodyLines[] = 'Details:';
        $bodyLines[] = $details;
    }

    $mailer->send($notificationEmail, $subject, implode("\n", $bodyLines));
}

function pco_request(array $pcoConfig, string $path, array $query = []): array
{
    $baseUrl = rtrim($pcoConfig['base_url'] ?? 'https://api.planningcenteronline.com', '/');
    $attempts = 0;
    $maxTries = 3;
    $baseDelay = 1.0;
    $lastErr = null;

    while ($attempts < $maxTries) {
        $attempts++;

        $url = str_starts_with($path, 'http') ? $path : $baseUrl . $path;
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $pcoConfig['app_id'] . ':' . $pcoConfig['secret'],
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $lastErr = 'Error calling PCO: ' . $err;
        } else {
            $shouldRetry = ($status === 429 || ($status >= 500 && $status < 600));
            if ($status >= 200 && $status < 300) {
                $decoded = json_decode($body, true);
                if (!is_array($decoded)) {
                    $lastErr = 'PCO JSON decode error: ' . json_last_error_msg() . ' | Raw body: ' . $body;
                } else {
                    return $decoded;
                }
            } elseif ($shouldRetry) {
                $lastErr = 'PCO HTTP error ' . $status . ': ' . $body;
            } else {
                throw new RuntimeException('PCO HTTP error ' . $status . ': ' . $body);
            }
        }

        if ($attempts < $maxTries) {
            $sleep = $baseDelay * (2 ** ($attempts - 1)) + mt_rand(0, 300) / 1000;
            usleep((int)round($sleep * 1_000_000));
            continue;
        }
    }

    throw new RuntimeException($lastErr ?? 'Unknown PCO error');
}

function pco_paginated_data(array $pcoConfig, string $path, array $query = []): array
{
    $data = [];
    $nextPath   = $path;
    $nextParams = $query;

    while ($nextPath !== null) {
        $resp = pco_request($pcoConfig, $nextPath, $nextParams);
        if (isset($resp['data']) && is_array($resp['data'])) {
            $data = array_merge($data, $resp['data']);
        }
        $links = $resp['links'] ?? [];
        if (!empty($links['next'])) {
            $nextPath   = $links['next'];
            $nextParams = [];
        } else {
            $nextPath = null;
        }
    }

    return $data;
}

function get_committed_batches(
    array $pcoConfig,
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd
): array {
    $batchData = pco_paginated_data(
        $pcoConfig,
        '/giving/v2/batches',
        [
            'per_page' => 100,
            'order'    => 'committed_at',
        ]
    );

    $batches = [];

    foreach ($batchData as $batch) {
        $attrs        = $batch['attributes'] ?? [];
        $committedStr = $attrs['committed_at'] ?? null;
        if (!$committedStr) {
            continue;
        }

        try {
            $committedAt = new DateTimeImmutable($committedStr);
        } catch (Throwable $e) {
            continue;
        }

        if ($committedAt < $windowStart || $committedAt > $windowEnd) {
            continue;
        }

        $name = $attrs['name'] ?? ($attrs['description'] ?? '');
        $batches[] = [
            'id'           => (string)$batch['id'],
            'name'         => (string)$name,
            'committed_at' => $committedAt,
        ];
    }

    return $batches;
}

function get_batch_donations(array $pcoConfig, string $batchId): array
{
    $donData = pco_paginated_data(
        $pcoConfig,
        '/giving/v2/batches/' . rawurlencode($batchId) . '/donations',
        [
            'per_page' => 100,
        ]
    );

    $donations = [];

    foreach ($donData as $don) {
        $id    = (string)($don['id'] ?? '');
        $attrs = $don['attributes'] ?? [];

        if ($id === '') {
            continue;
        }

        $paymentMethod = (string)($attrs['payment_method'] ?? '');
        if (!in_array($paymentMethod, ['cash', 'check'], true)) {
            continue;
        }

        $designations = [];
        $desRespData = pco_paginated_data(
            $pcoConfig,
            '/giving/v2/donations/' . rawurlencode($id) . '/designations',
            [
                'per_page' => 100,
            ]
        );
        foreach ($desRespData as $des) {
            $dAttrs = $des['attributes'] ?? [];
            $rels   = $des['relationships'] ?? [];
            $fund   = $rels['fund']['data'] ?? null;

            if (!$fund) {
                continue;
            }

            $fundId = (string)($fund['id'] ?? '');
            if ($fundId === '') {
                continue;
            }

            $designations[] = [
                'fund_id'      => $fundId,
                'amount_cents' => (int)($dAttrs['amount_cents'] ?? 0),
            ];
        }

        $donations[] = [
            'id'           => $id,
            'received_at'  => $attrs['received_at'] ?? null,
            'amount_cents' => (int)($attrs['amount_cents'] ?? 0),
            'payment_method'=> $paymentMethod,
            'designations' => $designations,
        ];
    }

    return $donations;
}

// ---------------------------------------------------------------------------
// Bootstrap DB / clients / logger
// ---------------------------------------------------------------------------

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
    $syncedBatchDonations = get_synced_items($pdo, 'batch_donation');
    $lockOwner = acquire_db_lock($pdo, 'batch_sync', 900);
    if ($lockOwner === null) {
        http_response_code(429);
        echo '<h1>Batch sync busy</h1><p>Another batch sync is running. Please retry in a few minutes.</p>';
        exit;
    }
    register_shutdown_function(static function () use ($pdo, $lockOwner) {
        try { release_db_lock($pdo, 'batch_sync', $lockOwner); } catch (Throwable $e) { /* ignore */ }
    });
    $displayTz = get_display_timezone($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    $content = '<div class="flash error"><span class="tag">Issue</span><div>Database error: ' .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div></div>';
    renderLayout('PCO -> QBO Batch Sync', 'PCO -> QBO Batch Sync', 'Run committed batches to QuickBooks', $content);
    exit;
}

$logger = new SyncLogger($pdo);
$logId  = $logger->start('batch');

$pcoConfig = $config['pco'] ?? [];
if (empty($pcoConfig['app_id']) || empty($pcoConfig['secret'])) {
    $logger->finish(
        $logId,
        'error',
        'PCO credentials not configured (batch sync).',
        'Missing pco.app_id or pco.secret in config.php.'
    );
    http_response_code(500);
    $content = '<div class="flash error"><span class="tag">Issue</span><div>PCO credentials are not configured. Please set pco.app_id and pco.secret in config.php.</div></div>';
    renderLayout('PCO -> QBO Batch Sync', 'PCO -> QBO Batch Sync', 'Missing credentials', $content);
    exit;
}

$errors          = [];
$createdDeposits = [];
$totalDonationsSeen = 0;
$totalDonationsProcessed = 0;
$skipStats = [
    'batches_total'           => 0,
    'batches_skipped_already' => 0,
    'donations_already_synced'=> 0,
    'deposits_skipped_no_lines'=> 0,
];

try {
    $qbo = new QboClient($pdo, $config);
} catch (Throwable $e) {
    $errors[] = 'Error creating QBO client: ' . $e->getMessage();
    $logger->finish(
        $logId,
        'error',
        'Failed to create QBO client (batch sync).',
        $errors[0]
    );
    http_response_code(500);
    $content = '<div class="flash error"><span class="tag">Issue</span><div>' .
        htmlspecialchars($errors[0], ENT_QUOTES, 'UTF-8') . '</div></div>';
    renderLayout('PCO -> QBO Batch Sync', 'PCO -> QBO Batch Sync', 'Cannot start batch sync', $content);
    exit;
}

// ---------------------------------------------------------------------------
// Check settings to see if batch sync is enabled
// ---------------------------------------------------------------------------

$enableBatchSync = get_setting($pdo, 'enable_batch_sync') ?? '0';
if ($enableBatchSync !== '1') {
    $logger->finish(
        $logId,
        'success',
        'Batch sync run requested but is disabled in settings.',
        null
    );
    ob_start();
    ?>
    <div class="flash error">
        <span class="tag">Disabled</span>
        <div>Batch sync is currently disabled in Settings.</div>
    </div>
    <div class="card">
        <p class="muted">Enable "Sync committed batches" in the Settings page to use this feature.</p>
        <div class="actions">
            <a class="btn secondary" href="settings.php">Go to Settings</a>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    renderLayout('PCO -> QBO Batch Sync', 'PCO -> QBO Batch Sync', 'Run committed batches to QuickBooks', $content);
    exit;
}

// ---------------------------------------------------------------------------
// Determine sync window (based on batch committed_at)
// ---------------------------------------------------------------------------

$nowUtc       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$lastBatchStr = get_setting($pdo, 'last_batch_sync_completed_at');
$backfillDays = isset($_GET['backfill_days']) ? max(1, min(90, (int)$_GET['backfill_days'])) : 7;
$resetWindow  = isset($_GET['reset_window']) && $_GET['reset_window'] === '1';
$defaultSince = $nowUtc->sub(new DateInterval('P' . $backfillDays . 'D'));

if ($resetWindow) {
    $sinceUtc = $defaultSince;
} elseif ($lastBatchStr) {
    try {
        $sinceUtc = new DateTimeImmutable($lastBatchStr);
    } catch (Throwable $e) {
        $sinceUtc = $defaultSince;
    }
} else {
    // No prior window; set and inform user
    set_setting($pdo, 'last_batch_sync_completed_at', $nowUtc->format(DateTimeInterface::ATOM));

    $summary = 'Initial batch sync run: window initialized, no batches processed.';
    $logger->finish($logId, 'success', $summary, null);

    ob_start();
    ?>
    <div class="flash success">
        <span class="tag">Initialized</span>
        <div>This is the first time batch sync has been run. Window has been set.</div>
    </div>
    <div class="card">
        <p class="muted">
            Start point:
            <strong><?= htmlspecialchars(fmt_dt($nowUtc, $displayTz), ENT_QUOTES, 'UTF-8') ?></strong>
            (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>).
            No historical batches were synced. Next run will pick up batches committed after this time.
        </p>
        <p class="muted">Need to sweep past days? <a href="?reset_window=1&backfill_days=7">Re-run last 7 days</a></p>
    </div>
    <?php
    $content = ob_get_clean();
    renderLayout('PCO -> QBO Batch Sync', 'PCO -> QBO Batch Sync', 'First run initialization', $content);
    exit;
}

// Guard against future/too-tight window
if ($sinceUtc >= $nowUtc) {
    $sinceUtc = $defaultSince;
}

// ---------------------------------------------------------------------------
// Look up QBO accounts and fund mappings
// ---------------------------------------------------------------------------

$depositBankName  = get_setting($pdo, 'deposit_bank_account_name') ?? 'TRINITY 2000 CHECKING';
$incomeAccountName = get_setting($pdo, 'income_account_name') ?? 'OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES';

try {
    $bankAccount = $qbo->getAccountByName($depositBankName, false);
    if (!$bankAccount) {
        $errors[] = "Could not find deposit bank account in QBO: {$depositBankName}";
    }

    $incomeAccount = $qbo->getAccountByName($incomeAccountName, true);
    if (!$incomeAccount) {
        $errors[] = "Could not find income account in QBO: {$incomeAccountName}";
    }
} catch (Throwable $e) {
    $errors[] = 'Error looking up QBO accounts: ' . $e->getMessage();
}

$incomeAccountsByName = [];
if (!empty($incomeAccount)) {
    $incomeAccountsByName[$incomeAccountName] = $incomeAccount;
}

$fundMappings = [];
try {
    $stmt = $pdo->query('SELECT * FROM fund_mappings');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fundId = (string)($row['pco_fund_id'] ?? '');
        if ($fundId === '') {
            continue;
        }
        $fundMappings[$fundId] = $row;
    }
} catch (Throwable $e) {
    $errors[] = 'Error loading fund mappings: ' . $e->getMessage();
}

if (!empty($errors) && (empty($bankAccount) || empty($incomeAccount))) {
    $details = implode("\n", $errors);
    $logger->finish($logId, 'error', 'Batch sync configuration error.', $details);

    ob_start();
    ?>
    <div class="flash error">
        <span class="tag">Issue</span>
        <div>
            <strong>Batch sync could not start due to configuration errors:</strong>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    renderLayout('PCO -> QBO Batch Sync', 'PCO -> QBO Batch Sync', 'Configuration errors', $content);
    exit;
}

// ---------------------------------------------------------------------------
// Fetch committed batches and build QBO deposits
// ---------------------------------------------------------------------------

$windowEnd = $nowUtc;

try {
    $batches = get_committed_batches($pcoConfig, $sinceUtc, $windowEnd);
} catch (Throwable $e) {
    $errors[] = 'Error fetching committed batches from PCO: ' . $e->getMessage();
    $batches  = [];
}

$batchSummaries = [];
$skipStats['batches_total'] = is_countable($batches) ? count($batches) : 0;

foreach ($batches as $batchInfo) {
    $batchId     = $batchInfo['id'];
    $batchName   = $batchInfo['name'];
    $committedAt = $batchInfo['committed_at'];

    $locationGroups = [];

    try {
        $donations = get_batch_donations($pcoConfig, $batchId);
    } catch (Throwable $e) {
        $errors[] = 'Error fetching donations for batch ' . $batchId . ': ' . $e->getMessage();
        continue;
    }

    $batchAlreadySynced = false;
    try {
        $batchAlreadySynced = has_synced_batch($pdo, $batchId);
    } catch (Throwable $e) {
        $errors[] = 'Error checking sync state for batch ' . $batchId . ': ' . $e->getMessage();
    }

    if ($batchAlreadySynced) {
        $skipStats['batches_skipped_already']++;
        $skipStats['donations_already_synced'] += is_countable($donations) ? count($donations) : 0;
        foreach ($donations as $donation) {
            $donationId = (string)($donation['id'] ?? '');
            if ($donationId === '') {
                continue;
            }
            try {
                mark_synced_item($pdo, 'batch_donation', $donationId, ['batch_id' => $batchId]);
            } catch (Throwable $e) {
                $errors[] = 'Error marking batch donation ' . $donationId . ' synced: ' . $e->getMessage();
            }
        }
        $batchSummaries[] = [
            'batch_id'  => $batchId,
            'committed' => $committedAt instanceof DateTimeInterface ? $committedAt->format(DateTimeInterface::ATOM) : null,
            'status'    => 'skipped',
            'reason'    => 'already synced',
            'deposits'  => [],
        ];
        continue;
    }

    foreach ($donations as $donation) {
        $totalDonationsSeen++;
        $donationId = (string)($donation['id'] ?? '');
        if ($donationId !== '' && in_array($donationId, $syncedBatchDonations ?? [], true)) {
            $skipStats['donations_already_synced']++;
            continue;
        }
        $totalDonationsProcessed++;
        $designations    = $donation['designations'] ?? [];
        $paymentMethod   = $donation['payment_method'] ?? '';
        $locKeysUsed     = [];

        foreach ($designations as $des) {
            $fundId      = (string)($des['fund_id'] ?? '');
            $amountCents = (int)($des['amount_cents'] ?? 0);

            if ($fundId === '' || $amountCents === 0) {
                continue;
            }

            $amount = $amountCents / 100.0;

            $mapping    = $fundMappings[$fundId] ?? [];
            $locName    = trim((string)($mapping['qbo_location_name'] ?? ''));
            $className  = trim((string)($mapping['qbo_class_name'] ?? ''));
            $incomeName = trim((string)($mapping['qbo_income_account_name'] ?? ''));

            $fundName = (string)(
                $mapping['pco_fund_name']
                ?? $mapping['fund_name']
                ?? ('Fund ' . $fundId)
            );

            $locKey = $locName !== '' ? $locName : '__NO_LOCATION__';
            $locKeysUsed[$locKey] = true;

            if (!isset($locationGroups[$locKey])) {
                $locationGroups[$locKey] = [
                    'batch_id'      => $batchId,
                    'batch_name'    => $batchName,
                    'committed_at'  => $committedAt,
                    'location_name' => $locName,
                    'funds'         => [],
                    'total_gross'   => 0.0,
                    'donation_ids'  => [],
                ];
            }

            if (!isset($locationGroups[$locKey]['funds'][$fundId])) {
                $locationGroups[$locKey]['funds'][$fundId] = [
                    'pco_fund_id'    => $fundId,
                    'pco_fund_name'  => $fundName,
                    'qbo_class_name' => $className,
                    'qbo_income_account_name' => $incomeName,
                    'gross'          => 0.0,
                    'method_totals'  => [],
                ];
            }

            $locationGroups[$locKey]['funds'][$fundId]['gross'] += $amount;
            $locationGroups[$locKey]['total_gross']             += $amount;
            $pmKey = $paymentMethod !== '' ? $paymentMethod : '(unspecified)';
            if (!isset($locationGroups[$locKey]['funds'][$fundId]['method_totals'][$pmKey])) {
                $locationGroups[$locKey]['funds'][$fundId]['method_totals'][$pmKey] = 0.0;
            }
            $locationGroups[$locKey]['funds'][$fundId]['method_totals'][$pmKey] += $amount;
        }

        if ($donationId !== '' && !empty($locKeysUsed)) {
            foreach (array_keys($locKeysUsed) as $lk) {
                if (!in_array($donationId, $locationGroups[$lk]['donation_ids'], true)) {
                    $locationGroups[$lk]['donation_ids'][] = $donationId;
                }
            }
        }
    }

    $batchHadErrors = false;
    $batchDeposits  = [];
    $pmStats = ['lines' => 0, 'with_ref' => 0, 'multi' => 0];

    foreach ($locationGroups as $locKey => $group) {
        $locName      = $group['location_name'];
        $batchName    = $group['batch_name'];
        $committedAt  = $group['committed_at'];
        $funds        = $group['funds'];

        if (empty($funds)) {
            continue;
        }

        $deptRef = null;
        if ($locName !== '') {
            try {
                $deptObj = $qbo->getDepartmentByName($locName);
                if (!$deptObj) {
                    $errors[] = "Could not find QBO Location (Department) for '{$locName}' (batch {$batchId}).";
                } else {
                    $deptRef = [
                        'value' => (string)$deptObj['Id'],
                    ];
                }
            } catch (Throwable $e) {
                $errors[] = 'Error looking up QBO Location (Department) "' . $locName .
                    '" for batch ' . $batchId . ': ' . $e->getMessage();
            }
        }

        $lines = [];

        foreach ($funds as $fundRow) {
            $fundName  = $fundRow['pco_fund_name'];
            $className = $fundRow['qbo_class_name'];
            $fundIncomeName = trim((string)($fundRow['qbo_income_account_name'] ?? ''));
            $methodTotals = $fundRow['method_totals'] ?? [];

            $classId = null;
            if ($className !== '') {
                try {
                    $classObj = $qbo->getClassByName($className);
                    if (!$classObj) {
                        $errors[] = "Could not find QBO Class for fund '{$fundName}' (batch {$batchId}): {$className}";
                    } else {
                        $classId = $classObj['Id'] ?? null;
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Error looking up QBO Class for fund ' . $fundName .
                        ' (batch ' . $batchId . '): ' . $e->getMessage();
                }
            }

            // Emit one line per payment method with true totals.
            if (empty($methodTotals)) {
                $methodTotals = ['(unspecified)' => (float)$fundRow['gross']];
            }

            foreach ($methodTotals as $pmRaw => $gross) {
                if ($gross == 0.0) {
                    continue;
                }

                $incomeName = $fundIncomeName !== '' ? $fundIncomeName : $incomeAccountName;
                $fundIncomeAccount = $incomeAccountsByName[$incomeName] ?? null;
                if ($fundIncomeAccount === null) {
                    try {
                        $fundIncomeAccount = $qbo->getAccountByName($incomeName, true);
                    } catch (Throwable $e) {
                        $fundIncomeAccount = null;
                    }
                    $incomeAccountsByName[$incomeName] = $fundIncomeAccount;
                }
                if (!$fundIncomeAccount) {
                    $errors[] = "Could not find income account for fund '{$fundName}' (batch {$batchId}): {$incomeName}";
                    $batchHadErrors = true;
                    continue 3;
                }

                $line = [
                    'Amount'     => $gross,
                    'DetailType' => 'DepositLineDetail',
                    'DepositLineDetail' => [
                        'AccountRef' => [
                            'value' => (string)$fundIncomeAccount['Id'],
                            'name'  => $fundIncomeAccount['Name'] ?? $incomeName,
                        ],
                    ],
                    'Description' => $fundName . ' (' . ($pmRaw !== '' ? $pmRaw : 'unspecified') . ') donations (Batch ' . $batchId . ')',
                ];

                $pmStats['lines']++;
                if ($pmRaw !== '(unspecified)') {
                    $pmName = map_payment_method_name($pmRaw);
                    if ($pmName) {
                        $pmObj = $qbo->getPaymentMethodByName($pmName);
                        if ($pmObj) {
                            $line['DepositLineDetail']['PaymentMethodRef'] = [
                                'value' => (string)$pmObj['Id'],
                                'name'  => $pmObj['Name'] ?? $pmName,
                            ];
                            $pmStats['with_ref']++;
                        }
                    }
                } else {
                    $pmStats['multi']++;
                }

                if ($classId) {
                    $line['DepositLineDetail']['ClassRef'] = [
                        'value' => (string)$classId,
                    ];
                }

                $lines[] = $line;
            }
        }

        if (empty($lines)) {
            $skipStats['deposits_skipped_no_lines']++;
            $errors[] = "No lines built for batch {$batchId} / Location '" . ($locName ?: '(no location)') . "', skipping deposit.";
            continue;
        }

        $memoParts = [
            'PCO Batch ' . $batchId,
        ];
        if ($batchName !== '') {
            $memoParts[] = $batchName;
        }
        $memoParts[] = 'Committed at ' . $committedAt->format('Y-m-d H:i:s');
        if ($locName !== '') {
            $memoParts[] = 'Location: ' . $locName;
        }

        $deposit = [
            'TxnDate' => $committedAt->format('Y-m-d'),
            'PrivateNote' => implode(' | ', $memoParts),
            'DepositToAccountRef' => [
                'value' => (string)$bankAccount['Id'],
                'name'  => $bankAccount['Name'] ?? $depositBankName,
            ],
            'Line' => $lines,
        ];

        if ($deptRef !== null) {
            $deposit['DepartmentRef'] = $deptRef;
        }

        try {
            $resp = $qbo->createDeposit($deposit);
            $dep  = $resp['Deposit'] ?? $resp;
            $createdDeposits[] = [
                'batch_id'      => $batchId,
                'batch_name'    => $batchName,
                'location_name' => $locName,
                'total_gross'   => $group['total_gross'],
                'deposit'       => $dep,
            ];

            $batchDeposits[] = [
                'location_name' => $locName,
                'total_gross'   => $group['total_gross'],
                'deposit'       => $dep,
            ];

            $donationIdsForGroup = $group['donation_ids'] ?? [];
            if (!empty($donationIdsForGroup)) {
                foreach ($donationIdsForGroup as $did) {
                    try {
                        mark_synced_item($pdo, 'batch_donation', (string)$did, ['batch_id' => $batchId]);
                    } catch (Throwable $e) {
                        $errors[] = 'Error marking batch donation ' . $did . ' synced: ' . $e->getMessage();
                    }
                }
            }
        } catch (Throwable $e) {
            $batchHadErrors = true;
            $errors[] = 'Error creating QBO Deposit for batch ' . $batchId .
                ' / Location ' . ($locName ?: '(no location)') . ': ' . $e->getMessage();
        }
    }

    if (!$batchHadErrors && !empty($batchDeposits)) {
        try {
            mark_synced_batch($pdo, $batchId);
        } catch (Throwable $e) {
            $errors[] = 'Error marking batch ' . $batchId . ' as synced: ' . $e->getMessage();
            $batchHadErrors = true;
        }
    }

    $batchSummaries[] = [
        'batch_id'  => $batchId,
        'committed' => $committedAt instanceof DateTimeInterface ? $committedAt->format(DateTimeInterface::ATOM) : null,
        'status'    => $batchHadErrors ? 'partial-error' : (!empty($batchDeposits) ? 'synced' : 'no-deposits'),
        'deposits'  => $batchDeposits,
    ];
}

set_setting($pdo, 'last_batch_sync_completed_at', $windowEnd->format(DateTimeInterface::ATOM));

if (empty($errors)) {
    $status = 'success';
} elseif (!empty($createdDeposits)) {
    $status = 'partial';
} else {
    $status = 'error';
}

$summary = sprintf(
    'Batch: processed %d donations; created %d deposits.',
    (int)$totalDonationsProcessed,
    count($createdDeposits)
);
$details = empty($errors) ? null : implode("\n", $errors);
$summaryData = [
    'ts'        => $nowUtc->format(DateTimeInterface::ATOM),
    'status'    => $status,
    'deposits'  => count($createdDeposits),
    'window'    => [
        'since' => $sinceUtc->format(DateTimeInterface::ATOM),
        'until' => $windowEnd->format(DateTimeInterface::ATOM),
    ],
    'totals' => [
        'batches_seen'       => $skipStats['batches_total'],
        'donations_seen'     => $totalDonationsSeen,
        'donations_processed'=> $totalDonationsProcessed,
    ],
    'skipped' => [
        'batches_already_synced'   => $skipStats['batches_skipped_already'],
        'donations_already_synced' => $skipStats['donations_already_synced'],
        'deposits_skipped_no_lines'=> $skipStats['deposits_skipped_no_lines'],
    ],
    'payment_method_lines_total' => $pmStats['lines'],
    'payment_method_lines_with_ref' => $pmStats['with_ref'],
    'payment_method_multi_method'   => $pmStats['multi'],
    'errors'    => $errors,
];
set_setting($pdo, 'last_batch_sync_summary', json_encode($summaryData));

$logger->finish($logId, $status, $summary, $details);

$notificationEmail = get_setting($pdo, 'notification_email');
if ($notificationEmail && in_array($status, ['error', 'partial'], true)) {
    $from   = $config['mail']['from'] ?? null;
    $mailer = new Mailer($from, $config['mail'] ?? []);

    $subject = '[PCO->QBO] Batch sync ' . strtoupper($status);
    $bodyLines = [
        'Batch sync run on ' . $nowUtc->format('Y-m-d H:i:s T'),
        '',
        'Status: ' . $status,
        $summary,
    ];
    if (!empty($details)) {
        $bodyLines[] = '';
        $bodyLines[] = 'Details:';
        $bodyLines[] = $details;
    }

    $mailer->send($notificationEmail, $subject, implode("\n", $bodyLines));
}

// ---------------------------------------------------------------------------
// Render result
// ---------------------------------------------------------------------------

ob_start();
?>

<?php if (!empty($errors)): ?>
    <div class="flash error">
        <span class="tag">Issues</span>
        <div>
            <strong>Sync completed with errors.</strong>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php else: ?>
    <div class="flash success">
        <span class="tag">Success</span>
        <div>Batch sync completed without errors.</div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="section-header">
        <div>
            <p class="section-title">Summary</p>
            <p class="section-sub">Run overview</p>
        </div>
        <div class="muted">
            Quick window reset:
            <a style="color: #fff;" href="?reset_window=1&backfill_days=1">24h</a> |
            <a style="color: #fff;" href="?reset_window=1&backfill_days=7">7d</a> |
            <a style="color: #fff;" href="?reset_window=1&backfill_days=30">30d</a>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric">
            <div class="label">Donations processed (cash/check)</div>
            <div class="value"><?= (int)$totalDonationsProcessed ?></div>
        </div>
        <div class="metric">
            <div class="label">Deposits created</div>
            <div class="value"><?= count($createdDeposits) ?></div>
        </div>
        <div class="metric">
            <div class="label">Batches skipped (already synced)</div>
            <div class="value"><?= (int)$skipStats['batches_skipped_already'] ?></div>
        </div>
        <div class="metric">
            <div class="label">Donations skipped (already synced)</div>
            <div class="value"><?= (int)$skipStats['donations_already_synced'] ?></div>
        </div>
        <div class="metric">
            <div class="label">Donations seen in window</div>
            <div class="value"><?= (int)$totalDonationsSeen ?></div>
        </div>
        <div class="metric">
            <div class="label">Window start (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>)</div>
            <div class="value"><?= htmlspecialchars(fmt_dt($sinceUtc, $displayTz), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="metric">
            <div class="label">Window end (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>)</div>
            <div class="value"><?= htmlspecialchars(fmt_dt($windowEnd, $displayTz), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>
    <?php if ($skipStats['deposits_skipped_no_lines'] > 0): ?>
        <p class="muted" style="margin-top:0.6rem;">
            Deposits skipped because no lines were built: <?= (int)$skipStats['deposits_skipped_no_lines'] ?>.
        </p>
    <?php endif; ?>
</div>

<div class="card">
    <div class="section-header">
        <div>
            <p class="section-title">Deposits created</p>
            <p class="section-sub">One per batch/location group</p>
        </div>
    </div>

    <?php if (empty($createdDeposits)): ?>
        <p class="muted">No committed batches in this window produced deposits.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Batch ID</th>
                    <th>Batch Name</th>
                    <th>Location</th>
                    <th>QBO Deposit (Id/DocNumber)</th>
                    <th>Total Gross</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($createdDeposits as $cd): ?>
                    <?php $dep = $cd['deposit'] ?? []; ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$cd['batch_id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$cd['batch_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($cd['location_name'] ?: '(no location)', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($dep['DocNumber'] ?? $dep['Id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>$<?= number_format((float)$cd['total_gross'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="section-header">
        <div>
            <p class="section-title">Window used</p>
            <p class="section-sub">Based on Batch.committed_at</p>
        </div>
    </div>
    <p class="muted">
        From <strong><?= htmlspecialchars(fmt_dt($sinceUtc, $displayTz), ENT_QUOTES, 'UTF-8') ?></strong>
        to <strong><?= htmlspecialchars(fmt_dt($windowEnd, $displayTz), ENT_QUOTES, 'UTF-8') ?></strong>
        (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>).
    </p>
</div>

<div class="actions">
    <a class="btn secondary" href="run-sync-preview.php">View online sync preview</a>
    <a class="btn secondary" href="settings.php">Adjust settings</a>
</div>

<div class="footer">
    &copy; <?= date('Y') ?> Rev. Tommy Sheppard ? <a href="help.php">Help</a>
</div>

<?php
$content = ob_get_clean();

renderLayout('PCO -> QBO Batch Sync', 'PCO -> QBO Batch Sync', 'Run committed batches to QuickBooks', $content);
