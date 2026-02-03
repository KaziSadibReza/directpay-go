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
            'id' => uniqid('loc_'),
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
}

// Initialize
DirectPay_Shipping_Handler::get_instance();
