# Changelog

All notable changes to the Paymos gateway extension for Paymenter are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

## [1.0.3] - 2026-11-25

- fix(plugins): accept stamped SemVer and avoid PHP 8.5 setAccessible deprecation in plugin tests
- fix(plugins): resolve 13 live CMS runtime issues in FOSSBilling 0.8.9, Paymenter v1.5.9, and Blesta 6.0.5

### Added
- One-Click **Connect Paymos** (`ConnectHandler`, `CredentialStore`, `resources/views/connect.blade.php`) using RFC 8628 `DeviceConnectClient` bound to slug `paymenter` and the installation's HTTPS URL, storing Sandbox and Live credentials inside an AES-256-GCM envelope keyed from Laravel `config('app.key')`.
- Crash-safe checkout (`CheckoutService`) with pre-POST `STATUS_CREATING` intent recovery, `paid_late_noted` guard, fail-closed `InvoiceReplacement::close()`, `InvoiceRenewal`, `$invoice->remaining` priority, and `products[]` line-item forwarding (1–50 items with amount-only fallback).
- Multi-environment webhook verification (`CallbackProcessor`) via `MultiEnvironmentWebhookVerifier` (Sandbox + Live, dual-`v1=`, 300s skew, `200`/`409`/`401`/`400`), `withdrawal.*` refund routing, `InvoiceReverseVerifier`, `AmountGuard`, roll-back guard, and note-only `invoice.paid_late`.
- Scheduled 10-minute reconciliation (`Reconciler` + `Paymos::boot()` Laravel `Schedule` registration) sweeping unpaid invoices over the last 24 hours with 600-second throttling alongside background refund reconciliation.
- Full refunds lifecycle (`RefundIntentStore`, `RefundAdapter`, `resources/views/refunds-panel.blade.php`) implementing `RefundIntentStoreInterface`, `RefundCandidateStoreInterface`, `NativeRefundProjectorInterface`, `RefundCoordinator`, `RefundDiscovery`, and `RefundCopy` for both `full` and `overpayment` modes, with async-safe `ExtensionHelper::refundLocally()` projection strictly inside `applyCompleted()`.
- Strict admin authorization (`admin.gateways.update` / `Gate::allows('update', Gateway::class)` for Connect, `admin.invoices.update` / `Gate::allows('update', Invoice::class)` for Refunds) and CSRF protection on all admin routes, rejecting regular customers with HTTP `403`.
- Six native localization catalogs (`lang/{en,ru,de,es,tr,zh-Hans}.php`) and `Translation` resolver.

### Changed
- Replaced manual credential settings in `Paymos::getConfig()` with Filament `Placeholder` (Connect Paymos), `mode` (`sandbox`/`live`), and `button_text`.
- Updated `composer.json` to require `paymos/php-sdk: ^1.11.0` and point all repository URLs to `https://github.com/Paymos-labs/paymenter`.

### Removed
- Legacy manual-credential `GatewayService.php` MVP implementation and obsolete standalone `scripts/package.py` / `dist/Paymos-Paymenter-v1.0.0.zip` artifacts.

## [1.0.2] - 2026-11-25

- chore: rebuild canonical CMS package

