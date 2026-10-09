// Package solosmartpay is the official Go client for the Solosmartpay payment
// API (QRIS, bank VA, e-wallet). Server-side use only: never ship your ssp_
// token to browsers or mobile apps.
package solosmartpay

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

const Version = "1.0.0"

// Error is returned for any non-2xx response or transport failure.
type Error struct {
	Status  int                 `json:"-"`
	Message string              `json:"message"`
	Errors  map[string][]string `json:"errors"`
}

func (e *Error) Error() string {
	if e.Status == 0 {
		return e.Message
	}
	return fmt.Sprintf("solosmartpay: %d %s", e.Status, e.Message)
}

// Invoice is the common shape of QRIS and bank/e-wallet bills.
type Invoice struct {
	ID                 string  `json:"id"`
	Reference          *string `json:"reference"`
	Status             string  `json:"status"` // pending | paid | expired | failed
	Amount             int64   `json:"amount"`
	UniqueCode         *int    `json:"unique_code"`
	TotalAmount        int64   `json:"total_amount"`
	Fee                int64   `json:"fee"`
	NetAmount          int64   `json:"net_amount"`
	ExpiresAt          *string `json:"expires_at"`
	PaidAt             *string `json:"paid_at"`
	CreatedAt          *string `json:"created_at"`
	CheckoutURL        string  `json:"checkout_url"`
	ProvisioningStatus string  `json:"provisioning_status"`
	// QRIS only (while pending).
	QrisString *string `json:"qris_string,omitempty"`
	QrisImage  *string `json:"qris_image,omitempty"`
	// Bank VA / e-wallet only.
	Method     *string `json:"method,omitempty"`
	PaymentURL *string `json:"payment_url,omitempty"`
}

type PaymentMethod struct {
	Code      string  `json:"code"`
	Name      string  `json:"name"`
	Group     string  `json:"group"`
	FeeType   string  `json:"fee_type"`
	Fee       float64 `json:"fee"`
	MinAmount int64   `json:"min_amount"`
	MaxAmount *int64  `json:"max_amount"`
}

// CreateResult is returned by create calls. StatusURL is present only when provisioning is queued.
type CreateResult struct {
	Data      Invoice `json:"data"`
	StatusURL string  `json:"status_url,omitempty"`
}

type Client struct {
	token      string
	baseURL    string
	httpClient *http.Client
	maxRetries int
}

type Option func(*Client)

func WithHTTPClient(h *http.Client) Option { return func(c *Client) { c.httpClient = h } }
func WithMaxRetries(n int) Option          { return func(c *Client) { c.maxRetries = n } }

// New creates a client. baseURL looks like "https://your-domain/v1".
func New(token, baseURL string, opts ...Option) (*Client, error) {
	if !strings.HasPrefix(token, "ssp_") {
		return nil, errors.New(`solosmartpay: token harus diawali "ssp_"`)
	}
	if baseURL == "" {
		return nil, errors.New("solosmartpay: baseURL wajib diisi")
	}
	c := &Client{token: token, baseURL: strings.TrimRight(baseURL, "/"), httpClient: &http.Client{Timeout: 20 * time.Second}, maxRetries: 2}
	for _, o := range opts {
		o(c)
	}
	return c, nil
}

// CreateQris creates a QRIS quote. idempotencyKey is required.
func (c *Client) CreateQris(ctx context.Context, amount int64, reference, idempotencyKey string) (*CreateResult, error) {
	body := map[string]any{"amount": amount}
	if reference != "" {
		body["reference"] = reference
	}
	var out CreateResult
	return &out, c.do(ctx, http.MethodPost, "/qris", body, idempotencyKey, &out)
}

// ProvisionQris queues a payable QRIS invoice from a quote. idempotencyKey must be new.
func (c *Client) ProvisionQris(ctx context.Context, amount int64, quoteID, idempotencyKey, redirectURL string) (*CreateResult, error) {
	body := map[string]any{"amount": amount, "quote_id": quoteID}
	if redirectURL != "" {
		body["redirect_url"] = redirectURL
	}
	var out CreateResult
	return &out, c.do(ctx, http.MethodPost, "/qris", body, idempotencyKey, &out)
}

func (c *Client) GetQris(ctx context.Context, id string) (*Invoice, error) {
	var out Invoice
	return &out, c.do(ctx, http.MethodGet, "/qris/"+url.PathEscape(id), nil, "", &out)
}

// PaymentMethods lists the bank VA / e-wallet methods enabled for your store.
func (c *Client) PaymentMethods(ctx context.Context) ([]PaymentMethod, error) {
	var out []PaymentMethod
	return out, c.do(ctx, http.MethodGet, "/payment-methods", nil, "", &out)
}

