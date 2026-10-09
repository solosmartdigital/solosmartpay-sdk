# Solosmartpay Go SDK

Go ≥ 1.21, standard library only. Server-side use only.

```bash
go get github.com/solosmartpay/solosmartpay-go
```

```go
ssp, err := solosmartpay.New(os.Getenv("SOLOSMARTPAY_TOKEN"), "https://YOUR-SOLOSMARTPAY-DOMAIN/v1")
if err != nil { log.Fatal(err) }

qris, err := ssp.CreateQris(ctx, 50000, "ORDER-123", "order-123-create")
var apiErr *solosmartpay.Error
if errors.As(err, &apiErr) { log.Println(apiErr.Status, apiErr.Message) }
fmt.Println(qris.StatusURL) // poll until qris.Data.ProvisioningStatus is ready

inv, _ := ssp.GetQris(ctx, qris.Data.ID)

methods, _ := ssp.PaymentMethods(ctx)
bill, _ := ssp.CreatePayment(ctx, 150000, "BR", "ORDER-124", "order-124-create")
```

## Webhook

```go
http.HandleFunc("/webhook/solosmartpay", func(w http.ResponseWriter, r *http.Request) {
    raw, _ := io.ReadAll(r.Body)
    ev, err := solosmartpay.VerifyWebhook(raw, r.Header.Get("X-SoloSmartPay-Signature"), r.Header.Get("X-SoloSmartPay-Timestamp"), os.Getenv("SOLOSMARTPAY_WEBHOOK_SECRET"), 0)
    if err != nil { w.WriteHeader(http.StatusUnauthorized); return }
    if ev.Type == "payment.paid" {
        // mark order paid — dedupe on ev.ID
    }
    w.WriteHeader(http.StatusOK)
})
```

Provisioned creates return `202`, `data`, and `status_url`; poll the URL until `data.provisioning_status` is `ready`. QRIS uses the same asynchronous provisioning flow as other channels. Reuse an idempotency key only with the same request body.
