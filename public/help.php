<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Help &amp; License</title>
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
        .page { max-width: 900px; margin: 0 auto; padding: 2.4rem 1.25rem 3rem; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.2rem 1.25rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18);
            margin-bottom: 1rem;
        }
        h1 { margin: 0 0 0.35rem; font-size: 2rem; letter-spacing: -0.01em; }
        h2 { margin: 1.2rem 0 0.4rem; }
        .lede { color: var(--muted); margin: 0 0 0.75rem; line-height: 1.6; }
        .muted { color: var(--muted); }
        a { color: var(--accent); }
        .footer {
            margin-top: 1.5rem;
            color: var(--muted);
            font-size: 0.95rem;
        }
        .footer a { color: var(--accent); text-decoration: none; }
        .footer a:hover { color: #dff1ff; }
        .nav {
            margin-top: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: var(--accent);
            text-decoration: none;
        }
        .nav:hover { color: #dff1ff; }
    </style>
</head>
<body>
<div class="page">
    <div class="card">
        <h1>Help &amp; License</h1>
        <p class="lede">Notes on usage, license, and support for the QuickBooks / Planning Center sync app.</p>

        <h2>License</h2>
        <p>This project is source-available and <strong>non-commercial</strong>.</p>
        <ul>
            <li>Churches, ministries, and non-profit organizations may use and modify it for their own internal, non-commercial use, under the terms of the <strong>Non-Commercial Church Use License</strong> in the <code>LICENSE</code> file.</li>
            <li>You may not sell this software or offer it as a paid hosted service without written permission.</li>
        </ul>
        <p>If you are interested in commercial use, please contact me.</p>

        <h2>Warranty &amp; Risk</h2>
        <p class="muted">This software is provided ?as-is? without any warranty. Use at your own risk. The author and contributors are not liable for any damages, data loss, or financial impact arising from its use.</p>

        <h2>Support</h2>
        <p>Assistance is offered as time permits.</p>
        <p>Contact: Rev. Tommy Sheppard ? <a href="mailto:tdsheppard77@gmail.com">tdsheppard77@gmail.com</a></p>
        <p class="muted">Feature requests or issues: please use the GitHub issue queue at <a href="https://github.com/revtommy/qbo-pco-sync" target="_blank" rel="noopener noreferrer">github.com/revtommy/qbo-pco-sync</a>.</p>

        <a class="nav" href="index.php">&larr; Back to dashboard</a>
    </div>
    <div class="footer">
        &copy; <?= date('Y') ?> Rev. Tommy Sheppard
    </div>
</div>
</body>
</html>
