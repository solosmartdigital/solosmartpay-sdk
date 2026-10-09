# Solosmartpay Go SDK

Go ≥ 1.21, standard library only. Server-side use only.

```bash
go get github.com/solosmartdigital/solosmartpay-sdk/go
```

```go
import solosmartpay "github.com/solosmartdigital/solosmartpay-sdk/go"
```

The module lives in the `go/` folder of the SDK repo, so versions are tagged `go/v1.2.0`, `go/v1.2.1`, and so on.

## Create, wait, show

```go
ssp, err := solosmartpay.New(os.Getenv("SOLOSMARTPAY_TOKEN"), "https://YOUR-SOLOSMARTPAY-DOMAIN/v1")
if err != nil { log.Fatal(err) }

qris, err := ssp.Qris.Create(ctx, solosmartpay.QrisParams{Amount: 50000, Reference: "ORDER-123", IdempotencyKey: "order-123-create"})
var apiErr *solosmartpay.Error
if errors.As(err, &apiErr) { log.Println(apiErr.Status, apiErr.Message) }

qris, err = ssp.Qris.WaitUntilReady(ctx, qris.ID, nil) // polls until the checkout is available

fmt.Println(qris.TotalAmount) // 50127 (amount + unique code)
fmt.Println(*qris.EmbedURL)   // give this to the browser: openCheckout(embedUrl)
fmt.Println(*qris.QrisImage)  // PNG data URI for a custom UI
```

Same flow for virtual account, e-wallet, and retail:

```go
method, _ := ssp.Methods.Find(ctx, "BR")      // *PaymentMethod or nil
ok := method.Accepts(150000)                  // inside the method's limits?

bill, _ := ssp.Payments.Create(ctx, solosmartpay.PaymentParams{Amount: 150000, Method: "BR", Reference: "ORDER-124", IdempotencyKey: "order-124-create"}) // or "all"
bill, _ = ssp.Payments.WaitUntilReady(ctx, bill.ID, &solosmartpay.WaitOptions{Timeout: time.Minute})

ewallets, _ := ssp.Methods.All(ctx, solosmartpay.GroupEwallet)
_, _ = ssp.Qris.Cancel(ctx, qris.ID)          // while still pending
```

`Invoice` (also named `Qris` and `Payment`) has `IsPending()`, `IsPaid()`, `IsExpired()`, `IsCancelled()`, `IsReady()`, and `StatusURL`. Pointer fields are `nil` when the API returns `null`; dates are `*time.Time`.

## Webhook

```go
http.HandleFunc("/webhook/solosmartpay", func(w http.ResponseWriter, r *http.Request) {
    raw, _ := io.ReadAll(r.Body)
    ev, err := solosmartpay.VerifyWebhook(raw, r.Header.Get("X-SoloSmartPay-Signature"), r.Header.Get("X-SoloSmartPay-Timestamp"), os.Getenv("SOLOSMARTPAY_WEBHOOK_SECRET"), 0)
    if err != nil { w.WriteHeader(http.StatusUnauthorized); return }
    if ev.IsPaid() {
        // match *ev.Data.Reference and ev.Data.Amount to your order; dedupe on ev.ID
    }
    w.WriteHeader(http.StatusOK)
})
```

## Notes

- Creation returns `202`; use `WaitUntilReady` (or poll `StatusURL`) before showing the checkout. Reuse an idempotency key only with the same request body.
- The flat helpers (`CreateQris`, `GetQris`, `CancelQris`, `CreatePayment`, `GetPayment`, `CancelPayment`, `PaymentMethods`) remain. `ProvisionQris` and its `quote_id` are removed: the API no longer has a quote step. `Invoice` dates are now `*time.Time` and `WebhookEvent.Data` is an `Invoice`.
- Run tests with `go test ./...`.
