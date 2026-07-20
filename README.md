# QuickBooks ↔ Planning Center Sync

Bridge Planning Center Giving data (Stripe payouts, committed batches, and Registrations) into QuickBooks Online. Includes a PHP dashboard to connect credentials, preview data, run syncs, and review logs.

## What it does
- Connects to QuickBooks Online via OAuth and stores refresh/access tokens.
- Stripe online donations → QBO Deposits (income + fee lines) grouped by weekly Monday payout date and fund; a QBO deposit never mixes fund types. Class/location and payment method refs are applied when available.
- PCO Giving committed batches (cash/check) → QBO Deposits grouped by location; per-payment-method deposit lines.
- PCO Registrations payments → QBO Deposits; refunds → QBO expenses using configured refund account.
- Previews for Stripe, batches, and registrations show only unsynced items (per-item tracking in `synced_items`). Refunds dedupe by fingerprint.
- Fund ↔ Class/Location mappings, notification email, and run logs.
- Optional webhook trigger for registrations via `giving.v2.events.batch.created`.

## Requirements
- PHP 8.1+ with PDO MySQL, cURL, and OpenSSL.
- MySQL 8 (or compatible).
- Composer (for PHPMailer SMTP support).
- Intuit Developer account with a QuickBooks app (OAuth 2.0).
- Planning Center personal access token (client id + secret).
- Web server pointing to `public/` (Apache/nginx or `php -S localhost:8000 -t public` for local testing).

## Install and configure
1) **Clone and dependencies**
   - `git clone https://github.com/dsuguy2007/qbo-pco-sync.git`
   - `cd qbo-pco-sync`
   - Run `composer install` to pull PHPMailer (needed for SMTP email). Ensure `vendor/autoload.php` exists before production use.

2) **Database**
   - Create a MySQL schema and user with read/write.
   - Initialize tables:
     ```sh
     mysql -u YOURUSER -p YOURDB < sql/schema.sql
     ```

3) **Run setup wizard**
   - Visit `public/setup.php` (or `setup.php?force=1` to rerun).
   - Enter DB creds, QBO keys, PCO PAT (app id/secret), webhook secrets, app base URL, notification email, and SMTP settings.
   - The wizard writes `config/.env`, generates `config/config.php`, and initializes the DB.
   - If `vendor/autoload.php` is missing, the wizard shows a reminder to run `composer install`.

4) **QuickBooks OAuth**
   - In <https://developer.intuit.com>, create a QBO app.
   - Add redirect URI: `https://your-host/qbo-pco-sync/public/oauth-callback.php`.
   - Put client ID/secret/environment in setup; then click “Connect QuickBooks” in the dashboard to authorize.

5) **Planning Center**
   - Create a personal access token in PCO.
   - Use the PAT client id/secret for `PCO_APP_ID` / `PCO_SECRET`.

6) **Web server**
   - Serve `public/` as the document root. Ensure PHP can write to `config/` and `logs/`.

## Usage
- **Dashboard**: `index.php` shows connection status and links to previews/syncs.
- **Stripe (online donations)**: preview `run-sync-preview.php`; sync `run-sync.php` (supports `reset_window`/`backfill_days`). The sync waits for PCO's weekly Monday payout update, groups gifts by payout date, and creates a separate QBO deposit for each fund. It windows on `updated_at` and rechecks a 14-day overlap because PCO can add final Stripe payout/fee details days after a donation completes; per-donation tracking prevents duplicates.
- **Committed batches (cash/check)**: preview `run-batch-preview.php`; sync `run-batch-sync.php`.
- **Registrations**: preview `run-registrations-preview.php`; sync `run-registrations-sync.php`. Refunds post as expenses to the configured refund account (or income fallback) with class/department applied.
- **Fund mappings**: `fund-mapping.php` maps PCO fund → QBO Class/Location/Income Account.
- **Settings**: `settings.php` for account names, notification email, refund account, and display timezone.
- **Logs**: `logs.php` for recent sync runs.
- **Webhook (optional)**: PCO `giving.v2.events.batch.created` can trigger registrations sync via shared secret in `webhook_secrets`.

## Email
- SMTP recommended. Set in `.env` via setup.
- PHPMailer loads from `vendor/autoload.php`; if missing, run `composer install`.
- If SMTP host is empty, Mailer falls back to PHP `mail()` (less reliable).

## Data safety and idempotency
- `synced_items` tracks processed donations/refunds/payments to prevent duplicates across reruns.
- `synced_deposits` retains legacy fingerprints for idempotency.
- Previews show only unsynced items by default.

## License

This project is source-available and **non-commercial**.

- Churches, ministries, and non-profit organizations may use and modify it
  for their own internal, non-commercial use, under the terms of the
  **Non-Commercial Church Use License** in the `LICENSE` file.
- You may not sell this software or offer it as a paid hosted service
  without my written permission.

For commercial use inquiries, contact tdsheppard77@gmail.com.
