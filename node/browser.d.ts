export interface CheckoutSuccess {
  type: string;
  publicId: string;
  redirectUrl?: string | null;
  totalAmount?: number;
}

export interface OpenCheckoutOptions {
  /** `embed_url` from the invoice response. */
  embedUrl?: string;
  /** Alternative to embedUrl; requires `baseUrl`. */
  publicId?: string;
  /** Solosmartpay origin, e.g. https://pay.example.com (only needed with `publicId`). */
  baseUrl?: string;
  lang?: 'id' | 'en' | 'auto';
  /** UX hint only. Confirm payment from the webhook on your server. */
  onSuccess?: (result: CheckoutSuccess) => void;
  onClose?: (event: { reason: string }) => void;
  closeOnOverlayClick?: boolean;
  closeOnEscape?: boolean;
  autoClose?: boolean;
}

export interface SoloSmartPayGlobal {
  open(input: string | OpenCheckoutOptions, options?: OpenCheckoutOptions): void;
  close(): void;
}

export declare function loadEmbed(baseUrl: string): Promise<SoloSmartPayGlobal>;
export declare function openCheckout(
  embedUrlOrOptions: string | OpenCheckoutOptions,
  options?: Omit<OpenCheckoutOptions, 'embedUrl'>,
): Promise<{ close(): void }>;
export declare function closeCheckout(): void;
