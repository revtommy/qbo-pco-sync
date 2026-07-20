<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/SyncService.php';
require_once __DIR__ . '/../src/QboClient.php';
require_once __DIR__ . '/../src/SyncLogger.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Mailer.php';

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

// In-memory lock (legacy); DB-backed lock applied after DB connection is established.
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

$logger = null;
$logId  = null;

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

/**
 * Get a setting from sync_settings.
 */
function get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM sync_settings WHERE setting_key = :key ORDER BY id DESC LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['setting_value'])) {
        return (string)$row['setting_value'];
    }
    return null;
}

/**
 * Set a setting in sync_settings (one row per key).
 */
function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('DELETE FROM sync_settings WHERE setting_key = :key');
    $stmt->execute([':key' => $key]);

    $stmt = $pdo->prepare('INSERT INTO sync_settings (setting_key, setting_value) VALUES (:key, :value)');
    $stmt->execute([
        ':key'   => $key,
        ':value' => $value,
    ]);
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

/**
 * Build a deterministic fingerprint for a Stripe deposit so we can avoid
 * creating duplicates if the sync is re-run.
 */
function build_stripe_deposit_fingerprint(array $deposit, string $locKey): string
{
    $payloadForHash = [
        'locKey' => $locKey,
        'bank'   => $deposit['DepositToAccountRef']['value'] ?? null,
        'lines'  => $deposit['Line'] ?? [],
    ];

    return hash(
        'sha256',
        json_encode($payloadForHash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * Check whether we've already synced a Stripe deposit with this fingerprint.
 */
function has_synced_stripe_deposit(PDO $pdo, string $fingerprint): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM synced_deposits WHERE type = :type AND fingerprint = :fp LIMIT 1');
    $stmt->execute([
        ':type' => 'stripe',
        ':fp'   => $fingerprint,
    ]);

    return (bool)$stmt->fetchColumn();
}

/**
 * Record that we've successfully synced a Stripe deposit with this fingerprint.
 */
function mark_synced_stripe_deposit(PDO $pdo, string $fingerprint): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO synced_deposits (type, fingerprint, created_at)
         VALUES (:type, :fp, :created_at)'
    );

    $stmt->execute([
        ':type'       => 'stripe',
        ':fp'         => $fingerprint,
        ':created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
    ]);
}

function has_synced_stripe_refund(PDO $pdo, string $donationId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM synced_deposits WHERE type = :type AND fingerprint = :fp LIMIT 1');
    $stmt->execute([
        ':type' => 'stripe_refund',
        ':fp'   => $donationId,
    ]);
    return (bool)$stmt->fetchColumn();
}

function mark_synced_stripe_refund(PDO $pdo, string $donationId): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO synced_deposits (type, fingerprint, created_at)
         VALUES (:type, :fp, :created_at)'
    );

    $stmt->execute([
        ':type'       => 'stripe_refund',
        ':fp'         => $donationId,
        ':created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
    ]);
}

function finish_log(?SyncLogger $logger, ?int $logId, string $status, string $summary, ?string $details = null): void
{
    if ($logger === null || $logId === null) {
        return;
    }

    try {
        $logger->finish($logId, $status, $summary, $details);
    } catch (Throwable $e) {
        // Swallow logging errors to avoid interrupting the user flow.
    }
}

