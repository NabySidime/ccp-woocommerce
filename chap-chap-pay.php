<?php
/**
 * Plugin Name: Chap Chap Pay
 * Plugin URI:  https://www.chapchappay.com/
 * Author:      CHAP CHAP PAY S.A
 * Author URI:  https://www.chapchappay.com/
 * Description: Allow users to pay using PayCard, Orange Money, MTN Mobile, Visa & MasterCard.
 * Version:     1.0.1
 * License:     GPL-2.0+
 * text-domain: chap-chap-pay
 */

if (!defined('ABSPATH')) exit;

// Vérifier WooCommerce
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . __('Chap Chap Pay nécessite WooCommerce pour fonctionner.', 'chap-chap-pay') . '</p></div>';
    });
    return;
}

add_action('plugins_loaded', 'chap_chap_pay_init', 11);

function chap_chap_pay_init()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    // Inclure les fichiers nécessaires
    require_once plugin_dir_path(__FILE__) . 'includes/ccp-gateway.php';
    require_once plugin_dir_path(__FILE__) . 'includes/ccp-rest-api.php';

    // Ajouter la gateway à WooCommerce
    add_filter('woocommerce_payment_gateways', function($methods){
        $methods[] = 'WC_Gateway_Chap_Chap_Pay';
        return $methods;
    });

    // Support pour WooCommerce Blocks
    add_action('woocommerce_blocks_loaded', 'chapchap_register_blocks_support');
}

function chapchap_register_blocks_support() {                                                                                                                                                                                                                                                                                                                                                                                                                                                   //211101-071104
    // Inclure la classe blocks
    require_once plugin_dir_path(__FILE__) . 'includes/ccp-gateway-blocks.php';
    
    // Enregistrer la méthode de paiement pour les blocks
    add_action('woocommerce_blocks_payment_method_type_registration', function($payment_method_registry) {
        if (class_exists('WC_Gateway_Chap_Chap_Pay_Blocks')) {
            $payment_method_registry->register(new WC_Gateway_Chap_Chap_Pay_Blocks());
        }
    });
}

// Charger les scripts pour les blocks
add_action('wp_enqueue_scripts', 'chapchap_enqueue_scripts');

function chapchap_enqueue_scripts() {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    // Enregistrer le script pour les blocks
    wp_register_script(
        'chapchap-pay-blocks',
        plugins_url('assets/js/blocks.js', __FILE__),
        array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-api-fetch'),
        '1.0.1',
        true
    );

    wp_enqueue_script('chapchap-pay-blocks');
}