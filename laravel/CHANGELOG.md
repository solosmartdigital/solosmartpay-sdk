# Changelog

Semua perubahan penting dicatat di sini. Format mengikuti [Keep a Changelog](https://keepachangelog.com/) dan
[Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-10-21

### Ditambahkan
- Mode sandbox: token berawalan `ssp_test_` hanya membuat transaksi uji (tanpa uang, tanpa KYC).
  `Solosmartpay::isSandbox()` dan `Solosmartpay::simulate($invoice, 'paid'|'failed'|'expired')` menentukan hasilnya.
- `Invoice::isSandbox()` dan `Invoice::$vaNumber`; event webhook punya `isSandbox()` (juga `WebhookReceived`).
  Jangan memenuhi pesanan asli dari event sandbox.
- `solosmartpay:check` menampilkan mode (Sandbox atau Live).

## [1.0.0] - 2026-10-08

### Ditambahkan
- Klien API: `createQris`, `createPayment`, `findQris`, `findPayment`, `find`, `cancelQris`, `cancelPayment`,
  `refresh`, `waitUntilReady`, `methods`, `ping`.
- `Idempotency-Key` otomatis dan stabil dari `reference`, retry untuk error jaringan, 429, dan 5xx.
- Objek `Invoice` dan `PaymentMethod` bertipe.
- Rute webhook otomatis dengan verifikasi HMAC-SHA256, batas umur 5 menit, dan pembuangan pengiriman ganda.
- Event Laravel: `PaymentPaid`, `InvoiceExpired`, `InvoiceFailed`, `InvoiceCancelled`, `WebhookReceived`.
- Komponen Blade `<x-solosmartpay::pay-button>` untuk checkout popup.
- Perintah `solosmartpay:install` dan `solosmartpay:check`.
- `Solosmartpay::fake()` dan `Webhook::sign()` untuk pengujian.
