<?php
/**
 * Solosmartpay payment gateway for WooCommerce.
 *
 * Flow: process_payment() creates a Solosmartpay invoice (method "all") and
 * redirects the customer to its checkout page; the `payment.paid` webhook is
 * the only thing that marks the order paid.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSP_WC_Gateway extends WC_Payment_Gateway
{
    const MAX_AMOUNT = 50000000;
    const DEFAULT_BASE_URL = 'https://solosmartpay.com/v1';

    /** True while a webhook is changing an order, so we do not call the API back. */
    private static $from_webhook = false;

    public function __construct()
    {
        $this->id = 'solosmartpay';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'Solosmartpay';
        $this->method_description = __('QRIS, Virtual Account, e-wallet, dan gerai retail. Pelanggan memilih metode di halaman pembayaran Solosmartpay.', 'solosmartpay-woocommerce');
        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_solosmartpay', array($this, 'handle_webhook'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_order_status_cancelled', array($this, 'on_order_cancelled'), 10, 2);
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Aktifkan', 'solosmartpay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Aktifkan Solosmartpay', 'solosmartpay-woocommerce'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Judul', 'solosmartpay-woocommerce'),
                'type' => 'text',
                'description' => __('Teks yang dilihat pelanggan saat checkout.', 'solosmartpay-woocommerce'),
                'default' => __('QRIS, Virtual Account, E-Wallet', 'solosmartpay-woocommerce'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Deskripsi', 'solosmartpay-woocommerce'),
                'type' => 'textarea',
                'default' => __('Bayar dengan QRIS, Virtual Account bank, e-wallet, atau gerai retail. Anda memilih metode di halaman berikutnya.', 'solosmartpay-woocommerce'),
            ),
            'api_section' => array(
                'title' => __('Koneksi API', 'solosmartpay-woocommerce'),
                'type' => 'title',
                'description' => __('Ambil token dan webhook secret dari Developer Hub Solosmartpay.', 'solosmartpay-woocommerce'),
            ),
            'base_url' => array(
                'title' => __('Base URL API', 'solosmartpay-woocommerce'),
                'type' => 'text',
                'default' => self::DEFAULT_BASE_URL,
                'description' => __('Contoh: https://solosmartpay.com/v1', 'solosmartpay-woocommerce'),
                'desc_tip' => true,
            ),
            'api_token' => array(
                'title' => __('API Token', 'solosmartpay-woocommerce'),
                'type' => 'ssp_secret',
                'description' => __('Token ssp_… dengan scope payments:write dan read. Isi whitelist IP server ini pada token.', 'solosmartpay-woocommerce'),
            ),
            'webhook_secret' => array(
                'title' => __('Webhook Secret', 'solosmartpay-woocommerce'),
                'type' => 'ssp_secret',
                'description' => __('Dipakai untuk memverifikasi tanda tangan webhook.', 'solosmartpay-woocommerce'),
            ),
            'webhook_section' => array(
                'title' => __('Webhook', 'solosmartpay-woocommerce'),
                'type' => 'title',
                /* translators: %s: webhook URL */
                'description' => sprintf(
                    __('Daftarkan URL ini di Developer Hub → Webhooks (harus HTTPS, port 443, dan bisa diakses publik): %s', 'solosmartpay-woocommerce'),
                    '<code>' . esc_html($this->webhook_url()) . '</code>'
                ),
            ),
            'debug' => array(
                'title' => __('Log debug', 'solosmartpay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Catat aktivitas ke WooCommerce → Status → Log (sumber: solosmartpay)', 'solosmartpay-woocommerce'),
                'default' => 'no',
            ),
        );
    }

    /** Secret inputs are never pre-filled with the stored value (it would sit in the page source). */
    public function generate_ssp_secret_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $data = wp_parse_args($data, array('title' => '', 'placeholder' => '', 'description' => '', 'desc_tip' => false, 'custom_attributes' => array()));
        $is_set = $this->get_option($key) !== '';

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label></th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <input class="input-text regular-input" type="password" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" value="" autocomplete="new-password"
                        placeholder="<?php echo esc_attr($is_set ? __('•••••••• tersimpan (kosongkan jika tidak diubah)', 'solosmartpay-woocommerce') : ''); ?>" />
                    <?php echo wp_kses_post($this->get_description_html($data)); ?>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    /** Blank keeps the stored secret; otherwise only token-safe characters are kept. */
    public function validate_ssp_secret_field($key, $value)
    {
        $value = is_null($value) ? '' : preg_replace('/\s+/', '', stripslashes((string) $value));

        return $value === '' ? (string) $this->get_option($key) : $value;
    }

    public function validate_base_url_field($key, $value)
    {
        $url = SSP_WC_Api::normalize_base_url(esc_url_raw(trim(stripslashes((string) $value))));
        if (! SSP_WC_Api::is_secure_url($url)) {
            WC_Admin_Settings::add_error(__('Base URL API harus memakai HTTPS. Nilai sebelumnya dipertahankan.', 'solosmartpay-woocommerce'));

            return $this->get_option($key, self::DEFAULT_BASE_URL);
        }

        return $url;
    }

    public function webhook_url()
    {
        return WC()->api_request_url('solosmartpay');
    }

    public function is_available()
    {
        if (! parent::is_available()) {
            return false;
        }

        return get_woocommerce_currency() === 'IDR'
            && $this->get_option('api_token') !== ''
            && $this->get_option('webhook_secret') !== '';
    }

    private function api()
    {
        return new SSP_WC_Api($this->get_option('base_url', self::DEFAULT_BASE_URL), $this->get_option('api_token'));
    }

    /** Text from the API is only ever shown as plain text. */
    private function plain($text)
    {
        return wp_strip_all_tags((string) $text);
    }

    private function log($message, $level = 'info')
    {
        if ($this->get_option('debug') !== 'yes' && $level !== 'error') {
            return;
        }
        wc_get_logger()->log($level, $message, array('source' => 'solosmartpay'));
    }

    // ---------------------------------------------------------------- checkout

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (! $order) {
            return array('result' => 'failure');
        }

        $total = (float) $order->get_total();
        $amount = (int) round($total);
        if (abs($total - $amount) > 0.001) {
            // Rounding would charge a different amount than the order total.
            $this->log('order ' . $order->get_id() . ' total is not a whole rupiah: ' . $total, 'error');
            wc_add_notice(__('Total pesanan harus bilangan bulat Rupiah. Hubungi pemilik toko.', 'solosmartpay-woocommerce'), 'error');

            return array('result' => 'failure');
        }
        if ($order->get_currency() !== 'IDR' || $amount < 1 || $amount > self::MAX_AMOUNT) {
            wc_add_notice(__('Total pesanan di luar batas Solosmartpay (Rp1 sampai Rp50.000.000, mata uang IDR).', 'solosmartpay-woocommerce'), 'error');

            return array('result' => 'failure');
        }

        $api = $this->api();
        if (! $api->is_usable()) {
            $this->log('gateway not usable: token empty or base URL not HTTPS', 'error');
            wc_add_notice(__('Pembayaran tidak tersedia saat ini. Silakan pilih metode lain.', 'solosmartpay-woocommerce'), 'error');

            return array('result' => 'failure');
        }

        // Retry of an order that already has an invoice: reuse it when still payable.
        $existing_id = (string) $order->get_meta('_ssp_invoice_id');
        if ($existing_id !== '') {
            $existing = $api->get_payment($existing_id);
            if (! is_wp_error($existing)) {
                if (($existing['status'] ?? '') === 'paid') {
                    $this->mark_paid($order, $existing);

                    return array('result' => 'success', 'redirect' => $this->get_return_url($order));
                }
                if (($existing['status'] ?? '') === 'pending') {
                    if ((int) ($existing['amount'] ?? 0) === $amount && ! empty($existing['checkout_url']) && $api->owns_url($existing['checkout_url'])) {
                        return $this->go_to_checkout($order, $existing['checkout_url']);
                    }
                    $api->cancel_payment($existing_id);
                }
            }
        }

        $attempt = (int) $order->get_meta('_ssp_attempt') + 1;
        $key = 'wc' . $order->get_id() . '-' . $attempt . '-' . substr(md5($order->get_order_key()), 0, 10);
        $return_url = $this->get_return_url($order);
        $redirect_url = strpos(strtolower($return_url), 'https://') === 0 ? $return_url : null;

        $invoice = $api->create_payment($amount, 'all', substr((string) $order->get_order_number(), 0, 50), $key, $redirect_url);
        if (is_wp_error($invoice)) {
            $this->log('create_payment failed for order ' . $order->get_id() . ': ' . $invoice->get_error_message(), 'error');
            wc_add_notice(__('Pembayaran tidak bisa dibuat saat ini. Silakan coba lagi atau pilih metode lain.', 'solosmartpay-woocommerce'), 'error');
            $order->add_order_note('Solosmartpay: gagal membuat tagihan: ' . $this->plain($invoice->get_error_message()));

            return array('result' => 'failure');
        }

        // Checkout is available right away for method "all"; poll briefly if it is not.
        for ($i = 0; $i < 6 && empty($invoice['checkout_url']) && ! empty($invoice['id']); $i++) {
            usleep(800000);
            $fresh = $api->get_payment($invoice['id']);
            if (is_wp_error($fresh)) {
                break;
            }
            $invoice = $fresh;
        }
        if (! empty($invoice['checkout_url']) && ! $api->owns_url($invoice['checkout_url'])) {
            $this->log('checkout_url host does not match the API host, refusing to redirect', 'error');
            $invoice['checkout_url'] = '';
        }
        if (empty($invoice['checkout_url']) || empty($invoice['id'])) {
            $this->log('no checkout_url for order ' . $order->get_id(), 'error');
            wc_add_notice(__('Halaman pembayaran belum siap. Silakan coba lagi.', 'solosmartpay-woocommerce'), 'error');

            return array('result' => 'failure');
        }

        $order->update_meta_data('_ssp_invoice_id', $invoice['id']);
        $order->update_meta_data('_ssp_attempt', $attempt);
        $order->save();

        /* translators: %s: invoice id */
        $order->update_status('on-hold', sprintf(__('Menunggu pembayaran Solosmartpay (invoice %s).', 'solosmartpay-woocommerce'), $invoice['id']));

        return $this->go_to_checkout($order, $invoice['checkout_url']);
    }

    private function go_to_checkout($order, $checkout_url)
    {
        $order->update_meta_data('_ssp_checkout_url', esc_url_raw($checkout_url));
        $order->save();
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        return array('result' => 'success', 'redirect' => $checkout_url);
    }

    public function thankyou_page($order_id)
    {
        $order = wc_get_order($order_id);
        if (! $order || $order->is_paid() || ! $order->has_status('on-hold')) {
            return;
        }

        $url = $order->get_meta('_ssp_checkout_url');
        echo '<p>' . esc_html__('Pembayaran Anda sedang diverifikasi. Jika belum membayar, lanjutkan di halaman Solosmartpay.', 'solosmartpay-woocommerce') . '</p>';
        if ($url) {
            echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Lanjutkan pembayaran', 'solosmartpay-woocommerce') . '</a></p>';
        }
    }

    /** Cancelling the order in WooCommerce also cancels the pending invoice (best effort). */
    public function on_order_cancelled($order_id, $order = null)
    {
        if (self::$from_webhook) {
            return;
        }
        $order = $order ?: wc_get_order($order_id);
        if (! $order || $order->get_payment_method() !== $this->id) {
            return;
        }
        $invoice_id = (string) $order->get_meta('_ssp_invoice_id');
        if ($invoice_id === '') {
            return;
        }

        $res = $this->api()->cancel_payment($invoice_id);
        if (is_wp_error($res)) {
            $this->log('cancel invoice ' . $invoice_id . ' failed: ' . $res->get_error_message());
        } else {
            $order->add_order_note('Solosmartpay: tagihan dibatalkan.');
        }
    }

    // ----------------------------------------------------------------- webhook

    public function handle_webhook()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, 'method not allowed');
        }

        $raw = (string) file_get_contents('php://input');
        if (strlen($raw) > 1048576) {
            $this->respond(413, 'payload too large');
        }
        $signature = isset($_SERVER['HTTP_X_SOLOSMARTPAY_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SOLOSMARTPAY_SIGNATURE'])) : '';
        $timestamp = isset($_SERVER['HTTP_X_SOLOSMARTPAY_TIMESTAMP']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SOLOSMARTPAY_TIMESTAMP'])) : '';

        $error = SSP_WC_Signature::error($raw, $signature, $timestamp, (string) $this->get_option('webhook_secret'));
        if ($error !== null) {
            $this->log('webhook rejected: ' . $error, 'error');
            $this->respond(401, 'invalid signature');
        }

        $event = json_decode($raw, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type']) || ! is_string($event['id']) || ! is_string($event['type'])) {
            $this->respond(400, 'invalid body');
        }

        $seen_key = 'ssp_evt_' . md5($event['id']);
        if (get_transient($seen_key)) {
            $this->respond(200, 'duplicate');
        }

        // Concurrent deliveries of the same event must not be applied twice.
        $lock = $this->acquire_lock((string) $event['id']);
        if ($lock === null) {
            $this->respond(409, 'event is being processed');
        }

        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
        $type = (string) $event['type'];

        $message = 'ok';
        if ($type === 'webhook.test') {
            $message = 'test ok';
        } else {
            $order = $this->find_order(isset($data['id']) ? (string) $data['id'] : '');
            if (! $order) {
                $this->log('webhook ' . $type . ': no order for invoice ' . (isset($data['id']) ? $this->plain($data['id']) : '?'));
                $message = 'order not found';
            } else {
                self::$from_webhook = true;
                try {
                    $this->apply_event($order, $type, $data);
                } finally {
                    self::$from_webhook = false;
                }
            }
        }

        set_transient($seen_key, 1, DAY_IN_SECONDS);
        $this->release_lock($lock);
        $this->respond(200, $message);
    }

    /** Atomic: the options table has a unique key, so a second INSERT fails. A lock older than 60s is treated as abandoned. */
    private function acquire_lock($event_id)
    {
        global $wpdb;
        $name = 'ssp_lock_' . md5($event_id);

        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert($wpdb->options, array('option_name' => $name, 'option_value' => (string) time(), 'autoload' => 'no'));
        $wpdb->suppress_errors($suppress);
        if ($inserted) {
            return $name;
        }

        $taken_at = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($taken_at > 0 && time() - $taken_at <= 60) {
            return null;
        }
        $wpdb->update($wpdb->options, array('option_value' => (string) time()), array('option_name' => $name));

        return $name;
    }

    private function release_lock($name)
    {
        global $wpdb;
        $wpdb->delete($wpdb->options, array('option_name' => $name));
    }

    private function find_order($invoice_id)
    {
        if ($invoice_id === '') {
            return null;
        }
        // meta_key/meta_value works with both order storages (meta_query is ignored by the
        // legacy posts storage), and the result is re-checked so a loose query can never
        // hand back an unrelated order.
        $orders = wc_get_orders(array(
            'limit' => 1,
            'return' => 'objects',
            'meta_key' => '_ssp_invoice_id',
            'meta_value' => $invoice_id,
        ));
        if (! $orders || (string) $orders[0]->get_meta('_ssp_invoice_id') !== $invoice_id) {
            return null;
        }

        return $orders[0];
    }

    private function apply_event($order, $type, array $data)
    {
        if ($type === 'payment.paid') {
            if ($order->is_paid()) {
                return;
            }
            // Never trust the event alone: amount and reference must match the order.
            $reference_ok = ! isset($data['reference']) || (string) $data['reference'] === substr((string) $order->get_order_number(), 0, 50);
            if (! $reference_ok || (int) ($data['amount'] ?? 0) !== (int) round((float) $order->get_total())) {
                $order->update_status('on-hold', 'Solosmartpay: nominal atau referensi pembayaran tidak cocok dengan pesanan. Periksa manual di dashboard Solosmartpay.');
                $this->log('amount/reference mismatch on order ' . $order->get_id(), 'error');

                return;
            }
            if ($order->has_status('cancelled')) {
                $order->add_order_note('Solosmartpay: pembayaran diterima setelah pesanan dibatalkan. Periksa stok sebelum memproses.');
            }
            $this->mark_paid($order, $data);

            return;
        }

        if (in_array($type, array('invoice.expired', 'invoice.cancelled'), true) && $order->has_status(array('pending', 'on-hold'))) {
            $order->update_status('cancelled', 'Solosmartpay: tagihan ' . ($type === 'invoice.expired' ? 'kedaluwarsa' : 'dibatalkan') . '.');

            return;
        }

        if ($type === 'invoice.failed' && $order->has_status(array('pending', 'on-hold'))) {
            $order->update_status('failed', 'Solosmartpay: pembuatan pembayaran gagal.');
        }
    }

    private function mark_paid($order, array $invoice)
    {
        if ($order->is_paid()) {
            return;
        }
        $order->payment_complete(isset($invoice['id']) ? (string) $invoice['id'] : '');
        $order->add_order_note('Solosmartpay: pembayaran diterima (invoice ' . $this->plain(isset($invoice['id']) ? $invoice['id'] : '?') . ').');
    }

    private function respond($status, $message)
    {
        status_header($status);
        wp_send_json(array('ok' => $status < 300, 'message' => $message), $status);
    }
}
