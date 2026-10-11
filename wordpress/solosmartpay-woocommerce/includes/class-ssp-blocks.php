<?php
/**
 * Makes the gateway available in the WooCommerce Checkout block.
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (! defined('ABSPATH')) {
    exit;
}

final class SSP_WC_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'solosmartpay';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_solosmartpay_settings', array());
    }

    public function is_active()
    {
        return isset($this->settings['enabled']) && $this->settings['enabled'] === 'yes'
            && ! empty($this->settings['api_token']) && ! empty($this->settings['webhook_secret'])
            && get_woocommerce_currency() === 'IDR';
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'ssp-wc-blocks',
            SSP_WC_URL . 'assets/js/blocks.js',
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            SSP_WC_VERSION,
            true
        );

        return array('ssp-wc-blocks');
    }

    public function get_payment_method_data()
    {
        return array(
            'title' => isset($this->settings['title']) ? $this->settings['title'] : 'Solosmartpay',
            'description' => isset($this->settings['description']) ? $this->settings['description'] : '',
            'supports' => array('products'),
        );
    }
}
