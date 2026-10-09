export declare const VERSION: string;

// ── Raw API shapes (snake_case): returned by the flat helpers and kept on `.raw` ──────────────

export interface RawInvoice {
  id: string;
  reference: string | null;
  redirect_url: string | null;
  status: 'pending' | 'paid' | 'expired' | 'cancelled';
  provisioning_status: 'queued' | 'processing' | 'ready' | 'failed' | 'selection_required';
  error_code: string | null;
  channel: 'qris' | 'bank_transfer';
  method: string | null;
  amount: number;
  unique_code: number | null;
  total_amount: number;
  fee: number;
  net_amount: number;
  expires_at: string | null;
  paid_at: string | null;
  created_at: string | null;
  checkout_url: string | null;
  embed_url: string | null;
  basic_url: string | null;
}

export interface RawQris extends RawInvoice {
  qris_string: string | null;
  qris_image: string | null;
}

export interface RawPayment extends RawInvoice {
  payment_url: string | null;
}

export interface RawPaymentMethod {
  code: string;
  name: string;
  group: 'va' | 'ewallet' | 'retail' | 'paylater';
  fee_type: 'flat' | 'percent';
  fee: number;
  min_amount: number;
  max_amount: number | null;
}

export interface RawWebhookEvent {
  id: string;
  type: 'payment.paid' | 'invoice.expired' | 'invoice.failed' | 'invoice.cancelled' | 'webhook.test';
  created_at: string;
  data: Partial<RawInvoice> & Record<string, unknown>;
}

export interface ProvisioningResponse<T extends RawInvoice> {
  data: T;
  status_url: string;
}

// ── Classes ────────────────────────────────────────────────────────────────────────────────────

export declare class SolosmartpayError extends Error {
  status: number;
  errors: Record<string, string[]>;
}

/** An invoice. Money is integer Rupiah; dates are `Date`. `raw` is the untouched API payload. */
export declare class Invoice {
  static readonly STATUS: Readonly<{ PENDING: 'pending'; PAID: 'paid'; EXPIRED: 'expired'; CANCELLED: 'cancelled' }>;
  static readonly PROVISIONING: Readonly<{ QUEUED: 'queued'; PROCESSING: 'processing'; READY: 'ready'; FAILED: 'failed'; SELECTION_REQUIRED: 'selection_required' }>;
  constructor(data: Partial<RawInvoice>, statusUrl?: string | null);
  readonly raw: Partial<RawInvoice>;
  id: string;
  reference: string | null;
  status: string;
  provisioningStatus: string | null;
  channel: string | null;
  method: string | null;
  amount: number;
  uniqueCode: number | null;
  totalAmount: number;
  fee: number;
  netAmount: number;
  expiresAt: Date | null;
  paidAt: Date | null;
  createdAt: Date | null;
  checkoutUrl: string | null;
  /** Pass to `openCheckout()` from `@solosmartpay/node/browser`. */
  embedUrl: string | null;
  basicUrl: string | null;
  redirectUrl: string | null;
  errorCode: string | null;
  /** Set on create: poll it (or call waitUntilReady) until the invoice is ready. */
  statusUrl: string | null;
  isPending(): boolean;
  isPaid(): boolean;
  isExpired(): boolean;
  isCancelled(): boolean;
  provisioningFailed(): boolean;
  /** True once the customer can be sent to checkout. */
  isReady(): boolean;
  toJSON(): Partial<RawInvoice>;
}

export declare class Qris extends Invoice {
  qrisString: string | null;
  /** PNG data URI, ready for `<img src>`. */
  qrisImage: string | null;
}

export declare class Payment extends Invoice {
  /** Gateway page for e-wallet / retail. Null for virtual accounts. */
  paymentUrl: string | null;
}

export declare class PaymentMethod {
  static readonly GROUP: Readonly<{ VIRTUAL_ACCOUNT: 'va'; EWALLET: 'ewallet'; RETAIL: 'retail'; PAYLATER: 'paylater' }>;
  constructor(data: RawPaymentMethod);
  readonly raw: RawPaymentMethod;
  code: string;
  name: string;
  group: string;
  feeType: string;
  fee: number;
  minAmount: number;
  maxAmount: number | null;
  isVirtualAccount(): boolean;
  isEwallet(): boolean;
  isRetail(): boolean;
  accepts(amount: number): boolean;
  toJSON(): RawPaymentMethod;
}

export declare class WebhookEvent {
  static readonly TYPE: Readonly<{ PAYMENT_PAID: 'payment.paid'; INVOICE_EXPIRED: 'invoice.expired'; INVOICE_FAILED: 'invoice.failed'; INVOICE_CANCELLED: 'invoice.cancelled'; TEST: 'webhook.test' }>;
  constructor(event: RawWebhookEvent);
  readonly raw: RawWebhookEvent;
  id: string;
  type: string;
  createdAt: Date | null;
  data: Invoice;
  isPaid(): boolean;
  isTest(): boolean;
}

export interface WaitOptions {
  /** Default 30000. */
  timeoutMs?: number;
  /** Default 1000. */
  intervalMs?: number;
}

export declare class QrisResource {
  create(input: { amount: number; reference?: string; redirectUrl?: string; idempotencyKey: string }): Promise<Qris>;
  get(id: string): Promise<Qris>;
  cancel(id: string): Promise<Qris>;
  waitUntilReady(qrisOrId: Qris | string, options?: WaitOptions): Promise<Qris>;
}

export declare class PaymentsResource {
  /** `method`: a code from `ssp.methods.all()`, or "all" to let the customer choose at checkout. */
  create(input: { amount: number; method: string; reference?: string; redirectUrl?: string; idempotencyKey: string }): Promise<Payment>;
  get(id: string): Promise<Payment>;
  cancel(id: string): Promise<Payment>;
  waitUntilReady(paymentOrId: Payment | string, options?: WaitOptions): Promise<Payment>;
}

export declare class PaymentMethodsResource {
  all(group?: 'va' | 'ewallet' | 'retail' | 'paylater'): Promise<PaymentMethod[]>;
  find(code: string): Promise<PaymentMethod | null>;
}

export declare class Solosmartpay {
  constructor(options: { token: string; baseUrl: string; timeoutMs?: number; maxRetries?: number });
  readonly qris: QrisResource;
  readonly payments: PaymentsResource;
  readonly methods: PaymentMethodsResource;

  // Flat helpers: return raw snake_case API data.
  createQris(input: { amount: number; reference?: string; redirectUrl?: string; idempotencyKey: string }): Promise<ProvisioningResponse<RawQris>>;
  getQris(id: string): Promise<RawQris>;
  cancelQris(id: string): Promise<RawQris>;
  paymentMethods(): Promise<RawPaymentMethod[]>;
  createPayment(input: { amount: number; method: string; reference?: string; redirectUrl?: string; idempotencyKey: string }): Promise<ProvisioningResponse<RawPayment>>;
  getPayment(id: string): Promise<RawPayment>;
  cancelPayment(id: string): Promise<RawPayment>;
}

export declare function verifyWebhook(
  rawBody: string | Buffer,
  signature: string,
  timestamp: string,
  secret: string,
  toleranceSeconds?: number,
): RawWebhookEvent;

/** Same checks as verifyWebhook(), returned as a WebhookEvent. */
export declare function parseWebhook(
  rawBody: string | Buffer,
  signature: string,
  timestamp: string,
  secret: string,
  toleranceSeconds?: number,
): WebhookEvent;

export default Solosmartpay;
