package solosmartpay

import (
	"encoding/json"
	"strings"
	"time"
)

// Invoice statuses and provisioning statuses.
const (
	StatusPending   = "pending"
	StatusPaid      = "paid"
	StatusExpired   = "expired"
	StatusCancelled = "cancelled"

	ProvisioningQueued            = "queued"
	ProvisioningProcessing        = "processing"
	ProvisioningReady             = "ready"
	ProvisioningFailed            = "failed"
	ProvisioningSelectionRequired = "selection_required"
)

// Payment method groups.
const (
	GroupVirtualAccount = "va"
	GroupEwallet        = "ewallet"
	GroupRetail         = "retail"
	GroupPaylater       = "paylater"
)

// Webhook event types.
const (
	EventPaymentPaid      = "payment.paid"
	EventInvoiceExpired   = "invoice.expired"
	EventInvoiceFailed    = "invoice.failed"
	EventInvoiceCancelled = "invoice.cancelled"
	EventTest             = "webhook.test"
)

// Invoice is the shape of QRIS and bank/e-wallet/retail invoices. Money is integer Rupiah.
// Pointer fields are null in the API response.
type Invoice struct {
	ID                 string     `json:"id"`
	Reference          *string    `json:"reference"`
	RedirectURL        *string    `json:"redirect_url"`
	Status             string     `json:"status"`
	ProvisioningStatus string     `json:"provisioning_status"`
	ErrorCode          *string    `json:"error_code"`
	Channel            string     `json:"channel"`
	Method             *string    `json:"method"`
	Amount             int64      `json:"amount"`
	UniqueCode         *int       `json:"unique_code"`
	TotalAmount        int64      `json:"total_amount"`
	Fee                int64      `json:"fee"`
	NetAmount          int64      `json:"net_amount"`
	ExpiresAt          *time.Time `json:"expires_at"`
	PaidAt             *time.Time `json:"paid_at"`
	CreatedAt          *time.Time `json:"created_at"`
	CheckoutURL        *string    `json:"checkout_url"`
	// EmbedURL is what the browser's openCheckout() / SoloSmartPay.open() takes.
	EmbedURL *string `json:"embed_url"`
	BasicURL *string `json:"basic_url"`
	// QRIS only. QrisImage is a PNG data URI; both are nil until ready and once the invoice is not pending.
	QrisString *string `json:"qris_string"`
	QrisImage  *string `json:"qris_image"`
	// Bank VA / e-wallet / retail only. Nil for virtual accounts: pay those from the Solosmartpay checkout.
	PaymentURL *string `json:"payment_url"`

	// StatusURL is set on create: poll it (or call WaitUntilReady) until the invoice is ready.
	StatusURL string `json:"-"`
}

// Qris and Payment are Invoice under the name of the resource that returns them.
type (
	Qris    = Invoice
	Payment = Invoice
)

func (i *Invoice) IsPending() bool   { return i.Status == StatusPending }
func (i *Invoice) IsPaid() bool      { return i.Status == StatusPaid }
func (i *Invoice) IsExpired() bool   { return i.Status == StatusExpired }
func (i *Invoice) IsCancelled() bool { return i.Status == StatusCancelled }

// ProvisioningFailed reports a failed provisioning; see ErrorCode.
func (i *Invoice) ProvisioningFailed() bool { return i.ProvisioningStatus == ProvisioningFailed }

// IsReady is true once the customer can be sent to checkout (EmbedURL / CheckoutURL are filled).
func (i *Invoice) IsReady() bool {
	return (i.EmbedURL != nil && *i.EmbedURL != "") || i.ProvisioningStatus == ProvisioningReady
}

// CreateResult is the raw create envelope.
type CreateResult struct {
	Data      Invoice `json:"data"`
	StatusURL string  `json:"status_url"`
}

// PaymentMethod is a method enabled for your store (GET /v1/payment-methods).
type PaymentMethod struct {
	Code      string  `json:"code"`
	Name      string  `json:"name"`
	Group     string  `json:"group"`
	FeeType   string  `json:"fee_type"`
	Fee       float64 `json:"fee"`
	MinAmount int64   `json:"min_amount"`
	MaxAmount *int64  `json:"max_amount"`
}

func (m PaymentMethod) IsVirtualAccount() bool { return m.Group == GroupVirtualAccount }
func (m PaymentMethod) IsEwallet() bool        { return m.Group == GroupEwallet }
func (m PaymentMethod) IsRetail() bool         { return m.Group == GroupRetail }

// Accepts reports whether amount is inside this method's limits.
func (m PaymentMethod) Accepts(amount int64) bool {
	return amount >= m.MinAmount && (m.MaxAmount == nil || amount <= *m.MaxAmount)
}

// WebhookEvent is the body Solosmartpay POSTs to your webhook URL.
// Deliveries are at-least-once: deduplicate on ID.
type WebhookEvent struct {
	ID        string          `json:"id"`
	Type      string          `json:"type"`
	CreatedAt *time.Time      `json:"created_at"`
	Data      Invoice         `json:"data"`
	Raw       json.RawMessage `json:"-"`
}

func (e *WebhookEvent) IsPaid() bool { return e.Type == EventPaymentPaid }
func (e *WebhookEvent) IsTest() bool { return e.Type == EventTest }

func equalFold(a, b string) bool { return strings.EqualFold(a, b) }
