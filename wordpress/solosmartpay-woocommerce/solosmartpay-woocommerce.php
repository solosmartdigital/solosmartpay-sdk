<?php
/**
 * Plugin Name:       Solosmartpay for WooCommerce
 * Plugin URI:        https://github.com/solosmartdigital/solosmartpay-sdk/tree/main/wordpress
 * Description:       Terima QRIS, Virtual Account, e-wallet, dan gerai retail lewat Solosmartpay di WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * Author:            Solosmartdigital
 * License:           MIT
 * Text Domain:       solosmartpay-woocommerce
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SSP_WC_VERSION', '1.0.0');
define('SSP_WC_FILE', __FILE__);
define('SSP_WC_DIR', plugin_dir_path(__FILE__));
define('SSP_WC_URL', plugin_dir_url(__FILE__));

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SSP_WC_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', SSP_WC_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    if (! class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Solosmartpay for WooCommerce membutuhkan WooCommerce yang aktif.', 'solosmartpay-woocommerce')
                . '</p></div>';
        });

        return;
    }

    require_once SSP_WC_DIR . 'includes/class-ssp-signature.php';
    require_once SSP_WC_DIR . 'includes/class-ssp-api.php';
    require_once SSP_WC_DIR . 'includes/class-ssp-gateway.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'SSP_WC_Gateway';

        return $gateways;
    });

    add_filter('plugin_action_links_' . plugin_basename(SSP_WC_FILE), function ($links) {
        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=solosmartpay');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Pengaturan', 'solosmartpay-woocommerce') . '</a>');

        return $links;
    });
});

// Checkout block (the default WooCommerce checkout since 8.3).
add_action('woocommerce_blocks_loaded', function () {
    if (! class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once SSP_WC_DIR . 'includes/class-ssp-blocks.php';

    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new SSP_WC_Blocks());
    });
});
