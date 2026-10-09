package solosmartpay

import (
	"context"
	"errors"
	"time"
)

// QrisParams are the inputs of QrisService.Create.
type QrisParams struct {
	Amount      int64  // Rupiah. 1,000 to 9,999,001; a unique code (1-999) is added.
	Reference   string // optional order id, max 50 chars
	RedirectURL string // optional https:// URL
	// IdempotencyKey is required. The same key with the same body returns the same invoice; a different body is a 409.
	IdempotencyKey string
}

// PaymentParams are the inputs of PaymentsService.Create.
type PaymentParams struct {
	Amount int64
	// Method is a code from Methods.All, or "all" to let the customer choose at checkout.
	Method         string
	Reference      string
	RedirectURL    string
	IdempotencyKey string
}

// WaitOptions tune WaitUntilReady. Zero values mean 30s timeout and 1s interval.
type WaitOptions struct {
	Timeout  time.Duration
	Interval time.Duration
}

// QrisService manages QRIS invoices (POST/GET/DELETE /v1/qris).
type QrisService struct{ c *Client }

// Create creates a QRIS invoice. Provisioning is asynchronous: call WaitUntilReady before using the QR or EmbedURL.
func (s *QrisService) Create(ctx context.Context, p QrisParams) (*Qris, error) {
	res, err := s.c.create(ctx, "/qris", map[string]any{"amount": p.Amount}, p.Reference, p.RedirectURL, p.IdempotencyKey)
	if err != nil {
		return nil, err
	}
	return &res.Data, nil
}

func (s *QrisService) Get(ctx context.Context, id string) (*Qris, error) { return s.c.GetQris(ctx, id) }

// Cancel cancels a pending invoice. Idempotent; 409 once paid or expired.
func (s *QrisService) Cancel(ctx context.Context, id string) (*Qris, error) {
	return s.c.CancelQris(ctx, id)
}

// WaitUntilReady polls until the checkout can be shown. It fails if provisioning fails or the timeout passes.
func (s *QrisService) WaitUntilReady(ctx context.Context, id string, o *WaitOptions) (*Qris, error) {
	return waitUntilReady(ctx, id, s.Get, o)
}

// PaymentsService manages bank VA, e-wallet and retail invoices (POST/GET/DELETE /v1/payments).
type PaymentsService struct{ c *Client }

func (s *PaymentsService) Create(ctx context.Context, p PaymentParams) (*Payment, error) {
	if p.Method == "" {
		return nil, errors.New(`solosmartpay: Method wajib diisi (kode metode atau "all")`)
	}
	res, err := s.c.create(ctx, "/payments", map[string]any{"amount": p.Amount, "method": p.Method}, p.Reference, p.RedirectURL, p.IdempotencyKey)
	if err != nil {
		return nil, err
	}
	return &res.Data, nil
}

func (s *PaymentsService) Get(ctx context.Context, id string) (*Payment, error) {
	return s.c.GetPayment(ctx, id)
}

// Cancel cancels a pending invoice. Idempotent; 409 once paid or expired.
func (s *PaymentsService) Cancel(ctx context.Context, id string) (*Payment, error) {
	return s.c.CancelPayment(ctx, id)
}

// WaitUntilReady polls until the checkout can be shown. It fails if provisioning fails or the timeout passes.
func (s *PaymentsService) WaitUntilReady(ctx context.Context, id string, o *WaitOptions) (*Payment, error) {
	return waitUntilReady(ctx, id, s.Get, o)
}

// MethodsService lists the payment methods enabled for your store (GET /v1/payment-methods).
type MethodsService struct{ c *Client }

// All returns every enabled method, or only those of group when given.
func (s *MethodsService) All(ctx context.Context, group ...string) ([]PaymentMethod, error) {
	all, err := s.c.PaymentMethods(ctx)
	if err != nil || len(group) == 0 || group[0] == "" {
		return all, err
	}
	var out []PaymentMethod
	for _, m := range all {
		if m.Group == group[0] {
			out = append(out, m)
		}
	}
	return out, nil
}

// Find returns the method with the given code (case-insensitive), or nil.
func (s *MethodsService) Find(ctx context.Context, code string) (*PaymentMethod, error) {
	all, err := s.c.PaymentMethods(ctx)
	if err != nil {
		return nil, err
	}
	for i := range all {
		if equalFold(all[i].Code, code) {
			return &all[i], nil
		}
	}
	return nil, nil
}

func waitUntilReady(ctx context.Context, id string, fetch func(context.Context, string) (*Invoice, error), o *WaitOptions) (*Invoice, error) {
	timeout, interval := 30*time.Second, time.Second
	if o != nil {
		if o.Timeout > 0 {
			timeout = o.Timeout
		}
		if o.Interval > 0 {
			interval = o.Interval
		}
	}
	deadline := time.Now().Add(timeout)

	for {
		inv, err := fetch(ctx, id)
		if err != nil {
			return nil, err
		}
		if inv.ProvisioningFailed() {
			msg := "tagihan gagal disiapkan"
			if inv.ErrorCode != nil {
				msg += " (" + *inv.ErrorCode + ")"
			}
			return nil, &Error{Message: msg}
		}
		if inv.IsReady() || !inv.IsPending() {
			return inv, nil
		}
		if time.Now().Add(interval).After(deadline) {
			return nil, &Error{Message: "tagihan belum siap setelah batas waktu"}
		}
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-time.After(interval):
		}
	}
}
