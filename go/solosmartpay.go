// Package solosmartpay is the official Go client for the Solosmartpay payment
// API (QRIS, bank VA, e-wallet). Server-side use only: never ship your ssp_
// token to browsers or mobile apps.
package solosmartpay

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"net/http"
	"net/url"
	"strings"
	"time"
)

const Version = "1.2.0"

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

// Client talks to the Solosmartpay API. Create one with New.
//
//	ssp, _ := solosmartpay.New(os.Getenv("SOLOSMARTPAY_TOKEN"), "https://your-domain/v1")
//	qris, _ := ssp.Qris.Create(ctx, solosmartpay.QrisParams{Amount: 50000, Reference: "ORDER-123", IdempotencyKey: "order-123-create"})
//	qris, _ = ssp.Qris.WaitUntilReady(ctx, qris.ID, nil) // EmbedURL / QrisImage filled
//
// Money is integer Rupiah. The flat helpers (CreateQris, GetQris, ...) remain available.
type Client struct {
	token      string
	baseURL    string
	httpClient *http.Client
	maxRetries int

	// Qris manages QRIS invoices.
	Qris *QrisService
	// Payments manages bank virtual account, e-wallet and retail invoices.
	Payments *PaymentsService
	// Methods lists the payment methods enabled for your store.
	Methods *MethodsService
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
	c.Qris = &QrisService{c}
	c.Payments = &PaymentsService{c}
	c.Methods = &MethodsService{c}
	return c, nil
}

// CreateQris creates a QRIS invoice and returns the raw envelope. idempotencyKey is required.
func (c *Client) CreateQris(ctx context.Context, amount int64, reference, idempotencyKey string) (*CreateResult, error) {
	return c.create(ctx, "/qris", map[string]any{"amount": amount}, reference, "", idempotencyKey)
}

func (c *Client) GetQris(ctx context.Context, id string) (*Invoice, error) {
	var out Invoice
	return &out, c.do(ctx, http.MethodGet, "/qris/"+url.PathEscape(id), nil, "", &out)
}

// CancelQris cancels a pending QRIS invoice (idempotent; 409 once paid or expired).
func (c *Client) CancelQris(ctx context.Context, id string) (*Invoice, error) {
	var out Invoice
	return &out, c.do(ctx, http.MethodDelete, "/qris/"+url.PathEscape(id), nil, "", &out)
}

// PaymentMethods lists the bank VA / e-wallet methods enabled for your store.
func (c *Client) PaymentMethods(ctx context.Context) ([]PaymentMethod, error) {
	var out []PaymentMethod
	return out, c.do(ctx, http.MethodGet, "/payment-methods", nil, "", &out)
}

// CreatePayment queues a bank VA / e-wallet bill. idempotencyKey is required.
func (c *Client) CreatePayment(ctx context.Context, amount int64, method, reference, idempotencyKey string) (*CreateResult, error) {
	return c.create(ctx, "/payments", map[string]any{"amount": amount, "method": method}, reference, "", idempotencyKey)
}

func (c *Client) GetPayment(ctx context.Context, id string) (*Invoice, error) {
	var out Invoice
	return &out, c.do(ctx, http.MethodGet, "/payments/"+url.PathEscape(id), nil, "", &out)
}

// CancelPayment cancels a pending bank VA / e-wallet bill (idempotent; 409 once paid or expired).
func (c *Client) CancelPayment(ctx context.Context, id string) (*Invoice, error) {
	var out Invoice
	return &out, c.do(ctx, http.MethodDelete, "/payments/"+url.PathEscape(id), nil, "", &out)
}

func (c *Client) create(ctx context.Context, path string, body map[string]any, reference, redirectURL, idempotencyKey string) (*CreateResult, error) {
	if idempotencyKey == "" {
		return nil, errors.New("solosmartpay: idempotencyKey wajib diisi")
	}
	if reference != "" {
		body["reference"] = reference
	}
	if redirectURL != "" {
		body["redirect_url"] = redirectURL
	}
	var out CreateResult
	if err := c.do(ctx, http.MethodPost, path, body, idempotencyKey, &out); err != nil {
		return nil, err
	}
	out.Data.StatusURL = out.StatusURL
	return &out, nil
}

// do retries network errors, 429 and 5xx when the call is safe to repeat.
func (c *Client) do(ctx context.Context, method, path string, body any, idempotencyKey string, out any) error {
	retryable := method == http.MethodGet || method == http.MethodDelete || idempotencyKey != ""
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
