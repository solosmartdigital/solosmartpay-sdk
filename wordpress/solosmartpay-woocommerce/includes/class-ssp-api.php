<?php
/**
 * Minimal Solosmartpay REST client (payments only) on top of the WordPress HTTP API.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSP_WC_Api
{
    private $base_url;
    private $token;

    public function __construct($base_url, $token)
    {
        $this->base_url = self::normalize_base_url($base_url);
        $this->token = (string) $token;
    }

    /** "https://host" and "https://host/v1/" both become "https://host/v1". */
    public static function normalize_base_url($url)
    {
        $url = rtrim(trim((string) $url), '/');
        if ($url !== '' && substr($url, -3) !== '/v1') {
            $url .= '/v1';
        }

        return $url;
    }

    /**
     * POST /v1/payments. `method` "all" lets the customer pick on the Solosmartpay checkout page.
     *
     * @return array|WP_Error invoice data
     */
    public function create_payment($amount, $method, $reference, $idempotency_key, $redirect_url = null)
    {
        $body = array('amount' => (int) $amount, 'method' => $method, 'reference' => (string) $reference);
        if ($redirect_url) {
            $body['redirect_url'] = $redirect_url;
        }

        $res = $this->request('POST', '/payments', $body, $idempotency_key);

        return is_wp_error($res) ? $res : $res['data'];
    }

    /** @return array|WP_Error invoice data */
    public function get_payment($id)
    {
        $res = $this->request('GET', '/payments/' . rawurlencode($id));

        return is_wp_error($res) ? $res : $res['data'];
    }

    /** @return array|WP_Error invoice data */
    public function cancel_payment($id)
    {
        $res = $this->request('DELETE', '/payments/' . rawurlencode($id));

        return is_wp_error($res) ? $res : $res['data'];
    }

    /** @return array|WP_Error decoded JSON body */
    private function request($method, $path, $body = null, $idempotency_key = null)
    {
        $headers = array(
            'Accept' => 'application/json',
            'X-API-Token' => $this->token,
            'User-Agent' => 'solosmartpay-woocommerce/' . SSP_WC_VERSION,
        );
        $args = array('method' => $method, 'timeout' => 20, 'headers' => $headers);
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        if ($idempotency_key) {
            $args['headers']['Idempotency-Key'] = $idempotency_key;
        }

        $response = wp_remote_request($this->base_url . $path, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300 || ! is_array($json)) {
            $message = is_array($json) && isset($json['message']) ? $json['message'] : 'HTTP ' . $status;

            return new WP_Error('ssp_http_' . $status, $message, array(
                'status' => $status,
                'errors' => is_array($json) && isset($json['errors']) ? $json['errors'] : array(),
            ));
        }

        if (! isset($json['data']) || ! is_array($json['data'])) {
            return new WP_Error('ssp_bad_response', 'Respons Solosmartpay tidak berisi data.', array('status' => $status));
        }

        return $json;
    }
}