### Added
- One-Click **Connect Paymos** (`ConnectHandler`, `CredentialStore`, `resources/views/connect.blade.php`) using RFC 8628 `DeviceConnectClient` bound to slug `paymenter` and the installation's HTTPS URL, storing Sandbox and Live credentials inside an AES-256-GCM envelope keyed from Laravel `config('app.key')`.
- Crash-safe checkout (`CheckoutService`) with pre-POST `STATUS_CREATING` intent recovery, `paid_late_noted` guard, fail-closed `InvoiceReplacement::close()`, `InvoiceRenewal`, `$invoice->remaining` priority, and `products[]` line-item forwarding (1–50 items with amount-only fallback).
- Multi-environment webhook verification (`CallbackProcessor`) via `MultiEnvironmentWebhookVerifier` (Sandbox + Live, dual-`v1=`, 300s skew, `200`/`409`/`401`/`400`), `withdrawal.*` refund routing, `InvoiceReverseVerifier`, `AmountGuard`, roll-back guard, and note-only `invoice.paid_late`.
- Scheduled 10-minute reconciliation (`Reconciler` + `Paymos::boot()` Laravel `Schedule` registration) sweeping unpaid invoices over the last 24 hours with 600-second throttling alongside background refund reconciliation.
- Full refunds lifecycle (`RefundIntentStore`, `RefundAdapter`, `resources/views/refunds-panel.blade.php`) implementing `RefundIntentStoreInterface`, `RefundCandidateStoreInterface`, `NativeRefundProjectorInterface`, `RefundCoordinator`, `RefundDiscovery`, and `RefundCopy` for both `full` and `overpayment` modes, with async-safe `ExtensionHelper::refundLocally()` projection strictly inside `applyCompleted()`.
- Strict admin authorization (`admin.gateways.update` / `Gate::allows('update', Gateway::class)` for Connect, `admin.invoices.update` / `Gate::allows('update', Invoice::class)` for Refunds) and CSRF protection on all admin routes, rejecting regular customers with HTTP `403`.
- Six native localization catalogs (`lang/{en,ru,de,es,tr,zh-Hans}.php`) and `Translation` resolver.

### Changed
- Replaced manual credential settings in `Paymos::getConfig()` with Filament `Placeholder` (Connect Paymos), `mode` (`sandbox`/`live`), and `button_text`.
- Updated `composer.json` to require `paymos/php-sdk: ^1.11.0` and point all repository URLs to `https://github.com/Paymos-labs/paymenter`.

### Removed
- Legacy manual-credential `GatewayService.php` MVP implementation and obsolete standalone `scripts/package.py` / `dist/Paymos-Paymenter-v1.0.0.zip` artifacts.

## [1.0.1] - 2026-11-25

- chore: bundle Paymos PHP SDK v1.13.0

### Added
- One-Click **Connect Paymos** (`ConnectHandler`, `CredentialStore`, `resources/views/connect.blade.php`) using RFC 8628 `DeviceConnectClient` bound to slug `paymenter` and the installation's HTTPS URL, storing Sandbox and Live credentials inside an AES-256-GCM envelope keyed from Laravel `config('app.key')`.
- Crash-safe checkout (`CheckoutService`) with pre-POST `STATUS_CREATING` intent recovery, `paid_late_noted` guard, fail-closed `InvoiceReplacement::close()`, `InvoiceRenewal`, `$invoice->remaining` priority, and `products[]` line-item forwarding (1–50 items with amount-only fallback).
- Multi-environment webhook verification (`CallbackProcessor`) via `MultiEnvironmentWebhookVerifier` (Sandbox + Live, dual-`v1=`, 300s skew, `200`/`409`/`401`/`400`), `withdrawal.*` refund routing, `InvoiceReverseVerifier`, `AmountGuard`, roll-back guard, and note-only `invoice.paid_late`.
- Scheduled 10-minute reconciliation (`Reconciler` + `Paymos::boot()` Laravel `Schedule` registration) sweeping unpaid invoices over the last 24 hours with 600-second throttling alongside background refund reconciliation.
- Full refunds lifecycle (`RefundIntentStore`, `RefundAdapter`, `resources/views/refunds-panel.blade.php`) implementing `RefundIntentStoreInterface`, `RefundCandidateStoreInterface`, `NativeRefundProjectorInterface`, `RefundCoordinator`, `RefundDiscovery`, and `RefundCopy` for both `full` and `overpayment` modes, with async-safe `ExtensionHelper::refundLocally()` projection strictly inside `applyCompleted()`.
- Strict admin authorization (`admin.gateways.update` / `Gate::allows('update', Gateway::class)` for Connect, `admin.invoices.update` / `Gate::allows('update', Invoice::class)` for Refunds) and CSRF protection on all admin routes, rejecting regular customers with HTTP `403`.
- Six native localization catalogs (`lang/{en,ru,de,es,tr,zh-Hans}.php`) and `Translation` resolver.

