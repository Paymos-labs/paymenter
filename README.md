# Paymos for Paymenter

**English** | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Official Paymos payment gateway extension for **Paymenter v1.x** (Laravel / Filament).

Accept **4 stablecoins (`USDT`, `USDC`, `USD1`, `DAI`)** and **gold-backed `XAUT`** across 13 mainnet networks. When an on-chain transfer confirms, the gateway records the payment natively through `ExtensionHelper::addPayment()` so Paymenter marks the invoice paid and runs your provisioning and automation rules automatically.

[![Watch: 12 Official CMS Crypto Plugins — 1-Click Connect (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video walkthrough (1:12, subtitles in 6 languages):** [Watch on YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requirements

- Paymenter v1.x (Laravel / Filament)
- PHP 8.2+ with `curl`, `hash`, `json`, `openssl`, and `pdo` extensions
- An **HTTPS** panel URL (`APP_URL`) — connecting over plain HTTP is rejected
- A Paymos account with the target project selected in the Paymos dashboard

No Composer step is required on the Paymenter host: the release package bundles `paymos/php-sdk` under `Paymos/vendor/paymos/php-sdk/`. Admin and checkout copy ships in 6 locales: English (`en`), Russian (`ru`), German (`de`), Spanish (`es`), Turkish (`tr`), and Simplified Chinese (`zh-Hans`).

## Install and connect

1. Download the latest `paymos-paymenter.zip` package from [GitHub Releases](https://github.com/Paymos-labs/paymenter/releases/latest).
2. Extract the archive into your Paymenter `extensions/Gateways/` directory so the layout is:

   ```text
   extensions/Gateways/Paymos/
     Paymos.php
     routes.php
     composer.json
     README.md
     CHANGELOG.md
     LICENSE
     src/
     resources/views/
     lang/
     vendor/paymos/php-sdk/
   ```

3. Clear Laravel caches if route or view caching is enabled:

   ```bash
   php artisan route:clear
   php artisan view:clear
   php artisan config:clear
   ```

4. In **Paymenter Admin → Extensions → Gateways**, enable **Paymos**.
5. Open the desired project in the Paymos dashboard, then click **Connect Paymos** inside the Paymos gateway settings in Paymenter and approve the installation in the Paymos tab.

Every merchant downloads the same package with no embedded credentials, project ID, or secrets. One approval provisions both **Sandbox** and **Live** environments atomically and registers the signed webhook endpoint for this Paymenter installation:

```text
https://<your-paymenter-panel>/extensions/gateways/paymos/webhook
```

After connecting, the gateway settings expose only **Connection** (Connect / Reconnect status), **Mode** (`Sandbox` or `Live`), and **Button Text** — never manual API key, API secret, project ID, or webhook secret fields.

## Secret storage

Provisioned Sandbox and Live credentials and temporary device-flow state are sealed inside an authenticated **AES-256-GCM** envelope (`AesGcmEnvelope`) keyed from Laravel's `config('app.key')` and stored in `paymos_settings`. Secrets are never rendered back into HTML, logs, or error messages. Reconnect if you change your panel URL, revoke a Payment key, or rotate a webhook secret.

## Checkout and invoice lifecycle

- **Crash-safe invoice creation:** The gateway persists a `creating` intent before sending `POST /v1/invoices` and replays the exact same `external_order_id`, amount, currency, project, and environment if a response is lost.
- **Line items and remaining balance:** Checkout prioritizes `$invoice->remaining` (so partially credited or partially paid invoices bill the exact balance due) and passes up to 50 validated line items from `$invoice->items` as `products[]`, falling back to amount-only when line items are unavailable or invalid.
- **Fail-closed replacement:** When the amount due, currency, mode, or project changes — or a previous Paymos invoice expires — the gateway closes the previous invoice via `InvoiceReplacement::close()` before minting a replacement. If the old invoice is paid, still payable, unreachable, or returns 404, replacement is blocked for manual review so the buyer cannot pay twice.
- **Signed webhooks and scheduled reconciliation:**
  - Incoming webhooks on `/extensions/gateways/paymos/webhook` are verified across Sandbox and Live via `MultiEnvironmentWebhookVerifier` (constant-time HMAC-SHA256, dual-`v1=` rotation, 300-second timestamp window) and deduplicated with owner-fenced reservations (`200` on committed duplicate, `409` on in-flight delivery, `401` on bad/stale signature).
  - Terminal events undergo live reverse verification (`GET /v1/invoices/{id}`), `AmountGuard` triangulation (`manual_review` + HTTP `400` on mismatch), and a roll-back guard so paid or finalized invoices are never downgraded or double-credited.
  - `invoice.paid_late` is handled strictly as an idempotent note + `paid_late_noted` guard without auto-crediting.
  - `Paymos::boot()` registers a Laravel `Schedule` job every 10 minutes (throttled to at most once every 600 seconds) that sweeps up to 50 unpaid Paymos invoices from the last 24 hours and reconciles pending refunds.

## Refunds

The extension supports server-calculated `full` and `overpayment` (`paid_over`) refunds via the Paymos Refunds API (`/v1/refunds`), sending funds back strictly to the verified `first_sender` address recorded on the original deposit (arbitrary partial amounts and manual recipient addresses are rejected before any API call):

- **Paymos refunds panel on admin invoices:** Rendered via Filament render hooks for administrators with `admin.invoices.update` permission. Displays operation status, supports `full` and (for `paid_over` invoices) `overpayment` actions with durable UUIDv4 `request_token` and CSRF protection, and discovers refunds created directly in the Paymos Dashboard (`RefundDiscovery`).
- **Async-safe native `Gateway::refund()`:** Because Paymenter's `ExtensionHelper::refund()` synchronously invokes `ExtensionHelper::recordRefund()` whenever `Gateway::refund()` returns `true`, `Paymos::refund()` never returns `true` while a Paymos refund is `pending`, `processing`, or `unknown`. Once Paymos confirms `completed` (via signed `withdrawal.completed` webhook or scheduled reconciliation), `RefundAdapter::applyCompleted()` records the native refund exactly once via `ExtensionHelper::refundLocally()` for `full` refunds and records a note (keeping the invoice paid) for `overpayment` refunds.

## Troubleshooting

- **Connect refuses to start:** Verify `APP_URL` starts with `https://` and that your administrator account holds the `admin.gateways.update` permission.
- **Refund routes return HTTP 403:** Connect endpoints require `admin.gateways.update` and Refund endpoints require `admin.invoices.update`; regular customer accounts are intentionally rejected with HTTP `403`.
- **Paid invoice held for manual review:** Check Laravel logs for `Manual review` entries — either the invoice amount changed after checkout was created, or an existing Paymos invoice could not be proven closed before replacement.

## Links & License

- [Documentation](https://paymos.io/docs/cms-paymenter)
- [Source & Issues](https://github.com/Paymos-labs/paymenter)
- [Changelog](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
- License: [MIT](LICENSE) — © 2026 Paymos Labs
