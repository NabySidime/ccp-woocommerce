<?php
/**
 * AJAX handlers for Chap Chap Pay
 */

if (!defined('ABSPATH')) {
    exit;
}

class CCP_Ajax_Handler {
    
    public function __construct() {
        // Actions AJAX pour le traitement des paiements
        add_action('wp_ajax_chapchap_process_payment', array($this, 'process_payment'));
        add_action('wp_ajax_nopriv_chapchap_process_payment', array($this, 'process_payment'));
        
        // Actions pour vérifier le statut des commandes
        add_action('wp_ajax_chapchap_check_order_status', array($this, 'check_order_status'));
        add_action('wp_ajax_nopriv_chapchap_check_order_status', array($this, 'check_order_status'));
        
        // Actions pour les callbacks de notification
        add_action('wp_ajax_chapchap_payment_callback', array($this, 'payment_callback'));
        add_action('wp_ajax_nopriv_chapchap_payment_callback', array($this, 'payment_callback'));
    }
    
    /**
     * Traitement du paiement via AJAX (pour les blocks)
     */
    public function process_payment() {
        try {
            // Vérifier le nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wc_store_api')) {
                throw new Exception(__('Nonce de sécurité invalide.', 'chap-chap-pay'));
            }
            
            // Vérifier que WooCommerce est actif
            if (!class_exists('WC_Payment_Gateway')) {
                throw new Exception(__('WooCommerce n\'est pas disponible.', 'chap-chap-pay'));
            }
            
            // Récupérer les données de la requête
            $order_id = absint($_POST['order_id'] ?? 0);
            
            if (!$order_id) {
                throw new Exception(__('ID de commande manquant.', 'chap-chap-pay'));
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Commande non trouvée.', 'chap-chap-pay'));
            }
            
            // Vérifier que la commande appartient à l'utilisateur actuel (sécurité)
            if (get_current_user_id() !== $order->get_customer_id() && !current_user_can('manage_woocommerce')) {
                throw new Exception(__('Accès non autorisé à cette commande.', 'chap-chap-pay'));
            }
            
            // Récupérer la gateway Chap Chap Pay
            $gateway = WC()->payment_gateways()->get_available_payment_gateways()['chap_chap_pay'] ?? null;
            if (!$gateway || $gateway->id !== 'chap_chap_pay') {
                throw new Exception(__('Gateway Chap Chap Pay non disponible.', 'chap-chap-pay'));
            }
            
            // Données pour l'API ChapChapPay
            $data = array(
                'amount' => floatval($order->get_total()),
                'description' => 'Commande ' . $order->get_order_number(),
                'order_id' => (string) $order_id,
                'notify_url' => $gateway->notify_url . '&ajax=1',
                'return_url' => $gateway->get_return_url($order) . '&ajax=1',
                'cancel_url' => $order->get_cancel_order_url() . '&ajax=1'
            );
            
            $headers = array(
                'CCP-Api-Key' => $gateway->api_key,
                'Content-Type' => 'application/json',
            );
            
            error_log('ChapChapPay AJAX: Envoi API pour commande ' . $order_id);
            
            $response = wp_remote_post($gateway->api_url, array(
                'method' => 'POST',
                'headers' => $headers,
                'body' => json_encode($data),
                'timeout' => 45,
            ));
            
            if (is_wp_error($response)) {
                throw new Exception(__('Erreur de connexion avec le service de paiement.', 'chap-chap-pay'));
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $response_data = json_decode($body, true);
            
            error_log('ChapChapPay AJAX: Réponse API - Code: ' . $response_code);
            
            if ($response_code === 201 && isset($response_data['payment_url'])) {
                $order->update_status('pending', __('En attente de paiement ChapChapPay', 'chap-chap-pay'));
                
                // Sauvegarder les métadonnées
                if (isset($response_data['operation_id'])) {
                    update_post_meta($order_id, 'Chap Chap Pay Operation ID', $response_data['operation_id']);
                }
                
                $order->add_order_note(__('Paiement ChapChapPay initié via AJAX - Le client choisira sa méthode de paiement', 'chap-chap-pay'));
                
                wp_send_json_success(array(
                    'result' => 'success',
                    'redirect' => $response_data['payment_url'],
                    'redirect_url' => $response_data['payment_url'],
                    'operation_id' => $response_data['operation_id'] ?? '',
                    'message' => __('Redirection vers le paiement...', 'chap-chap-pay')
                ));
                
            } else {
                $error_msg = __('Erreur lors de la création du paiement.', 'chap-chap-pay');
                if (isset($response_data['message'])) {
                    $error_msg .= ' ' . $response_data['message'];
                }
                throw new Exception($error_msg);
            }
            
        } catch (Exception $e) {
            error_log('ChapChapPay AJAX Error: ' . $e->getMessage());
            
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'result' => 'failure'
            ));
        }
    }
    
    /**
     * Vérifier le statut d'une commande
     */
    public function check_order_status() {
        try {
            // Vérifier le nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wc_store_api')) {
                throw new Exception(__('Nonce de sécurité invalide.', 'chap-chap-pay'));
            }
            
            $order_id = absint($_POST['order_id'] ?? 0);
            if (!$order_id) {
                throw new Exception(__('ID de commande manquant.', 'chap-chap-pay'));
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Commande non trouvée.', 'chap-chap-pay'));
            }
            
            // Vérifier les droits d'accès
            if (get_current_user_id() !== $order->get_customer_id() && !current_user_can('manage_woocommerce')) {
                throw new Exception(__('Accès non autorisé.', 'chap-chap-pay'));
            }
            
            $status = $order->get_status();
            $status_name = wc_get_order_status_name($status);
            $is_paid = $order->is_paid();
            
            wp_send_json_success(array(
                'order_id' => $order_id,
                'status' => $status,
                'status_name' => $status_name,
                'is_paid' => $is_paid,
                'payment_method' => $order->get_payment_method(),
                'order_key' => $order->get_order_key()
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }
    
    /**
     * Callback pour les notifications de paiement (version AJAX)
     */
    public function payment_callback() {
        try {
            
            $hmac_signature = $_SERVER['HTTP_CCP_HMAC_SIGNATURE'] ?? '';
            $api_key_header = $_SERVER['HTTP_CCP_API_KEY'] ?? '';
            $payload = file_get_contents('php://input');
            $data = json_decode($payload, true);
                        
            // Récupérer la gateway pour vérification
            $gateway = WC()->payment_gateways()->get_available_payment_gateways()['chap_chap_pay'] ?? null;
            if (!$gateway) {
                throw new Exception('Gateway non disponible');
            }
            
            // Vérifier HMAC
            if (!$gateway->verify_hmac($payload, $hmac_signature)) {
                status_header(401);
                wp_send_json_error('Signature invalide');
                return;
            }
            
            // Vérifier la clé API
            if ($api_key_header !== $gateway->api_key) {
                status_header(401);
                wp_send_json_error('Clé API invalide');
                return;
            }
            
            if (isset($data['order_id'])) {
                $order_id = $data['order_id'];
                $order = wc_get_order($order_id);
                
                if (!$order) {
                    status_header(404);
                    wp_send_json_error('Commande non trouvée');
                    return;
                }
                
                // Traiter le statut (identique à la méthode check_response de la gateway)
                $status = $data['status']['code'] ?? '';
                $amount = $data['amount'] ?? 0;
                $operation_id = $data['operation_id'] ?? '';
                $payment_method = $data['transaction']['payment_method'] ?? '';
                                
                // Répondre immédiatement
                wp_send_json_success(array(
                    'received' => true,
                    'order_id' => $order_id,
                    'status' => $status,
                    'processed' => true
                ));
                
            } else {
                status_header(400);
                wp_send_json_error('Données manquantes');
            }
            
        } catch (Exception $e) {
            status_header(500);
            wp_send_json_error('Erreur interne');
        }
    }
    
    /**
     * Méthode utilitaire pour obtenir l'URL AJAX
     */
    public static function get_ajax_url($action) {
        return admin_url('admin-ajax.php?action=' . $action);
    }
}

// Initialisation de la classe
new CCP_Ajax_Handler();

// Fonctions helper accessibles globalement
if (!function_exists('chapchap_get_ajax_url')) {
    function chapchap_get_ajax_url($action) {
        return CCP_Ajax_Handler::get_ajax_url($action);
    }
}