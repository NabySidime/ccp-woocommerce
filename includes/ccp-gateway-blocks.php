<?php
if (!defined('ABSPATH')) exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_Chap_Chap_Pay_Blocks extends AbstractPaymentMethodType {

    protected $name = 'chap_chap_pay';
    private $gateway;

    public function initialize() {
        $this->settings = get_option('woocommerce_chap_chap_pay_settings', []);
        
        $gateways = WC()->payment_gateways()->payment_gateways();
        $this->gateway = $gateways['chap_chap_pay'] ?? null;
    }

    public function is_active() {
        return $this->gateway && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'chapchap-pay-blocks',
            plugin_dir_url(__DIR__) . 'assets/js/blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-api-fetch'],
            '1.0.1',
            true
        );
        
        return ['chapchap-pay-blocks'];
    }

    public function get_payment_method_data() {
        $title = $this->gateway ? $this->gateway->title : __('Chap Chap Pay', 'chap-chap-pay');
        $description = $this->gateway ? $this->gateway->description : __('Payez via Chap Chap Pay', 'chap-chap-pay');
        
        return [
            'title' => $title,
            'description' => $description,
            'supports' => $this->get_supported_features(),
            'icon' => $this->gateway ? $this->gateway->icon : '',
        ];
    }

    private function get_supported_features() {
        return ['products'];
    }
}