# Paymos für Paymenter

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielle Paymos-Zahlungsgateway-Erweiterung für **Paymenter v1.x** (Laravel / Filament).

Akzeptieren Sie **4 Stablecoins (`USDT`, `USDC`, `USD1`, `DAI`)** sowie das **goldgedeckte `XAUT`** auf 13 Mainnet-Netzwerken. Sobald eine On-Chain-Transaktion bestätigt wird, bucht das Gateway die Zahlung nativ über `ExtensionHelper::addPayment()`, sodass Paymenter die Rechnung als bezahlt markiert und Ihre Provisioning- sowie Automatisierungsregeln automatisch ausführt.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video-Walkthrough (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- Paymenter v1.x (Laravel / Filament)
- PHP 8.2+ mit den Erweiterungen `curl`, `hash`, `json`, `openssl` und `pdo`
- Eine **HTTPS**-Panel-URL (`APP_URL`) — Verbindungen über unverschlüsseltes HTTP werden abgelehnt
- Ein Paymos-Konto mit ausgewähltem Zielprojekt im Paymos-Dashboard

Auf dem Paymenter-Server ist kein Composer-Schritt nötig: Das Release-Paket enthält `paymos/php-sdk` direkt unter `Paymos/vendor/paymos/php-sdk/`. Admin- und Checkout-Texte werden in 6 Sprachen ausgeliefert: Englisch (`en`), Russisch (`ru`), Deutsch (`de`), Spanisch (`es`), Türkisch (`tr`) und Chinesisch (vereinfacht) (`zh-Hans`).

## Installation und Verbindung

1. Laden Sie das aktuelle Paket `paymos-paymenter.zip` von [GitHub Releases](https://github.com/Paymos-labs/paymenter/releases/latest) herunter.
2. Entpacken Sie das Archiv in das Verzeichnis `extensions/Gateways/` Ihrer Paymenter-Installation, sodass folgende Struktur entsteht:

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

3. Leeren Sie die Laravel-Caches, falls Routen- oder View-Caching aktiv ist:

   ```bash
   php artisan route:clear
   php artisan view:clear
   php artisan config:clear
   ```

4. Aktivieren Sie unter **Paymenter Admin → Extensions → Gateways** die Erweiterung **Paymos**.
5. Öffnen Sie das gewünschte Projekt im Paymos-Dashboard, klicken Sie in den Paymos-Gateway-Einstellungen in Paymenter auf **Paymos verbinden** (Connect Paymos) und bestätigen Sie die Installation im Paymos-Tab.

Jeder Händler lädt dasselbe Paket ohne eingebettete Zugangsdaten, Projekt-ID oder Geheimnisse herunter. Eine einzige Bestätigung richtet sowohl **Sandbox** als auch **Live** atomar ein und registriert den signierten Webhook-Endpunkt für diese Paymenter-Installation:

```text
https://<your-paymenter-panel>/extensions/gateways/paymos/webhook
```

Nach dem Verbinden zeigen die Gateway-Einstellungen ausschließlich **Verbindung** (Status Verbinden / Neu verbinden), **Modus** (`Sandbox` oder `Live`) und **Buttontext** — niemals manuelle Felder für API-Schlüssel, API-Secret, Projekt-ID oder Webhook-Secret.

## Speicherung von Geheimnissen

Eingerichtete Sandbox- und Live-Zugangsdaten sowie temporäre Device-Flow-Zustände werden in einem authentifizierten **AES-256-GCM**-Umschlag (`AesGcmEnvelope`) verschlüsselt, abgeleitet aus Laravels `config('app.key')`, und in `paymos_settings` abgelegt. Geheimnisse werden niemals in HTML, Protokollen oder Fehlermeldungen ausgegeben. Verbinden Sie das Gateway erneut, wenn Sie Ihre Panel-URL ändern, einen Payment-Schlüssel widerrufen oder ein Webhook-Secret rotieren.

## Checkout und Rechnungslebenszyklus

- **Absturzsichere Rechnungserstellung:** Das Gateway speichert vor dem Versand von `POST /v1/invoices` eine `creating`-Absicht und wiederholt bei verlorener Antwort exakt dieselbe `external_order_id`, denselben Betrag, dieselbe Währung, dasselbe Projekt und dieselbe Umgebung.
- **Rechnungspositionen und Restbetrag:** Der Checkout priorisiert `$invoice->remaining` (sodass teilweise gutgeschriebene oder teilbezahlte Rechnungen exakt den offenen Restbetrag abrechnen) und übergibt bis zu 50 validierte Positionen aus `$invoice->items` als `products[]`; bei fehlenden oder ungültigen Positionen erfolgt ein sauberer Fallback auf reine Betragsabrechnung.
- **Fail-closed-Ersetzung:** Ändern sich fälliger Betrag, Währung, Modus oder Projekt — oder läuft eine frühere Paymos-Rechnung ab — schließt das Gateway die vorherige Rechnung über `InvoiceReplacement::close()`, bevor eine neue angelegt wird. Ist die alte Rechnung bereits bezahlt, noch bezahlbar, nicht erreichbar oder liefert sie 404, wird die Ersetzung zur manuellen Prüfung blockiert, damit der Käufer nicht doppelt zahlen kann.
- **Signierte Webhooks und geplanter Abgleich:**
  - Eingehende Webhooks auf `/extensions/gateways/paymos/webhook` werden über `MultiEnvironmentWebhookVerifier` für Sandbox und Live verifiziert (zeitkonstantes HMAC-SHA256, Dual-`v1=`-Rotation, 300-Sekunden-Zeitfenster) und über Owner-fenced-Reservierungen dedupliziert (`200` bei bereits verarbeitetem Duplikat, `409` bei laufender Zustellung, `401` bei ungültiger oder abgelaufener Signatur).
  - Abschlussereignisse durchlaufen eine Live-Rückwärtsprüfung (`GET /v1/invoices/{id}`), die `AmountGuard`-Triangulation (`manual_review` + HTTP `400` bei Abweichung) und einen Rollback-Schutz, sodass bezahlte oder abgeschlossene Rechnungen weder herabgestuft noch doppelt gutgeschrieben werden.
  - `invoice.paid_late` wird strikt als idempotente Notiz mit `paid_late_noted`-Markierung ohne automatische Gutschrift behandelt.
  - `Paymos::boot()` registriert einen Laravel-`Schedule`-Job alle 10 Minuten (gedrosselt auf höchstens einmal alle 600 Sekunden), der bis zu 50 unbezahlte Paymos-Rechnungen der letzten 24 Stunden abgleicht und offene Erstattungen nachverfolgt.

## Erstattungen

Die Erweiterung unterstützt serverseitig berechnete `full`- und `overpayment`-Erstattungen (`paid_over`) über die Paymos Refunds API (`/v1/refunds`). Die Auszahlung erfolgt strikt zurück an die verifizierte `first_sender`-Adresse der ursprünglichen Einzahlung (beliebige Teilbeträge und manuelle Empfängeradressen werden bereits vor jedem API-Aufruf abgelehnt):

- **Panel „Paymos-Erstattungen“ auf Admin-Rechnungen:** Wird über Filament-Render-Hooks für Administratoren mit der Berechtigung `admin.invoices.update` eingeblendet. Es zeigt den Vorgangsstatus, unterstützt die Aktionen `full` und (bei `paid_over`-Rechnungen) `overpayment` mit persistentem UUIDv4-`request_token` und CSRF-Schutz und erkennt direkt im Paymos-Dashboard ausgelöste Erstattungen (`RefundDiscovery`).
- **Asynchron-sicheres natives `Gateway::refund()`:** Da Paymenters `ExtensionHelper::refund()` synchron `ExtensionHelper::recordRefund()` aufruft, sobald `Gateway::refund()` `true` zurückgibt, gibt `Paymos::refund()` niemals `true` zurück, solange eine Paymos-Erstattung den Status `pending`, `processing` oder `unknown` hat. Sobald Paymos `completed` bestätigt (per signiertem `withdrawal.completed`-Webhook oder geplantem Abgleich), trägt `RefundAdapter::applyCompleted()` die native Erstattung bei `full`-Erstattungen genau einmal über `ExtensionHelper::refundLocally()` ein und fügt bei `overpayment`-Erstattungen eine Notiz hinzu (die Rechnung bleibt dabei bezahlt).

## Fehlerbehebung

- **Verbindung startet nicht:** Prüfen Sie, ob `APP_URL` mit `https://` beginnt und Ihr Administratorkonto die Berechtigung `admin.gateways.update` besitzt.
- **Erstattungsrouten liefern HTTP 403:** Connect-Endpunkte erfordern `admin.gateways.update`, Erstattungs-Endpunkte erfordern `admin.invoices.update`; reguläre Kundenkonten werden gezielt mit HTTP `403` abgewiesen.
- **Bezahlte Rechnung für manuelle Prüfung zurückgehalten:** Prüfen Sie die Laravel-Logs auf `Manual review`-Einträge — entweder änderte sich der Rechnungsbetrag nach dem Start des Checkouts, oder der Abschluss einer bestehenden Paymos-Rechnung ließ sich vor einer Ersetzung nicht nachweisen.

## Links & Lizenz

- [Dokumentation](https://paymos.io/de/docs/cms-paymenter)
- [Quellcode & Issues](https://github.com/Paymos-labs/paymenter)
- [Änderungsprotokoll](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
- Lizenz: [MIT](LICENSE) — © 2026 Paymos Labs