// CreatePayment queues a bank VA / e-wallet bill. idempotencyKey is required.
func (c *Client) CreatePayment(ctx context.Context, amount int64, method, reference, idempotencyKey string) (*CreateResult, error) {
	body := map[string]any{"amount": amount, "method": method}
	if reference != "" {
		body["reference"] = reference
	}
	var out CreateResult
	return &out, c.do(ctx, http.MethodPost, "/payments", body, idempotencyKey, &out)
}

func (c *Client) GetPayment(ctx context.Context, id string) (*Invoice, error) {
	var out Invoice
	return &out, c.do(ctx, http.MethodGet, "/payments/"+url.PathEscape(id), nil, "", &out)
}

// do retries network errors, 429 and 5xx when the call is safe to repeat.
func (c *Client) do(ctx context.Context, method, path string, body any, idempotencyKey string, out any) error {
	retryable := method == http.MethodGet || idempotencyKey != ""
	var payload []byte
	if body != nil {
		var err error
		if payload, err = json.Marshal(body); err != nil {
			return err
		}
	}

	for attempt := 1; ; attempt++ {
		req, err := http.NewRequestWithContext(ctx, method, c.baseURL+path, bytes.NewReader(payload))
		if err != nil {
			return err
		}
		req.Header.Set("Accept", "application/json")
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("X-API-Token", c.token)
		if idempotencyKey != "" {
			req.Header.Set("Idempotency-Key", idempotencyKey)
		}
		req.Header.Set("User-Agent", "solosmartpay-go/"+Version)

		res, err := c.httpClient.Do(req)
		if err != nil {
			if retryable && attempt <= c.maxRetries && ctx.Err() == nil {
				time.Sleep(backoff(attempt))
				continue
			}
			return &Error{Message: "tidak bisa terhubung ke Solosmartpay: " + err.Error()}
		}
		raw, _ := io.ReadAll(io.LimitReader(res.Body, 10<<20))
		res.Body.Close()

		if (res.StatusCode == 429 || res.StatusCode >= 500) && retryable && attempt <= c.maxRetries {
			time.Sleep(backoff(attempt))
			continue
		}
		if res.StatusCode < 200 || res.StatusCode >= 300 {
			apiErr := &Error{Status: res.StatusCode}
			_ = json.Unmarshal(raw, apiErr)
			if apiErr.Message == "" {
				apiErr.Message = http.StatusText(res.StatusCode)
			}
			return apiErr
		}

		if _, ok := out.(*CreateResult); ok {
			return json.Unmarshal(raw, out)
		}
		var envelope struct {
			Data json.RawMessage `json:"data"`
		}
		if err := json.Unmarshal(raw, &envelope); err != nil {
			return err
		}
		return json.Unmarshal(envelope.Data, out)
	}
}

func backoff(attempt int) time.Duration {
	return time.Duration(250*math.Pow(2, float64(attempt-1))) * time.Millisecond
}

// WebhookEvent is the body Solosmartpay POSTs to your webhook URL.
type WebhookEvent struct {
	ID        string          `json:"id"`
	Type      string          `json:"type"` // payment.paid | invoice.expired | webhook.test
	CreatedAt string          `json:"created_at"`
	Data      json.RawMessage `json:"data"`
}

// VerifyWebhook checks the X-SoloSmartPay-Signature / -Timestamp headers against
// the raw body and returns the event. Deliveries are at-least-once: dedupe on ID.
func VerifyWebhook(rawBody []byte, signature, timestamp, secret string, tolerance time.Duration) (*WebhookEvent, error) {
	if secret == "" {
		return nil, &Error{Message: "webhook secret kosong"}
	}
	if tolerance <= 0 {
		tolerance = 5 * time.Minute
	}
	ts, err := strconv.ParseInt(timestamp, 10, 64)
	if err != nil || time.Since(time.Unix(ts, 0)).Abs() > tolerance {
		return nil, &Error{Message: "timestamp webhook tidak valid atau kedaluwarsa (kemungkinan replay)"}
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(timestamp + "." + string(rawBody)))
	expected := "sha256=" + hex.EncodeToString(mac.Sum(nil))
	if !hmac.Equal([]byte(expected), []byte(signature)) {
		return nil, &Error{Message: "signature webhook tidak valid"}
	}
	var ev WebhookEvent
	if err := json.Unmarshal(rawBody, &ev); err != nil || ev.ID == "" || ev.Type == "" {
		return nil, &Error{Message: "body webhook tidak valid"}
	}
	return &ev, nil
}
