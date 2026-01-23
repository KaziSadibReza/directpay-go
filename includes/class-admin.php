<?php
/**
 * DirectPay Go Admin
 * Handles admin settings and language management
 */

namespace DirectPayGo;

if (!defined('ABSPATH')) {
    exit;
}

class Admin {
    
    private $supported_languages = [
        'en_US' => 'English',
        'fr_FR' => 'Français',
        'es_ES' => 'Español',
        'de_DE' => 'Deutsch',
    ];
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_directpay_download_translation', [$this, 'ajax_download_translation']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('DirectPay Go', 'directpay-go'),
            __('DirectPay Go', 'directpay-go'),
            'manage_options',
            'directpay-go',
            [$this, 'render_admin_page'],
            'dashicons-cart',
            56
        );
        
        add_submenu_page(
            'directpay-go',
            __('Languages', 'directpay-go'),
            __('Languages', 'directpay-go'),
            'manage_options',
            'directpay-go-languages',
            [$this, 'render_languages_page']
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'directpay-go') === false) {
            return;
        }
        
        wp_enqueue_style(
            'directpay-admin',
            DIRECTPAY_GO_PLUGIN_URL . 'assets/admin/admin.css',
            [],
            DIRECTPAY_GO_VERSION
        );
        
        wp_enqueue_script(
            'directpay-admin',
            DIRECTPAY_GO_PLUGIN_URL . 'assets/admin/admin.js',
            ['jquery'],
            DIRECTPAY_GO_VERSION,
            true
        );
        
        wp_localize_script('directpay-admin', 'directPayAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('directpay_admin'),
        ]);
    }
    
    /**
     * Render main admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="directpay-admin-card">
                <h2>🚀 <?php _e('Welcome to DirectPay Go', 'directpay-go'); ?></h2>
                <p><?php _e('High-performance custom checkout for WooCommerce, optimized for 500+ concurrent visitors.', 'directpay-go'); ?></p>
                
                <div class="directpay-stats">
                    <div class="stat-box">
                        <h3><?php echo count($this->supported_languages); ?></h3>
                        <p><?php _e('Supported Languages', 'directpay-go'); ?></p>
                    </div>
                    <div class="stat-box">
                        <h3><?php echo $this->count_installed_languages(); ?>/4</h3>
                        <p><?php _e('Installed Translations', 'directpay-go'); ?></p>
                    </div>
                </div>
                
                <a href="<?php echo admin_url('admin.php?page=directpay-go-languages'); ?>" class="button button-primary">
                    <?php _e('Manage Languages', 'directpay-go'); ?>
                </a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render languages admin page
     */
    public function render_languages_page() {
        $language_status = $this->get_language_status();
        ?>
        <div class="wrap">
            <h1><?php _e('Language Management', 'directpay-go'); ?></h1>
            <p><?php _e('DirectPay Go supports 4 languages. WooCommerce translation files are required for automatic translation.', 'directpay-go'); ?></p>
            
            <div class="directpay-languages-container">
                <?php foreach ($language_status as $locale => $status): ?>
                <div class="directpay-language-card <?php echo $status['installed'] ? 'installed' : 'not-installed'; ?>">
                    <div class="language-header">
                        <span class="language-flag"><?php echo $status['flag']; ?></span>
                        <h3><?php echo esc_html($status['name']); ?></h3>
                        <span class="status-badge <?php echo $status['installed'] ? 'badge-success' : 'badge-warning'; ?>">
                            <?php 
                            if ($status['is_default'] ?? false) {
                                echo '✓ Default Language';
                            } elseif ($status['installed']) {
                                echo '✓ Installed';
                            } else {
                                echo '✗ Not Installed';
                            }
                            ?>
                        </span>
                    </div>
                    
                    <div class="language-details">
                        <p><strong><?php _e('Locale:', 'directpay-go'); ?></strong> <?php echo esc_html($locale); ?></p>
                        
                        <?php if ($status['is_default'] ?? false): ?>
                            <p><strong><?php _e('Status:', 'directpay-go'); ?></strong> <?php _e('Default language (no translation file needed)', 'directpay-go'); ?></p>
                        <?php else: ?>
                            <p><strong><?php _e('File:', 'directpay-go'); ?></strong> woocommerce-<?php echo esc_html($locale); ?>.mo</p>
                        <?php endif; ?>
                        
                        <?php if ($status['installed'] && !($status['is_default'] ?? false)): ?>
                            <p class="status-message success">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php printf(__('Installed on %s', 'directpay-go'), date('M j, Y', $status['installed_date'])); ?>
                            </p>
                        <?php elseif (!$status['installed'] && !($status['is_default'] ?? false)): ?>
                            <p class="status-message warning">
                                <span class="dashicons dashicons-info"></span>
                                <?php _e('Translation file not found. Download required for automatic translation.', 'directpay-go'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="language-actions">
                        <?php if (!($status['is_default'] ?? false)): ?>
                            <?php if (!$status['installed']): ?>
                                <button class="button button-primary download-translation" 
                                        data-locale="<?php echo esc_attr($locale); ?>"
                                        data-language="<?php echo esc_attr($status['name']); ?>">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php _e('Download Translation', 'directpay-go'); ?>
                                </button>
                            <?php else: ?>
                                <button class="button redownload-translation" 
                                        data-locale="<?php echo esc_attr($locale); ?>"
                                        data-language="<?php echo esc_attr($status['name']); ?>">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php _e('Re-download', 'directpay-go'); ?>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="download-progress" style="display: none;">
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <p class="progress-message"></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="directpay-bulk-actions">
                <button class="button button-large button-primary" id="download-all-missing">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Download All Missing Translations', 'directpay-go'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get language status for all supported languages
     */
    private function get_language_status() {
        $status = [];
        
        $flags = [
            'en_US' => '🇺🇸',
            'fr_FR' => '🇫🇷',
            'es_ES' => '🇪🇸',
            'de_DE' => '🇩🇪',
        ];
        
        foreach ($this->supported_languages as $locale => $name) {
            // English is default, no translation file needed
            if ($locale === 'en_US') {
                $status[$locale] = [
                    'name' => $name,
                    'flag' => $flags[$locale],
                    'installed' => true,
                    'installed_date' => null,
                    'file_path' => null,
                    'is_default' => true,
                ];
                continue;
            }
            
            $mo_file = WP_LANG_DIR . '/plugins/woocommerce-' . $locale . '.mo';
            $installed = file_exists($mo_file);
            
            $status[$locale] = [
                'name' => $name,
                'flag' => $flags[$locale],
                'installed' => $installed,
                'installed_date' => $installed ? filemtime($mo_file) : null,
                'file_path' => $mo_file,
                'is_default' => false,
            ];
        }
        
        return $status;
    }
    
    /**
     * Count installed languages
     */
    private function count_installed_languages() {
        $count = 0;
        foreach ($this->supported_languages as $locale => $name) {
            $mo_file = WP_LANG_DIR . '/plugins/woocommerce-' . $locale . '.mo';
            if (file_exists($mo_file)) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * AJAX: Download translation
     */
    public function ajax_download_translation() {
        check_ajax_referer('directpay_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $locale = sanitize_text_field($_POST['locale']);
        
        if (!isset($this->supported_languages[$locale])) {
            wp_send_json_error(['message' => 'Invalid locale']);
        }
        
        // Download translation
        $result = $this->download_woocommerce_translation($locale);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(__('%s translation downloaded successfully!', 'directpay-go'), $this->supported_languages[$locale])
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['message']
            ]);
        }
    }
    
    /**
     * Download WooCommerce translation for a specific locale
     */
    private function download_woocommerce_translation($locale) {
        // Skip for English
        if ($locale === 'en_US') {
            return [
                'success' => true,
                'message' => 'English is the default language'
            ];
        }
        
        // Check if already exists
        $mo_file = WP_LANG_DIR . '/plugins/woocommerce-' . $locale . '.mo';
        
        // Include required WordPress files
        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        // Get WooCommerce plugin info
        $wc_plugin_path = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
        
        if (!file_exists($wc_plugin_path)) {
            return [
                'success' => false,
                'message' => __('WooCommerce plugin not found', 'directpay-go')
            ];
        }
        
        // Get WooCommerce version
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $wc_data = get_plugin_data($wc_plugin_path);
        $wc_version = $wc_data['Version'];
        
        try {
            // Use WordPress API to get translation info
            $api_url = "https://api.wordpress.org/translations/plugins/1.0/?slug=woocommerce&version={$wc_version}";
            $response = wp_remote_get($api_url, ['timeout' => 15]);
            
            if (is_wp_error($response)) {
                return [
                    'success' => false,
                    'message' => __('Failed to fetch translation info: ', 'directpay-go') . $response->get_error_message()
                ];
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (empty($data['translations'])) {
                return [
                    'success' => false,
                    'message' => __('No translations found', 'directpay-go')
                ];
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
                return [
                    'success' => false,
                    'message' => sprintf(__('Translation for %s not found', 'directpay-go'), $locale)
                ];
            }
            
            // Download the translation package
            $package_url = $translation['package'];
            $download_response = wp_remote_get($package_url, ['timeout' => 60]);
            
            if (is_wp_error($download_response)) {
                return [
                    'success' => false,
                    'message' => __('Failed to download translation: ', 'directpay-go') . $download_response->get_error_message()
                ];
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
                return [
                    'success' => false,
                    'message' => __('Failed to unzip translation: ', 'directpay-go') . $result->get_error_message()
                ];
            }
            
            return [
                'success' => true,
                'message' => sprintf(__('%s translation installed successfully', 'directpay-go'), $this->supported_languages[$locale])
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('Error: ', 'directpay-go') . $e->getMessage()
            ];
        }
    }
}
