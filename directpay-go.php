<?php
/**
 * Plugin Name: DirectPay Go
 * Plugin URI: https://yoursite.com/directpay-go
 * Description: High-performance custom checkout for WooCommerce with React frontend. Optimized for 500+ concurrent visitors.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yoursite.com
 * Text Domain: directpay-go
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('DIRECTPAY_GO_VERSION', '1.0.0');
define('DIRECTPAY_GO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DIRECTPAY_GO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DIRECTPAY_GO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main DirectPay Go Class
 */
class DirectPay_Go {
    
    /**
     * Single instance
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }
        
        $this->includes();
        $this->init_hooks();
    }
    
    /**
     * Include required files
     */
    private function includes() {
        require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/class-translation-helper.php';
        require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/class-payment-method-integration.php';
        require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/class-api.php';
        require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/class-order.php';
        require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/class-shipping-handler.php';
        require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/class-shipping-session.php';
        
        // Shipping method
        require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/class-wc-shipping-directpay.php';
        
        // Admin area
        if (is_admin()) {
            require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/class-admin.php';
            require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/class-admin-menu.php';
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', [$this, 'load_textdomain']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('directpay_checkout', [$this, 'render_checkout_shortcode']);
        
        // Add custom page template
        add_filter('template_include', [$this, 'custom_page_template'], 99);
        
        // Declare WooCommerce HPOS compatibility
        add_action('before_woocommerce_init', [$this, 'declare_woocommerce_compatibility']);
        
        // Register shipping methods
        add_filter('woocommerce_shipping_methods', [$this, 'register_shipping_methods']);
        
        // Save pickup location on order
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_shipping_pickup_location']);
        
        // Display shipping info in admin order - Disabled, using meta boxes instead
        // add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_shipping_info_in_admin']);
        
        // Display shipping info in customer emails
        add_action('woocommerce_email_after_order_table', [$this, 'display_shipping_info_in_email'], 10, 4);
        
        // Initialize admin
        if (is_admin()) {
            new DirectPayGo\Admin();
            // Initialize order admin hooks
            DirectPay_Go_Order::init_admin_hooks();
        }
    }
    
    /**
     * Declare WooCommerce feature compatibility
     */
    public function declare_woocommerce_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, false);
        }
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'directpay-go',
            false,
            dirname(DIRECTPAY_GO_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    /**
     * Enqueue React app and styles
     */
    public function enqueue_assets() {
        // Only load on pages with our shortcode
        if (!is_page()) {
            return;
        }
        
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'directpay_checkout')) {
            return;
        }
        
        // Enqueue jQuery for Mondial Relay widget
        wp_enqueue_script('jquery');
        
        // Payment gateway scripts are handled by DirectPay_Payment_Method_Integration class
        
        $dist_dir = DIRECTPAY_GO_PLUGIN_DIR . 'dist/';
        $dist_url = DIRECTPAY_GO_PLUGIN_URL . 'dist/';
        
        // Check if manifest exists (production build)
        $manifest_path = $dist_dir . '.vite/manifest.json';
        
        if (file_exists($manifest_path)) {
            // Production mode: Use built assets with cache busting
            $manifest = json_decode(file_get_contents($manifest_path), true);
            
            if (isset($manifest['src/main.jsx'])) {
                $entry = $manifest['src/main.jsx'];
                
                // Enqueue all CSS files from the main entry
                if (!empty($entry['css'])) {
                    foreach ($entry['css'] as $index => $css_file) {
                        wp_enqueue_style(
                            'directpay-go-style-' . $index,
                            $dist_url . $css_file,
                            [],
                            null
                        );
                    }
                }
                
                // Enqueue CSS from imported modules (like CustomDropdown)
                if (!empty($entry['imports'])) {
                    foreach ($entry['imports'] as $import_key) {
                        if (isset($manifest[$import_key]) && !empty($manifest[$import_key]['css'])) {
                            foreach ($manifest[$import_key]['css'] as $index => $css_file) {
                                wp_enqueue_style(
                                    'directpay-go-import-style-' . $index,
                                    $dist_url . $css_file,
                                    [],
                                    null
                                );
                            }
                        }
                    }
                }
                
                // Enqueue JS
                wp_enqueue_script(
                    'directpay-go-app',
                    $dist_url . $entry['file'],
                    [],
                    null,
                    true
                );
                
                // Add module type for production build
                add_filter('script_loader_tag', function($tag, $handle) {
                    if ($handle === 'directpay-go-app') {
                        $tag = str_replace('<script ', '<script type="module" ', $tag);
                    }
                    return $tag;
                }, 10, 2);
            }
        } else {
            // Development mode: Use Vite dev server
            wp_enqueue_script(
                'directpay-go-vite-client',
                'http://localhost:3000/@vite/client',
                [],
                null,
                true
            );
            
            wp_enqueue_script(
                'directpay-go-app',
                'http://localhost:3000/src/main.jsx',
                [],
                null,
                true
            );
            
            // Add module type
            add_filter('script_loader_tag', function($tag, $handle) {
                if (in_array($handle, ['directpay-go-vite-client', 'directpay-go-app'])) {
                    $tag = str_replace('<script ', '<script type="module" ', $tag);
                }
                return $tag;
            }, 10, 2);
        }
        
        // Localize script with config
        
        // Get WooCommerce countries
        $countries_obj = new WC_Countries();
        $countries = $countries_obj->get_countries();
        
        wp_localize_script('directpay-go-app', 'directPayConfig', [
            'apiUrl' => rest_url('directpay/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'storeApiUrl' => rest_url('wc/store/v1'),
            'locale' => get_locale(),
            'currency' => get_woocommerce_currency(),
            'currencySymbol' => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
            'currencyPosition' => get_option('woocommerce_currency_pos', 'left'),
            'currencyDecimalSeparator' => wc_get_price_decimal_separator(),
            'currencyThousandSeparator' => wc_get_price_thousand_separator(),
            'currencyDecimals' => wc_get_price_decimals(),
            'countries' => $countries,
            'defaultCountry' => $countries_obj->get_base_country(),
            'translations' => $this->get_translations(),
        ]);
    }
    
    /**
     * Get translations for React
     */
    private function get_translations() {
        return [
            'checkout_title' => __('Custom Checkout', 'directpay-go'),
            'reference_label' => __('Order Reference', 'directpay-go'),
            'reference_placeholder' => __('Enter your reference', 'directpay-go'),
            'amount_label' => __('Amount', 'directpay-go'),
            'amount_placeholder' => __('Enter amount', 'directpay-go'),
            'email_label' => __('Email', 'directpay-go'),
            'email_placeholder' => __('your@email.com', 'directpay-go'),
            'first_name_label' => __('First Name', 'directpay-go'),
            'last_name_label' => __('Last Name', 'directpay-go'),
            'address_label' => __('Address', 'directpay-go'),
            'city_label' => __('City', 'directpay-go'),
            'postcode_label' => __('Postal Code', 'directpay-go'),
            'country_label' => __('Country', 'directpay-go'),
            'phone_label' => __('Phone', 'directpay-go'),
            'payment_method_label' => __('Payment Method', 'directpay-go'),
            'place_order_button' => __('Place Order', 'directpay-go'),
            'processing' => __('Processing...', 'directpay-go'),
            'error_required' => __('This field is required', 'directpay-go'),
            'error_email' => __('Invalid email address', 'directpay-go'),
            'error_amount' => __('Amount must be greater than 0', 'directpay-go'),
        ];
    }
    
    /**
     * Render checkout shortcode
     */
    public function render_checkout_shortcode($atts) {
        return '<div id="directpay-checkout-root"></div>';
    }
    
    /**
     * Custom page template
     */
    public function custom_page_template($template) {
        if (is_page() && has_shortcode(get_post()->post_content ?? '', 'directpay_checkout')) {
            $custom_template = DIRECTPAY_GO_PLUGIN_DIR . 'templates/checkout-page.php';
            if (file_exists($custom_template)) {
                return $custom_template;
            }
        }
        return $template;
    }
    
    /**
     * Check if WooCommerce is active
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>';
        echo esc_html__('DirectPay Go requires WooCommerce to be installed and active.', 'directpay-go');
        echo '</p></div>';
    }
    
    /**
     * Enqueue payment gateway scripts properly
     * This method calls each gateway's payment_scripts() method to enqueue their assets
     * This is how WooCommerce's default checkout works - we're replicating it
     */
    private function enqueue_payment_gateway_scripts() {
        // Get available payment gateways
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
        
        if (empty($available_gateways)) {
            return;
        }
        
        // Let each gateway enqueue its scripts
        // This is the standard WooCommerce way
        foreach ($available_gateways as $gateway) {
            if ($gateway->enabled === 'yes' && method_exists($gateway, 'payment_scripts')) {
                $gateway->payment_scripts();
            }
        }
        
        // Also enqueue WooCommerce's checkout scripts if not already enqueued
        // These provide jQuery events that gateways depend on
        if (!wp_script_is('wc-checkout', 'enqueued')) {
            wp_enqueue_script('wc-checkout');
        }
    }
    
    /**
     * Register custom shipping method
     */
    public function register_shipping_methods($methods) {
        $methods['directpay_shipping'] = 'WC_Shipping_DirectPay';
        return $methods;
    }
    
    /**
     * Save pickup location on order
     */
    public function save_shipping_pickup_location($order_id) {
        if (isset($_POST['directpay_pickup_location'])) {
            $location_data = json_decode(stripslashes($_POST['directpay_pickup_location']), true);
            if ($location_data) {
                update_post_meta($order_id, '_directpay_pickup_location', $location_data);
            }
        }
        
        if (isset($_POST['directpay_delivery_type'])) {
            update_post_meta($order_id, '_directpay_delivery_type', sanitize_text_field($_POST['directpay_delivery_type']));
        }
        
        if (isset($_POST['directpay_shipping_method'])) {
            update_post_meta($order_id, '_directpay_shipping_method', sanitize_text_field($_POST['directpay_shipping_method']));
        }
    }
    
    
    /**
     * Display shipping info in customer emails
     */
    public function display_shipping_info_in_email($order, $sent_to_admin, $plain_text, $email) {
        $location_data = $order->get_meta('_directpay_pickup_location');
        $delivery_type = $order->get_meta('_directpay_delivery_type');
        $shipping_method = $order->get_meta('_directpay_shipping_method');
        
        if (!$location_data) {
            return;
        }
        
        if ($plain_text) {
            echo "\n" . __('Pickup Location:', 'directpay-go') . "\n";
            echo ucfirst(str_replace('_', ' ', $shipping_method));
            if ($delivery_type) {
                echo ' - ' . ucfirst($delivery_type) . ' Delivery';
            }
            echo "\n";
            
            if (is_array($location_data)) {
                echo $location_data['name'] . "\n";
                echo $location_data['address'] . "\n";
                echo $location_data['postalCode'] . ' ' . $location_data['city'] . "\n";
                echo $location_data['country'] . "\n";
            }
        } else {
            echo '<h2>' . esc_html__('Pickup Location', 'directpay-go') . '</h2>';
            echo '<p><strong>' . esc_html(ucfirst(str_replace('_', ' ', $shipping_method))) . '</strong>';
            if ($delivery_type) {
                echo ' - <span style="color: #0066cc;">' . esc_html(ucfirst($delivery_type)) . ' Delivery</span>';
            }
            echo '</p>';
            
            if (is_array($location_data)) {
                echo '<p>';
                echo '<strong>' . esc_html($location_data['name']) . '</strong><br>';
                echo esc_html($location_data['address']) . '<br>';
                echo esc_html($location_data['postalCode']) . ' ' . esc_html($location_data['city']) . '<br>';
                echo esc_html($location_data['country']);
                echo '</p>';
            }
        }
    }
}

/**
 * Initialize plugin
 */
function directpay_go_init() {
    return DirectPay_Go::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'directpay_go_init');

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('DirectPay Go requires WooCommerce to be installed and active.', 'directpay-go'),
            'Plugin dependency check',
            ['back_link' => true]
        );
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
});

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});
