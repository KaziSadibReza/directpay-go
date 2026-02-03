<?php
/**
 * Admin Menu Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Admin_Menu {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'DirectPay Go',
            'DirectPay Go',
            'manage_woocommerce',
            'directpay-go',
            [$this, 'render_main_page'],
            'dashicons-cart',
            56
        );
        
        add_submenu_page(
            'directpay-go',
            'Orders',
            'Orders',
            'manage_woocommerce',
            'directpay-orders',
            [$this, 'render_orders_page']
        );
        
        add_submenu_page(
            'directpay-go',
            'Shipping Methods',
            'Shipping Methods',
            'manage_woocommerce',
            'directpay-shipping',
            [$this, 'render_shipping_page']
        );
    }
    
    /**
     * Render main admin page
     */
    public function render_main_page() {
        ?>
        <div class="wrap">
            <script>
                // Make config available before React loads
                window.directpayGoAdmin = <?php echo json_encode([
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('directpay_admin_nonce'),
                    'restUrl' => rest_url(),
                    'restNonce' => wp_create_nonce('wp_rest'),
                    'wpVersion' => get_bloginfo('version'),
                    'wcVersion' => defined('WC_VERSION'),
                    'phpVersion' => PHP_VERSION,
                ]); ?>;
            </script>
            <div id="directpay-sessions-root"></div>
        </div>
        <?php
    }
    
    /**
     * Render orders page
     */
    public function render_orders_page() {
        ?>
        <div class="wrap">
            <script>
                // Make config available before React loads
                window.directpayGoAdmin = <?php echo json_encode([
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('directpay_admin_nonce'),
                    'restUrl' => rest_url(),
                    'restNonce' => wp_create_nonce('wp_rest')
                ]); ?>;
            </script>
            <div id="directpay-orders-root"></div>
        </div>
        <?php
    }
    
    /**
     * Render shipping management page
     */
    public function render_shipping_page() {
        // Get WooCommerce countries
        $wc_countries = new WC_Countries();
        $countries_list = $wc_countries->get_countries();
        
        // Format countries for frontend
        $formatted_countries = [];
        foreach ($countries_list as $code => $name) {
            $formatted_countries[] = [
                'value' => $code,
                'label' => $name
            ];
        }
        
        // Get currency symbol
        $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
        
        ?>
        <div class="wrap">
            <script>
                // Make config available before React loads
                window.directpayGoAdmin = <?php echo json_encode([
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('directpay_admin_nonce'),
                    'restUrl' => rest_url(),
                    'restNonce' => wp_create_nonce('wp_rest'),
                    'countries' => $formatted_countries,
                    'currencySymbol' => $currency_symbol
                ]); ?>;
                console.log('DirectPay Admin Config:', window.directpayGoAdmin);
            </script>
            <div id="directpay-shipping-root"></div>
        </div>
        <?php
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'directpay') === false) {
            return;
        }
        
        // Enqueue existing admin CSS
        wp_enqueue_style(
            'directpay-admin',
            DIRECTPAY_GO_PLUGIN_URL . 'assets/admin/admin.css',
            [],
            DIRECTPAY_GO_VERSION
        );
        
        // Enqueue React admin app
        $dev_server = 'http://localhost:3000';
        
        // Vite client for HMR
        add_action('admin_head', function() use ($dev_server, $hook) {
            echo '<script type="module" src="' . esc_url($dev_server . '/@vite/client') . '"></script>' . "\n";
            
            // Main DirectPay Go page (sessions)
            if (strpos($hook, 'toplevel_page_directpay-go') !== false) {
                echo '<script type="module" src="' . esc_url($dev_server . '/src/admin/sessions-admin.jsx') . '"></script>' . "\n";
            }
            // Orders page
            elseif (strpos($hook, 'directpay-orders') !== false) {
                echo '<script type="module" src="' . esc_url($dev_server . '/src/admin/orders-admin.jsx') . '"></script>' . "\n";
            }
            // Shipping Methods page
            elseif (strpos($hook, 'directpay-shipping') !== false) {
                echo '<script type="module" src="' . esc_url($dev_server . '/src/admin/admin.jsx') . '"></script>' . "\n";
            }
        });
    }
    
    /**
     * Enqueue assets from Vite manifest (for production)
     */
    private function enqueue_from_manifest() {
        $manifest_file = DIRECTPAY_GO_PLUGIN_DIR . 'dist/.vite/manifest.json';
        $manifest = json_decode(file_get_contents($manifest_file), true);
        
        if (isset($manifest['src/admin/admin.jsx'])) {
            $admin_entry = $manifest['src/admin/admin.jsx'];
            
            // Enqueue admin JS
            wp_enqueue_script(
                'directpay-admin-app',
                DIRECTPAY_GO_PLUGIN_URL . 'dist/' . $admin_entry['file'],
                ['wp-element'],
                DIRECTPAY_GO_VERSION,
                true
            );
            
            // Enqueue admin CSS if exists
            if (!empty($admin_entry['css'])) {
                foreach ($admin_entry['css'] as $css_file) {
                    wp_enqueue_style(
                        'directpay-admin-app',
                        DIRECTPAY_GO_PLUGIN_URL . 'dist/' . $css_file,
                        [],
                        DIRECTPAY_GO_VERSION
                    );
                }
            }
        }
    }
}

// Initialize
DirectPay_Admin_Menu::get_instance();
