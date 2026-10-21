# Solosmartpay untuk Laravel

Terima pembayaran **QRIS, Virtual Account, e-wallet, dan retail** dari aplikasi Laravel tanpa menulis kode API sendiri.
Install lewat Composer, isi token, selesai. Package ini mengurus panggilan API, idempotency, retry, popup checkout,
dan penerimaan webhook yang sudah terverifikasi.

- Laravel 10, 11, 12, dan 13. PHP 8.1 atau lebih baru.
- Satu baris untuk membuat tagihan: `Solosmartpay::createQris(50000, 'ORDER-123')`.
- Tombol bayar siap pakai (popup) lewat komponen Blade.
- Webhook otomatis: rute sudah terpasang, tanda tangan HMAC diperiksa, dan hasilnya jadi event Laravel biasa.
- Aman diulang: request yang sama tidak membuat tagihan kedua.
- `Solosmartpay::fake()` untuk tes aplikasi Anda tanpa jaringan.

## Daftar isi

1. [Instalasi](#instalasi)
2. [Mulai cepat](#mulai-cepat)
3. [Membuat tagihan](#membuat-tagihan)
4. [Menampilkan pembayaran ke pelanggan](#menampilkan-pembayaran-ke-pelanggan)
5. [Menerima hasil pembayaran (webhook)](#menerima-hasil-pembayaran-webhook)
6. [Mengecek, membatalkan, dan memuat ulang tagihan](#mengecek-membatalkan-dan-memuat-ulang-tagihan)
7. [Metode pembayaran](#metode-pembayaran)
8. [Penanganan error](#penanganan-error)
9. [Menguji aplikasi Anda](#menguji-aplikasi-anda)
10. [Konfigurasi](#konfigurasi)
11. [Keamanan](#keamanan)
12. [Pemecahan masalah](#pemecahan-masalah)

## Instalasi

```bash
composer require solosmartdigital/solosmartpay
php artisan solosmartpay:install
```

Perintah kedua menerbitkan `config/solosmartpay.php` dan mencetak langkah berikutnya. Package terdaftar otomatis
(auto-discovery), tidak ada yang perlu ditambahkan ke `config/app.php`.

Isi `.env`:

```dotenv
SOLOSMARTPAY_TOKEN=ssp_xxxxxxxxxxxxxxxx
SOLOSMARTPAY_WEBHOOK_SECRET=xxxxxxxxxxxxxxxx
```

- **Token**: dashboard Solosmartpay, menu *Developer / API Tokens*. Token hanya dipakai di server.
- **Webhook secret**: menu *Developer / API & Webhook*, setelah Anda mengisi URL webhook (lihat di bawah).

Cek bahwa semuanya terhubung:

```bash
php artisan solosmartpay:check
```

```
API: https://solosmartpay.com/v1
Token valid. 3 metode bank/e-wallet aktif untuk toko Anda.
Webhook secret terisi. URL: https://toko-anda.com/solosmartpay/webhook
```

## Mulai cepat

Membuat tagihan QRIS dan mengarahkan pelanggan ke halaman bayar:

```php
use SolosmartDigital\Solosmartpay\Facades\Solosmartpay;

class CheckoutController extends Controller
{
    public function pay(Order $order)
    {
        $invoice = Solosmartpay::createQris(
            amount: $order->total,                 // rupiah, bilangan bulat
            reference: $order->number,             // nomor pesanan Anda
            redirectUrl: route('orders.thanks', $order),
        );

        return redirect($invoice->checkoutUrl);
    }
}
```

Lalu tandai pesanan lunas saat webhook datang. Buat listener (Laravel menemukannya otomatis dari type-hint):

```php
namespace App\Listeners;

use SolosmartDigital\Solosmartpay\Events\PaymentPaid;

class MarkOrderPaid
{
    public function handle(PaymentPaid $event): void
    {
        $order = Order::where('number', $event->invoice->reference)->firstOrFail();

        if ($order->isPaid()) {
            return;
        }

        $order->markPaid(paidAt: $event->invoice->paidAt);
    }
}
```

Hanya itu. Rute webhook, pemeriksaan tanda tangan, dan perlindungan dari pengiriman ganda sudah ditangani package.

Daftarkan URL webhook di dashboard: `https://toko-anda.com/solosmartpay/webhook`.

## Membuat tagihan

### QRIS

```php
$invoice = Solosmartpay::createQris(
    amount: 50000,
    reference: 'ORDER-123',     // opsional, tetapi sangat disarankan
    redirectUrl: 'https://toko-anda.com/terima-kasih',   // opsional, wajib https
);

$invoice->totalAmount;   // 50127: nominal + kode unik 3 digit yang harus dibayar pelanggan
$invoice->uniqueCode;    // 127
$invoice->qrisImage;     // data URI PNG, siap dipakai di <img src="...">
$invoice->qrisString;    // payload QRIS mentah
$invoice->checkoutUrl;   // halaman bayar milik Solosmartpay
$invoice->expiresAt;     // Carbon
```

Nominal minimum QRIS adalah Rp1.000.

### Virtual Account, e-wallet, retail

```php
// Pelanggan memilih sendiri metodenya di halaman bayar:
$invoice = Solosmartpay::createPayment(150000, reference: 'ORDER-124');

// Atau tentukan metodenya (kode dari Solosmartpay::methods()):
$invoice = Solosmartpay::createPayment(150000, 'BC', reference: 'ORDER-124');
```

### Idempotency: aman diulang

Request dikirim dengan `Idempotency-Key`. Kalau Anda memberi `reference`, kunci dibuat otomatis dari isi request,
jadi memanggil `createQris(50000, 'ORDER-123')` dua kali (tombol diklik dua kali, request diulang setelah timeout)
mengembalikan **tagihan yang sama**, bukan tagihan baru.

- `reference` yang sama dengan nominal berbeda ditolak dengan 409.
- Tanpa `reference`, tidak ada yang bisa dijadikan patokan, jadi setiap panggilan membuat tagihan baru.
- Untuk kendali penuh, kirim kunci sendiri: `createQris(50000, 'ORDER-123', idempotencyKey: 'order-123-attempt-1')`.
  Kunci terdiri dari 8 sampai 100 karakter: huruf, angka, titik, garis, atau titik dua.

### Menunggu tagihan siap

Tagihan disiapkan secara asinkron (HTTP 202). Secara bawaan package menunggu sampai siap, paling lama 10 detik,
supaya QR dan URL bayar langsung bisa dipakai. Ubah di config (`wait_until_ready`, `ready_timeout`) atau per panggilan:

```php
$invoice = Solosmartpay::createQris(50000, 'ORDER-123', wait: false);   // langsung kembali
$invoice->isProvisioning();                                             // true selama masih disiapkan

$invoice = Solosmartpay::waitUntilReady($invoice, timeout: 15);         // tunggu belakangan
```

Kalau melewati batas waktu, Anda tetap mendapat tagihan terakhir; panggil `Solosmartpay::refresh($invoice)` nanti.

## Menampilkan pembayaran ke pelanggan

Ada tiga cara. Pilih yang paling cocok.

### 1. Tombol popup (disarankan)

```blade
<x-solosmartpay::pay-button :invoice="$invoice" class="btn btn-primary">
    Bayar Rp {{ number_format($invoice->totalAmount, 0, ',', '.') }}
</x-solosmartpay::pay-button>
```

Komponen ini memuat `embed.js` satu kali, membuka checkout sebagai popup di halaman Anda, dan kembali ke halaman penuh
kalau `embed.js` diblokir. Atribut lain (`class`, `id`, dan seterusnya) diteruskan ke `<button>`.

| Atribut | Fungsi |
|---|---|
| `:invoice` | Tagihan dari `createQris()` atau `createPayment()` (wajib) |
| `label` | Teks tombol bila tidak ada isi slot (bawaan: "Bayar sekarang") |
| `lang` | `id` atau `en`; bawaan mengikuti bahasa browser |
| `on-success` | Nama fungsi JavaScript global yang dipanggil setelah pembayaran terkonfirmasi |

Tombol juga memicu event DOM `solosmartpay:paid` dan `solosmartpay:closed`:

```blade
<script>
    document.addEventListener('solosmartpay:paid', () => window.location.href = '/pesanan/terima-kasih');
</script>
```

> Callback di browser hanya untuk tampilan. **Penuhi pesanan dari webhook**, bukan dari `on-success`.

### 2. Halaman bayar penuh

```php
return redirect($invoice->checkoutUrl);
```

### 3. Tampilkan QR sendiri (QRIS)

```blade
<img src="{{ $invoice->qrisImage }}" alt="QRIS">
<p>Bayar tepat Rp {{ number_format($invoice->totalAmount, 0, ',', '.') }}</p>
```

Nominal harus dibayar persis, termasuk kode uniknya.

## Menerima hasil pembayaran (webhook)

Package mendaftarkan `POST /solosmartpay/webhook` (ubah dengan `SOLOSMARTPAY_WEBHOOK_PATH`). Setiap pengiriman:

1. diperiksa tanda tangan HMAC-SHA256 dan umurnya (maksimal 5 menit, melawan replay);
2. dibuang kalau id event-nya sudah pernah diproses (pengiriman Solosmartpay bersifat *at-least-once*);
3. diubah menjadi event Laravel.

| Event | Kapan | Kelas |
|---|---|---|
| `payment.paid` | Pembayaran lunas | `Events\PaymentPaid` |
| `invoice.expired` | Pelanggan tidak membayar sampai batas waktu | `Events\InvoiceExpired` |
| `invoice.failed` | Solosmartpay gagal menyiapkan tagihan | `Events\InvoiceFailed` |
| `invoice.cancelled` | Tagihan dibatalkan | `Events\InvoiceCancelled` |
| semua tipe | Setiap webhook valid, termasuk tipe baru di masa depan | `Events\WebhookReceived` |

Setiap event membawa `$event->invoice` (objek `Invoice`) dan `$event->event` (payload lengkap: `id`, `type`, `created_at`, `data`).

```php
use SolosmartDigital\Solosmartpay\Events\{PaymentPaid, InvoiceExpired};

class FulfilOrder
{
    public function handle(PaymentPaid $event): void
    {
        $invoice = $event->invoice;

        $order = Order::where('number', $invoice->reference)->firstOrFail();

        // Pastikan nominalnya cocok dengan pesanan Anda.
        abort_if($invoice->amount !== $order->total, 422);

        $order->markPaid($invoice->paidAt);
    }
}
```

Untuk Laravel 10 yang tidak menemukan listener otomatis, daftarkan di `EventServiceProvider`:

```php
protected $listen = [
    \SolosmartDigital\Solosmartpay\Events\PaymentPaid::class => [\App\Listeners\FulfilOrder::class],
];
```

Listener boleh antre dengan `implements ShouldQueue`.

**Jika listener melempar exception**, webhook dibalas 500 dan Solosmartpay mengirim ulang (sampai 8 kali dengan jeda yang makin panjang), jadi pesanan tidak hilang.
Jadikan listener Anda idempoten (cek apakah pesanan sudah lunas) karena bisa dipanggil lebih dari sekali.

### Pengaturan webhook

```php
// config/solosmartpay.php
'webhook' => [
    'enabled' => true,
    'secret' => env('SOLOSMARTPAY_WEBHOOK_SECRET'),
    'path' => 'solosmartpay/webhook',
    'middleware' => ['throttle:120,1'],   // tambahan; jangan masukkan "web" atau CSRF
    'tolerance' => 300,
    'dedupe_ttl' => 604800,
],
```

Rute ini sengaja tidak berada di grup `web`: permintaan server-ke-server tidak punya session maupun token CSRF.

Gunakan cache yang dipakai bersama semua server (Redis, database) di produksi, supaya pemeriksaan duplikat bekerja
di semua instance.

Memverifikasi webhook di tempat lain (misalnya di rute sendiri):

```php
use SolosmartDigital\Solosmartpay\Webhook;

$event = Webhook::verify(
    $request->getContent(),
    $request->header('X-SoloSmartPay-Signature', ''),
    $request->header('X-SoloSmartPay-Timestamp', ''),
    config('solosmartpay.webhook.secret'),
);   // melempar InvalidWebhookException bila tidak valid
```

## Mengecek, membatalkan, dan memuat ulang tagihan

```php
$invoice = Solosmartpay::findQris($id);          // atau findPayment($id)
$invoice = Solosmartpay::find($id);              // tidak tahu jenisnya; null bila tidak ada
$invoice = Solosmartpay::refresh($invoice);      // muat ulang

$invoice->isPending();    $invoice->isPaid();      $invoice->isExpired();
$invoice->isCancelled();  $invoice->isFailed();    $invoice->isReady();

Solosmartpay::cancelQris($id);                   // atau cancelPayment($id)
```

Membatalkan tagihan yang sudah lunas atau kedaluwarsa melempar `SolosmartpayException` dengan status 409.

## Metode pembayaran

```php
foreach (Solosmartpay::methods() as $method) {
    $method->code;            // "BC"
    $method->name;            // "BCA Virtual Account"
    $method->group;           // va, ewallet, retail, paylater
    $method->feeFor(150000);  // biaya dalam rupiah untuk nominal ini
    $method->minAmount;       // batas minimal
}
```

## Penanganan error

Semua kegagalan API melempar `SolosmartDigital\Solosmartpay\Exceptions\SolosmartpayException`:

```php
use SolosmartDigital\Solosmartpay\Exceptions\SolosmartpayException;

try {
    $invoice = Solosmartpay::createQris($order->total, $order->number);
} catch (SolosmartpayException $e) {
    $e->getMessage();     // pesan dari API
    $e->status;           // kode HTTP; 0 bila tidak bisa terhubung
    $e->errors;           // ['amount' => ['...']] untuk error validasi

    if ($e->isValidation())   { /* 422: data tidak valid */ }
    if ($e->isConflict())     { /* 409: reference sudah dipakai untuk tagihan lain */ }
    if ($e->isUnauthorized()) { /* 401/403: token salah atau scope kurang */ }
}
```

Request baca dan request tulis yang membawa `Idempotency-Key` otomatis diulang pada error jaringan, 429, dan 5xx
(2 kali, dengan jeda yang makin panjang). Error 4xx tidak diulang.

## Menguji aplikasi Anda

Tanpa jaringan dan tanpa token asli:

```php
use SolosmartDigital\Solosmartpay\Facades\Solosmartpay;

public function test_checkout_redirects_to_the_payment_page(): void
{
    Solosmartpay::fake();

    $this->post('/checkout', ['order' => 1])->assertRedirect('https://pay.test/pay/fake-qris-id');
}
```

`fake()` mengembalikan tagihan yang sudah siap. Ubah isinya bila perlu: `Solosmartpay::fake(['status' => 'paid'])`.

Mensimulasikan webhook masuk ke aplikasi Anda:

```php
use SolosmartDigital\Solosmartpay\Webhook;

public function test_paid_webhook_marks_the_order_paid(): void
{
    config(['solosmartpay.webhook.secret' => 'secret-tes']);

    $body = json_encode([
        'id' => 'evt_1', 'type' => 'payment.paid', 'created_at' => now()->toIso8601String(),
        'data' => ['id' => 'inv-1', 'reference' => 'ORDER-123', 'status' => 'paid', 'channel' => 'qris',
                   'amount' => 50000, 'total_amount' => 50127],
    ]);
    $timestamp = time();

    $this->call('POST', '/solosmartpay/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SOLOSMARTPAY_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SOLOSMARTPAY_SIGNATURE' => Webhook::sign($body, 'secret-tes', $timestamp),
    ], $body)->assertOk();

    $this->assertTrue(Order::where('number', 'ORDER-123')->first()->isPaid());
}
```

Atau pakai `Event::fake([PaymentPaid::class])` kalau yang diuji adalah listener Anda.

## Mode sandbox

Token berawalan `ssp_test_` adalah **token sandbox**: ia hanya membuat transaksi uji. Tidak ada uang sungguhan, KYC tidak diperlukan, dan saldo serta laporan Anda tidak terpengaruh. Endpoint, batas nominal, dan bentuk respons sama dengan produksi.

```php
Solosmartpay::isSandbox();                       // true

$invoice = Solosmartpay::createQris(50000, 'ORDER-123');
$invoice->isSandbox();                           // true

Solosmartpay::simulate($invoice, 'paid');        // atau 'failed' / 'expired'
```

Hasil simulasi dikirim ke webhook Anda dengan tanda sandbox. Di listener, **jangan** memenuhi pesanan asli dari event sandbox:

```php
public function handle(PaymentPaid $event): void
{
    if ($event->isSandbox()) {
        return; // atau tandai pesanan uji
    }
    // ...
}
```

`simulate()` melempar `SolosmartpayException` bila tokennya live. `php artisan solosmartpay:check` menampilkan mode yang sedang dipakai.

## Konfigurasi

| Kunci `.env` | Bawaan | Keterangan |
|---|---|---|
| `SOLOSMARTPAY_TOKEN` | kosong | Token API (`ssp_…`) |
| `SOLOSMARTPAY_BASE_URL` | `https://solosmartpay.com/v1` | Alamat API; `embed.js` dan halaman bayar dilayani dari domain yang sama |
| `SOLOSMARTPAY_WEBHOOK_SECRET` | kosong | Secret penanda tangan webhook |
| `SOLOSMARTPAY_WEBHOOK_PATH` | `solosmartpay/webhook` | Alamat rute webhook |
| `SOLOSMARTPAY_WEBHOOK_ENABLED` | `true` | Matikan bila Anda memasang rute sendiri |
| `SOLOSMARTPAY_TIMEOUT` | `20` | Batas waktu request (detik) |
| `SOLOSMARTPAY_RETRIES` | `2` | Jumlah pengulangan |
| `SOLOSMARTPAY_WAIT_UNTIL_READY` | `true` | Tunggu tagihan siap setelah dibuat |
| `SOLOSMARTPAY_READY_TIMEOUT` | `10` | Batas tunggu (detik) |

Menyesuaikan tampilan tombol: `php artisan vendor:publish --tag=solosmartpay-views`.

## Keamanan

- **Token hanya di server.** Jangan taruh di JavaScript, aplikasi mobile, atau repositori. Pakai `.env`.
- **Webhook adalah sumber kebenaran.** Penuhi pesanan dari `PaymentPaid`, bukan dari callback di browser atau dari
  halaman `redirectUrl`, karena keduanya bisa dipalsukan oleh pelanggan.
- **Cocokkan nominal** (`$invoice->amount`) dengan pesanan Anda sebelum menandainya lunas.
- Webhook tanpa tanda tangan yang benar, atau lebih tua dari 5 menit, ditolak dengan 400 dan tidak memicu event apa pun.
- `embed.js` hanya membuka checkout dari domain tempat script itu dimuat. Jangan menyalin `embed.js` ke server Anda.

## Pemecahan masalah

| Gejala | Penyebab dan solusi |
|---|---|
| `SOLOSMARTPAY_TOKEN belum diisi` | Isi `.env`, lalu `php artisan config:clear` bila config sudah di-cache. |
| Status 401 atau 403 | Token salah, dicabut, atau tidak punya scope `qris:write` / `payments:write` / `read`. Jalankan `solosmartpay:check`. |
| Webhook dibalas 400 | `SOLOSMARTPAY_WEBHOOK_SECRET` salah, atau jam server melenceng lebih dari 5 menit. |
| Webhook tidak pernah datang | URL di dashboard salah, atau server tidak bisa diakses dari internet. Solosmartpay hanya memakai HTTPS port 443. |
| Event tidak jalan setelah `config:cache` | Jalankan `php artisan config:clear`, lalu `config:cache` lagi. |
| Popup tidak terbuka | `embed.js` diblokir (ekstensi iklan); tombol otomatis membuka halaman bayar penuh. |
| `checkoutUrl` bernilai `null` | Tagihan belum siap. Panggil `Solosmartpay::waitUntilReady($invoice)`. |

## Versi dan lisensi

Semantic Versioning. Lihat [CHANGELOG.md](CHANGELOG.md). Lisensi [MIT](LICENSE).

Masalah atau saran: <https://github.com/solosmartdigital/solosmartpay-sdk/issues>
