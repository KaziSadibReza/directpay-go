<?php
/**
 * DirectPay Go Admin
 * Backend API for language management
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
        add_action('wp_ajax_directpay_download_translation', [$this, 'ajax_download_translation']);
        add_action('wp_ajax_directpay_check_languages', [$this, 'ajax_check_languages']);
        
        // Initialize orders list customization
        require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/admin/class-orders-list.php';
        \DirectPay_Go_Orders_List::init();
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
            
            // Check multiple possible locations for translation files
            $possible_paths = [
                WP_LANG_DIR . '/plugins/woocommerce-' . $locale . '.mo',
                WP_LANG_DIR . '/woocommerce-' . $locale . '.mo',
                WP_PLUGIN_DIR . '/woocommerce/i18n/languages/woocommerce-' . $locale . '.mo',
            ];
            
            $mo_file = '';
            $installed = false;
            
            // Debug each path
            error_log("Checking locale: $locale");
            foreach ($possible_paths as $path) {
                $exists = file_exists($path);
                error_log("  Path: $path - " . ($exists ? 'EXISTS' : 'NOT FOUND'));
                if ($exists) {
                    $mo_file = $path;
                    $installed = true;
                    break;
                }
            }
            
            $status[$locale] = [
                'name' => $name,
                'flag' => $flags[$locale],
                'installed' => $installed,
                'installed_date' => $installed ? filemtime($mo_file) : null,
                'file_path' => $mo_file ?: null,
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
            // Check multiple possible locations
            $paths = [
                WP_LANG_DIR . '/plugins/woocommerce-' . $locale . '.mo',
                WP_LANG_DIR . '/woocommerce-' . $locale . '.mo',
                WP_PLUGIN_DIR . '/woocommerce/languages/woocommerce-' . $locale . '.mo',
            ];
            
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    $count++;
                    break;
                }
            }
        }
        return $count;
    }
    
    /**
     * AJAX: Download translation
     */
    public function ajax_download_translation() {
        check_ajax_referer('directpay_admin_nonce', 'nonce');
        
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
     * AJAX: Check language status
     */
    public function ajax_check_languages() {
        check_ajax_referer('directpay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $language_status = $this->get_language_status();
        
        // Debug logging
        error_log('DirectPay Language Status:');
        error_log('WP_LANG_DIR: ' . WP_LANG_DIR);
        error_log(print_r($language_status, true));
        
        // Transform array to include locale in each entry
        $languages = [];
        foreach ($language_status as $locale => $status) {
            $languages[] = array_merge($status, ['locale' => $locale]);
        }
        
        wp_send_json_success([
            'languages' => $languages,
            'debug' => [
                'wp_lang_dir' => WP_LANG_DIR,
                'count' => count($languages)
            ]
        ]);
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
