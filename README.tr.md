# Paymenter için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

**Paymenter v1.x** (Laravel / Filament) için resmi Paymos ödeme ağ geçidi uzantısıdır.

13 ana ağda **4 stablecoin (`USDT`, `USDC`, `USD1`, `DAI`)** ve **altın destekli `XAUT`** kabul edin. Zincir üzerindeki bir transfer onaylandığında ağ geçidi ödemeyi `ExtensionHelper::addPayment()` üzerinden yerleşik olarak kaydeder; böylece Paymenter faturayı ödenmiş olarak işaretler ve provizyon ile otomasyon kurallarınızı otomatik olarak çalıştırır.

[![İzleyin: 12 Resmi CMS Kripto Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video tanıtımı (1:12, 6 dilde altyazılı):** [YouTube’da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- Paymenter v1.x (Laravel / Filament)
- `curl`, `hash`, `json`, `openssl` ve `pdo` uzantıları etkin PHP 8.2+
- **HTTPS** üzerinden çalışan bir panel URL’si (`APP_URL`) — düz HTTP üzerinden bağlantı reddedilir
- Paymos panelinde hedef projenin seçili olduğu bir Paymos hesabı

Paymenter sunucusunda Composer adımı gerekmez: sürüm paketi `paymos/php-sdk` kütüphanesini `Paymos/vendor/paymos/php-sdk/` altında hazır olarak içerir. Yönetim ve ödeme ekranı metinleri 6 dilde sunulur: İngilizce (`en`), Rusça (`ru`), Almanca (`de`), İspanyolca (`es`), Türkçe (`tr`) ve Basitleştirilmiş Çince (`zh-Hans`).

## Kurulum ve bağlantı

1. En güncel `paymos-paymenter.zip` paketini [GitHub Releases](https://github.com/Paymos-labs/paymenter/releases/latest) sayfasından indirin.
2. Arşivi Paymenter’ın `extensions/Gateways/` dizinine aşağıdaki yapıyı oluşturacak şekilde çıkarın:

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

3. Rota veya görünüm önbelleklemesi etkinse Laravel önbelleklerini temizleyin:

   ```bash
   php artisan route:clear
   php artisan view:clear
   php artisan config:clear
   ```

4. **Paymenter Admin → Extensions → Gateways** bölümünde **Paymos** uzantısını etkinleştirin.
5. Paymos panelinde istediğiniz projeyi açın, ardından Paymenter’daki Paymos ağ geçidi ayarlarında **Paymos’u bağla** (Connect Paymos) düğmesine tıklayın ve açılan Paymos sekmesinde kurulumu onaylayın.

Her satıcı gömülü kimlik bilgisi, proje ID’si veya gizli anahtar içermeyen aynı paketi indirir. Tek bir onay hem **Sandbox** hem de **Canlı** (Live) ortamlarını atomik olarak yapılandırır ve bu Paymenter kurulumu için imzalı webhook uç noktasını kaydeder:

```text
https://<your-paymenter-panel>/extensions/gateways/paymos/webhook
```

Bağlantıdan sonra ağ geçidi ayarları yalnızca **Bağlantı** (Bağla / Yeniden bağla durumu) ve **Mod** (`Sandbox` veya `Live`) alanlarını gösterir — hiçbir yerde elle girilen API anahtarı, API gizli anahtarı, proje ID’si veya webhook gizli anahtarı alanı yer almaz.

## Gizli bilgilerin saklanması

Yapılandırılan Sandbox ve Canlı kimlik bilgileri ile geçici cihaz akışı durumu, Laravel’in `config('app.key')` anahtarından türetilen kimlik doğrulamalı bir **AES-256-GCM** zarfı (`AesGcmEnvelope`) içinde mühürlenerek `paymos_settings` tablosunda saklanır. Gizli bilgiler hiçbir zaman HTML çıktısına, günlüklere veya hata mesajlarına yazdırılmaz. Panel URL’nizi değiştirir, bir Ödeme anahtarını iptal eder veya webhook gizli anahtarını yenilerseniz bağlantıyı yeniden kurun.

## Ödeme ve fatura yaşam döngüsü

- **Çökmeye dayanıklı fatura oluşturma:** Ağ geçidi `POST /v1/invoices` isteğini göndermeden önce bir `creating` kaydı saklar; yanıt kaybolursa aynı `external_order_id`, tutar, para birimi, proje ve ortam bilgileriyle isteği yeniden oynatarak yinelenen fatura oluşmasını engeller.
- **Fatura kalemleri ve kalan bakiye:** Ödeme akışı `$invoice->remaining` değerini önceliklendirir (böylece kısmen kredilendirilmiş veya kısmen ödenmiş faturalar tam olarak kalan tutarı tahsil eder) ve `$invoice->items` içinden doğrulanmış en fazla 50 kalemi `products[]` olarak iletir; kalemler mevcut değilse veya geçersizse yalnızca tutar bazlı ödemeye sorunsuz geçiş yapar.
- **Güvenli durdurmalı (fail-closed) fatura yenileme:** Ödenecek tutar, para birimi, mod veya proje değiştiğinde — ya da önceki Paymos faturasının süresi dolduğunda — ağ geçidi yeni fatura oluşturmadan önce `InvoiceReplacement::close()` ile eski faturayı kapatır. Eski fatura ödenmişse, hâlâ ödenebilir durumdaysa, erişilemiyorsa veya 404 döndürüyorsa, alıcının iki kez ödeme yapmasını önlemek için yenileme manuel incelemeye alınarak engellenir.
- **İmzalı webhook’lar ve zamanlanmış mutabakat:**
  - `/extensions/gateways/paymos/webhook` adresine gelen webhook’lar `MultiEnvironmentWebhookVerifier` ile Sandbox ve Canlı ortamları genelinde doğrulanır (sabit zamanlı HMAC-SHA256, çift `v1=` anahtar rotasyonu, 300 saniyelik zaman damgası penceresi) ve sahip-kilitli rezervasyonlarla tekilleştirilir (işlenmiş yinelenen olay için `200`, eşzamanlı süren teslimat için `409`, geçersiz veya süresi dolmuş imza için `401`).
  - Nihai olaylar canlı ters doğrulamadan (`GET /v1/invoices/{id}`), `AmountGuard` üçgen tutar kontrolünden (uyuşmazlıkta `manual_review` + HTTP `400`) ve geri alma korumasından geçer; böylece ödenmiş veya kesinleşmiş faturaların durumu düşürülmez ve iki kez işlenmez.
  - `invoice.paid_late` olayı otomatik bakiye işlemeden kesinlikle idempotent bir not + `paid_late_noted` koruması olarak kaydedilir.
  - `Paymos::boot()`, her 10 dakikada bir (en fazla 600 saniyede bir çalışacak şekilde sınırlandırılmış) bir Laravel `Schedule` görevi kaydeder; bu görev son 24 saate ait en fazla 50 ödenmemiş Paymos faturasını tarar ve bekleyen iadeleri mutabık kılar.

## İadeler

Uzantı, Paymos Refunds API (`/v1/refunds`) üzerinden sunucu tarafından hesaplanan `full` ve `overpayment` (`paid_over`) iadelerini destekler ve fonları kesinlikle ilk yatırma işleminde kaydedilen doğrulanmış `first_sender` adresine geri gönderir (keyfi kısmi tutarlar ve elle girilen alıcı adresleri herhangi bir API çağrısından önce reddedilir):

- **Yönetici faturalarında Paymos iadeleri paneli:** `admin.invoices.update` iznine sahip yöneticiler için Filament render kancalarıyla gösterilir. İşlem durumunu görüntüler, kalıcı UUIDv4 `request_token` ve CSRF korumasıyla `full` ve (`paid_over` faturalar için) `overpayment` işlemlerini destekler ve doğrudan Paymos panelinden oluşturulan iadeleri algılar (`RefundDiscovery`).
- **Asenkron güvenli yerleşik `Gateway::refund()`:** Paymenter’ın `ExtensionHelper::refund()` yöntemi, `Gateway::refund()` `true` döndürdüğü anda eşzamanlı olarak `ExtensionHelper::recordRefund()` çağırdığı için, `Paymos::refund()` bir Paymos iadesi `pending`, `processing` veya `unknown` durumundayken asla `true` döndürmez. Paymos `completed` durumunu onayladığında (imzalı `withdrawal.completed` webhook’u veya zamanlanmış mutabakat ile), `RefundAdapter::applyCompleted()` `full` iadeleri `ExtensionHelper::refundLocally()` üzerinden tam bir kez kaydeder, `overpayment` iadeleri içinse faturayı ödenmiş tutarak bir not ekler.

## Sorun giderme

- **Bağlantı başlamıyor:** `APP_URL` değerinin `https://` ile başladığını ve yönetici hesabınızın `admin.gateways.update` iznine sahip olduğunu doğrulayın.
- **İade rotaları HTTP 403 döndürüyor:** Bağlantı uç noktaları `admin.gateways.update`, iade uç noktaları ise `admin.invoices.update` izni gerektirir; normal müşteri hesapları bilinçli olarak HTTP `403` ile reddedilir.
- **Ödenmiş fatura manuel inceleme için bekletiliyor:** Laravel günlüklerinde `Manual review` kayıtlarını kontrol edin — ya ödeme sayfası oluşturulduktan sonra fatura tutarı değişmiştir ya da yenileme öncesinde mevcut bir Paymos faturasının kapatıldığı doğrulanamamıştır.

## Bağlantılar ve Lisans

- [Dokümantasyon](https://paymos.io/tr/docs/cms-paymenter)
- [Kaynak kodu ve sorunlar](https://github.com/Paymos-labs/paymenter)
- [Değişiklik günlüğü](CHANGELOG.md)
- [Destek](mailto:support@paymos.io)
- Lisans: [MIT](LICENSE) — © 2026 Paymos Labs
