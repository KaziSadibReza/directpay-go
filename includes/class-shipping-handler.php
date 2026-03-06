<?php
/**
 * Shipping Methods Handler
 * Manages Chronopost and Mondial Relay pickup locations
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Shipping_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Get all shipping locations (admin)
        register_rest_route('directpay/v1', '/shipping/locations', [
            'methods' => 'GET',
            'callback' => [$this, 'get_shipping_locations'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // Add shipping location (admin)
        register_rest_route('directpay/v1', '/shipping/locations', [
            'methods' => 'POST',
            'callback' => [$this, 'add_shipping_location'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // Delete shipping location (admin)
        register_rest_route('directpay/v1', '/shipping/locations/(?P<id>[a-zA-Z0-9_]+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_shipping_location'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // Get checkout locations (public)
        register_rest_route('directpay/v1', '/shipping/checkout-locations', [
            'methods' => 'GET',
            'callback' => [$this, 'get_checkout_locations'],
            'permission_callback' => '__return_true'
        ]);

        // Get available shipping providers for a country (public)
        register_rest_route('directpay/v1', '/shipping/checkout-providers', [
            'methods' => 'GET',
            'callback' => [$this, 'get_checkout_providers'],
            'permission_callback' => '__return_true'
        ]);
        
        // Add pricing rule (admin)
        register_rest_route('directpay/v1', '/shipping/pricing', [
            'methods' => 'POST',
            'callback' => [$this, 'add_pricing_rule'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // Delete pricing rule (admin)
        register_rest_route('directpay/v1', '/shipping/pricing/(?P<country>[A-Z]{2})', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_pricing_rule'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // ── Mondial Relay API Integration ──
        
        // Save MR API settings
        register_rest_route('directpay/v1', '/shipping/mondial-relay/settings', [
            'methods' => 'POST',
            'callback' => [$this, 'save_mr_settings'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // Get MR API settings
        register_rest_route('directpay/v1', '/shipping/mondial-relay/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_mr_settings'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // Test MR API connection
        register_rest_route('directpay/v1', '/shipping/mondial-relay/test', [
            'methods' => 'POST',
            'callback' => [$this, 'test_mr_connection'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // Search MR relay points
        register_rest_route('directpay/v1', '/shipping/mondial-relay/search-points', [
            'methods' => 'GET',
            'callback' => [$this, 'search_mr_relay_points'],
            'permission_callback' => '__return_true'
        ]);
        
        // Create MR shipment (send order to Mondial Relay)
        register_rest_route('directpay/v1', '/shipping/mondial-relay/create-shipment', [
            'methods' => 'POST',
            'callback' => [$this, 'create_mr_shipment'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // Get MR shipping label
        register_rest_route('directpay/v1', '/shipping/mondial-relay/label/(?P<expedition>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_mr_label'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
    }
    
    /**
     * Get all shipping locations for admin
     */
    public function get_shipping_locations($request) {
        $chronopost = get_option('directpay_chronopost_locations', []);
        $mondial_relay = get_option('directpay_mondial_relay_locations', []);
        $chronopost_pricing = get_option('directpay_chronopost_pricing', []);
        $mondial_relay_pricing = get_option('directpay_mondial_relay_pricing', []);
        
        return new WP_REST_Response([
            'chronopost' => $chronopost,
            'mondial_relay' => $mondial_relay,
            'chronopost_pricing' => $chronopost_pricing,
            'mondial_relay_pricing' => $mondial_relay_pricing
        ], 200);
    }
    
    /**
     * Add new shipping location
     */
    public function add_shipping_location($request) {
        $location_data = $request->get_json_params();
        
        if (!$location_data) {
            return new WP_Error('invalid_data', 'Invalid location data', ['status' => 400]);
        }
        
        // Validate required fields
        $required_fields = ['name', 'address', 'city', 'postalCode', 'country', 'type'];
        foreach ($required_fields as $field) {
            if (empty($location_data[$field])) {
                return new WP_Error('missing_field', "Missing required field: {$field}", ['status' => 400]);
            }
        }
        
        $type = $location_data['type'];
        $option_key = $type === 'chronopost' ? 'directpay_chronopost_locations' : 'directpay_mondial_relay_locations';
        
        $locations = get_option($option_key, []);
        
        // Create new location
        $new_location = [
            'id' => 'loc_' . wp_generate_password(12, false),
            'name' => sanitize_text_field($location_data['name']),
            'address' => sanitize_text_field($location_data['address']),
            'city' => sanitize_text_field($location_data['city']),
            'postalCode' => sanitize_text_field($location_data['postalCode']),
            'country' => sanitize_text_field($location_data['country']),
            'createdAt' => current_time('mysql'),
        ];
        
        $locations[] = $new_location;
        update_option($option_key, $locations);
        
        return new WP_REST_Response([
            'message' => 'Location added successfully',
            'location' => $new_location
        ], 201);
    }
    
    /**
     * Delete shipping location
     */
    public function delete_shipping_location($request) {
        $location_id = $request->get_param('id');
        $type = $request->get_param('type');
        
        if (!$type || !in_array($type, ['chronopost', 'mondial-relay'])) {
            return new WP_Error('invalid_type', 'Invalid shipping type', ['status' => 400]);
        }
        
        $option_key = $type === 'chronopost' ? 'directpay_chronopost_locations' : 'directpay_mondial_relay_locations';
        
        $locations = get_option($option_key, []);
        $locations = array_filter($locations, function($loc) use ($location_id) {
            return $loc['id'] !== $location_id;
        });
        
        // Re-index array
        $locations = array_values($locations);
        update_option($option_key, $locations);
        
        return new WP_REST_Response([
            'message' => 'Location deleted successfully'
        ], 200);
    }
    
    /**
     * Get locations for checkout (public endpoint)
     */
    public function get_checkout_locations($request) {
        $country = $request->get_param('country');
        $type = $request->get_param('type');
        
        if (!in_array($type, ['chronopost', 'mondial-relay'])) {
            return new WP_Error('invalid_type', 'Invalid shipping type', ['status' => 400]);
        }
        
        $option_key = $type === 'chronopost' ? 'directpay_chronopost_locations' : 'directpay_mondial_relay_locations';
        $all_locations = get_option($option_key, []);
        
        // Filter by country if specified
        if ($country) {
            $all_locations = array_filter($all_locations, function($loc) use ($country) {
                return $loc['country'] === $country;
            });
        }
        
        // Get pricing rules
        $pricing_key = $type === 'chronopost' ? 'directpay_chronopost_pricing' : 'directpay_mondial_relay_pricing';
        $pricing_rules = get_option($pricing_key, []);
        
        // Format for dropdown
        $formatted_locations = [];
        foreach ($all_locations as $location) {
            $country_pricing = isset($pricing_rules[$location['country']]) ? $pricing_rules[$location['country']] : null;
            
            $formatted_locations[] = [
                'id' => $location['id'],
                'name' => $location['name'],
                'address' => $location['address'],
                'city' => $location['city'],
                'postalCode' => $location['postalCode'],
                'country' => $location['country'],
                'expressPrice' => $country_pricing ? floatval($country_pricing['express']) : 0,
                'normalPrice' => $country_pricing ? floatval($country_pricing['normal']) : 0,
                'displayName' => sprintf(
                    '%s - %s, %s %s (%s)',
                    $location['name'],
                    $location['address'],
                    $location['postalCode'],
                    $location['city'],
                    $location['country']
                )
            ];
        }
        
        return new WP_REST_Response($formatted_locations, 200);
    }
    
    /**
     * Add pricing rule for a country
     */
    public function add_pricing_rule($request) {
        $pricing_data = $request->get_json_params();
        
        if (!$pricing_data) {
            return new WP_Error('invalid_data', 'Invalid pricing data', ['status' => 400]);
        }
        
        // Validate required fields
        $required_fields = ['country', 'type'];
        foreach ($required_fields as $field) {
            if (!isset($pricing_data[$field]) || $pricing_data[$field] === '') {
                return new WP_Error('missing_field', "Missing required field: {$field}", ['status' => 400]);
            }
        }
        
        // At least one price must be set
        if (empty($pricing_data['expressPrice']) && empty($pricing_data['normalPrice'])) {
            return new WP_Error('missing_price', 'At least one delivery price must be set', ['status' => 400]);
        }
        
        $type = $pricing_data['type'];
        $country = sanitize_text_field($pricing_data['country']);
        $option_key = $type === 'chronopost' ? 'directpay_chronopost_pricing' : 'directpay_mondial_relay_pricing';
        
        $pricing_rules = get_option($option_key, []);
        
        // Add or update pricing for country
        $pricing_rules[$country] = [
            'express' => !empty($pricing_data['expressPrice']) ? floatval($pricing_data['expressPrice']) : 0,
            'normal' => !empty($pricing_data['normalPrice']) ? floatval($pricing_data['normalPrice']) : 0,
            'updatedAt' => current_time('mysql'),
        ];
        
        update_option($option_key, $pricing_rules);
        
        return new WP_REST_Response([
            'message' => 'Pricing rule added successfully',
            'country' => $country,
            'pricing' => $pricing_rules[$country]
        ], 201);
    }
    
    /**
     * Delete pricing rule for a country
     */
    public function delete_pricing_rule($request) {
        $country = $request->get_param('country');
        $type = $request->get_param('type');
        
        if (!$type || !in_array($type, ['chronopost', 'mondial-relay'])) {
            return new WP_Error('invalid_type', 'Invalid shipping type', ['status' => 400]);
        }
        
        $option_key = $type === 'chronopost' ? 'directpay_chronopost_pricing' : 'directpay_mondial_relay_pricing';
        
        $pricing_rules = get_option($option_key, []);
        
        if (isset($pricing_rules[$country])) {
            unset($pricing_rules[$country]);
            update_option($option_key, $pricing_rules);
        }
        
        return new WP_REST_Response([
            'message' => 'Pricing rule deleted successfully'
        ], 200);
    }

    // ─────────────────────────────────────────────────
    // Mondial Relay API Handler Methods
    // ─────────────────────────────────────────────────

    /**
     * Get available shipping providers for a country (public)
     * Returns which providers have pricing configured + their prices
     */
    public function get_checkout_providers($request) {
        $country = sanitize_text_field($request->get_param('country') ?? '');

        if (empty($country)) {
            return new WP_REST_Response(['providers' => []], 200);
        }

        $chronopost_pricing = get_option('directpay_chronopost_pricing', []);
        $mondial_relay_pricing = get_option('directpay_mondial_relay_pricing', []);

        $providers = [];

        // Check Chronopost
        if (isset($chronopost_pricing[$country])) {
            $cp = $chronopost_pricing[$country];
            if (floatval($cp['express'] ?? 0) > 0 || floatval($cp['normal'] ?? 0) > 0) {
                $providers['chronopost'] = [
                    'available' => true,
                    'express'   => floatval($cp['express'] ?? 0),
                    'normal'    => floatval($cp['normal'] ?? 0),
                ];
            }
        }

        // Check Mondial Relay
        if (isset($mondial_relay_pricing[$country])) {
            $mr = $mondial_relay_pricing[$country];
            if (floatval($mr['express'] ?? 0) > 0 || floatval($mr['normal'] ?? 0) > 0) {
                // Also check if MR API is configured
                $mr_settings = get_option('directpay_mondial_relay_api', []);
                $mr_configured = !empty($mr_settings['enseigne']) && !empty($mr_settings['private_key']);

                $providers['mondial_relay'] = [
                    'available'    => true,
                    'express'      => floatval($mr['express'] ?? 0),
                    'normal'       => floatval($mr['normal'] ?? 0),
                    'api_ready'    => $mr_configured,
                ];
            }
        }

        return new WP_REST_Response(['providers' => $providers], 200);
    }

    /**
     * Save Mondial Relay API settings
     */
    public function save_mr_settings($request) {
        $data = $request->get_json_params();

        if (empty($data['enseigne']) || empty($data['private_key'])) {
            return new WP_Error('missing_fields', __('Enseigne and Private Key are required', 'directpay-go'), ['status' => 400]);
        }

        $settings = [
            'enseigne'      => sanitize_text_field($data['enseigne']),
            'private_key'   => sanitize_text_field($data['private_key']),
            'brand_id'      => sanitize_text_field($data['brand_id'] ?? ''),
            'sender_phone'  => sanitize_text_field($data['sender_phone'] ?? ''),
            'sender_email'  => sanitize_email($data['sender_email'] ?? ''),
        ];

        update_option('directpay_mondial_relay_api', $settings);

        return new WP_REST_Response([
            'message'  => __('Mondial Relay API settings saved successfully', 'directpay-go'),
            'settings' => [
                'enseigne' => $settings['enseigne'],
                'brand_id' => $settings['brand_id'],
                'configured' => true,
            ],
        ], 200);
    }

    /**
     * Get Mondial Relay API settings
     */
    public function get_mr_settings($request) {
        $settings = get_option('directpay_mondial_relay_api', []);

        return new WP_REST_Response([
            'enseigne'     => $settings['enseigne'] ?? '',
            'private_key'  => $settings['private_key'] ?? '',
            'brand_id'     => $settings['brand_id'] ?? '',
            'sender_phone' => $settings['sender_phone'] ?? '',
            'sender_email' => $settings['sender_email'] ?? '',
            'configured'   => !empty($settings['enseigne']) && !empty($settings['private_key']),
        ], 200);
    }

    /**
     * Test Mondial Relay API connection
     */
    public function test_mr_connection($request) {
        if (!class_exists('DirectPay_Mondial_Relay_API')) {
            return new WP_Error('class_missing', __('Mondial Relay API class not loaded', 'directpay-go'), ['status' => 500]);
        }

        $result = DirectPay_Mondial_Relay_API::test_connection();

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Search Mondial Relay pickup points
     */
    public function search_mr_relay_points($request) {
        if (!class_exists('DirectPay_Mondial_Relay_API')) {
            return new WP_Error('class_missing', __('Mondial Relay API class not loaded', 'directpay-go'), ['status' => 500]);
        }

        $country  = sanitize_text_field($request->get_param('country') ?? 'FR');
        $postcode = sanitize_text_field($request->get_param('postcode') ?? '');

        if (empty($postcode)) {
            return new WP_Error('missing_postcode', __('Postal code is required', 'directpay-go'), ['status' => 400]);
        }

        $nb_results = intval($request->get_param('nb_results') ?? 20);
        $result = DirectPay_Mondial_Relay_API::search_relay_points($country, $postcode, $nb_results);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'points' => $result,
            'count'  => count($result),
        ], 200);
    }

    /**
     * Create a Mondial Relay shipment from an order
     */
    public function create_mr_shipment($request) {
        if (!class_exists('DirectPay_Mondial_Relay_API')) {
            return new WP_Error('class_missing', __('Mondial Relay API class not loaded', 'directpay-go'), ['status' => 500]);
        }

        $data = $request->get_json_params();

        // Support multiple order IDs (grouped customer shipment)
        $order_ids = [];
        if (!empty($data['order_ids']) && is_array($data['order_ids'])) {
            $order_ids = array_map('intval', $data['order_ids']);
        } elseif (!empty($data['order_id'])) {
            $order_ids = [intval($data['order_id'])];
        }

        if (empty($order_ids)) {
            return new WP_Error('missing_order', __('At least one Order ID is required', 'directpay-go'), ['status' => 400]);
        }

        // Load and validate all orders
        $orders = [];
        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) {
                return new WP_Error('invalid_order', sprintf(__('Order #%d not found', 'directpay-go'), $oid), ['status' => 404]);
            }
            $existing = $order->get_meta('_mr_expedition_num');
            if ($existing) {
                return new WP_Error(
                    'already_shipped',
                    sprintf(__('Order #%d already has expedition number: %s', 'directpay-go'), $oid, $existing),
                    ['status' => 400]
                );
            }
            $orders[] = $order;
        }

        // Use the first order for customer address info
        $primary_order = $orders[0];

        // Get store address as sender
        $store_address  = get_option('woocommerce_store_address', '');
        $store_city     = get_option('woocommerce_store_city', '');
        $store_postcode = get_option('woocommerce_store_postcode', '');
        $store_country  = WC()->countries->get_base_country() ?: 'FR';
        $store_name     = get_option('blogname', 'Store');

        // Build reference from all order IDs
        $order_ids_str = implode(',', $order_ids);
        $reference = sanitize_text_field($data['reference'] ?? ('DP-' . $order_ids_str));

        // Build shipment data
        $shipment_data = [
            // Sender (store)
            'sender_name'       => $store_name,
            'sender_address'    => $store_address,
            'sender_city'       => $store_city,
            'sender_postcode'   => $store_postcode,
            'sender_country'    => $store_country,
            'sender_phone'      => $this->get_sender_phone(),
            'sender_email'      => $this->get_sender_email(),

            // Recipient (customer from primary order)
            'recipient_name'    => $primary_order->get_shipping_first_name() . ' ' . $primary_order->get_shipping_last_name(),
            'recipient_address' => $primary_order->get_shipping_address_1(),
            'recipient_city'    => $primary_order->get_shipping_city(),
            'recipient_postcode'=> $primary_order->get_shipping_postcode(),
            'recipient_country' => $primary_order->get_shipping_country() ?: $primary_order->get_billing_country(),
            'recipient_phone'   => $primary_order->get_billing_phone(),
            'recipient_email'   => $primary_order->get_billing_email(),

            'reference'         => $reference,
            'product_name'      => sanitize_text_field($data['product_name'] ?? 'Commande #' . $order_ids_str),
            'weight'            => intval($data['weight'] ?? 1000),
            'delivery_mode'     => sanitize_text_field($data['delivery_mode'] ?? '24R'),
            'nb_parcels'        => intval($data['nb_parcels'] ?? 1),
            'relay_id'          => sanitize_text_field($data['relay_id'] ?? ''),
        ];

        // Fall back to billing address if shipping is empty
        if (empty(trim($shipment_data['recipient_name']))) {
            $shipment_data['recipient_name'] = $primary_order->get_billing_first_name() . ' ' . $primary_order->get_billing_last_name();
        }
        if (empty($shipment_data['recipient_address'])) {
            $shipment_data['recipient_address'] = $primary_order->get_billing_address_1();
        }
        if (empty($shipment_data['recipient_city'])) {
            $shipment_data['recipient_city'] = $primary_order->get_billing_city();
        }
        if (empty($shipment_data['recipient_postcode'])) {
            $shipment_data['recipient_postcode'] = $primary_order->get_billing_postcode();
        }

        $result = DirectPay_Mondial_Relay_API::create_shipment($shipment_data);

        if (is_wp_error($result)) {
            // Include shipment data summary in error for debugging
            $debug_info = [
                'sender_name'       => $shipment_data['sender_name'],
                'sender_address'    => $shipment_data['sender_address'],
                'sender_city'       => $shipment_data['sender_city'],
                'sender_postcode'   => $shipment_data['sender_postcode'],
                'sender_country'    => $shipment_data['sender_country'],
                'sender_phone'      => $shipment_data['sender_phone'],
                'sender_email'      => $shipment_data['sender_email'],
                'recipient_name'    => $shipment_data['recipient_name'],
                'recipient_address' => $shipment_data['recipient_address'],
                'recipient_city'    => $shipment_data['recipient_city'],
                'recipient_postcode'=> $shipment_data['recipient_postcode'],
                'recipient_country' => $shipment_data['recipient_country'],
                'recipient_phone'   => $shipment_data['recipient_phone'],
                'delivery_mode'     => $shipment_data['delivery_mode'],
                'relay_id'          => $shipment_data['relay_id'],
                'weight'            => $shipment_data['weight'],
            ];
            error_log('MR Shipment Data that failed: ' . json_encode($debug_info));
            $result->add_data(array_merge($result->get_error_data() ?: [], ['shipment_debug' => $debug_info]));
            return $result;
        }

        // Save expedition number on ALL orders in this shipment
        foreach ($orders as $order) {
            $order->update_meta_data('_mr_expedition_num', $result['expedition_num']);
            $order->update_meta_data('_mr_tracking_url', $result['tracking_url']);
            $order->update_meta_data('_mr_shipment_date', current_time('mysql'));
            $order->update_meta_data('_mr_shipment_weight', $shipment_data['weight']);
            $order->update_meta_data('_mr_product_name', $shipment_data['product_name']);
            $order->add_order_note(
                sprintf(
                    __('Mondial Relay shipment created (grouped with orders: %s). Expedition: %s | Weight: %dg | Product: %s', 'directpay-go'),
                    $order_ids_str,
                    $result['expedition_num'],
                    $shipment_data['weight'],
                    $shipment_data['product_name']
                )
            );
            $order->save();
        }

        return new WP_REST_Response([
            'message'        => __('Shipment created successfully', 'directpay-go'),
            'expedition_num' => $result['expedition_num'],
            'tracking_url'   => $result['tracking_url'],
            'order_ids'      => $order_ids,
        ], 201);
    }

    /**
     * Get Mondial Relay shipping label
     */
    public function get_mr_label($request) {
        if (!class_exists('DirectPay_Mondial_Relay_API')) {
            return new WP_Error('class_missing', __('Mondial Relay API class not loaded', 'directpay-go'), ['status' => 500]);
        }

        $expedition = sanitize_text_field($request->get_param('expedition'));

        if (empty($expedition)) {
            return new WP_Error('missing_expedition', __('Expedition number is required', 'directpay-go'), ['status' => 400]);
        }

        $result = DirectPay_Mondial_Relay_API::get_label($expedition);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'label_url'      => $result['label_url'],
            'expedition_num' => $expedition,
        ], 200);
    }

    /**
     * Get sender phone from MR settings, falling back to admin user phone.
     */
    private function get_sender_phone() {
        $mr_settings = get_option('directpay_mondial_relay_api', []);
        if (!empty($mr_settings['sender_phone'])) {
            return $mr_settings['sender_phone'];
        }
        // Fallback: admin user phone
        $admin_id = get_option('admin_user', 1);
        $phone = get_user_meta($admin_id, 'billing_phone', true);
        return $phone ?: '';
    }

    /**
     * Get sender email from MR settings, falling back to admin email.
     */
    private function get_sender_email() {
        $mr_settings = get_option('directpay_mondial_relay_api', []);
        if (!empty($mr_settings['sender_email'])) {
            return $mr_settings['sender_email'];
        }
        return get_option('admin_email', '');
    }
}

// Initialize
DirectPay_Shipping_Handler::get_instance();
