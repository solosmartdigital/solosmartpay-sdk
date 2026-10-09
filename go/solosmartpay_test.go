package solosmartpay

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

type reply struct {
	status int
	body   any
}

type recorded struct {
	method, path, idem, body string
}

// server answers from a queue and records every request.
func server(t *testing.T, queue ...reply) (*Client, *[]recorded) {
	t.Helper()
	var calls []recorded
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		calls = append(calls, recorded{r.Method, r.URL.Path, r.Header.Get("Idempotency-Key"), string(b)})
		if r.Header.Get("X-API-Token") != "ssp_test" {
			t.Errorf("missing token header")
		}
		rep := reply{500, map[string]any{"message": "queue empty"}}
		if len(queue) > 0 {
			rep, queue = queue[0], queue[1:]
		}
		w.WriteHeader(rep.status)
		_ = json.NewEncoder(w).Encode(rep.body)
	}))
	t.Cleanup(srv.Close)
	c, err := New("ssp_test", srv.URL+"/v1/", WithMaxRetries(1))
	if err != nil {
		t.Fatal(err)
	}
	return c, &calls
}

func invoice(over map[string]any) map[string]any {
	base := map[string]any{
		"id": "abc-123", "reference": "ORDER-1", "status": "pending", "provisioning_status": "queued", "channel": "qris",
		"method": "qris", "amount": 50000, "unique_code": 127, "total_amount": 50127, "fee": 350, "net_amount": 49650,
		"expires_at": "2026-09-27T15:15:00+07:00", "paid_at": nil, "created_at": "2026-09-27T15:00:00+07:00",
		"checkout_url": nil, "embed_url": nil, "basic_url": nil, "qris_string": nil, "qris_image": nil, "payment_url": nil,
	}
	for k, v := range over {
		base[k] = v
	}
	return base
}

var ready = map[string]any{
	"provisioning_status": "ready", "embed_url": "https://pay.example.com/pay/abc-123/embed",
	"checkout_url": "https://pay.example.com/pay/abc-123", "qris_image": "data:image/png;base64,AA",
}

func TestQrisCreateReturnsInvoiceWithStatusURL(t *testing.T) {
	c, calls := server(t, reply{202, map[string]any{"data": invoice(nil), "status_url": "https://pay.example.com/v1/qris/abc-123"}})
	q, err := c.Qris.Create(context.Background(), QrisParams{Amount: 50000, Reference: "ORDER-1", IdempotencyKey: "order-1-create"})
	if err != nil {
		t.Fatal(err)
	}
	if q.TotalAmount != 50127 || *q.UniqueCode != 127 || q.StatusURL != "https://pay.example.com/v1/qris/abc-123" || q.IsReady() || !q.IsPending() {
		t.Fatalf("unexpected invoice: %+v", q)
	}
	if q.ExpiresAt == nil || q.ExpiresAt.Year() != 2026 || q.PaidAt != nil {
		t.Fatalf("dates not parsed: %+v", q)
	}
	call := (*calls)[0]
	if call.method != "POST" || call.path != "/v1/qris" || call.idem != "order-1-create" {
		t.Fatalf("bad request: %+v", call)
	}
	var body map[string]any
	_ = json.Unmarshal([]byte(call.body), &body)
	if len(body) != 2 || body["amount"].(float64) != 50000 || body["reference"] != "ORDER-1" {
		t.Fatalf("bad body: %s", call.body)
	}
}

func TestIdempotencyKeyRequired(t *testing.T) {
	c, _ := server(t)
	if _, err := c.Qris.Create(context.Background(), QrisParams{Amount: 5000}); err == nil {
		t.Fatal("expected an error without idempotency key")
	}
	if _, err := c.Payments.Create(context.Background(), PaymentParams{Amount: 5000, Method: "BR"}); err == nil {
		t.Fatal("expected an error without idempotency key")
	}
	if _, err := c.Payments.Create(context.Background(), PaymentParams{Amount: 5000, IdempotencyKey: "order-1-create"}); err == nil {
		t.Fatal("expected an error without method")
	}
}

