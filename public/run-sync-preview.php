<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/SyncService.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();

function get_synced_items(PDO $pdo, string $type): array {
    $stmt = $pdo->prepare('SELECT item_id FROM synced_items WHERE item_type = :t');
    $stmt->execute([':t' => $type]);
    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ids[] = (string)$row['item_id'];
    }
    return $ids;
}

function get_display_timezone(PDO $pdo): DateTimeZone {
    $tz = null;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM sync_settings WHERE setting_key = 'display_timezone' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val) { $tz = new DateTimeZone((string)$val); }
    } catch (Throwable $e) {
        $tz = null;
    }
    return $tz ?? new DateTimeZone('UTC');
}

function fmt(DateTimeInterface $dt, DateTimeZone $tz): string {
    return $dt->setTimezone($tz)->format('Y-m-d h:i A T');
}

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

try {
    $pco = new PcoClient($config);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>PCO client error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$service = new SyncService($pdo, $pco);
$displayTz = get_display_timezone($pdo);

// days back to look based on completed_at
$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
if ($days < 1) {
    $days = 1;
}

$nowUtc   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$sinceUtc = $nowUtc->sub(new DateInterval('P' . $days . 'D'));

$syncedDonations = get_synced_items($pdo, 'stripe_donation');
$syncedRefunds   = get_synced_items($pdo, 'stripe_refund');
$preview         = $service->buildDepositPreview($sinceUtc, $nowUtc, $syncedDonations);
$refundPreview   = $service->buildRefundPreview($sinceUtc, $nowUtc, $syncedRefunds);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO &harr; QBO Sync Preview</title>
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
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.2rem 1.25rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18);
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
        .filters {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .filters label {
            font-weight: 700;
        }
        .filters input[type="number"] {
            width: 80px;
            padding: 0.55rem 0.6rem;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font-size: 1rem;
        }
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 0.75rem;
            margin-top: 0.6rem;
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
            margin-top: 0.75rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
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
            text-align: left;
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
        .small {
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.5;
        }
        .muted {
            color: var(--muted);
            font-size: 0.95rem;
        }
        .footnote {
            margin-top: 1.2rem;
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
            .filters { width: 100%; justify-content: space-between; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <div>
            <div class="eyebrow">Preview</div>
            <h1>PCO to QuickBooks sync preview</h1>
            <p class="lede">Inspect online donations by completed_at window before pushing to QuickBooks deposits.</p>
        </div>
        <a class="btn secondary" href="index.php">&larr; Back to dashboard</a>
    </div>

    <div class="card" style="margin-top: 1.1rem;">
        <div class="section-header">
            <div>
                <p class="section-title">Window and filters</p>
                <p class="section-sub">payment_status = succeeded, completed_at within the selected range.</p>
            </div>
            <form method="get" class="filters">
                <label for="days">Days back</label>
                <input type="number" min="1" id="days" name="days" value="<?= htmlspecialchars((string)$days, ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn secondary" type="submit">Refresh</button>
            </form>
        </div>

        <div class="metrics-grid">
            <div class="metric">
                <div class="label">Window start (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>)</div>
                <div class="value"><?= htmlspecialchars(fmt($preview['since'], $displayTz), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="metric">
                <div class="label">Window end (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>)</div>
                <div class="value"><?= htmlspecialchars(fmt($preview['until'], $displayTz), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="metric">
                <div class="label">Donations evaluated</div>
                <div class="value"><?= (int)$preview['donation_count'] ?></div>
            </div>
            <div class="metric">
                <div class="label">Included in totals</div>
                <div class="value"><?= (int)$preview['processed_donations'] ?></div>
            </div>
            <div class="metric">
                <div class="label">Offline donations skipped</div>
                <div class="value"><?= (int)$preview['skipped_offline'] ?></div>
            </div>
            <div class="metric">
                <div class="label">Net (expected payout)</div>
                <div class="value">$<?= number_format($preview['total_net'], 2) ?></div>
            </div>
            <div class="metric">
                <div class="label">Pending refunds (count)</div>
                <div class="value"><?= count($refundPreview['refunds']) ?></div>
            </div>
            <div class="metric">
                <div class="label">Pending refunds (total)</div>
                <div class="value">$<?= number_format($refundPreview['total_refund'], 2) ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="section-header">
            <div>
                <p class="section-title">Totals</p>
                <p class="section-sub">Aggregated across all funds in this window.</p>
            </div>
        </div>
        <div class="metrics-grid">
            <div class="metric">
                <div class="label">Total gross</div>
                <div class="value">$<?= number_format($preview['total_gross'], 2) ?></div>
            </div>
            <div class="metric">
                <div class="label">Total Stripe fees</div>
                <div class="value">$<?= number_format($preview['total_fee'], 2) ?></div>
            </div>
            <div class="metric">
                <div class="label">Total net</div>
                <div class="value">$<?= number_format($preview['total_net'], 2) ?></div>
            </div>
        </div>
    </div>

    <?php if (empty($preview['funds'])): ?>
        <div class="card">
            <p class="muted">No eligible Stripe donations found in this window.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="section-header">
                <div>
                    <p class="section-title">Per-fund breakdown</p>
                    <p class="section-sub">Future QuickBooks deposit lines per fund.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>PCO Fund</th>
                        <th>QBO Class</th>
                        <th>QBO Location</th>
                        <th>QBO Income Account</th>
                        <th>Gross</th>
                        <th>Stripe Fee</th>
                        <th>Net</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview['funds'] as $row): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($row['pco_fund_name'], ENT_QUOTES, 'UTF-8') ?><br>
                                <span class="small">Fund ID: <?= htmlspecialchars((string)$row['pco_fund_id'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td><?= htmlspecialchars((string)$row['qbo_class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$row['qbo_location_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($row['qbo_income_account_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>$<?= number_format($row['gross'], 2) ?></td>
                            <td>$<?= number_format($row['fee'], 2) ?></td>
                            <td>$<?= number_format($row['net'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="4">Totals</th>
                        <th>$<?= number_format($preview['total_gross'], 2) ?></th>
                        <th>$<?= number_format($preview['total_fee'], 2) ?></th>
                        <th>$<?= number_format($preview['total_net'], 2) ?></th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($preview['skipped_unmapped'])): ?>
        <div class="card">
            <div class="section-header">
                <div>
                    <p class="section-title">Skipped donation pieces</p>
                    <p class="section-sub">Unmapped funds or missing designations.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Donation ID</th>
                        <th>Reason</th>
                        <th>Fund ID</th>
                        <th>Amount (cents)</th>
                        <th>Payment Method</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview['skipped_unmapped'] as $skip): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($skip['donation_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($skip['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($skip['fund_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($skip['amount_cents'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($skip['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($refundPreview['refunds'])): ?>
        <div class="card">
            <div class="section-header">
                <div>
                    <p class="section-title">Pending refunds</p>
                    <p class="section-sub">Refunds in this window that would post as expenses in QBO.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Donation ID</th>
                        <th>Updated at</th>
                        <th>Payment method</th>
                        <th>Refund total</th>
                        <th>Lines</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($refundPreview['refunds'] as $row): ?>
                        <?php
                            $lineSum = 0.0;
                            foreach ($row['lines'] as $ln) { $lineSum += (float)$ln['amount']; }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$row['donation_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(fmt($row['updated_at'], $displayTz), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($row['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>$<?= number_format($lineSum, 2) ?></td>
                            <td>
                                <?php foreach ($row['lines'] as $ln): ?>
                                    <div class="small">
                                        <?= htmlspecialchars($ln['fund_name'] ?? ('Fund ' . ($ln['fund_id'] ?? '')), ENT_QUOTES, 'UTF-8') ?> |
                                        Class: <?= htmlspecialchars((string)($ln['qbo_class_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> |
                                        Location: <?= htmlspecialchars((string)($ln['qbo_location_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> |
                                        $<?= number_format((float)$ln['amount'], 2) ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="3">Totals</th>
                        <th>$<?= number_format($refundPreview['total_refund'], 2) ?></th>
                        <th></th>
                    </tr>
                    </tfoot>
                </table>
            </div>
            <p class="muted">Refunds already synced are excluded. Total pending refunds: <?= count($refundPreview['refunds']) ?>.</p>
            <p class="muted">Already processed refunds (all time): <?= count($syncedRefunds) ?>.</p>
        </div>
    <?php endif; ?>

    <p class="muted footnote">
        Once this preview matches your Stripe payout report for the same period, these per-fund gross and fee totals will flow into a QuickBooks Online deposit:
        one income line and one fee line per fund into your configured bank account, using the mapped Class, Location, and Income Account.
    </p>
    <div class="footer">
        &copy; <?= date('Y') ?> Rev. Tommy Sheppard ? <a href="help.php">Help</a>
    </div>
</div>
</body>
</html>