### Changed
- Replaced manual credential settings in `Paymos::getConfig()` with Filament `Placeholder` (Connect Paymos), `mode` (`sandbox`/`live`), and `button_text`.
- Updated `composer.json` to require `paymos/php-sdk: ^1.11.0` and point all repository URLs to `https://github.com/Paymos-labs/paymenter`.

### Removed
- Legacy manual-credential `GatewayService.php` MVP implementation and obsolete standalone `scripts/package.py` / `dist/Paymos-Paymenter-v1.0.0.zip` artifacts.

## [1.0.0] - 2026-11-25

- feat(cms): rebuild Blesta, FOSSBilling, and Paymenter to full Paymos CMS lifecycle standard [skip ecosystem release]
- feat(plugins,distribution): add Package B hosting/billing gateways (Blesta, FOSSBilling, Paymenter) and archive Forge change [skip ecosystem release]
- chore: bundle Paymos PHP SDK v1.12.0
- chore: rebuild canonical CMS package

### Added
- One-Click **Connect Paymos** (`ConnectHandler`, `CredentialStore`, `resources/views/connect.blade.php`) using RFC 8628 `DeviceConnectClient` bound to slug `paymenter` and the installation's HTTPS URL, storing Sandbox and Live credentials inside an AES-256-GCM envelope keyed from Laravel `config('app.key')`.
- Crash-safe checkout (`CheckoutService`) with pre-POST `STATUS_CREATING` intent recovery, `paid_late_noted` guard, fail-closed `InvoiceReplacement::close()`, `InvoiceRenewal`, `$invoice->remaining` priority, and `products[]` line-item forwarding (1–50 items with amount-only fallback).
- Multi-environment webhook verification (`CallbackProcessor`) via `MultiEnvironmentWebhookVerifier` (Sandbox + Live, dual-`v1=`, 300s skew, `200`/`409`/`401`/`400`), `withdrawal.*` refund routing, `InvoiceReverseVerifier`, `AmountGuard`, roll-back guard, and note-only `invoice.paid_late`.
- Scheduled 10-minute reconciliation (`Reconciler` + `Paymos::boot()` Laravel `Schedule` registration) sweeping unpaid invoices over the last 24 hours with 600-second throttling alongside background refund reconciliation.
- Full refunds lifecycle (`RefundIntentStore`, `RefundAdapter`, `resources/views/refunds-panel.blade.php`) implementing `RefundIntentStoreInterface`, `RefundCandidateStoreInterface`, `NativeRefundProjectorInterface`, `RefundCoordinator`, `RefundDiscovery`, and `RefundCopy` for both `full` and `overpayment` modes, with async-safe `ExtensionHelper::refundLocally()` projection strictly inside `applyCompleted()`.
- Strict admin authorization (`admin.gateways.update` / `Gate::allows('update', Gateway::class)` for Connect, `admin.invoices.update` / `Gate::allows('update', Invoice::class)` for Refunds) and CSRF protection on all admin routes, rejecting regular customers with HTTP `403`.
- Six native localization catalogs (`lang/{en,ru,de,es,tr,zh-Hans}.php`) and `Translation` resolver.

### Changed
- Replaced manual credential settings in `Paymos::getConfig()` with Filament `Placeholder` (Connect Paymos), `mode` (`sandbox`/`live`), and `button_text`.
- Updated `composer.json` to require `paymos/php-sdk: ^1.11.0` and point all repository URLs to `https://github.com/Paymos-labs/paymenter`.

### Removed
- Legacy manual-credential `GatewayService.php` MVP implementation and obsolete standalone `scripts/package.py` / `dist/Paymos-Paymenter-v1.0.0.zip` artifacts.

