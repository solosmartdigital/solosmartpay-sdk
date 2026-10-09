export declare const VERSION: string;

export interface Invoice {
  id: string;
  reference: string | null;
  status: 'pending' | 'paid' | 'expired' | 'cancelled';
  provisioning_status: 'queued' | 'processing' | 'ready' | 'failed' | 'selection_required';
  amount: number;
  unique_code: number | null;
  total_amount: number;
  fee: number;
  net_amount: number;
  expires_at: string | null;
  paid_at: string | null;
  created_at: string | null;
  checkout_url: string | null;
  /** Use with `openCheckout()` from `@solosmartpay/node/browser`. */
  embed_url: string | null;
  basic_url: string | null;
  redirect_url: string | null;
}

export interface Qris extends Invoice {
  qris_string: string | null;
  qris_image: string | null;
}

export interface Payment extends Invoice {
  method: string | null;
  payment_url: string | null;
}

export interface ProvisioningResponse<T extends Invoice> {
  data: T;
  status_url: string;
}

export interface PaymentMethod {
  code: string;
  name: string;
  group: 'va' | 'ewallet' | 'retail';
  fee_type: 'flat' | 'percent';
  fee: number;
  min_amount: number;
  max_amount: number | null;
}

export interface WebhookEvent {
  id: string;
  type: 'payment.paid' | 'invoice.expired' | 'invoice.failed' | 'invoice.cancelled' | 'webhook.test';
  created_at: string;
  data: Invoice & Record<string, unknown>;
}

export declare class SolosmartpayError extends Error {
  status: number;
  errors: Record<string, string[]>;
}

export declare class Solosmartpay {
  constructor(options: { token: string; baseUrl: string; timeoutMs?: number; maxRetries?: number });
  createQris(input: { amount: number; reference?: string; redirectUrl?: string; idempotencyKey: string }): Promise<ProvisioningResponse<Qris>>;
  getQris(id: string): Promise<Qris>;
  paymentMethods(): Promise<PaymentMethod[]>;
  createPayment(input: { amount: number; method: string; reference?: string; redirectUrl?: string; idempotencyKey: string }): Promise<ProvisioningResponse<Payment>>;
  getPayment(id: string): Promise<Payment>;
  cancelQris(id: string): Promise<Qris>;
  cancelPayment(id: string): Promise<Payment>;
}

export declare function verifyWebhook(
  rawBody: string | Buffer,
  signature: string,
  timestamp: string,
  secret: string,
  toleranceSeconds?: number,
): WebhookEvent;

export default Solosmartpay;