func TestWaitUntilReady(t *testing.T) {
	c, calls := server(t,
		reply{200, map[string]any{"data": invoice(nil)}},
		reply{200, map[string]any{"data": invoice(ready)}},
	)
	q, err := c.Qris.WaitUntilReady(context.Background(), "abc-123", &WaitOptions{Interval: time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	if !q.IsReady() || *q.EmbedURL != "https://pay.example.com/pay/abc-123/embed" || *q.QrisImage != "data:image/png;base64,AA" || len(*calls) != 2 {
		t.Fatalf("unexpected: %+v calls=%d", q, len(*calls))
	}
}

func TestWaitUntilReadyFailureAndTimeout(t *testing.T) {
	c, _ := server(t, reply{200, map[string]any{"data": invoice(map[string]any{"provisioning_status": "failed", "error_code": "provider_down"})}})
	if _, err := c.Qris.WaitUntilReady(context.Background(), "abc-123", &WaitOptions{Interval: time.Millisecond}); err == nil || !strings.Contains(err.Error(), "provider_down") {
		t.Fatalf("expected provisioning failure, got %v", err)
	}
	c, _ = server(t, reply{200, map[string]any{"data": invoice(nil)}}, reply{200, map[string]any{"data": invoice(nil)}})
	if _, err := c.Qris.WaitUntilReady(context.Background(), "abc-123", &WaitOptions{Timeout: time.Millisecond, Interval: 5 * time.Millisecond}); err == nil || !strings.Contains(err.Error(), "belum siap") {
		t.Fatalf("expected timeout, got %v", err)
	}
}

func TestCancelAndAPIError(t *testing.T) {
	c, calls := server(t,
		reply{200, map[string]any{"data": invoice(map[string]any{"status": "cancelled"})}},
		reply{409, map[string]any{"message": "Tagihan sudah dibayar"}},
	)
	q, err := c.Qris.Cancel(context.Background(), "abc-123")
	if err != nil || !q.IsCancelled() || (*calls)[0].method != "DELETE" {
		t.Fatalf("cancel failed: %v %+v", err, q)
	}
	_, err = c.Payments.Cancel(context.Background(), "abc-123")
	var apiErr *Error
	if !errors.As(err, &apiErr) || apiErr.Status != 409 || apiErr.Message != "Tagihan sudah dibayar" {
		t.Fatalf("expected a 409 *Error, got %v", err)
	}
}

func TestPaymentsAndMethods(t *testing.T) {
	rows := []map[string]any{
		{"code": "BR", "name": "BRI VA", "group": "va", "fee_type": "flat", "fee": 4000, "min_amount": 10000, "max_amount": 5000000},
		{"code": "OV", "name": "OVO", "group": "ewallet", "fee_type": "percent", "fee": 1.5, "min_amount": 1000, "max_amount": nil},
	}
	pay := invoice(map[string]any{"channel": "bank_transfer", "method": "BR", "payment_url": "https://gw.example/pay/1", "provisioning_status": "ready", "embed_url": "https://pay.example.com/pay/abc-123/embed"})
	c, calls := server(t,
		reply{202, map[string]any{"data": pay, "status_url": "u"}},
		reply{200, map[string]any{"data": rows}},
		reply{200, map[string]any{"data": rows}},
		reply{200, map[string]any{"data": rows}},
		reply{200, map[string]any{"data": rows}},
	)
	p, err := c.Payments.Create(context.Background(), PaymentParams{Amount: 150000, Method: "BR", IdempotencyKey: "order-2-create"})
	if err != nil || *p.PaymentURL != "https://gw.example/pay/1" || !p.IsReady() || *p.Method != "BR" {
		t.Fatalf("payment: %v %+v", err, p)
	}
	if strings.Contains((*calls)[0].body, "reference") {
		t.Fatalf("empty reference must be omitted: %s", (*calls)[0].body)
	}
	all, _ := c.Methods.All(context.Background())
	if len(all) != 2 || !all[0].IsVirtualAccount() || !all[1].IsEwallet() {
		t.Fatalf("methods: %+v", all)
	}
	if !all[0].Accepts(10000) || all[0].Accepts(9999) || all[0].Accepts(5000001) || !all[1].Accepts(99999999) {
		t.Fatal("limits wrong")
	}
	if m, _ := c.Methods.Find(context.Background(), "br"); m == nil || m.Name != "BRI VA" {
		t.Fatalf("find: %+v", m)
	}
	if va, _ := c.Methods.All(context.Background(), GroupVirtualAccount); len(va) != 1 {
		t.Fatalf("group filter: %+v", va)
	}
}

func TestRetriesGetOn503(t *testing.T) {
	c, calls := server(t, reply{503, map[string]any{"message": "down"}}, reply{200, map[string]any{"data": invoice(nil)}})
	if inv, err := c.GetQris(context.Background(), "abc-123"); err != nil || inv.ID != "abc-123" || len(*calls) != 2 {
		t.Fatalf("retry failed: %v calls=%d", err, len(*calls))
	}
}

func TestLegacyCreateHelpers(t *testing.T) {
	c, _ := server(t, reply{202, map[string]any{"data": invoice(nil), "status_url": "u"}})
	res, err := c.CreateQris(context.Background(), 50000, "ORDER-1", "order-3-create")
	if err != nil || res.StatusURL != "u" || res.Data.StatusURL != "u" {
		t.Fatalf("legacy: %v %+v", err, res)
	}
	if _, err := New("bad", "https://x/v1"); err == nil {
		t.Fatal("token prefix must be enforced")
	}
}

func sign(secret, ts, body string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(ts + "." + body))
	return "sha256=" + hex.EncodeToString(mac.Sum(nil))
}

func TestVerifyWebhook(t *testing.T) {
	secret := "whsec_test"
	body := fmt.Sprintf(`{"id":"evt_1","type":"payment.paid","created_at":"2026-09-27T15:03:12+07:00","data":{"id":"abc-123","reference":"ORDER-1","status":"paid","amount":50000}}`)
	ts := strconv.FormatInt(time.Now().Unix(), 10)
	ev, err := VerifyWebhook([]byte(body), sign(secret, ts, body), ts, secret, 0)
	if err != nil || !ev.IsPaid() || !ev.Data.IsPaid() || *ev.Data.Reference != "ORDER-1" || ev.CreatedAt == nil || string(ev.Raw) != body {
		t.Fatalf("verify: %v %+v", err, ev)
	}
	if _, err := VerifyWebhook([]byte(body+" "), sign(secret, ts, body), ts, secret, 0); err == nil || !strings.Contains(err.Error(), "signature") {
		t.Fatalf("tampering must fail: %v", err)
	}
	old := strconv.FormatInt(time.Now().Add(-time.Hour).Unix(), 10)
	if _, err := VerifyWebhook([]byte(body), sign(secret, old, body), old, secret, 0); err == nil || !strings.Contains(err.Error(), "kedaluwarsa") {
		t.Fatalf("replay must fail: %v", err)
	}
}
