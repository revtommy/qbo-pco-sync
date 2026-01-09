<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::requireLogin();

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

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
            'designations' => $designations,
        ];
    }

    return $donations;
}

// ---------------------------------------------------------------------------
// Bootstrap DB
// ---------------------------------------------------------------------------

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
    $displayTz = get_display_timezone($pdo);
    $syncedBatchDonations = get_synced_items($pdo, 'batch_donation');
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// ---------------------------------------------------------------------------
// Input window
// ---------------------------------------------------------------------------

$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
if ($days < 1) {
    $days = 1;
}

$nowUtc   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$sinceUtc = $nowUtc->sub(new DateInterval('P' . $days . 'D'));

// ---------------------------------------------------------------------------
// Fetch PCO data
// ---------------------------------------------------------------------------

$pcoConfig = $config['pco'] ?? [];
if (empty($pcoConfig['app_id']) || empty($pcoConfig['secret'])) {
    http_response_code(500);
    $content = '<div class="flash error"><span class="tag">Issue</span><div>PCO credentials are not configured. Please set pco.app_id and pco.secret in config.php.</div></div>';
    renderLayout('PCO Batch Preview', 'PCO Batch Preview', 'Inspect committed batches before syncing to QuickBooks', $content);
    exit;
}

try {
    $batches = get_committed_batches($pcoConfig, $sinceUtc, $nowUtc);
} catch (Throwable $e) {
    http_response_code(500);
    $content = '<div class="flash error"><span class="tag">Issue</span><div>Error loading batches: ' .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div></div>';
    renderLayout('PCO Batch Preview', 'PCO Batch Preview', 'Inspect committed batches before syncing to QuickBooks', $content);
    exit;
}

// ---------------------------------------------------------------------------
// Load mappings
// ---------------------------------------------------------------------------

$fundMappings = [];
try {
    $stmt = $pdo->query('SELECT * FROM fund_mappings');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fid = (string)($row['pco_fund_id'] ?? '');
        if ($fid === '') {
            continue;
        }
        $fundMappings[$fid] = $row;
    }
} catch (Throwable $e) {
    // ignore mapping load errors; will show empty mapping info
}

// ---------------------------------------------------------------------------
// Aggregate donations by fund
// ---------------------------------------------------------------------------

$fundTotals           = [];
$unmappedFunds        = [];
$donationsEvaluated   = 0;
$batchesEvaluated     = count($batches);
$grossTotal           = 0.0;

foreach ($batches as $batch) {
    $batchId = $batch['id'];
    try {
        $donations = get_batch_donations($pcoConfig, $batchId);
    } catch (Throwable $e) {
        $unmappedFunds[] = ['fund_id' => 'batch:' . $batchId, 'reason' => 'Error loading donations: ' . $e->getMessage()];
        continue;
    }

    foreach ($donations as $donation) {
        $donationsEvaluated++;
        $donationId = (string)($donation['id'] ?? '');
        if ($donationId !== '' && in_array($donationId, $syncedBatchDonations, true)) {
            continue;
        }
        foreach ($donation['designations'] as $des) {
            $fundId = (string)($des['fund_id'] ?? '');
            $amount = ((int)($des['amount_cents'] ?? 0)) / 100.0;
            if ($fundId === '' || $amount === 0.0) {
                continue;
            }

            $mapping   = $fundMappings[$fundId] ?? [];
            $fundName   = $mapping['pco_fund_name'] ?? ('Fund ' . $fundId);
            $className  = $mapping['qbo_class_name'] ?? '';
            $locName    = $mapping['qbo_location_name'] ?? '';
            $incomeName = $mapping['qbo_income_account_name'] ?? '';

            if (!isset($fundTotals[$fundId])) {
                $fundTotals[$fundId] = [
                    'pco_fund_id'      => $fundId,
                    'pco_fund_name'    => $fundName,
                    'qbo_class_name'   => $className,
                    'qbo_location_name'=> $locName,
                    'qbo_income_account_name' => $incomeName,
                    'gross'            => 0.0,
                    'batch_ids'        => [],
                ];
            }

            $fundTotals[$fundId]['gross'] += $amount;
            $grossTotal                   += $amount;
            if (!in_array($batchId, $fundTotals[$fundId]['batch_ids'], true)) {
                $fundTotals[$fundId]['batch_ids'][] = $batchId;
            }

            if (empty($mapping)) {
                $unmappedFunds[$fundId] = [
                    'fund_id'   => $fundId,
                    'fund_name' => $fundName,
                ];
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

ob_start();
?>

<div class="card" style="margin-top: 1.1rem;">
    <div class="section-header">
        <div>
            <p class="section-title">Window and filters</p>
            <p class="section-sub">Committed batches within the selected range; cash and check donations only.</p>
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
            <div class="value"><?= htmlspecialchars(fmt_dt($sinceUtc, $displayTz), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="metric">
            <div class="label">Window end (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>)</div>
            <div class="value"><?= htmlspecialchars(fmt_dt($nowUtc, $displayTz), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="metric">
            <div class="label">Batches evaluated</div>
            <div class="value"><?= (int)$batchesEvaluated ?></div>
        </div>
        <div class="metric">
            <div class="label">Donations evaluated</div>
            <div class="value"><?= (int)$donationsEvaluated ?></div>
        </div>
        <div class="metric">
            <div class="label">Total gross</div>
            <div class="value">$<?= number_format($grossTotal, 2) ?></div>
        </div>
    </div>
</div>

<?php if (empty($fundTotals)): ?>
    <div class="card">
        <p class="muted">No committed batch donations found in this window.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="section-header">
            <div>
                <p class="section-title">Per-fund breakdown</p>
                <p class="section-sub">Totals that would be booked into QuickBooks deposits.</p>
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
                    <th>Batch ID(s)</th>
                    <th>Gross</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($fundTotals as $row): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($row['pco_fund_name'], ENT_QUOTES, 'UTF-8') ?><br>
                            <span class="small">Fund ID: <?= htmlspecialchars((string)$row['pco_fund_id'], ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td><?= htmlspecialchars((string)$row['qbo_class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['qbo_location_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($row['qbo_income_account_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(implode(', ', $row['batch_ids'] ?? []), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>$<?= number_format($row['gross'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th colspan="5">Totals</th>
                    <th>$<?= number_format($grossTotal, 2) ?></th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($unmappedFunds)): ?>
    <div class="card">
        <div class="section-header">
            <div>
                <p class="section-title">Unmapped funds</p>
                <p class="section-sub">Add fund mappings before syncing.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Fund ID</th>
                    <th>Name</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($unmappedFunds as $uf): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$uf['fund_id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($uf['fund_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<p class="muted footnote">
    This preview totals committed batch donations (cash/check) by fund, using your current Class, Location, and Income Account mappings from the database.
    Use it to reconcile before running the batch sync to QuickBooks.
</p>
<div class="footer">
    &copy; <?= date('Y') ?> Rev. Tommy Sheppard ? <a href="help.php">Help</a>
</div>

<?php
$content = ob_get_clean();

renderLayout('PCO Batch Preview', 'PCO Batch Preview', 'Inspect committed batches before syncing to QuickBooks', $content);
