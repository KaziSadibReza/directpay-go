<?php
/**
 * Custom REST API endpoints for DirectPay Go
 * 
 * Handles order creation and custom checkout logic
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Go_API {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    /**
     * Register custom REST routes
     */
    public function register_routes() {
        $namespace = 'directpay/v1';
        
        // Create order endpoint
        register_rest_route($namespace, '/orders', [
            'methods' => 'POST',
            'callback' => [$this, 'create_order'],
            'permission_callback' => '__return_true', // Public endpoint
            'args' => $this->get_order_args(),
        ]);
        
        // Get shipping methods
        register_rest_route($namespace, '/shipping-methods', [
            'methods' => 'GET',
            'callback' => [$this, 'get_shipping_methods'],
            'permission_callback' => '__return_true',
            'args' => [
                'country' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'FR',
                ],
                'postcode' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'city' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'address' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ]);
        
        // Get payment methods
        register_rest_route($namespace, '/payment-methods', [
            'methods' => 'GET',
            'callback' => [$this, 'get_payment_methods'],
            'permission_callback' => '__return_true',
            'args' => [
                'amount' => [
                    'required' => false,
                    'type' => 'number',
                    'default' => 0,
                ],
            ],
        ]);
        
        // Get payment gateway fields HTML (for gateways with custom fields)
        register_rest_route($namespace, '/payment-fields/(?P<gateway_id>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_payment_fields'],
            'permission_callback' => '__return_true',
        ]);
        
        // Validate reference (optional - for real-time validation)
        register_rest_route($namespace, '/validate-reference', [
            'methods' => 'POST',
            'callback' => [$this, 'validate_reference'],
            'permission_callback' => '__return_true',
        ]);
        
        // Calculate shipping for Express Checkout
        register_rest_route($namespace, '/calculate-shipping', [
            'methods' => 'POST',
            'callback' => [$this, 'calculate_shipping'],
            'permission_callback' => '__return_true',
            'args' => [
                'amount' => [
                    'required' => true,
                    'type' => 'number',
                ],
                'country' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'state' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'city' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'postalCode' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ]);
        
        // Process Express Checkout payment
        register_rest_route($namespace, '/process-express-payment', [
            'methods' => 'POST',
            'callback' => [$this, 'process_express_payment'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    /**
     * Get order creation arguments
     */
    private function get_order_args() {
        return [
            'reference' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'amount' => [
                'required' => true,
                'type' => 'number',
                'validate_callback' => function($param) {
                    return $param > 0;
                },
            ],
            'customer' => [
                'required' => true,
                'type' => 'object',
            ],
            'shipping_method' => [
                'required' => false,
                'type' => 'string',
            ],
            'payment_method' => [
                'required' => true,
                'type' => 'string',
            ],
            'locale' => [
                'required' => false,
                'type' => 'string',
                'default' => 'en_US',
            ],
        ];
    }
    
    /**
     * Create order
     */
    public function create_order($request) {
        try {
            $params = $request->get_json_params();
            
            // Validate required data
            if (empty($params['reference']) || empty($params['amount']) || empty($params['customer'])) {
                return new WP_Error(
                    'invalid_data',
                    __('Missing required fields', 'directpay-go'),
                    ['status' => 400]
                );
            }
            
            // Create the order using our Order class
            $order_handler = new DirectPay_Go_Order();
            $order = $order_handler->create_custom_order($params);
            
            if (is_wp_error($order)) {
                return $order;
            }
            
            // Return order details (NO payment_url - everything happens on custom page)
            return new WP_REST_Response([
                'success' => true,
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'order_key' => $order->get_order_key(),
                'total' => $order->get_total(),
                'subtotal' => $order->get_subtotal(),
                'shipping_total' => $order->get_shipping_total(),
                'currency' => $order->get_currency(),
                'status' => $order->get_status(),
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
            ], 201);
            
        } catch (Exception $e) {
            return new WP_Error(
                'order_creation_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Ensure WooCommerce translation files are downloaded and available
     * Automatically downloads translation files from WordPress.org if needed
     */
    private function ensure_woocommerce_translations($locale) {
        // Skip for English
        if ($locale === 'en_US') {
            return true;
        }
        
        // Check if translation files exist
        $wc_mo_file = WP_LANG_DIR . '/plugins/woocommerce-' . $locale . '.mo';
        
        if (file_exists($wc_mo_file)) {
            // Translation already exists
            return true;
        }
        
        // Translation doesn't exist, try to download it
        error_log("DirectPay: Downloading WooCommerce translation for {$locale}");
        
        // Include required WordPress files
        if (!function_exists('wp_download_language_pack')) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }
        
        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        // Get WooCommerce plugin info
        $wc_slug = 'woocommerce';
        $wc_plugin_path = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
        
        if (!file_exists($wc_plugin_path)) {
            error_log("DirectPay: WooCommerce plugin not found");
            return false;
        }
        
        // Get WooCommerce version
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $wc_data = get_plugin_data($wc_plugin_path);
        $wc_version = $wc_data['Version'];
        
        // Try to download the language pack
        try {
            // Use WordPress API to get translation info
            $api_url = "https://api.wordpress.org/translations/plugins/1.0/?slug={$wc_slug}&version={$wc_version}";
            $response = wp_remote_get($api_url, array('timeout' => 10));
            
            if (is_wp_error($response)) {
                error_log("DirectPay: Failed to fetch translation info: " . $response->get_error_message());
                return false;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (empty($data['translations'])) {
                error_log("DirectPay: No translations found for {$locale}");
                return false;
            }
            
            // Find the translation for our locale
            $translation = null;
            foreach ($data['translations'] as $trans) {
                if ($trans['language'] === $locale) {
                    $translation = $trans;
                    break;
                }
            }
            
            if (!$translation) {
                error_log("DirectPay: Translation for {$locale} not found in API response");
                return false;
            }
            
            // Download the translation package
            $package_url = $translation['package'];
            error_log("DirectPay: Downloading from {$package_url}");
            
            $download_response = wp_remote_get($package_url, array('timeout' => 30));
            
            if (is_wp_error($download_response)) {
                error_log("DirectPay: Failed to download translation: " . $download_response->get_error_message());
                return false;
            }
            
            $zip_content = wp_remote_retrieve_body($download_response);
            
            // Save to temp file
            $temp_file = wp_tempnam($package_url);
            file_put_contents($temp_file, $zip_content);
            
            // Unzip to wp-content/languages/plugins/
            WP_Filesystem();
            global $wp_filesystem;
            
            $result = unzip_file($temp_file, WP_LANG_DIR . '/plugins');
            
            // Clean up temp file
            @unlink($temp_file);
            
            if (is_wp_error($result)) {
                error_log("DirectPay: Failed to unzip translation: " . $result->get_error_message());
                return false;
            }
            
            error_log("DirectPay: Successfully downloaded and installed WooCommerce translation for {$locale}");
            return true;
            
        } catch (Exception $e) {
            error_log("DirectPay: Error downloading translation: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get available shipping methods (optimized with caching)
     */
    public function get_shipping_methods($request) {
        try {
            $locale = sanitize_text_field($request->get_param('locale'));
            
            // Map frontend locale to WordPress locale
            $locale_map = array(
                'en' => 'en_US',
                'fr' => 'fr_FR',
                'es' => 'es_ES',
                'de' => 'de_DE',
            );
            
            $wp_locale = isset($locale_map[$locale]) ? $locale_map[$locale] : 'en_US';
            
            // Ensure WooCommerce translation files are downloaded and available
            $this->ensure_woocommerce_translations($wp_locale);
            
            // Switch to requested locale using translation helper
            $locale_state = DirectPay_Translation_Helper::switch_to_locale($wp_locale);
            
            $methods = [];
            
            // Initialize WooCommerce cart and session
            if (!WC()->cart) {
                WC()->frontend_includes();
                WC()->session = new WC_Session_Handler();
                WC()->session->init();
                WC()->cart = new WC_Cart();
                WC()->customer = new WC_Customer(get_current_user_id(), true);
            }
            
            // Get customer data from request if provided
            $customer_data = $request->get_param('customer');
            if ($customer_data) {
                WC()->customer->set_shipping_country($customer_data['country'] ?? 'FR');
                WC()->customer->set_shipping_postcode($customer_data['postcode'] ?? '');
                WC()->customer->set_shipping_city($customer_data['city'] ?? '');
                WC()->customer->set_shipping_address($customer_data['address'] ?? '');
            }
            
            // Calculate shipping
            WC()->cart->calculate_shipping();
            WC()->cart->calculate_totals();
            
            // Get available shipping packages
            $packages = WC()->shipping()->get_packages();
            
            foreach ($packages as $package_key => $package) {
                if (empty($package['rates'])) {
                    continue;
                }
                
                foreach ($package['rates'] as $rate) {
                    // Get meta data for description if available
                    $meta_data = $rate->get_meta_data();
                    $description = '';
                    
                    // Try to get description from rate method
                    if (method_exists($rate, 'get_method')) {
                        $method = $rate->get_method();
                        if ($method && method_exists($method, 'get_method_description')) {
                            $description = wp_strip_all_tags($method->get_method_description());
                        }
                    }
                    
                    $methods[] = [
                        'id' => $rate->id,
                        'method_id' => $rate->method_id,
                        'instance_id' => $rate->instance_id,
                        'name' => $rate->label,
                        'description' => $description,
                        'cost' => floatval($rate->cost),
                        'taxes' => $rate->taxes,
                    ];
                }
            }
            
            // If no methods found, get all configured methods as fallback
            if (empty($methods)) {
                $zones = WC_Shipping_Zones::get_zones();
                
                foreach ($zones as $zone_data) {
                    if (empty($zone_data['shipping_methods'])) {
                        continue;
                    }
                    
                    foreach ($zone_data['shipping_methods'] as $method) {
                        if ($method->enabled !== 'yes') {
                            continue;
                        }
                        
                        $cost = $method->get_option('cost', 0);
                        $description = $method->get_method_description();
                        $methods[] = [
                            'id' => $method->id . ':' . $method->instance_id,
                            'method_id' => $method->id,
                            'instance_id' => $method->instance_id,
                            'name' => $method->get_title(),
                            'description' => $description ? wp_strip_all_tags($description) : '',
                            'cost' => floatval($cost),
                        ];
                    }
                }
                
                // Add worldwide/default zone
                $worldwide_zone = new WC_Shipping_Zone(0);
                foreach ($worldwide_zone->get_shipping_methods(true) as $method) {
                    if ($method->enabled === 'yes') {
                        $cost = $method->get_option('cost', 0);
                        $description = $method->get_method_description();
                        $methods[] = [
                            'id' => $method->id . ':' . $method->instance_id,
                            'method_id' => $method->id,
                            'instance_id' => $method->instance_id,
                            'name' => $method->get_title(),
                            'description' => $description ? wp_strip_all_tags($description) : '',
                            'cost' => floatval($cost),
                        ];
                    }
                }
            }
            
            // Remove duplicates
            $methods = array_values(array_reduce($methods, function($carry, $method) {
                $carry[$method['id']] = $method;
                return $carry;
            }, []));
            
            // Restore original locale
            if ($locale_state['switched']) {
                DirectPay_Translation_Helper::restore_locale($locale_state['original']);
            }
            
            return new WP_REST_Response($methods, 200);
            
        } catch (Exception $e) {
            error_log('DirectPay Shipping Error: ' . $e->getMessage());
            return new WP_Error(
                'shipping_methods_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Get available payment methods (using WooCommerce core)
     */
    public function get_payment_methods($request) {
        try {
            $locale = sanitize_text_field($request->get_param('locale'));
            $amount = floatval($request->get_param('amount'));
            
            error_log("DirectPay: Payment methods requested with locale: " . $locale . " and amount: " . $amount);
            
            // CRITICAL: Initialize cart with amount so payment gateways can load
            // Stripe and other gateways check if there's something to pay for
            if ($amount > 0) {
                $this->initialize_cart_with_amount($amount);
            }
            
            // Map frontend locale to WordPress locale
            $locale_map = array(
                'en' => 'en_US',
                'fr' => 'fr_FR',
                'es' => 'es_ES',
                'de' => 'de_DE',
            );
            
            $wp_locale = isset($locale_map[$locale]) ? $locale_map[$locale] : 'en_US';
            
            error_log("DirectPay: Mapped to WordPress locale: " . $wp_locale);
            error_log("DirectPay: Current locale before switch: " . get_locale());
            
            // Switch to requested locale using translation helper
            $locale_state = DirectPay_Translation_Helper::switch_to_locale($wp_locale);
            
            error_log("DirectPay: Locale switched: " . ($locale_state['switched'] ? 'yes' : 'no'));
            error_log("DirectPay: Current locale after switch: " . get_locale());
            
            $methods = [];
            
            // IMPORTANT: Reinitialize WooCommerce payment gateways AFTER locale switch
            // This ensures gateway titles and descriptions are loaded in the correct language
            // Force recreation by creating new instance
            WC()->payment_gateways = new WC_Payment_Gateways();
            
            // Debug: Log all registered gateways
            $all_gateways = WC()->payment_gateways->payment_gateways();
            error_log("DirectPay: All registered gateways: " . implode(', ', array_keys($all_gateways)));
            
            // Get all available payment gateways (now with translated strings)
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
            error_log("DirectPay: Available gateways: " . implode(', ', array_keys($available_gateways)));
            
            if (empty($available_gateways)) {
                // Restore original locale before returning
                if ($locale_state['switched']) {
                    DirectPay_Translation_Helper::restore_locale($locale_state['original']);
                }
                // No gateways enabled
                return new WP_REST_Response([], 200);
            }
            
            foreach ($available_gateways as $gateway) {
                // Only include enabled gateways
                if ($gateway->enabled === 'yes') {
                    $title = $gateway->get_title();
                    $description = $gateway->get_description();
                    
                    // Debug: Log what we're getting from gateway
                    error_log("DirectPay Gateway: " . $gateway->id . " | Title: " . $title . " | Desc: " . substr($description, 0, 50));
                    
                    // Try translating with __() which checks loaded textdomains
                    if ($locale_state['switched']) {
                        $translated_title = __($title, 'woocommerce');
                        $translated_desc = __($description, 'woocommerce');
                        
                        error_log("DirectPay Translated: Title: " . $translated_title . " | Desc: " . substr($translated_desc, 0, 50));
                        
                        if ($translated_title !== $title) {
                            $title = $translated_title;
                        }
                        if ($translated_desc !== $description) {
                            $description = $translated_desc;
                        }
                    }
                    
                    $methods[] = [
                        'id' => $gateway->id,
                        'title' => $title,
                        'description' => $description ? wp_strip_all_tags($description) : '',
                        'icon' => $gateway->get_icon() ?: '',
                        'has_fields' => $gateway->has_fields(),
                        'method_title' => $gateway->get_method_title(),
                    ];
                }
            }
            
            // Restore original locale
            if ($locale_state['switched']) {
                DirectPay_Translation_Helper::restore_locale($locale_state['original']);
            }
            
            return new WP_REST_Response($methods, 200);
            
        } catch (Exception $e) {
            error_log('DirectPay Payment Error: ' . $e->getMessage());
            return new WP_Error(
                'payment_methods_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Get payment gateway fields HTML for gateways with custom fields
     */
    public function get_payment_fields($request) {
        try {
            $gateway_id = sanitize_text_field($request['gateway_id']);
            $locale = sanitize_text_field($request->get_param('locale'));
            
            // Map frontend locale to WordPress locale
            $locale_map = array(
                'en' => 'en_US',
                'fr' => 'fr_FR',
                'es' => 'es_ES',
                'de' => 'de_DE',
            );
            
            $wp_locale = isset($locale_map[$locale]) ? $locale_map[$locale] : 'en_US';
            
            // Switch to requested locale using translation helper
            $locale_state = DirectPay_Translation_Helper::switch_to_locale($wp_locale);
            
            // Reinitialize WooCommerce payment gateways AFTER locale switch
            // This ensures gateway fields are rendered in the correct language
            WC()->payment_gateways = new WC_Payment_Gateways();
            
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
            
            if (!isset($available_gateways[$gateway_id])) {
                // Restore original locale
                if ($locale_state['switched']) {
                    DirectPay_Translation_Helper::restore_locale($locale_state['original']);
                }
                
                return new WP_Error(
                    'gateway_not_found',
                    __('Payment gateway not found', 'directpay-go'),
                    ['status' => 404]
                );
            }
            
            $gateway = $available_gateways[$gateway_id];
            
            // Capture the payment fields HTML (now in the requested language)
            ob_start();
            $gateway->payment_fields();
            $fields_html = ob_get_clean();
            
            // Restore original locale
            if ($locale_state['switched']) {
                DirectPay_Translation_Helper::restore_locale($locale_state['original']);
            }
            
            return new WP_REST_Response([
                'gateway_id' => $gateway_id,
                'has_fields' => $gateway->has_fields(),
                'fields_html' => $fields_html,
                'locale' => $wp_locale,
                'supports' => [
                    'tokenization' => $gateway->supports('tokenization'),
                    'subscriptions' => $gateway->supports('subscriptions'),
                    'refunds' => $gateway->supports('refunds'),
                ],
            ], 200);
            
        } catch (Exception $e) {
            error_log('DirectPay Payment Fields Error: ' . $e->getMessage());
            return new WP_Error(
                'payment_fields_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Validate reference (optional endpoint)
     */
    public function validate_reference($request) {
        $reference = $request->get_param('reference');
        
        if (empty($reference)) {
            return new WP_Error(
                'invalid_reference',
                __('Reference is required', 'directpay-go'),
                ['status' => 400]
            );
        }
        
        // Check if reference already exists
        $existing_orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => '_custom_reference',
            'meta_value' => $reference,
        ]);
        
        if (!empty($existing_orders)) {
            return new WP_REST_Response([
                'valid' => false,
                'message' => __('This reference already exists', 'directpay-go'),
            ], 200);
        }
        
        return new WP_REST_Response([
            'valid' => true,
            'message' => __('Reference is valid', 'directpay-go'),
        ], 200);
    }
    
    /**
     * Initialize WooCommerce cart with a temporary product for the given amount
     * This is necessary for payment gateways (like Stripe) to initialize properly
     * Payment gateways check if there's an actual cart with items before loading
     */
    private function initialize_cart_with_amount($amount, $calculate_totals = true, $product_id = null) {
        // Initialize WooCommerce cart and session if not already done
        if (!WC()->cart) {
            WC()->frontend_includes();
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
            WC()->cart = new WC_Cart();
            WC()->customer = new WC_Customer(get_current_user_id(), true);
        }
        
        // Clear existing cart
        WC()->cart->empty_cart();
        
        // Create or get a temporary product
        if (!$product_id) {
            $product_id = $this->get_or_create_temp_product();
        }
        
        if ($product_id) {
            // Add filter to force the custom price to persist through all WooCommerce calculations
            add_filter('woocommerce_product_get_price', function($price, $product) use ($amount, $product_id) {
                if ($product->get_id() == $product_id) {
                    return $amount;
                }
                return $price;
            }, 99, 2);
            
            add_filter('woocommerce_product_get_regular_price', function($price, $product) use ($amount, $product_id) {
                if ($product->get_id() == $product_id) {
                    return $amount;
                }
                return $price;
            }, 99, 2);
            
            // Add product to cart
            $cart_item_key = WC()->cart->add_to_cart($product_id, 1);
            
            if ($cart_item_key) {
                // IMPORTANT: Set the price directly on the cart item
                // This ensures the correct price is used during shipping calculation
                WC()->cart->cart_contents[$cart_item_key]['data']->set_price($amount);
                WC()->cart->cart_contents[$cart_item_key]['data']->set_regular_price($amount);
                
                error_log("DirectPay: Cart item added with price: " . $amount);
            }
            
            // Only calculate totals if requested (skip for shipping calculation to avoid premature shipping addition)
            if ($calculate_totals) {
                WC()->cart->calculate_totals();
                error_log("DirectPay: Cart initialized with amount: " . $amount . " | Cart total: " . WC()->cart->get_total('edit'));
            } else {
                error_log("DirectPay: Cart initialized with amount: " . $amount . " | Totals NOT calculated yet");
            }
        }
        
        return $product_id;
    }
    
    /**
     * Get or create a temporary product for checkout
     * This product is used to hold the custom amount in the cart
     * Auto-recreates if product was deleted or is virtual (needs to be shippable)
     */
    private function get_or_create_temp_product() {
        $product_id = get_option('directpay_temp_product_id');
        
        // Check if product exists and is valid
        if ($product_id) {
            $post = get_post($product_id);
            
            // If product was deleted, clear the option
            if (!$post || $post->post_type !== 'product') {
                error_log("DirectPay: Stored product ID {$product_id} not found or invalid, will create new");
                delete_option('directpay_temp_product_id');
                $product_id = false;
            } else {
                // Check if existing product is virtual (needs to be shippable)
                $existing_product = wc_get_product($product_id);
                if ($existing_product && $existing_product->is_virtual()) {
                    error_log("DirectPay: Existing product is virtual, recreating as shippable");
                    wp_delete_post($product_id, true); // Force delete
                    delete_option('directpay_temp_product_id');
                    $product_id = false;
                } else {
                    // Product exists and is valid
                    error_log("DirectPay: Using existing temporary product ID: " . $product_id);
                    return $product_id;
                }
            }
        }
        
        // Create new temporary product
        error_log("DirectPay: Creating new temporary product...");
        $product = new WC_Product_Simple();
        $product->set_name('DirectPay Custom Order');
        $product->set_status('publish'); // Must be published for payment gateways to work
        $product->set_catalog_visibility('hidden'); // Hidden from store but still valid
        $product->set_virtual(false); // IMPORTANT: Must be shippable for WooCommerce to calculate shipping
        $product->set_sold_individually(true);
        $product->set_price(100); // Set a default price (will be overridden)
        $product->set_regular_price(100);
        
        $product_id = $product->save();
        
        if (!$product_id) {
            error_log("DirectPay: ERROR - Failed to create temporary product");
            return false;
        }
        
        // Save product ID for reuse
        update_option('directpay_temp_product_id', $product_id);
        
        error_log("DirectPay: Successfully created temporary product ID: " . $product_id);
        
        return $product_id;
    }
    
    /**
     * Format price for display
     */
    private function format_price($price) {
        return wc_price($price);
    }
    
    /**
     * Calculate shipping rates for Express Checkout
     */
    public function calculate_shipping($request) {
        try {
            $params = $request->get_json_params();
            
            $amount = floatval($params['amount']);
            $country = sanitize_text_field($params['country']);
            $state = isset($params['state']) ? sanitize_text_field($params['state']) : '';
            $city = isset($params['city']) ? sanitize_text_field($params['city']) : '';
            $postcode = isset($params['postalCode']) ? sanitize_text_field($params['postalCode']) : '';
            
            error_log("DirectPay: Calculating shipping for {$country}, {$state}, {$city}, {$postcode}, amount: {$amount}");
            
            // Initialize WooCommerce if not loaded
            if (!did_action('woocommerce_init')) {
                WC()->frontend_includes();
            }
            
            // Initialize session
            if (!WC()->session) {
                WC()->session = new WC_Session_Handler();
                WC()->session->init();
            }
            
            // Initialize cart
            if (!WC()->cart) {
                WC()->cart = new WC_Cart();
            }
            
            // Initialize customer
            if (!WC()->customer) {
                WC()->customer = new WC_Customer(get_current_user_id(), true);
            }
            
            // Initialize cart with the amount (WITHOUT calculating totals yet)
            $product_id = $this->get_or_create_temp_product();
            $this->initialize_cart_with_amount($amount, false, $product_id); // Pass false to skip totals calculation
            
            // Set the shipping address
            WC()->customer->set_shipping_country($country);
            WC()->customer->set_shipping_state($state);
            WC()->customer->set_shipping_city($city);
            WC()->customer->set_shipping_postcode($postcode);
            
            // Also set billing to match shipping for calculation
            WC()->customer->set_billing_country($country);
            WC()->customer->set_billing_state($state);
            WC()->customer->set_billing_city($city);
            WC()->customer->set_billing_postcode($postcode);
            
            error_log("DirectPay: Customer address set. Cart total: " . WC()->cart->get_cart_contents_total());
            
            // NOW calculate shipping with the correct address
            WC()->cart->calculate_shipping();
            WC()->cart->calculate_totals();
            
            error_log("DirectPay: Shipping calculated. Cart total with shipping: " . WC()->cart->get_total(''));
            
            // Get available shipping methods
            $packages = WC()->shipping()->get_packages();
            $shipping_rates = [];
            
            error_log("DirectPay: Packages count: " . count($packages));
            
            if (!empty($packages)) {
                foreach ($packages as $package_key => $package) {
                    error_log("DirectPay: Package {$package_key} has " . count($package['rates']) . " rates");
                    if (!empty($package['rates'])) {
                        foreach ($package['rates'] as $rate) {
                            $shipping_rates[] = [
                                'id' => $rate->get_id(),
                                'label' => $rate->get_label(),
                                'amount' => floatval($rate->get_cost()),
                            ];
                            error_log("DirectPay: Rate: " . $rate->get_label() . " - " . $rate->get_cost());
                        }
                    }
                }
            }
            
            error_log("DirectPay: Found " . count($shipping_rates) . " shipping rates");
            
            // Calculate line items for display
            $line_items = [];
            
            // Add product line item
            $line_items[] = [
                'label' => 'Product',
                'amount' => $amount,
            ];
            
            // Add shipping line item if there are shipping rates
            if (!empty($shipping_rates)) {
                $first_shipping_rate = $shipping_rates[0];
                $line_items[] = [
                    'label' => $first_shipping_rate['label'],
                    'amount' => $first_shipping_rate['amount'],
                ];
            }
            
            // Calculate total (product + shipping)
            $total_amount = $amount;
            if (!empty($shipping_rates)) {
                $total_amount += $shipping_rates[0]['amount'];
            }
            
            error_log("DirectPay: Returning - Product: {$amount}, Shipping: " . ($shipping_rates[0]['amount'] ?? 0) . ", Total: {$total_amount}");
            
            return new WP_REST_Response([
                'success' => true,
                'shippingRates' => $shipping_rates,
                'lineItems' => $line_items,
                'total' => $total_amount,
            ], 200);
            
        } catch (Exception $e) {
            error_log("DirectPay: Error calculating shipping: " . $e->getMessage());
            error_log("DirectPay: Stack trace: " . $e->getTraceAsString());
            return new WP_Error(
                'shipping_calculation_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        } catch (Error $e) {
            error_log("DirectPay: Fatal error calculating shipping: " . $e->getMessage());
            error_log("DirectPay: Stack trace: " . $e->getTraceAsString());
            return new WP_Error(
                'shipping_calculation_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Process Express Checkout payment
     */
    public function process_express_payment($request) {
        try {
            $params = $request->get_json_params();
            error_log("DirectPay: Express payment request: " . print_r($params, true));
            
            // Extract order data
            $reference = sanitize_text_field($params['reference']);
            $amount = floatval($params['amount']);
            $shipping_cost = isset($params['shipping_cost']) ? floatval($params['shipping_cost']) : 0;
            $total = isset($params['total']) ? floatval($params['total']) : $amount;
            
            // Create WooCommerce order
            $order = wc_create_order();
            
            // Add product (temporary product for payment)
            $product_id = $this->get_or_create_temp_product();
            $order->add_product(wc_get_product($product_id), 1, [
                'subtotal' => $amount,
                'total' => $amount,
            ]);
            
            // Set billing address
            $order->set_billing_first_name($params['first_name'] ?? '');
            $order->set_billing_last_name($params['last_name'] ?? '');
            $order->set_billing_address_1($params['billing_address_1'] ?? '');
            $order->set_billing_address_2($params['billing_address_2'] ?? '');
            $order->set_billing_city($params['billing_city'] ?? '');
            $order->set_billing_state($params['billing_state'] ?? '');
            $order->set_billing_postcode($params['billing_postcode'] ?? '');
            $order->set_billing_country($params['billing_country'] ?? '');
            $order->set_billing_email($params['email'] ?? '');
            $order->set_billing_phone($params['phone'] ?? '');
            
            // Set shipping address
            $order->set_shipping_first_name($params['shipping_first_name'] ?? $params['first_name'] ?? '');
            $order->set_shipping_last_name($params['shipping_last_name'] ?? $params['last_name'] ?? '');
            $order->set_shipping_address_1($params['shipping_address_1'] ?? '');
            $order->set_shipping_address_2($params['shipping_address_2'] ?? '');
            $order->set_shipping_city($params['shipping_city'] ?? '');
            $order->set_shipping_state($params['shipping_state'] ?? '');
            $order->set_shipping_postcode($params['shipping_postcode'] ?? '');
            $order->set_shipping_country($params['shipping_country'] ?? '');
            
            // Add shipping if selected
            if ($shipping_cost > 0 && isset($params['shipping_method'])) {
                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_title('Flat rate');
                $shipping_item->set_method_id($params['shipping_method']);
                $shipping_item->set_total($shipping_cost);
                $order->add_item($shipping_item);
            }
            
            // Set order reference as custom meta
            $order->add_meta_data('_directpay_reference', $reference, true);
            
            // Set payment method
            $order->set_payment_method('stripe');
            $order->set_payment_method_title('Stripe (Express Checkout)');
            
            // Calculate totals
            $order->calculate_totals();
            
            // Save order
            $order->save();
            
            error_log("DirectPay: Order created: " . $order->get_id());
            error_log("DirectPay: Order total: " . $order->get_total());
            
            // Return success with order ID
            return new WP_REST_Response([
                'success' => true,
                'order_id' => $order->get_id(),
                'redirect_url' => $order->get_checkout_order_received_url(),
            ], 200);
            
        } catch (Exception $e) {
            error_log("DirectPay: Error processing express payment: " . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

// Initialize API
new DirectPay_Go_API();