/**
 * Render shared layout with the polished UI.
 */
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
            .section-title {
                margin: 0;
                font-size: 1.1rem;
                letter-spacing: -0.01em;
            }
            .section-sub {
                margin: 0;
                color: var(--muted);
                font-size: 0.95rem;
            }
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
            .metric .label {
                color: var(--muted);
                font-size: 0.9rem;
                margin-bottom: 0.2rem;
            }
            .metric .value {
                font-size: 1.25rem;
                font-weight: 700;
            }
            .table-wrap {
                overflow: auto;
                border-radius: 10px;
                border: 1px solid var(--border);
                margin-top: 0.5rem;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                min-width: 860px;
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
            tr:hover td {
                background: rgba(46,168,255,0.03);
            }
            .muted { color: var(--muted); font-size: 0.95rem; line-height: 1.5; }
            .pill {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                padding: 0.35rem 0.65rem;
                border-radius: 999px;
                font-size: 0.85rem;
                font-weight: 700;
                color: #0f172a;
            }
            .pill.ok { background: rgba(57, 217, 138, 0.9); }
            .pill.warn { background: rgba(242, 201, 76, 0.9); }
            .pill.error { background: rgba(255, 122, 122, 0.9); }
            .actions {
                display: flex;
                justify-content: flex-start;
                gap: 0.75rem;
                flex-wrap: wrap;
                margin-top: 1rem;
            }
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
                .btn.primary { width: 100%; }
            }
        </style>
    </head>
    <body>
    <div class="page">
        <div class="hero">
            <div>
                <div class="eyebrow">Sync</div>
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

/**
 * Map raw payment method to the QBO PaymentMethod name.
 */
function map_payment_method_name(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $m = strtolower(trim($raw));
    if ($m === '') {
        return null;
    }
    if (in_array($m, ['card', 'credit_card', 'credit card'], true)) {
        return 'Credit Card';
    }
    if ($m === 'ach') {
        return 'ACH';
    }
    if ($m === 'eft') {
        return 'EFT';
    }
    if ($m === 'cash') {
        return 'cash';
    }
    if (in_array($m, ['check', 'cheque'], true)) {
        return 'check';
    }
    return null;
}

function get_display_timezone(PDO $pdo): DateTimeZone
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM sync_settings WHERE setting_key = 'display_timezone' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val) {
            return new DateTimeZone((string)$val);
        }
    } catch (Throwable $e) {
    }
    return new DateTimeZone('UTC');
}

function fmt_dt(DateTimeInterface $dt, DateTimeZone $tz): string
{
    return $dt->setTimezone($tz)->format('Y-m-d h:i A T');
}

// --- Bootstrap DB / clients --------------------------------------------------

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
    $syncedStripeDonations = get_synced_items($pdo, 'stripe_donation');
    $syncedStripeRefunds   = get_synced_items($pdo, 'stripe_refund');
    $lockOwner = acquire_db_lock($pdo, 'run_sync', 900);
    if ($lockOwner === null) {
        http_response_code(429);
        echo '<h1>Sync busy</h1><p>A sync is already running. Please try again in a few minutes.</p>';
        exit;
    }
    register_shutdown_function(static function () use ($pdo, $lockOwner) {
        try { release_db_lock($pdo, 'run_sync', $lockOwner); } catch (Throwable $e) { /* ignore */ }
    });
    $logger = new SyncLogger($pdo);
    $logId  = $logger->start('stripe');
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Sync error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

