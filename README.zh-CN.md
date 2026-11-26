# Paymos Paymenter 官方稳定币支付扩展

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

适用于 **Paymenter v1.x**（Laravel / Filament）的 Paymos 官方支付网关扩展。

支持在 13 条主网接收 **4 种稳定币（`USDT`、`USDC`、`USD1`、`DAI`）** 以及 **黄金锚定代币 `XAUT`** 收款。链上转账确认后，网关通过 `ExtensionHelper::addPayment()` 原生记账，Paymenter 自动将账单标记为已付款，并触发您的自动开通与业务自动化规则。

[![观看演示：12 款官方 CMS 稳定币支付插件 — 一键连接 (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **视频演示（1:12，支持 6 种语言字幕）：** [在 YouTube 上观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- Paymenter v1.x（Laravel / Filament）
- PHP 8.2+，启用 `curl`、`hash`、`json`、`openssl` 和 `pdo` 扩展
- 使用 **HTTPS** 的面板地址（`APP_URL`）——拒绝通过明文 HTTP 连接
- 已注册 Paymos 账户，并在 Paymos 控制台中选中目标项目

在 Paymenter 服务器上无需执行 Composer 安装：发行包已将 `paymos/php-sdk` 内置于 `Paymos/vendor/paymos/php-sdk/`。后台管理与收银台提示内置 6 种语言：英文（`en`）、俄文（`ru`）、德文（`de`）、西班牙文（`es`）、土耳其文（`tr`）和简体中文（`zh-Hans`）。

## 安装与一键连接

1. 前往 [GitHub Releases](https://github.com/Paymos-labs/paymenter/releases/latest) 下载最新 `paymos-paymenter.zip` 安装包。
2. 将压缩包解压到 Paymenter 的 `extensions/Gateways/` 目录，保持如下目录结构：

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

3. 如果启用了路由或视图缓存，请清理 Laravel 缓存：

   ```bash
   php artisan route:clear
   php artisan view:clear
   php artisan config:clear
   ```

4. 在 **Paymenter Admin → Extensions → Gateways** 中启用 **Paymos**。
5. 在 Paymos 控制台中打开目标项目，随后进入 Paymenter 的 Paymos 网关设置，点击**连接 Paymos**（Connect Paymos），并在弹出的 Paymos 授权标签页中批准此次安装。

所有商家下载的压缩包完全一致，不包含任何内置凭证、项目 ID 或密钥。一次授权即可原子化完成**沙盒**（Sandbox）与**正式模式**（Live）两套环境的配置，并自动注册当前 Paymenter 站点的签名 Webhook 端点：

```text
https://<your-paymenter-panel>/extensions/gateways/paymos/webhook
```

连接完成后，网关设置页仅展示**连接**（连接 / 重新连接状态）、**模式**（`Sandbox` 或 `Live`）和**按钮文字**三项——绝不提供手动输入 API 密钥、API Secret、项目 ID 或 Webhook 密钥的字段。

## 密钥安全存储

配置好的沙盒与正式环境凭证，以及临时的设备授权流程状态，都会通过认证加密的 **AES-256-GCM** 封装（`AesGcmEnvelope`）加密保护，密钥派生自 Laravel 的 `config('app.key')`，并存储在 `paymos_settings` 中。任何密钥都不会输出到 HTML 页面、日志或错误信息中。如果更换了面板地址、吊销了 Payment 密钥或轮换了 Webhook 密钥，重新执行连接即可。

## 结账与账单生命周期

- **防崩溃账单创建：** 网关在发送 `POST /v1/invoices` 之前先持久化一条 `creating` 意图记录；如果请求响应在网络或进程异常时丢失，下次加载会以完全相同的 `external_order_id`、金额、币种、项目和环境重放请求，避免产生重复账单。
- **明细项与待付余额：** 结账时优先读取 `$invoice->remaining`（确保已部分抵扣或部分支付的账单只收取精确余款），并从 `$invoice->items` 中校验并透传最多 50 条商品明细作为 `products[]`；若明细不可用或校验不通过，则平滑回退为纯金额结账。
- **安全阻断式账单替换（Fail-closed）：** 当应付金额、币种、模式或项目变更，或上一张 Paymos 账单过期时，网关会先调用 `InvoiceReplacement::close()` 关闭旧账单，再生成新账单。如果旧账单已付款、仍处于可支付状态、无法连接或返回 404，系统会阻断替换并转入人工审核，防止买家对同一笔账单重复支付。
- **签名 Webhook 与定时对账：**
  - 发送至 `/extensions/gateways/paymos/webhook` 的入站 Webhook 由 `MultiEnvironmentWebhookVerifier` 在沙盒与正式环境之间统一校验（恒定时间 HMAC-SHA256 签名验证、双 `v1=` 密钥轮换宽限、300 秒时间戳窗口），并通过归属围栏式（owner-fenced）预留机制去重（已提交的重复事件返回 `200`，并发处理中的投递返回 `409`，签名无效或过期返回 `401`）。
  - 所有终态事件都会执行实时反向校验（`GET /v1/invoices/{id}`）、`AmountGuard` 三角金额核验（金额不符时标记 `manual_review` 并返回 HTTP `400`）以及防回滚保护，确保已付或已终结的账单绝不会被降级或重复入账。
  - `invoice.paid_late` 严格按幂等备注 + `paid_late_noted` 标记处理，不会自动入账。
  - `Paymos::boot()` 注册了一个每 10 分钟运行一次的 Laravel `Schedule` 计划任务（限频为每 600 秒最多执行一次），用于扫描过去 24 小时内最多 50 笔未付 Paymos 账单，并同步对账处理中的退款。

## 退款

扩展通过 Paymos Refunds API（`/v1/refunds`）支持由服务端计算金额的 `full`（全额）与 `overpayment`（`paid_over` 多付部分）退款，资金严格原路退回原始入账时记录并校验的 `first_sender` 地址（任何自定义部分金额或手动指定的收款地址在发起 API 请求前即会被拦截）：

- **管理员账单页 Paymos 退款面板：** 通过 Filament 渲染钩子向拥有 `admin.invoices.update` 权限的管理员展示。面板实时呈现操作状态，支持带持久化 UUIDv4 `request_token` 幂等键和 CSRF 防护的 `full` 与（针对 `paid_over` 账单的）`overpayment` 退款操作，并通过 `RefundDiscovery` 自动发现直接在 Paymos 控制台发起的退款。
- **异步安全的原生 `Gateway::refund()`：** 由于 Paymenter 的 `ExtensionHelper::refund()` 会在 `Gateway::refund()` 返回 `true` 时立即同步调用 `ExtensionHelper::recordRefund()`，因此在 Paymos 退款处于 `pending`、`processing` 或 `unknown` 状态时，`Paymos::refund()` 绝不会提前返回 `true`。待 Paymos 确认退款达到 `completed`（经签名 `withdrawal.completed` Webhook 或定时对账确认）后，`RefundAdapter::applyCompleted()` 会精准执行一次原生入账：对 `full` 退款调用 `ExtensionHelper::refundLocally()`，对 `overpayment` 退款则记录备注并保持账单为已付款状态。

## 故障排查

- **点击连接后无法启动：** 请确认 `APP_URL` 以 `https://` 开头，且您的管理员账户拥有 `admin.gateways.update` 权限。
- **退款接口返回 HTTP 403：** 连接相关接口需要 `admin.gateways.update` 权限，退款相关接口需要 `admin.invoices.update` 权限；普通客户账户访问时按设计返回 HTTP `403`。
- **已付款账单被挂起等待人工审核：** 请查看 Laravel 日志中的 `Manual review` 条目——通常是因为创建收银台后账单金额发生了变更，或者在替换旧账单前无法确认旧 Paymos 账单已关闭。

## 相关链接与许可证

- [开发文档](https://paymos.io/zh-hans/docs/cms-paymenter)
- [源码与问题反馈](https://github.com/Paymos-labs/paymenter)
- [更新日志](CHANGELOG.md)
- [技术支持](mailto:support@paymos.io)
- 许可证：[MIT](LICENSE) — © 2026 Paymos Labs
