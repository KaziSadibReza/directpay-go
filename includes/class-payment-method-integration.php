<?php
/**
 * Payment Method Integration for DirectPay Go
 * 
 * Mimics WooCommerce Blocks approach for payment gateway integration
 * This ensures payment gateways (especially Stripe) work exactly like in WC Blocks
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Payment_Method_Integration {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add REST API endpoints
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Get available payment methods
        register_rest_route('directpay/v1', '/payment-methods', [
            'methods' => 'GET',
            'callback' => [$this, 'get_payment_methods'],
            'permission_callback' => '__return_true',
        ]);
        
        // Render native payment gateway fields
        register_rest_route('directpay/v1', '/payment-fields', [
            'methods' => 'POST',
            'callback' => [$this, 'render_payment_fields'],
            'permission_callback' => '__return_true',
            'args' => [
                'payment_method' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);
        
        // Create Stripe Payment Intent
        register_rest_route('directpay/v1', '/create-payment-intent', [
            'methods' => 'POST',
            'callback' => [$this, 'create_payment_intent'],
            'permission_callback' => '__return_true',
            'args' => [
                'amount' => [
                    'required' => true,
                    'type' => 'number',
                ],
            ],
        ]);
        
        // Get Express Checkout parameters
        register_rest_route('directpay/v1', '/express-checkout-params', [
            'methods' => 'GET',
            'callback' => [$this, 'get_express_checkout_params'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    /**
     * Render native WooCommerce payment gateway fields
     */
    public function render_payment_fields($request) {
        try {
            $payment_method = $request->get_param('payment_method');
            
            error_log('DirectPay: Rendering payment fields for: ' . $payment_method);
            
            // Get the gateway
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();
            
            error_log('DirectPay: Available gateways: ' . print_r(array_keys($gateways), true));
            
            if (!isset($gateways[$payment_method])) {
                error_log('DirectPay: Payment method not found: ' . $payment_method);
                return new WP_Error('invalid_gateway', 'Payment method not found', ['status' => 404]);
            }
            
            $gateway = $gateways[$payment_method];
            error_log('DirectPay: Gateway class: ' . get_class($gateway));
            
            // Capture payment fields HTML
            ob_start();
            $gateway->payment_fields();
            $html = ob_get_clean();
            
            error_log('DirectPay: Generated HTML length: ' . strlen($html));
            error_log('DirectPay: HTML preview: ' . substr($html, 0, 200));
            
            // Get required scripts
            $scripts = [];
            if ($payment_method === 'stripe') {
                $stripe_settings = get_option('woocommerce_stripe_settings', []);
                $test_mode = (!empty($stripe_settings['testmode']) && 'yes' === $stripe_settings['testmode']);
                $publishable_key = $test_mode 
                    ? ($stripe_settings['test_publishable_key'] ?? '') 
                    : ($stripe_settings['publishable_key'] ?? '');
                
                error_log('DirectPay: Stripe test mode: ' . ($test_mode ? 'yes' : 'no'));
                error_log('DirectPay: Stripe publishable key exists: ' . (!empty($publishable_key) ? 'yes' : 'no'));
                
                // Get Stripe plugin URL
                $stripe_plugin_file = WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/woocommerce-gateway-stripe.php';
                
                // Load WordPress dependencies first (in correct order)
                $scripts[] = [
                    'handle' => 'wp-polyfill',
                    'src' => includes_url('js/dist/vendor/wp-polyfill.min.js'),
                ];
                
                $scripts[] = [
                    'handle' => 'wp-hooks',
                    'src' => includes_url('js/dist/hooks.min.js'),
                ];
                
                $scripts[] = [
                    'handle' => 'wp-i18n',
                    'src' => includes_url('js/dist/i18n.min.js'),
                ];
                
                $scripts[] = [
                    'handle' => 'stripe-js',
                    'src' => 'https://js.stripe.com/v3/',
                ];
                
                // Check if using UPE (new) or Classic (old) gateway
                $is_upe = (strpos($html, 'wc-stripe-upe') !== false);
                error_log('DirectPay: Is UPE gateway: ' . ($is_upe ? 'yes' : 'no'));
                
                // Only add WC Stripe scripts if plugin file exists
                if (file_exists($stripe_plugin_file)) {
                    error_log('DirectPay: Stripe plugin file found');
                    
                    if ($is_upe) {
                        // Use UPE (Unified Payment Element) script
                        $scripts[] = [
                            'handle' => 'wc-stripe-upe',
                            'src' => plugins_url('build/upe-classic.js', $stripe_plugin_file),
                            'params' => [
                                'key' => $publishable_key,
                                'testmode' => $test_mode,
                            ],
                        ];
                    } else {
                        // Use classic card elements script
                        $scripts[] = [
                            'handle' => 'wc-stripe',
                            'src' => plugins_url('assets/js/stripe.js', $stripe_plugin_file),
                            'params' => [
                                'key' => $publishable_key,
                                'testmode' => $test_mode,
                            ],
                        ];
                    }
                } else {
                    error_log('DirectPay: Stripe plugin file NOT found at: ' . $stripe_plugin_file);
                }
            }
            
            error_log('DirectPay: Returning ' . count($scripts) . ' scripts');
            
            return new WP_REST_Response([
                'html' => $html,
                'scripts' => $scripts,
            ], 200);
            
        } catch (Exception $e) {
            error_log('DirectPay Payment Fields Error: ' . $e->getMessage());
            error_log('DirectPay Stack trace: ' . $e->getTraceAsString());
            return new WP_Error('render_failed', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Get available payment methods
     */
    public function get_payment_methods($request) {
        try {
            // Get all available payment gateways
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();
            
            $payment_methods = [];
            
            foreach ($gateways as $gateway_id => $gateway) {
                if ($gateway->enabled === 'yes') {
                    $payment_methods[] = [
                        'id' => $gateway_id,
                        'title' => $gateway->get_title(),
                        'description' => $gateway->get_description(),
                        'icon' => $gateway->get_icon(),
                        'supports' => [
                            'tokenization' => $gateway->supports('tokenization'),
                            'saved_cards' => $gateway->supports('tokenization'),
                        ],
                    ];
                }
            }
            
            return new WP_REST_Response([
                'payment_methods' => $payment_methods,
            ], 200);
            
        } catch (Exception $e) {
            error_log('DirectPay Payment Methods Error: ' . $e->getMessage());
            return new WP_Error('payment_methods_failed', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Create Stripe Payment Intent
     */
    public function create_payment_intent($request) {
        try {
            // Check if Stripe Gateway is active
            if (!class_exists('WC_Stripe_API')) {
                error_log('DirectPay Error: WC_Stripe_API class not found');
                return new WP_Error('no_stripe', 'WooCommerce Stripe Gateway not active', ['status' => 400]);
            }
            
            $amount = $request->get_param('amount');
            
            if (!$amount || $amount <= 0) {
                error_log('DirectPay Error: Invalid amount - ' . $amount);
                return new WP_Error('invalid_amount', 'Amount must be greater than 0', ['status' => 400]);
            }
            
            // Convert amount to cents (Stripe expects smallest currency unit)
            $amount_cents = intval($amount * 100);
            
            // Get currency
            $currency = strtolower(get_woocommerce_currency());
            
            // Get Stripe settings
            $stripe_settings = get_option('woocommerce_stripe_settings', []);
            $test_mode = (!empty($stripe_settings['testmode']) && 'yes' === $stripe_settings['testmode']);
            
            // Get publishable key
            $publishable_key = $test_mode 
                ? ($stripe_settings['test_publishable_key'] ?? '') 
                : ($stripe_settings['publishable_key'] ?? '');
            
            if (empty($publishable_key)) {
                error_log('DirectPay Error: Stripe publishable key not configured');
                return new WP_Error('no_key', 'Stripe publishable key not configured', ['status' => 500]);
            }
            
            // Create Payment Intent via Stripe API
            $intent_data = apply_filters('wc_stripe_generate_payment_intent_args', [
                'amount' => $amount_cents,
                'currency' => $currency,
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
                'capture_method' => 'automatic',
            ]);
            
            // Log the request data for debugging
            error_log('DirectPay Creating Payment Intent: ' . json_encode($intent_data));
            
            // Call Stripe API
            $response = WC_Stripe_API::request($intent_data, 'payment_intents');
            
            // Log the response for debugging
            error_log('DirectPay Stripe Response: ' . json_encode($response));
            
            if (!$response || !isset($response->client_secret)) {
                $error_msg = isset($response->error) ? $response->error->message : 'Failed to create payment intent';
                $error_details = [
                    'message' => $error_msg,
                    'response' => $response,
                ];
                
                error_log('DirectPay Payment Intent Error: ' . $error_msg);
                if (isset($response->error)) {
                    error_log('DirectPay Stripe Full Error: ' . json_encode($response->error));
                    $error_details['stripe_code'] = $response->error->code ?? 'unknown';
                    $error_details['stripe_type'] = $response->error->type ?? 'unknown';
                }
                
                return new WP_Error(
                    'payment_failed', 
                    $error_msg, 
                    ['status' => 500, 'details' => $error_details]
                );
            }
            
            return new WP_REST_Response([
                'clientSecret' => $response->client_secret,
                'paymentIntentId' => $response->id, // Add payment intent ID
                'publishableKey' => $publishable_key,
            ], 200);
            
        } catch (Exception $e) {
            error_log('DirectPay Stripe Intent Exception: ' . $e->getMessage());
            error_log('DirectPay Exception Stack Trace: ' . $e->getTraceAsString());
            return new WP_Error(
                'payment_intent_failed',
                $e->getMessage(),
                [
                    'status' => 500,
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );
        }
    }
    
    /**
     * Get Express Checkout parameters
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_express_checkout_params() {
        try {
            // Get Stripe settings
            $stripe_settings = get_option('woocommerce_stripe_settings', []);
            $test_mode = (!empty($stripe_settings['testmode']) && 'yes' === $stripe_settings['testmode']);
            
            $publishable_key = $test_mode 
                ? ($stripe_settings['test_publishable_key'] ?? '')
                : ($stripe_settings['publishable_key'] ?? '');
            
            if (empty($publishable_key)) {
                return new WP_Error('missing_key', 'Stripe publishable key not configured', ['status' => 500]);
            }
            
            // Get currency
            $currency = get_woocommerce_currency();
            
            // Return config - amount will be provided by React from form
            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'publishable_key' => $publishable_key,
                    'currency' => $currency,
                    'test_mode' => $test_mode,
                ],
            ], 200);
            
        } catch (Exception $e) {
            error_log('DirectPay Express Checkout Params Exception: ' . $e->getMessage());
            return new WP_Error(
                'express_checkout_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }
}

// Initialize
new DirectPay_Payment_Method_Integration();
  