try {
    $pco = new PcoClient($config);
} catch (Throwable $e) {
    finish_log($logger, $logId, 'error', 'PCO client error during Stripe sync.', $e->getMessage());
    http_response_code(500);
    echo '<h1>PCO error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

try {
    $qbo = new QboClient($pdo, $config);
} catch (Throwable $e) {
    finish_log($logger, $logId, 'error', 'QBO client error during Stripe sync.', $e->getMessage());
    http_response_code(500);
    echo '<h1>QBO error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$service = new SyncService($pdo, $pco);
$displayTz = get_display_timezone($pdo);

// --- Determine sync window ---------------------------------------------------

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
$lastSyncStr  = get_setting($pdo, 'last_completed_at');
$backfillDays = isset($_GET['backfill_days']) ? max(1, min(365, (int)$_GET['backfill_days'])) : 7;
$resetWindow  = isset($_GET['reset_window']) && $_GET['reset_window'] === '1';
$defaultSince = $nowUtc->sub(new DateInterval('P' . $backfillDays . 'D'));
$cursorOverlap = new DateInterval('P14D');

if ($resetWindow) {
    $sinceUtc = $defaultSince;
} elseif ($lastSyncStr === null) {
    // A first run must include the event that triggered it. Per-donation
    // idempotency makes this bounded initial backfill safe.
    $sinceUtc = $defaultSince;
}

if (!isset($sinceUtc)) {
    try {
        $sinceUtc = new DateTimeImmutable($lastSyncStr);
    } catch (Throwable $e) {
        $sinceUtc = $defaultSince;
        finish_log(
            $logger,
            $logId,
            'partial',
            'Invalid Stripe cursor; using the default backfill window.',
            $e->getMessage()
        );
    }
}

// PCO often applies Stripe payout and fee updates days after completed_at.
// Re-read a safe overlap and let synced_items provide per-donation idempotency.
if (!$resetWindow && $lastSyncStr !== null) {
    $sinceUtc = $sinceUtc->sub($cursorOverlap);
}

if ($sinceUtc >= $nowUtc) {
    $sinceUtc = $defaultSince;
}

// --- Build preview of what we would deposit ---------------------------------

try {
    $preview = $service->buildDepositPreview($sinceUtc, $nowUtc, $syncedStripeDonations);
    $refundPreview = $service->buildRefundPreview($sinceUtc, $nowUtc, $syncedStripeRefunds);
} catch (Throwable $e) {
    finish_log(
        $logger,
        $logId,
        'error',
        'Error building Stripe sync preview.',
        $e->getMessage()
    );
    http_response_code(500);
    echo '<h1>Sync error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// If there are no funds to deposit, simply move the update cursor forward.
if (empty($preview['funds']) && empty($refundPreview['refunds'])) {
    if (!$dryRun) {
        set_setting($pdo, 'last_completed_at', $nowUtc->format(DateTimeInterface::ATOM));
    }
    finish_log(
        $logger,
        $logId,
        'success',
        $dryRun
            ? 'Dry run: no eligible Stripe donations or refunds; cursor unchanged.'
            : 'No eligible Stripe donations or refunds; window advanced.',
        null
    );

    ob_start();
    ?>
    <div class="flash success">
        <span class="tag">Info</span>
        <div>No eligible Stripe donations/refunds were found to sync in this window.</div>
    </div>
    <div class="card">
        <p class="muted">
            <?php if ($dryRun): ?>
                Dry run: the saved sync cursor remains unchanged.
            <?php else: ?>
                Last completed sync window is now set to
                <strong><?= htmlspecialchars($nowUtc->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?></strong>
                (based on updated_at, with a 14-day overlap on the next run).
            <?php endif; ?>
        </p>
    </div>
    <?php
    $content = ob_get_clean();
    renderLayout('PCO -> QBO Sync', 'PCO -> QBO Sync', 'No donations found', $content);
    exit;
}

// --- Look up QBO accounts we need --------------------------------------------

$errors          = [];
$createdDeposits = [];
$createdRefunds  = [];
$alreadySyncedDonations = 0;

$depositBankName      = $config['qbo']['stripe_deposit_bank'] ?? 'TRINITY 2000 CHECKING';
$weeklyIncomeName     = $config['qbo']['stripe_income_account'] ?? 'OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES';
$stripeFeeAccountName = $config['qbo']['stripe_fee_account'] ?? 'OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES';
$stripeRefundName     = get_setting($pdo, 'stripe_refund_account_name') ?? '';

try {
    $bankAccount = $qbo->getAccountByName($depositBankName, true);
    if (!$bankAccount) {
        $errors[] = "Could not find bank account in QBO: {$depositBankName}";
    }

    $incomeAccount = $qbo->getAccountByName($weeklyIncomeName, true);
    if (!$incomeAccount) {
        $errors[] = "Could not find income account in QBO: {$weeklyIncomeName}";
    }

    $feeAccount = $qbo->getAccountByName($stripeFeeAccountName, true);
    if (!$feeAccount) {
        $errors[] = "Could not find Stripe fee account in QBO: {$stripeFeeAccountName}";
    }

    $refundAccount = $stripeRefundName !== '' ? $qbo->getAccountByName($stripeRefundName, true) : null;
    if ($stripeRefundName !== '' && !$refundAccount) {
        $errors[] = "Could not find refund income account in QBO: {$stripeRefundName}";
    }
    $refundAccountFallback = $incomeAccount;
} catch (Throwable $e) {
    $errors[] = 'Error looking up QBO accounts: ' . $e->getMessage();
}

// Cache default income account; overrides can reuse the QBO client cache too.
$incomeAccountsByName = [];
if (!empty($incomeAccount)) {
    $incomeAccountsByName[$weeklyIncomeName] = $incomeAccount;
}

// --- Group by payout date and fund (one Deposit per payout/fund) ------------

$depositGroups = [];
$requiredGroupsByDonation = [];
$incompleteDonationSet = array_fill_keys($preview['incomplete_donation_ids'] ?? [], true);

foreach ($preview['funds'] as $row) {
    $locName    = trim((string)($row['qbo_location_name'] ?? ''));
    $locKey     = $locName !== '' ? $locName : '__NO_LOCATION__';
    $payoutDate = (string)($row['payout_date'] ?? $nowUtc->format('Y-m-d'));
    $fundId     = (string)($row['pco_fund_id'] ?? '');
    $groupKey   = $payoutDate . '|' . $fundId . '|' . $locKey;

    $depositGroups[$groupKey] = [
        'payout_date'  => $payoutDate,
        'fund_id'      => $fundId,
        'fund_name'    => (string)($row['pco_fund_name'] ?? ('Fund ' . $fundId)),
        'location_name'=> $locName,
        'funds'        => [$row],
        'total_gross'  => (float)$row['gross'],
        'total_fee'    => (float)$row['fee'],
        'total_net'    => (float)$row['net'],
        'donation_ids' => $row['donation_ids'] ?? [],
    ];

    foreach ($depositGroups[$groupKey]['donation_ids'] as $donationId) {
        $requiredGroupsByDonation[(string)$donationId][$groupKey] = true;
    }
}

// --- Build and send one Deposit per payout date and fund --------------------

$pmStats = ['lines' => 0, 'with_ref' => 0, 'multi' => 0];
$successfulGroupKeys = [];
$existingGroupKeys = [];
if (empty($errors)) {
    foreach ($depositGroups as $groupKey => $group) {
        $locName    = $group['location_name'];
        $fundName   = $group['fund_name'];
        $payoutDate = $group['payout_date'];

        $deptRef = null;
        if ($locName !== '') {
            try {
                $deptObj = $qbo->getDepartmentByName($locName);
                if (!$deptObj) {
                    $errors[] = "Could not find QBO Location (Department) for '{$locName}'.";
                    continue;
                }
                $deptRef = [
                    'value' => (string)$deptObj['Id'],
                    'name'  => (string)($deptObj['Name'] ?? $locName),
                ];
            } catch (Throwable $e) {
                $errors[] = 'Error looking up QBO Location (Department) for ' . $locName . ': ' . $e->getMessage();
                continue;
            }
        }

        $lines = [];

        foreach ($group['funds'] as $fundRow) {
            $fundName  = $fundRow['pco_fund_name'];
            $className = $fundRow['qbo_class_name'];
            $fundIncomeName = trim((string)($fundRow['qbo_income_account_name'] ?? ''));
            $methodTotals = $fundRow['method_totals'] ?? [];

            $classId = null;
            if ($className) {
                try {
                    $classObj = $qbo->getClassByName($className);
                    if (!$classObj) {
                        $errors[] = "Could not find QBO Class for fund '{$fundName}' (expected: '{$className}').";
                        continue 2;
                    }
                    $classId = (string)$classObj['Id'];
                } catch (Throwable $e) {
                    $errors[] = 'Error looking up QBO Class for fund ' . $fundName . ': ' . $e->getMessage();
                    continue 2;
                }
            }

            $incomeName = $fundIncomeName !== '' ? $fundIncomeName : $weeklyIncomeName;
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
                $errors[] = "Could not find income account for fund '{$fundName}' (expected: '{$incomeName}').";
                continue 2;
            }

            if (empty($methodTotals)) {
                $fallbackMethods = $fundRow['payment_methods'] ?? [];
                $fallbackMethod = count($fallbackMethods) === 1 ? (string)$fallbackMethods[0] : '(unspecified)';
                $methodTotals = [$fallbackMethod => [
                    'gross' => (float)$fundRow['gross'],
                    'fee'   => (float)$fundRow['fee'],
                ]];
            }

            foreach ($methodTotals as $pmRaw => $methodAmounts) {
                $gross = (float)($methodAmounts['gross'] ?? 0.0);
                $fee   = (float)($methodAmounts['fee'] ?? 0.0);
                if ($gross == 0.0) {
                    continue;
                }

                $line = [
                    'Amount'     => round($gross, 2),
                    'DetailType' => 'DepositLineDetail',
                    'DepositLineDetail' => [
                        'AccountRef' => [
                            'value' => (string)$fundIncomeAccount['Id'],
                            'name'  => $fundIncomeAccount['Name'] ?? $incomeName,
                        ],
                    ],
                    'Description' => $fundName . ' (' . $pmRaw . ') donations | Stripe payout ' . $payoutDate,
                ];
                $pmStats['lines']++;
                if ($pmRaw !== '(unspecified)') {
                    $pmName = map_payment_method_name((string)$pmRaw);
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
                if ($classId !== null) {
                    $line['DepositLineDetail']['ClassRef'] = [
                        'value' => $classId,
                        'name'  => $className,
                    ];
                }
                $lines[] = $line;

                if ($fee !== 0.0) {
                    $feeLine = [
                        // QBO Deposit fee lines must be negative
                        'Amount'     => round($fee * -1, 2),
                        'DetailType' => 'DepositLineDetail',
                        'DepositLineDetail' => [
                            'AccountRef' => [
                                'value' => (string)$feeAccount['Id'],
                                'name'  => $feeAccount['Name'] ?? $stripeFeeAccountName,
                            ],
                        ],
                        'Description' => $fundName . ' (' . $pmRaw . ') Stripe fees | Payout ' . $payoutDate,
                    ];
                    if ($classId !== null) {
                        $feeLine['DepositLineDetail']['ClassRef'] = [
                            'value' => $classId,
                            'name'  => $className,
                        ];
                    }
                    $lines[] = $feeLine;
                }
            }
        }

        if (empty($lines)) {
            continue;
        }

        $deposit = [
            'TxnDate' => $payoutDate,
            'PrivateNote' => 'PCO Stripe payout ' . $payoutDate .
                ' | Fund: ' . $fundName .
                ($locName ? (' | Location: ' . $locName) : ''),
            'DepositToAccountRef' => [
                'value' => (string)$bankAccount['Id'],
                'name'  => $bankAccount['Name'] ?? $depositBankName,
            ],
            'Line' => $lines,
        ];

        if ($deptRef !== null) {
            $deposit['DepartmentRef'] = $deptRef;
        }

    $fingerprint = build_stripe_deposit_fingerprint($deposit, (string)$groupKey);
    if (has_synced_stripe_deposit($pdo, $fingerprint)) {
        $successfulGroupKeys[$groupKey] = true;
        $existingGroupKeys[$groupKey] = true;
        $createdDeposits[] = [
            'payout_date'  => $payoutDate,
            'fund_name'    => $fundName,
            'location_name' => $locName,
            'total_gross'   => $group['total_gross'],
            'total_fee'     => $group['total_fee'],
            'total_net'     => $group['total_net'],
            'deposit'       => null,
            'skipped'       => true,
        ];
        continue;
    }

    if ($dryRun) {
        $createdDeposits[] = [
            'payout_date'  => $payoutDate,
            'fund_name'    => $fundName,
            'location_name' => $locName,
            'total_gross'   => $group['total_gross'],
            'total_fee'     => $group['total_fee'],
            'total_net'     => $group['total_net'],
            'deposit'       => ['DryRun' => true, 'Fingerprint' => $fingerprint, 'LineCount' => count($lines)],
            'skipped'       => false,
        ];
        continue;
    }

    try {
        $resp = $qbo->createDeposit($deposit);
        $dep  = $resp['Deposit'] ?? null;

        mark_synced_stripe_deposit($pdo, $fingerprint);
        $successfulGroupKeys[$groupKey] = true;

        $createdDeposits[] = [
            'payout_date'  => $payoutDate,
            'fund_name'    => $fundName,
            'location_name' => $locName,
            'total_gross'   => $group['total_gross'],
            'total_fee'     => $group['total_fee'],
            'total_net'     => $group['total_net'],
            'deposit'       => $dep,
            'skipped'       => false,
        ];
    } catch (Throwable $e) {
        $errors[] = 'Error creating QBO Deposit for payout ' . $payoutDate .
            ', fund ' . $fundName . ', location ' . ($locName ?: '(no location)') . ': ' . $e->getMessage();
    }
    }
}

// A donation may contain designations for more than one fund. Mark the source
// donation complete only after every required payout/fund deposit succeeded (or
// was already present), so a partial QBO failure cannot lose another fund.
if (!$dryRun) {
    foreach ($requiredGroupsByDonation as $donationId => $requiredGroupKeys) {
        if (!isset($incompleteDonationSet[$donationId]) && empty(array_diff_key($requiredGroupKeys, $successfulGroupKeys))) {
            mark_synced_item($pdo, 'stripe_donation', (string)$donationId);
            if (empty(array_diff_key($requiredGroupKeys, $existingGroupKeys))) {
                $alreadySyncedDonations++;
            }
        }
    }
}

if (!$dryRun && empty($errors) && (!empty($createdDeposits) || !empty($createdRefunds))) {
    set_setting($pdo, 'last_completed_at', $preview['until']->format(DateTimeInterface::ATOM));
}

// --- Process refunds as Expenses --------------------------------------------

if (empty($errors) && !empty($refundPreview['refunds'])) {
    foreach ($refundPreview['refunds'] as $refund) {
        $donationId = $refund['donation_id'] ?? '';
        if ($donationId === '') {
            continue;
        }
        if (has_synced_stripe_refund($pdo, $donationId)) {
            mark_synced_item($pdo, 'stripe_refund', (string)$donationId);
            continue;
        }

        $lines = [];
        foreach ($refund['lines'] as $line) {
            $lineDetail = [
                'Amount' => round((float)$line['amount'], 2),
                'DetailType' => 'AccountBasedExpenseLineDetail',
                'AccountBasedExpenseLineDetail' => [
                    'AccountRef' => [
                        'value' => (string)($refundAccount['Id'] ?? $refundAccountFallback['Id']),
                        'name'  => ($refundAccount['Name'] ?? $stripeRefundName) ?: ($refundAccountFallback['Name'] ?? $weeklyIncomeName),
                    ],
                ],
                'Description' => 'Refund for fund ' . ($line['fund_name'] ?? $line['fund_id']),
            ];

            if (!empty($line['qbo_class_name'])) {
                try {
                    $classObj = $qbo->getClassByName($line['qbo_class_name']);
                    if ($classObj) {
                        $lineDetail['AccountBasedExpenseLineDetail']['ClassRef'] = [
                            'value' => (string)$classObj['Id'],
                            'name'  => $classObj['Name'] ?? $line['qbo_class_name'],
                        ];
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Refund class lookup failed for donation ' . $donationId . ': ' . $e->getMessage();
                    continue 2;
                }
            }

            if (!empty($line['qbo_location_name'])) {
                try {
                    $deptObj = $qbo->getDepartmentByName($line['qbo_location_name']);
                    if ($deptObj) {
                        $lineDetail['AccountBasedExpenseLineDetail']['DepartmentRef'] = [
                            'value' => (string)$deptObj['Id'],
                            'name'  => $deptObj['Name'] ?? $line['qbo_location_name'],
                        ];
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Refund location lookup failed for donation ' . $donationId . ': ' . $e->getMessage();
                    continue 2;
                }
            }

            $lines[] = $lineDetail;
        }

        if (empty($lines)) {
            continue;
        }

        $purchase = [
            'TxnDate' => $nowUtc->format('Y-m-d'),
            'PaymentType' => 'Cash',
            'AccountRef' => [
                'value' => (string)$bankAccount['Id'],
                'name'  => $bankAccount['Name'] ?? $depositBankName,
            ],
            'PrivateNote' => 'Refund for donation ' . $donationId . ' (updated_at ' . ($refund['updated_at']->format('Y-m-d H:i:s') ?? '') . ')',
            'Line' => $lines,
        ];

        try {
            $resp = $qbo->createPurchase($purchase);
            $createdRefunds[] = $resp['Purchase'] ?? $resp;
            mark_synced_stripe_refund($pdo, $donationId);
            mark_synced_item($pdo, 'stripe_refund', (string)$donationId);
        } catch (Throwable $e) {
            $errors[] = 'Error creating refund expense for donation ' . $donationId . ': ' . $e->getMessage();
        }
    }
}

// --- Render result -----------------------------------------------------------

ob_start();

if (!empty($errors)): ?>
    <div class="flash error">
        <span class="tag">Issues</span>
        <div>
            <strong>Sync completed with errors.</strong>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($createdDeposits)): ?>
                <p class="muted">
                    Some deposits may have been created in QuickBooks before the errors occurred.
                    Review QBO and adjust the <code>last_completed_at</code> setting if you need to rerun this window.
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="flash success">
        <span class="tag">Success</span>
        <div>
            <?php if (!empty($createdDeposits)): ?>
                <strong><?= count($createdDeposits) ?> deposit(s) created or already present in QuickBooks.</strong>
            <?php else: ?>
                <strong>No deposits were created.</strong>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

    <div class="card">
        <div class="section-header">
            <div>
                <p class="section-title">Window used</p>
                <p class="section-sub">updated_at range (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>; normal runs include a 14-day overlap)</p>
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
                <div class="label">From</div>
                <div class="value"><?= htmlspecialchars(fmt_dt($preview['since'], $displayTz), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="metric">
                <div class="label">To</div>
                <div class="value"><?= htmlspecialchars(fmt_dt($preview['until'], $displayTz), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="metric">
                <div class="label">Fund rows processed</div>
                <div class="value"><?= count($preview['funds']) ?></div>
            </div>
    </div>
</div>

<?php if (!empty($createdDeposits)): ?>
    <div class="card">
        <div class="section-header">
            <div>
                <p class="section-title">Deposits by payout date and fund</p>
                <p class="section-sub">Each transaction contains only one fund type.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Payout Date</th>
                    <th>Fund</th>
                    <th>Location</th>
                    <th>QBO Deposit Id</th>
                    <th>TxnDate</th>
                    <th>QBO Total</th>
                    <th>Gross</th>
                    <th>Fees</th>
                    <th>Net</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($createdDeposits as $cd): ?>
                    <?php
                    $dep     = $cd['deposit'] ?? [];
                    $skipped = $cd['skipped'] ?? false;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($cd['payout_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($cd['fund_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($cd['location_name'] ?: '(no location)', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($skipped): ?>
                                <span class="muted">skipped (already synced)</span>
                            <?php else: ?>
                                <?= htmlspecialchars((string)($dep['Id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)($dep['TxnDate'] ?? ($cd['payout_date'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $skipped ? '' : ('$' . htmlspecialchars((string)($dep['TotalAmt'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></td>
                        <td>$<?= number_format($cd['total_gross'], 2) ?></td>
                        <td>$<?= number_format($cd['total_fee'], 2) ?></td>
                        <td>$<?= number_format($cd['total_net'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="actions">
    <a class="btn secondary" href="run-sync-preview.php">View sync preview</a>
    <a class="btn secondary" href="settings.php">Adjust settings</a>
</div>

<div class="footer">
    &copy; <?= date('Y') ?> Rev. Tommy Sheppard ? <a href="help.php">Help</a>
</div>

<?php
$content = ob_get_clean();

$status = empty($errors) ? 'success' : (!empty($createdDeposits) ? 'partial' : 'error');
$summary = empty($errors)
    ? (empty($createdDeposits)
        ? 'Stripe sync: no deposits created; window advanced.'
        : 'Stripe sync: ' . count($createdDeposits) . ' deposit(s) created or already present.')
    : 'Stripe sync completed with errors.';
$detailsLines = [];
if ($dryRun) {
    $detailsLines[] = 'Dry run mode: no QBO writes performed.';
}
if ($alreadySyncedDonations > 0) {
    $detailsLines[] = 'Skipped already-synced donations: ' . $alreadySyncedDonations;
}
$details = empty($errors) ? null : implode("\n", $errors);
if (!empty($detailsLines)) {
    $details = ($details ? $details . "\n" : '') . implode("\n", $detailsLines);
}
$summaryData = [
    'ts'        => $nowUtc->format(DateTimeInterface::ATOM),
    'status'    => $status,
    'deposits'  => count($createdDeposits),
    'refunds'   => count($createdRefunds),
    'already_synced_donations' => $alreadySyncedDonations,
    'dry_run'   => $dryRun,
    'window'    => [
        'since' => $preview['since']->format(DateTimeInterface::ATOM),
        'until' => $preview['until']->format(DateTimeInterface::ATOM),
    ],
    'payment_method_lines_total' => $pmStats['lines'],
    'payment_method_lines_with_ref' => $pmStats['with_ref'],
    'payment_method_multi_method'   => $pmStats['multi'],
    'errors'    => $errors,
];
set_setting($pdo, 'last_stripe_sync_summary', json_encode($summaryData));
finish_log($logger, $logId, $status, $summary, $details);

// Email alert on error/partial
$notificationEmail = get_setting($pdo, 'notification_email');
if ($notificationEmail && in_array($status, ['error', 'partial'], true)) {
    $from   = $config['mail']['from'] ?? null;
    $mailer = new Mailer($from, $config['mail'] ?? []);
    $subject = '[PCO->QBO] Stripe sync ' . strtoupper($status);
    $bodyLines = [
        'Stripe sync run on ' . $nowUtc->format('Y-m-d H:i:s T'),
        'Status: ' . $status,
        'Deposits: ' . count($createdDeposits),
        'Window: ' . $preview['since']->format('Y-m-d H:i:s') . ' to ' . $preview['until']->format('Y-m-d H:i:s'),
    ];
    if (!empty($errors)) {
        $bodyLines[] = '';
        $bodyLines[] = 'Errors:';
        $bodyLines = array_merge($bodyLines, $errors);
    }
    $mailer->send($notificationEmail, $subject, implode("\n", $bodyLines));
}

renderLayout('PCO -> QBO Stripe Sync Result', 'PCO -> QBO Stripe Sync Result', 'Manual run of Stripe donation deposits', $content);
