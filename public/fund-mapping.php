<?php
declare(strict_types=1);

// Load config and common classes
$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();

// ---- DB connection via Db helper ----
try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// ---- PCO client ----
try {
    $pcoClient = new PcoClient($config);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>PCO client error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$flashMessage = null;
$errorMessage = null;

// ---- Handle POST (save mappings) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['fund']) || !is_array($_POST['fund'])) {
            throw new RuntimeException('No fund data submitted.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO fund_mappings (pco_fund_id, pco_fund_name, qbo_class_name, qbo_location_name, qbo_income_account_name)
             VALUES (:pco_fund_id, :pco_fund_name, :qbo_class_name, :qbo_location_name, :qbo_income_account_name)
             ON DUPLICATE KEY UPDATE
               pco_fund_name = VALUES(pco_fund_name),
               qbo_class_name = VALUES(qbo_class_name),
               qbo_location_name = VALUES(qbo_location_name),
               qbo_income_account_name = VALUES(qbo_income_account_name),
               updated_at = CURRENT_TIMESTAMP"
        );

        foreach ($_POST['fund'] as $fundId => $row) {
            $pcoFundId   = (string)($row['pco_fund_id'] ?? '');
            $pcoFundName = trim((string)($row['pco_fund_name'] ?? ''));
            $className   = trim((string)($row['qbo_class_name'] ?? ''));
            $locName     = trim((string)($row['qbo_location_name'] ?? ''));
            $incomeName  = trim((string)($row['qbo_income_account_name'] ?? ''));

            if ($pcoFundId === '' || $pcoFundName === '') {
                continue;
            }

            $stmt->execute([
                ':pco_fund_id'            => $pcoFundId,
                ':pco_fund_name'          => $pcoFundName,
                ':qbo_class_name'         => $className !== '' ? $className : null,
                ':qbo_location_name'      => $locName !== '' ? $locName : null,
                ':qbo_income_account_name'=> $incomeName !== '' ? $incomeName : null,
            ]);
        }

        $flashMessage = 'Mappings saved.';
    } catch (Throwable $e) {
        $errorMessage = 'Error saving mappings: ' . $e->getMessage();
    }
}

// ---- Load current PCO funds ----
try {
    $funds = $pcoClient->listFunds();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Error loading PCO funds</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// ---- Load existing mappings from DB ----
$existingMappings = [];
try {
    $rows = $pdo->query("SELECT * FROM fund_mappings")->fetchAll();
    foreach ($rows as $row) {
        $existingMappings[$row['pco_fund_id']] = $row;
    }
} catch (Throwable $e) {
    $errorMessage = 'Error loading existing mappings: ' . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fund &rarr; QBO Mapping</title>
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
        .form-stack {
            margin-top: 1.5rem;
            display: grid;
            gap: 1rem;
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
        .table-wrap {
            overflow: auto;
            border-radius: 10px;
            border: 1px solid var(--border);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
            background: rgba(255,255,255,0.01);
        }
        th, td {
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 0.65rem 0.75rem;
            vertical-align: top;
            font-size: 0.95rem;
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
        input[type="text"] {
            width: 100%;
            padding: 0.6rem 0.65rem;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font-size: 0.95rem;
        }
        input[type="text"]:focus {
            outline: 2px solid rgba(46,168,255,0.4);
            border-color: rgba(46,168,255,0.45);
        }
        .small {
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.5;
        }
        .actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 0.9rem;
        }
        button[type="submit"] {
            border: none;
            cursor: pointer;
            font-size: 1rem;
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
            .actions { justify-content: stretch; }
            .actions .btn { width: 100%; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <div>
            <div class="eyebrow">Mappings</div>
            <h1>Fund to QBO mapping</h1>
            <p class="lede">Assign QuickBooks Class, Location, and Income Account names for each Planning Center fund before syncing deposits.</p>
        </div>
        <a class="btn secondary" href="index.php">&larr; Back to dashboard</a>
    </div>

    <?php if ($flashMessage): ?>
        <div class="flash success">
            <span class="tag">Saved</span>
            <div><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="flash error">
            <span class="tag">Issue</span>
            <div><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <form method="post" class="form-stack">
        <div class="card">
            <div class="section-header">
                <div>
                    <p class="eyebrow" style="margin-bottom: 0.35rem;">Fund mapping</p>
                    <p class="section-title">Class, location, and income targets</p>
                    <p class="section-sub">Use the exact QuickBooks names to avoid sync errors.</p>
                </div>
                <button type="submit" class="btn">Save mappings</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th style="width: 13%;">PCO Fund ID</th>
                        <th style="width: 24%;">PCO Fund Name</th>
                        <th style="width: 20%;">QBO Class Name<br><span class="small">e.g. Trinity - General</span></th>
                        <th style="width: 20%;">QBO Location Name<br><span class="small">e.g. Moundsville</span></th>
                        <th style="width: 23%;">QBO Income Account<br><span class="small">e.g. OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($funds as $fund): ?>
                        <?php
                        $fid   = isset($fund['id']) ? (string)$fund['id'] : '';
                        $attrs = $fund['attributes'] ?? [];
                        $fname = isset($attrs['name']) ? (string)$attrs['name'] : '';

                        $map   = $existingMappings[$fid] ?? null;
                        ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>
                                <input type="hidden"
                                       name="fund[<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>][pco_fund_id]"
                                       value="<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td>
                                <?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>
                                <input type="hidden"
                                       name="fund[<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>][pco_fund_name]"
                                       value="<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td>
                                <input type="text"
                                       name="fund[<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>][qbo_class_name]"
                                       value="<?= htmlspecialchars($map['qbo_class_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td>
                                <input type="text"
                                       name="fund[<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>][qbo_location_name]"
                                       value="<?= htmlspecialchars($map['qbo_location_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                            <td>
                                <input type="text"
                                       name="fund[<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>][qbo_income_account_name]"
                                       value="<?= htmlspecialchars($map['qbo_income_account_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="small" style="margin-top: 0.65rem;">
                These mappings are used when creating deposits in QuickBooks. Leave Class, Location, or Income blank to use defaults.
            </div>

            <div class="actions">
                <button type="submit" class="btn">Save mappings</button>
            </div>
        </div>
    </form>
    <div class="footer">
        &copy; <?= date('Y') ?> Rev. Tommy Sheppard ? <a href="help.php">Help</a>
    </div>
</div>
</body>
</html>
