# Paymos para Paymenter

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Extensión oficial de pasarela de pago de Paymos para **Paymenter v1.x** (Laravel / Filament).

Acepte **4 stablecoins (`USDT`, `USDC`, `USD1`, `DAI`)** y **`XAUT` respaldado por oro** en 13 redes principales. Cuando una transferencia se confirma en la cadena, la pasarela registra el pago de forma nativa mediante `ExtensionHelper::addPayment()`, para que Paymenter marque la factura como pagada y ejecute automáticamente sus reglas de aprovisionamiento y automatización.

[![Ver vídeo: 12 plugins oficiales de CMS para pagos cripto — Conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Demostración en vídeo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- Paymenter v1.x (Laravel / Filament)
- PHP 8.2+ con las extensiones `curl`, `hash`, `json`, `openssl` y `pdo`
- Una URL de panel con **HTTPS** (`APP_URL`) — la conexión sobre HTTP sin cifrar se rechaza
- Una cuenta de Paymos con el proyecto destino seleccionado en el panel de Paymos

No hace falta ejecutar Composer en el servidor de Paymenter: el paquete de lanzamiento incluye `paymos/php-sdk` en `Paymos/vendor/paymos/php-sdk/`. Los textos del panel de administración y del checkout están disponibles en 6 idiomas: inglés (`en`), ruso (`ru`), alemán (`de`), español (`es`), turco (`tr`) y chino simplificado (`zh-Hans`).

## Instalación y conexión

1. Descargue el último paquete `paymos-paymenter.zip` desde [GitHub Releases](https://github.com/Paymos-labs/paymenter/releases/latest).
2. Extraiga el archivo en el directorio `extensions/Gateways/` de su instalación de Paymenter para obtener esta estructura:

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

3. Limpie las cachés de Laravel si el almacenamiento en caché de rutas o vistas está habilitado:

   ```bash
   php artisan route:clear
   php artisan view:clear
   php artisan config:clear
   ```

4. En **Paymenter Admin → Extensions → Gateways**, active **Paymos**.
5. Abra el proyecto deseado en el panel de Paymos, haga clic en **Conectar Paymos** (Connect Paymos) dentro de los ajustes de la pasarela Paymos en Paymenter y apruebe la instalación en la pestaña de Paymos.

Todos los comercios descargan exactamente el mismo paquete sin credenciales, IDs de proyecto ni secretos embebidos. Una sola aprobación aprovisiona de forma atómica los entornos **Sandbox** y **Producción** (Live) y registra el endpoint de webhook firmado para esta instalación de Paymenter:

```text
https://<your-paymenter-panel>/extensions/gateways/paymos/webhook
```

Una vez conectado, los ajustes de la pasarela muestran únicamente **Conexión** (estado Conectar / Reconectar) y **Modo** (`Sandbox` o `Live`), nunca campos manuales de clave de API, secreto de API, ID de proyecto ni secreto de webhook.

## Almacenamiento de secretos

Las credenciales aprovisionadas de Sandbox y Producción, junto con el estado temporal del flujo de dispositivo, se sellan en un sobre autenticado **AES-256-GCM** (`AesGcmEnvelope`) con clave derivada de `config('app.key')` de Laravel y se guardan en `paymos_settings`. Los secretos nunca se muestran en HTML, registros ni mensajes de error. Vuelva a conectar si cambia la URL de su panel, revoca una clave de pago o rota un secreto de webhook.

## Checkout y ciclo de vida de la factura

- **Creación de facturas resistente a fallos:** la pasarela persiste una intención `creating` antes de enviar `POST /v1/invoices` y vuelve a enviar exactamente el mismo `external_order_id`, importe, moneda, proyecto y entorno si se pierde una respuesta.
- **Líneas de factura y saldo pendiente:** el checkout prioriza `$invoice->remaining` (para que las facturas parcialmente abonadas o parcialmente pagadas cobren el saldo exacto pendiente) y envía hasta 50 líneas validadas de `$invoice->items` en `products[]`, pasando limpiamente a cobro solo por importe si las líneas no están disponibles o no son válidas.
- **Sustitución fail-closed:** cuando cambia el importe pendiente, la moneda, el modo o el proyecto — o cuando vence una factura anterior de Paymos — la pasarela cierra primero la factura anterior mediante `InvoiceReplacement::close()` antes de crear una nueva. Si la factura anterior está pagada, aún es pagable, resulta inalcanzable o devuelve 404, se bloquea la sustitución para revisión manual y así se evita que el comprador pague dos veces.
- **Webhooks firmados y conciliación programada:**
  - Los webhooks entrantes en `/extensions/gateways/paymos/webhook` se verifican entre Sandbox y Producción con `MultiEnvironmentWebhookVerifier` (HMAC-SHA256 en tiempo constante, rotación dual `v1=`, ventana de marca de tiempo de 300 segundos) y se deduplican mediante reservas con control de propietario (`200` en duplicado confirmado, `409` en entrega concurrente en curso, `401` en firma inválida o caducada).
  - Los eventos terminales pasan por verificación inversa en vivo (`GET /v1/invoices/{id}`), triangulación de `AmountGuard` (`manual_review` + HTTP `400` en caso de discrepancia) y protección contra retrocesos para que una factura pagada o finalizada nunca sea degradada ni acreditada dos veces.
  - `invoice.paid_late` se gestiona estrictamente como una nota idempotente junto con la marca `paid_late_noted`, sin acreditar el pago automáticamente.
  - `Paymos::boot()` registra una tarea programada de Laravel `Schedule` cada 10 minutos (limitada como máximo a una ejecución cada 600 segundos) que revisa hasta 50 facturas de Paymos impagadas de las últimas 24 horas y concilia los reembolsos pendientes.

## Reembolsos

La extensión admite reembolsos calculados en el servidor en modo `full` y `overpayment` (`paid_over`) mediante la API de reembolsos de Paymos (`/v1/refunds`), enviando los fondos estrictamente a la dirección verificada `first_sender` registrada en el depósito original (los importes parciales arbitrarios y las direcciones de destino manuales se rechazan antes de cualquier llamada a la API):

- **Panel de reembolsos de Paymos en facturas de administrador:** renderizado mediante hooks de Filament para administradores con el permiso `admin.invoices.update`. Muestra el estado de la operación, ofrece las acciones `full` y (en facturas `paid_over`) `overpayment` con `request_token` UUIDv4 persistente y protección CSRF, y detecta reembolsos creados directamente desde el panel de Paymos (`RefundDiscovery`).
- **`Gateway::refund()` nativo y seguro para flujos asíncronos:** dado que `ExtensionHelper::refund()` en Paymenter invoca de forma síncrona `ExtensionHelper::recordRefund()` cada vez que `Gateway::refund()` devuelve `true`, `Paymos::refund()` nunca devuelve `true` mientras un reembolso de Paymos siga en estado `pending`, `processing` o `unknown`. Una vez que Paymos confirma `completed` (por webhook firmado `withdrawal.completed` o conciliación programada), `RefundAdapter::applyCompleted()` registra el reembolso nativo exactamente una vez mediante `ExtensionHelper::refundLocally()` para reembolsos `full` y guarda una nota (manteniendo la factura como pagada) para reembolsos `overpayment`.

## Solución de problemas

- **La conexión no se inicia:** compruebe que `APP_URL` empieza por `https://` y que su cuenta de administrador tiene el permiso `admin.gateways.update`.
- **Las rutas de reembolso devuelven HTTP 403:** los endpoints de conexión requieren `admin.gateways.update` y los de reembolso requieren `admin.invoices.update`; las cuentas de clientes habituales se rechazan deliberadamente con HTTP `403`.
- **Factura pagada retenida para revisión manual:** revise los logs de Laravel en busca de entradas `Manual review`: o bien el importe de la factura cambió después de crearse el checkout, o bien no se pudo confirmar el cierre de una factura previa de Paymos antes de sustituirla.

## Enlaces y licencia

- [Documentación](https://paymos.io/es/docs/cms-paymenter)
- [Código fuente e incidencias](https://github.com/Paymos-labs/paymenter)
- [Historial de cambios](CHANGELOG.md)
- [Soporte](mailto:support@paymos.io)
- Licencia: [MIT](LICENSE) — © 2026 Paymos Labs
