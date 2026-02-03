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
        
        // Check if we should use dev server or production build
        $manifest_path = DIRECTPAY_GO_PLUGIN_DIR . 'dist/.vite/manifest.json';
        
        if (file_exists($manifest_path)) {
            // Production mode: Load from manifest
            $this->enqueue_from_manifest($hook);
        } else {
            // Development mode: Use Vite dev server
            $this->enqueue_dev_server($hook);
        }
    }
    
    /**
     * Enqueue dev server scripts
     */
    private function enqueue_dev_server($hook) {
        $dev_server = 'http://localhost:3000';
        
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
    private function enqueue_from_manifest($hook) {
        $manifest_file = DIRECTPAY_GO_PLUGIN_DIR . 'dist/.vite/manifest.json';
        $manifest = json_decode(file_get_contents($manifest_file), true);
        
        // Determine which entry to load based on the page
        $entry_key = '';
        $handle_prefix = '';
        
        if (strpos($hook, 'toplevel_page_directpay-go') !== false) {
            // Sessions page
            $entry_key = 'src/admin/sessions-admin.jsx';
            $handle_prefix = 'directpay-sessions';
        } elseif (strpos($hook, 'directpay-orders') !== false) {
            // Orders page
            $entry_key = 'src/admin/orders-admin.jsx';
            $handle_prefix = 'directpay-orders';
        } elseif (strpos($hook, 'directpay-shipping') !== false) {
            // Shipping Methods page
            $entry_key = 'src/admin/admin.jsx';
            $handle_prefix = 'directpay-shipping';
        }
        
        if (!$entry_key || !isset($manifest[$entry_key])) {
            return;
        }
        
        $entry = $manifest[$entry_key];
        
        // Enqueue CSS files
        if (!empty($entry['css'])) {
            foreach ($entry['css'] as $index => $css_file) {
                wp_enqueue_style(
                    $handle_prefix . '-style-' . $index,
                    DIRECTPAY_GO_PLUGIN_URL . 'dist/' . $css_file,
                    [],
                    DIRECTPAY_GO_VERSION
                );
            }
        }
        
        // Enqueue imported CSS (like CustomDropdown)
        if (!empty($entry['imports'])) {
            foreach ($entry['imports'] as $import_key) {
                if (isset($manifest[$import_key]) && !empty($manifest[$import_key]['css'])) {
                    foreach ($manifest[$import_key]['css'] as $index => $css_file) {
                        wp_enqueue_style(
                            $handle_prefix . '-import-style-' . $index,
                            DIRECTPAY_GO_PLUGIN_URL . 'dist/' . $css_file,
                            [],
                            DIRECTPAY_GO_VERSION
                        );
                    }
                }
            }
        }
        
        // Enqueue JS
        wp_enqueue_script(
            $handle_prefix . '-app',
            DIRECTPAY_GO_PLUGIN_URL . 'dist/' . $entry['file'],
            [],
            DIRECTPAY_GO_VERSION,
            true
        );
        
        // Add module type attribute
        add_filter('script_loader_tag', function($tag, $handle) use ($handle_prefix) {
            if (strpos($handle, $handle_prefix) !== false) {
                $tag = str_replace('<script ', '<script type="module" ', $tag);
            }
            return $tag;
        }, 10, 2);
    }
}

// Initialize
DirectPay_Admin_Menu::get_instance();
