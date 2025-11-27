<?php
/**
 * REST API support for Chap Chap Pay
 */

if (!defined('ABSPATH')) {
    exit;
}

class CCP_REST_API_Support {
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    public function register_rest_routes() {
        register_rest_route('chapchap/v1', '/check-status', array(
            'methods' => 'POST',
            'callback' => array($this, 'check_payment_status'),
            'permission_callback' => array($this, 'verify_nonce'),
        ));
    }
    
    public function check_payment_status($request) {
        $params = $request->get_json_params();
        $order_id = $params['order_id'] ?? 0;
        
        if (!$order_id) {
            return new WP_Error('missing_order_id', 'Order ID required', array('status' => 400));
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found', array('status' => 404));
        }
        
        return array(
            'order_id' => $order_id,
            'status' => $order->get_status(),
            'is_paid' => $order->is_paid()
        );
    }
    
    public function verify_nonce($request) {
        $nonce = $request->get_header('X-WC-Store-API-Nonce');
        return wp_verify_nonce($nonce, 'wc_store_api');
    }
}

new CCP_REST_API_Support();