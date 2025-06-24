<?php

/**
 * Payu Calculation Shipping and Tax cost.

 */

class PayuShippingTaxApiCalc
{

    protected $payu_salt;

    public function __construct()
    {
        // Register both REST API routes
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // 1. Shipping cost route
        register_rest_route('payu/v1', '/get-shipping-cost', array(
            'methods' => ['POST'],
            'callback' => array($this, 'payuShippingCostCallback'),
            'permission_callback' => '__return_true',
        ));

        // 2. Token generation route
        register_rest_route('payu/v1', '/generate-user-token', array(
            'methods' => ['POST'],
            'callback' => array($this, 'payu_generate_user_token_callback'),
            'permission_callback' => '__return_true'
        ));
    }

    /* ================================================================
     - ----------- 1. Shipping Cost API -----------------------------
     - This API calculates shipping cost based on user token and address.
    ================================================================ */
    public function payuShippingCostCallback(WP_REST_Request $request)
    {
        $body = $request->get_body();
        $parameters = json_decode($body, true);
    
        error_log('Shipping API Request: ' . $body);
    
        $headers = getallheaders();
        $token = $headers['Auth-Token'] ?? '';
        // $received_hash = $headers['X-Payu-Hash'] ?? '';
    
        // Get salt from plugin settings
        $plugin_data = get_option('woocommerce_payubiz_settings');
        $this->payu_salt = isset($plugin_data['currency1_payu_salt']) ? sanitize_text_field($plugin_data['currency1_payu_salt']) : null;
    
        //  Server-side hash generation
        // $generated_hash = hash('sha256', $body . $this->payu_salt);
    
        //  Hash match check
        // if (empty($received_hash) || $generated_hash !== $received_hash) {
        //     return new WP_REST_Response([
        //         'status' => false,
        //         'data' => [],
        //         'message' => 'Hash mismatch. Request may be tampered.'
        //     ], 403);
        // }

        $email = sanitize_email($parameters['email'] ?? '');
        $txnid = sanitize_text_field($parameters['txnid'] ?? '');
        try {
            if ($token && $this->payu_validate_authentication_token(PAYU_USER_TOKEN_EMAIL, $token)) {
                $response = $this->handleValidToken($parameters, $email, $txnid);
            } else {
                return new WP_REST_Response([
                    'status' => false,
                    'data' => [],
                    'message' => 'Invalid or expired token.'
                ], 401);
            }
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'status' => false,
                'data' => [],
                'message' => 'Exception: ' . $e->getMessage()
            ], 500);
        }

        $response_code = $response['status'] === 'false' ? 400 : 200;
        error_log('Shipping API Response: ' . json_encode($response));
        return new WP_REST_Response($response, $response_code);
    }

    /**
     * Handles the logic for a valid token, including updating the shipping address,
     * creating a guest user if necessary, and returning shipping data.
     *
     * @param array $parameters The request parameters.
     * @param string $email The email address of the user.
     * @param string $txnid The transaction ID.
     * @return array An array containing the status, data, and message.
     */
    private function handleValidToken($parameters, $email, $txnid)
    {
        // error_log('Handling valid token for email: ' . $email . ' and transaction ID: ' . $txnid);

        $parameters['address']['state'] = get_state_code_by_name($parameters['address']['state']);

        if (!$parameters['address']['state']) {
            return [
                'status' => 'false',
                'data' => [],
                'message' => 'The State value is wrong'
            ];
        }

        $session_key = $parameters['udf4'];
        $order_string = explode('_', $txnid);
        $order_id = (int)$order_string[0];
        $order = wc_get_order($order_id);

        $shipping_address = $parameters['address'];
        if (!$email) {
            
            $guest_email = $session_key . '@mailinator.com';
            $user_id = $this->payu_create_guest_user($guest_email);
            if ($user_id) {
                // error_log('Yes insert here and recive a data');
                // error_log($user_id . '<=================>' . $order);
                $this->payu_add_new_guest_user_cart_data($user_id, $session_key);
                $shipping_data = $this->update_cart_data($user_id, $order);
                require_once(ABSPATH . 'wp-admin/includes/user.php');
                wp_delete_user($user_id);
            }
        } else {
            if (email_exists($email)) {
                $user = get_user_by('email', $email);
                $user_id = $user->ID;
                $this->payu_add_new_guest_user_cart_data($user_id, $session_key);
                $order = $this->update_order_shipping_address($order, $shipping_address, $email);
                $shipping_data = $this->update_cart_data($user_id, $order);
            } else {
                $user_id = $this->payu_create_guest_user($email);
                if ($user_id) {
                    $this->payu_add_new_guest_user_cart_data($user_id, $session_key);
                    $order = $this->update_order_shipping_address($order, $shipping_address, $email);
                    $shipping_data = $this->update_cart_data($user_id, $order);
                }
            }
        }

        // error_log(print_r($shipping_data, true));

        if (isset($shipping_data)) {
            return [
                'status' => 'success',
                'data' => $shipping_data,
                'message' => 'Shipping methods fetched successfully.'
            ];
        } else {
            return [
                'status' => 'false',
                'data' => [],
                'message' => 'Shipping Data Not Found'
            ];
        }
    }

    /**
     * Updates the shipping and billing address of the order.
     *
     * @param WC_Order $order The WooCommerce order object.
     * @param array $new_address The new address data to set.
     * @param string $email The email address to set for the order.
     * @return WC_Order The updated order object.
     */
    public function update_order_shipping_address($order, $new_address, $email)
    {
        $order->set_shipping_address($new_address);
        $order->set_address($new_address, 'shipping');
        $order->set_address($new_address, 'billing');
        $order->set_billing_email($email);
        return $order;
    }

    /**
     * Updates the cart data for the user based on the order details against User.
     *
     * @param int $user_id The ID of the user.
     * @param WC_Order $order The WooCommerce order object.
     * @return array An array of shipping data including carrier code, method code, and costs.
     */
    public function update_cart_data($user_id, $order)
    {
        global $wpdb, $table_prefix;

        if (!$user_id || !$order) return [];

        $shipping_data = [];
        $user_session_table = $table_prefix . "woocommerce_sessions";

        // Include necessary WooCommerce files
        include_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-cart-functions.php';
        include_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-notice-functions.php';
        include_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-template-hooks.php';

        // Init session and customer
        WC()->session = new WC_Session_Handler();
        WC()->session->init();

        WC()->customer = new WC_Customer($user_id, true);
        WC()->cart = new WC_Cart();

        // Set customer shipping data
        $shipping_country = $order->get_shipping_country();
        $shipping_state = $order->get_shipping_state();
        $shipping_city = $order->get_shipping_city();
        $shipping_postcode = $order->get_shipping_postcode();
        $shipping_address = $order->get_shipping_address_1();

        WC()->customer->set_shipping_country($shipping_country);
        WC()->customer->set_shipping_state($shipping_state);
        WC()->customer->set_shipping_city($shipping_city);
        WC()->customer->set_shipping_postcode($shipping_postcode);
        WC()->customer->set_shipping_address_1($shipping_address);

        // Update session data manually
        $session_data = WC()->session->get_session($user_id);
        $customer_data = maybe_unserialize($session_data['customer']);

        $customer_data['shipping_country'] = $shipping_country;
        $customer_data['shipping_state'] = $shipping_state;
        $customer_data['shipping_city'] = $shipping_city;
        $customer_data['shipping_postcode'] = $shipping_postcode;
        $customer_data['shipping_address_1'] = $shipping_address;

        $session_data['customer'] = maybe_serialize($customer_data);

        // Update session in DB
        $wpdb->update(
            $user_session_table,
            ['session_value' => maybe_serialize($session_data)],
            ['session_key' => $user_id]
        );

        // Optional: set user as current (for dynamic loading)
        wp_set_current_user($user_id);

        // Calculate totals
        WC()->cart->calculate_totals();

        $shipping_method_count = 0;

        foreach (WC()->cart->get_shipping_packages() as $package_id => $package) {
            if (WC()->session->__isset("shipping_for_package_$package_id")) {
                $rates = WC()->session->get("shipping_for_package_$package_id")['rates'];

                foreach ($rates as $rate) {
                    $tax_amount = array_sum(wp_list_pluck(WC()->cart->get_tax_totals(), 'amount'));

                    $shipping_data[] = [
                        'carrier_code'   => $rate->id,
                        'method_code'    => $rate->get_method_id(),
                        'carrier_title'  => $rate->get_label(),
                        'amount'         => $rate->get_cost(),
                        'tax_price'      => round($tax_amount, 2),
                        'subtotal'       => WC()->cart->get_subtotal(),
                        'grand_total'    => round(WC()->cart->get_subtotal() + $rate->get_cost() + $tax_amount, 2),
                        'error_message'  => '',
                    ];
                }
            }
        }

        return $shipping_data;
    }

    /**
     * Creates a new guest user with the provided email guest_randomnumber.
     *
     * @param string $email The email address for the new user.
     * @return int|false The ID of the newly created user, or false on failure.
     */
    private function payu_create_guest_user($email)
    {
        $user_id = wp_create_user($email, wp_generate_password(), $email);
        return (!is_wp_error($user_id)) ? $user_id : false;
    }

    /**
     * Adds the cart data for a new guest user based on the session key.
     *
     * @param int $user_id The ID of the user.
     * @param string $session_key The session key to retrieve the cart data.
     */

    private function payu_add_new_guest_user_cart_data($user_id, $session_key)
    {
        global $wpdb;
        global $table_prefix, $wpdb;
        $woocommerce_sessions = 'woocommerce_sessions';
        $wp_woocommerce_sessions_table = $table_prefix . "$woocommerce_sessions ";
        // Prepare the SQL query with a placeholder for the session key
        $query = $wpdb->prepare("SELECT session_value FROM $wp_woocommerce_sessions_table
        WHERE session_key = %s", $session_key);

        // Execute the prepared statement
        $wc_session_data = $wpdb->get_var($query);

        $cart_data['cart'] = maybe_unserialize(maybe_unserialize($wc_session_data)['cart']);
        update_user_meta($user_id, '_woocommerce_persistent_cart_1', $cart_data);
    }


    /* ================================================================
     - ----------- 2. Generate User Token API -----------------------------
     - This API generates a user token for Payu authentication.
    ================================================================ */
    public function payu_generate_user_token_callback(WP_REST_Request $request)
    {
       // recive commercepro@payu.in plugin in this request params
        $params = $request->get_params();
        // error_log("PayU Token Request Parameters: " . print_r($params, true));

        $email = sanitize_email($request->get_param('email'));

        if (!$email || !is_email($email)) {
            return new WP_REST_Response([
                'status' => false,
                'data' => [],
                'message' => 'Invalid email address.'
            ], 400);
        }

        $plugin_data = get_option('woocommerce_payubiz_settings');
        $this->payu_salt = isset($plugin_data['currency1_payu_salt']) ? sanitize_text_field($plugin_data['currency1_payu_salt']) : null;

        if (!$this->payu_salt) {
            return new WP_REST_Response([
                'status' => false,
                'data' => [],
                'message' => 'Plugin not configured properly.'
            ], 500);
        }

        if (email_exists($email)) {
            $user = get_user_by('email', $email);
            $token = $this->payu_generate_authentication_token($user->ID, $email);

            return new WP_REST_Response([
                'status' => true,
                'data' => ['token' => $token],
                'message' => 'Token generated.'
            ], 200);
        } else {
            return new WP_REST_Response([
                'status' => false,
                'data' => [],
                'message' => 'Account does not exist for this email.'
            ], 404);
        }
    }


    /**
     * Generates or retrieves a PayU authentication token for the given user.
     *
     * If a valid token already exists in the user meta (i.e., not expired), it is returned.
     * Otherwise, a new token is generated using secure random bytes, stored in user meta,
     * and returned.
     *
     * @param int $user_id The ID of the user for whom the token is generated.
     * @return string The existing or newly generated authentication token.
     */

    private function payu_generate_authentication_token($user_id, $email)
    {

        $stored_token = get_user_meta($user_id, 'payu_auth_token', true);
        $expiration = get_user_meta($user_id, 'payu_auth_token_expiration', true);

        if ($stored_token && $expiration >= time()) {
            return $stored_token;
        }

        $token = bin2hex(random_bytes(50));
        $expiration = time() + 86400;

        update_user_meta($user_id, 'payu_auth_token', $token);
        update_user_meta($user_id, 'payu_auth_token_expiration', $expiration);

        return $token;
    }

    /**
     * Validates the PayU authentication token for the given email.
     *
     * Checks if the token matches the stored token for the user and if it has not expired.
     * call in payuShippingCostCallback method/Function
     * @param string $email The email of the user.
     * @param string $token The authentication token to validate.
     * @return bool True if valid, false otherwise.
     */

    private function payu_validate_authentication_token($email, $token)
    {
        // error_log('EMAIL'. $email);
        $user = get_user_by('email', $email);
        // User Exist here
        // error_log(print_r($user, true));
        if (!$user) return false;

        $stored_token = get_user_meta($user->ID, 'payu_auth_token', true);
        $expiration = get_user_meta($user->ID, 'payu_auth_token_expiration', true);

        // error_log('Stored Token'. $stored_token . 'Expiration'. $expiration);
        // error_log('Token Verification Debug:');
        // error_log('Stored Token: ' . $stored_token);
        // error_log('Generated Token: ' . $token);
        // error_log('Expiration Time: ' . $expiration);
        // error_log('Current Time: ' . time());
        // error_log('Token Match: ' . ($stored_token === $token ? 'true' : 'false'));
        // error_log('Is Not Expired: ' . ($expiration >= time() ? 'true' : 'false'));
        return ($stored_token === $token && $expiration >= time());
    }
}
$payu_shipping_tax_api_calc = new PayuShippingTaxApiCalc();
