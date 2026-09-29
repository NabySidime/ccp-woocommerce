<?php
/**
 * Class WC_Gateway_Chap_Chap_Pay file.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Chap_Chap_Pay extends WC_Payment_Gateway
{
    public $api_key;
    public $encryption_key;
    public $api_url;
    public $notify_url;

    /**
     * Liste complète des moyens de paiement supportés par l'API ChapChapPay.
     */
    const ALL_PAYMENT_METHODS = array(
        'paycard',
        'cc',
        'orange_money',
        'mtn_momo',
        'kulu',
        'soutra_money',
        'akiba',
    );

    public function __construct()
    {
        $this->id = 'chap_chap_pay';
        $this->icon = apply_filters('woocommerce_chap_chap_pay_icon', plugins_url('/assets/logo.png', dirname(__FILE__)));
        $this->method_title = __('Chap Chap Pay', 'chap-chap-pay');
        $this->method_description = __('Accept payment using CHAP CHAP PAY.', 'chap-chap-pay');
        $this->order_button_text = __('Payez', 'chap-chap-pay');
        $this->has_fields = false;
        $this->notify_url = WC()->api_request_url('WC_Gateway_Chap_Chap_Pay');

        $this->supports = array('products');

        // Initialisation
        $this->init_form_fields();
        $this->init_settings();

        // Variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->api_key = $this->get_option('api_key');
        $this->encryption_key = $this->get_option('encryption_key');
        $this->api_url = 'https://chapchappay.com/api/ecommerce/create';
        $this->enabled = $this->get_option('enabled');

        // Actions
        if (is_admin()) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_response'));
        add_action('woocommerce_before_thankyou', array($this, 'thankyou_page_content'));
    }

    /**
     * Check if the gateway is available for use
     */
    public function is_available() {
        // Vérifier d'abord si le gateway est activé
        if ($this->enabled !== 'yes') {
            return false;
        }

        // Vérifier que les clés API sont configurées
        if (empty($this->api_key) || empty($this->encryption_key)) {
            return false;
        }

        // Vérifier la devise (GNF seulement)
        $currency = get_woocommerce_currency();
        if ($currency !== 'GNF') {
            return false;
        }

        // Vérifier le montant du panier (uniquement sur les pages de checkout)
        if (is_checkout() || is_cart()) {
            $cart_total = WC()->cart ? WC()->cart->get_total('edit') : 0;
            $cart_total_float = floatval($cart_total);
            $minimum_amount = 5000; // 5 000 GNF minimum

            if ($cart_total_float > 0 && $cart_total_float < $minimum_amount) {
                return false;
            }
        }

        return parent::is_available();
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Activer/Desactiver', 'chap-chap-pay'),
                'label' => __('Activer Chap Chap Pay', 'chap-chap-pay'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Titre', 'chap-chap-pay'),
                'type' => 'text',
                'description' => __('Titre affiché lors du paiement', 'chap-chap-pay'),
                'default' => __('Chap Chap Pay', 'chap-chap-pay'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'chap-chap-pay'),
                'type' => 'textarea',
                'description' => __('Description affichée lors du paiement', 'chap-chap-pay'),
                'default' => __('Payer avec PayCard, Orange Money, MTN Mobile, Visa & MasterCard. Montant minimum: 5 000 GNF', 'chap-chap-pay'),
                'desc_tip' => true,
            ),
            'api_key' => array(
                'title' => __('API Key', 'chap-chap-pay'),
                'type' => 'text',
                'description' => __('Votre clé API ChapChapPay', 'chap-chap-pay'),
                'default' => '',
            ),
            'encryption_key' => array(
                'title' => __('Clé de chiffrement', 'chap-chap-pay'),
                'type' => 'password',
                'description' => __('Votre clé secrète pour HMAC', 'chap-chap-pay'),
                'default' => '',
            ),
            'payment_methods' => array(
                'title' => __('Moyens de paiement autorisés', 'chap-chap-pay'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'css' => 'width: 400px;',
                'description' => __('Moyens de paiement proposés au client sur la page ChapChapPay. Si tous sont sélectionnés (ou aucun), tous seront proposés par défaut.', 'chap-chap-pay'),
                'default' => self::ALL_PAYMENT_METHODS,
                'options' => array(
                    'paycard'      => __('PayCard', 'chap-chap-pay'),
                    'cc'           => __('Carte bancaire (Visa / MasterCard)', 'chap-chap-pay'),
                    'orange_money' => __('Orange Money', 'chap-chap-pay'),
                    'mtn_momo'     => __('MTN Mobile Money', 'chap-chap-pay'),
                    'kulu'         => __('Kulu', 'chap-chap-pay'),
                    'soutra_money' => __('Soutra Money', 'chap-chap-pay'),
                    'akiba'        => __('Akiba', 'chap-chap-pay'),
                ),
                'desc_tip' => true,
            ),
        );
    }

    /**
     * Calcule la liste des moyens de paiement à désactiver, à partir du
     * réglage "payment_methods" (moyens autorisés). Si tous les moyens
     * sont sélectionnés ou qu'aucun ne l'est, on ne désactive rien afin
     * de laisser ChapChapPay proposer son offre complète par défaut.
     */
    private function get_disabled_payment_methods()
    {
        $selected = $this->get_option('payment_methods', self::ALL_PAYMENT_METHODS);

        if (!is_array($selected) || empty($selected)) {
            return array();
        }

        $disabled = array_values(array_diff(self::ALL_PAYMENT_METHODS, $selected));

        return $disabled;
    }

    /**
     * Résout l'URL de callback à utiliser.
     * En local avec WP_DEBUG actif, on peut forcer une URL ngrok (HTTPS) via
     * la constante CCP_NGROK_BASE définie dans wp-config.php, pour tester
     * le callback sans avoir à coder une URL en dur dans ce fichier.
     */
    private function resolve_callback_url()
    {
        $callback_url = $this->notify_url;

        if (defined('WP_DEBUG') && WP_DEBUG && defined('CCP_NGROK_BASE') && CCP_NGROK_BASE) {
            $callback_url = str_replace('http://localhost', CCP_NGROK_BASE, $this->notify_url);
        }

        return $callback_url;
    }

    /**
     * Résout l'URL de retour à utiliser (même logique que le callback : on
     * force ngrok en local si CCP_NGROK_BASE est défini, sinon on garde
     * l'URL WooCommerce normale, HTTPS en prod).
     */
    private function resolve_return_url($order)
    {
        $return_url = $this->get_return_url($order);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('CCP_NGROK_BASE') && CCP_NGROK_BASE) {
            $return_url = str_replace('http://localhost', CCP_NGROK_BASE, $return_url);
        }

        return $return_url;
    }

    /**
     * Process payment
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        // Validation du montant minimum
        $order_total = floatval($order->get_total());
        $minimum_amount = 5000;

        if ($order_total < $minimum_amount) {
            $error_message = sprintf(
                __('Le montant minimum pour payer avec Chap Chap Pay est de %s GNF. Votre commande est de %s GNF.', 'chap-chap-pay'),
                number_format($minimum_amount, 0, ',', ' '),
                number_format($order_total, 0, ',', ' ')
            );

            error_log('ChapChapPay: Montant insuffisant - Commande: ' . $order_total . ' GNF');

            if (wp_is_json_request() || defined('REST_REQUEST')) {
                return array(
                    'result' => 'failure',
                    'messages' => $error_message,
                    'refresh' => false,
                    'reload' => false
                );
            } else {
                wc_add_notice($error_message, 'error');
                return;
            }
        }

        // Formater l'order_id
        $chapchap_order_id = 'WC-' . $order_id . '-' . time();

        // MODE TEST - Si les clés sont de test
        if ($this->api_key === 'test_key_123' || empty($this->api_key) || $this->api_key === 'votre_clé_api_ici') {

            $test_payment_url = add_query_arg(array(
                'order_id' => $order_id,
                'test_mode' => '1',
                'chapchap_test' => '1',
                'chapchap_order_id' => $chapchap_order_id
            ), $this->get_return_url($order));

            $order->update_status('pending', __('En attente de paiement ChapChapPay (MODE TEST)', 'chap-chap-pay'));

            $order->add_order_note(
                sprintf(__('Paiement ChapChapPay en mode test - Montant: %s GNF - OrderID: %s', 'chap-chap-pay'),
                number_format($order_total, 0, ',', ' '),
                $chapchap_order_id)
            );

            return array(
                'result' => 'success',
                'redirect' => $test_payment_url,
            );
        }

        // Résolution des URLs (callback + retour), avec override ngrok en local si configuré
        $callback_url = $this->resolve_callback_url();
        $return_url = $this->resolve_return_url($order);

        // Données pour l'API ChapChapPay
        $data = array(
            'amount'       => $order_total,
            'order_id'     => $chapchap_order_id,
            'callback_url' => $callback_url,
            'return_url'   => $return_url,
        );

        // Restreindre les moyens de paiement proposés, si l'admin en a désactivé
        $disabled_methods = $this->get_disabled_payment_methods();
        if (!empty($disabled_methods)) {
            $data['options'] = array(
                'disabled-payment-methods' => $disabled_methods,
            );
        }

        error_log('ChapChapPay ENVOI - callback_url: ' . $callback_url . ' | return_url: ' . $return_url . ' | disabled_methods: ' . implode(',', $disabled_methods));

        $headers = array(
            'CCP-Api-Key' => $this->api_key,
            'Content-Type' => 'application/json',
        );

        $response = wp_remote_post($this->api_url, array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => json_encode($data),
            'timeout' => 45,
        ));

        if (is_wp_error($response)) {
            $error_message = __('Erreur de connexion. Veuillez réessayer.', 'chap-chap-pay');

            if (wp_is_json_request() || defined('REST_REQUEST')) {
                return array(
                    'result' => 'failure',
                    'messages' => $error_message,
                    'refresh' => false,
                    'reload' => false
                );
            } else {
                wc_add_notice($error_message, 'error');
                return;
            }
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $response_data = json_decode($body, true);

        if ($response_code === 201 && isset($response_data['payment_url'])) {
            $order->update_status('pending', __('En attente de paiement ChapChapPay', 'chap-chap-pay'));

            // Sauvegarder les métadonnées
            if (isset($response_data['operation_id'])) {
                update_post_meta($order_id, 'Chap Chap Pay Operation ID', $response_data['operation_id']);
            }
            update_post_meta($order_id, 'Chap Chap Pay Formatted Order ID', $chapchap_order_id);

            $order->add_order_note(
                sprintf(__('Paiement ChapChapPay initié - Montant: %s GNF - OrderID: %s', 'chap-chap-pay'),
                number_format($order_total, 0, ',', ' '),
                $chapchap_order_id)
            );

            if (wp_is_json_request() || defined('REST_REQUEST')) {
                return array(
                    'result' => 'success',
                    'redirect' => $response_data['payment_url'],
                    'payment_url' => $response_data['payment_url']
                );
            }

            return array(
                'result' => 'success',
                'redirect' => $response_data['payment_url'],
            );
        } else {
            $error_message = __('Erreur de paiement.', 'chap-chap-pay');
            if (isset($response_data['message'])) {
                $error_message .= ' ' . $response_data['message'];
            } elseif (isset($response_data['error'])) {
                $error_message .= ' ' . $response_data['error'];
            }
            error_log('ChapChapPay: Erreur API - ' . $error_message);

            if (wp_is_json_request() || defined('REST_REQUEST')) {
                return array(
                    'result' => 'failure',
                    'messages' => $error_message,
                    'refresh' => false,
                    'reload' => false
                );
            } else {
                wc_add_notice($error_message, 'error');
                return;
            }
        }
    }

    /**
     * Handle ChapChapPay callback
     */
    public function check_response()
    {
        error_log('ChapChapPay CALLBACK reçu: ' . file_get_contents('php://input'));

        $hmac_signature = $_SERVER['HTTP_CCP_HMAC_SIGNATURE'] ?? '';
        $api_key_header = $_SERVER['HTTP_CCP_API_KEY'] ?? '';
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);

        // Vérifier HMAC
        if (!$this->verify_hmac($payload, $hmac_signature)) {
            status_header(401);
            exit;
        }

        // Vérifier la clé API
        if ($api_key_header !== $this->api_key) {
            status_header(401);
            exit;
        }

        if (isset($data['order_id'])) {
            $chapchap_order_id = $data['order_id'];
            $order_id = $this->find_order_id_from_chapchap_id($chapchap_order_id);

            if (!$order_id) {
                status_header(404);
                exit;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                status_header(404);
                exit;
            }

            $status = $data['status']['code'] ?? '';
            $amount = $data['amount'] ?? 0;
            $operation_id = $data['operation_id'] ?? '';
            $payment_method = $data['transaction']['payment_method'] ?? '';

            // Le statut "success" est définitif (doc ChapChapPay) : on ignore
            // tout callback ultérieur non-success reçu après paiement confirmé.
            if ($order->is_paid() && $status !== 'success') {
                status_header(200);
                echo json_encode(['status' => 'ignored_already_paid']);
                exit;
            }

            // Vérifier le montant
            if (abs($order->get_total() - floatval($amount)) >= 0.001) {
                $order->update_status('on-hold',
                    sprintf(__('Le montant du paiement (%s) ne correspond pas au montant de la commande (%s).', 'chap-chap-pay'),
                    $amount,
                    $order->get_total())
                );
            } else {
                switch ($status) {
                    case 'success':
                        $order->payment_complete($operation_id);
                        $order->add_order_note(
                            sprintf(__('Paiement ChapChapPay réussi. Méthode: %s, Operation: %s', 'chap-chap-pay'),
                            $this->ccpay_payment_text($payment_method),
                            $operation_id)
                        );

                        update_post_meta($order_id, 'Chap Chap Pay Operation ID', $operation_id);
                        update_post_meta($order_id, 'Chap Chap Pay Payment Method', $payment_method);
                        update_post_meta($order_id, 'Chap Chap Pay Payment Status', $status);
                        break;

                    case 'failed':
                        $order->update_status('failed', __('Paiement ChapChapPay échoué.', 'chap-chap-pay'));
                        update_post_meta($order_id, 'Chap Chap Pay Payment Status', 'failed');
                        break;

                    case 'canceled':
                        $order->update_status('cancelled', __('Paiement ChapChapPay annulé.', 'chap-chap-pay'));
                        update_post_meta($order_id, 'Chap Chap Pay Payment Status', 'canceled');
                        break;

                    case 'pending':
                        $order->update_status('pending',
                            sprintf(__('Paiement ChapChapPay en attente. Operation: %s', 'chap-chap-pay'), $operation_id)
                        );
                        update_post_meta($order_id, 'Chap Chap Pay Payment Status', 'pending');
                        break;

                    default:
                        $order->add_order_note(
                            sprintf(__('Statut ChapChapPay non traité: %s', 'chap-chap-pay'), $status)
                        );
                        break;
                }
            }

            status_header(200);
            echo json_encode(['status' => 'processed']);
            exit;
        }

        status_header(400);
        exit;
    }

    /**
     * Find WooCommerce order ID from ChapChapPay formatted order ID
     */
    private function find_order_id_from_chapchap_id($chapchap_order_id) {
        global $wpdb;

        $order_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'Chap Chap Pay Formatted Order ID'
            AND meta_value = %s
        ", $chapchap_order_id));

        if ($order_id) {
            return $order_id;
        }

        if (preg_match('/^WC-(\d+)-\d+$/', $chapchap_order_id, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Verify HMAC signature
     */
    private function verify_hmac($payload, $signature)
    {
        $computed = hash_hmac('sha256', $payload, $this->encryption_key);
        return hash_equals($computed, $signature);
    }

    /**
     * Thank you page content
     */
    public function thankyou_page_content($order_id)
    {
        $order = wc_get_order($order_id);

        if ('chap_chap_pay' === $order->get_payment_method()) {
            echo '<div class="chapchap-thankyou">';
            echo '<h3>' . __('Merci pour votre paiement!', 'chap-chap-pay') . '</h3>';
            echo '<p>' . __('Votre paiement a été traité avec Chap Chap Pay.', 'chap-chap-pay') . '</p>';
            echo '</div>';
        }
    }

    /**
     * Get payment method text (libellé lisible pour les notes de commande)
     */
    private function ccpay_payment_text($payment_method)
    {
        switch ($payment_method) {
            case 'paycard':
                return "PAYCARD";
            case 'cc':
                return "Carte bancaire";
            case 'orange_money':
                return "Orange Money";
            case 'mtn_momo':
                return "MTN Mobile Money";
            case 'kulu':
                return "Kulu";
            case 'soutra_money':
                return "Soutra Money";
            case 'akiba':
                return "Akiba";
            default:
                return $payment_method;
        }
    }

    /**
     * Support pour l'API REST des blocks
     */
    public function supports($feature) {
        return in_array($feature, array(
            'products',
        ));
    }
}