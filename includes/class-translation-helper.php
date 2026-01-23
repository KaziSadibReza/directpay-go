<?php
/**
 * Translation Helper for DirectPay Go
 * Handles automatic WooCommerce translation downloads
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Translation_Helper {
    
    /**
     * Ensure WooCommerce translation files are downloaded and available
     */
    public static function ensure_translation($locale) {
        // Skip for English
        if ($locale === 'en_US') {
            return true;
        }
        
        // Check if translation files exist
        $wc_mo_file = WP_LANG_DIR . '/plugins/woocommerce-' . $locale . '.mo';
        
        if (file_exists($wc_mo_file)) {
            return true;
        }
        
        // Translation doesn't exist, try to download it
        error_log("DirectPay: Downloading WooCommerce translation for {$locale}");
        
        return self::download_woocommerce_translation($locale);
    }
    
    /**
     * Download WooCommerce translation
     */
    private static function download_woocommerce_translation($locale) {
        // Include required WordPress files
        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        // Get WooCommerce plugin info
        $wc_plugin_path = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
        
        if (!file_exists($wc_plugin_path)) {
            error_log("DirectPay: WooCommerce plugin not found");
            return false;
        }
        
        $wc_data = get_plugin_data($wc_plugin_path);
        $wc_version = $wc_data['Version'];
        
        try {
            // Use WordPress API to get translation info
            $api_url = "https://api.wordpress.org/translations/plugins/1.0/?slug=woocommerce&version={$wc_version}";
            $response = wp_remote_get($api_url, ['timeout' => 15]);
            
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
            $download_response = wp_remote_get($package_url, ['timeout' => 60]);
            
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
     * Switch to locale and load WooCommerce translations
     */
    public static function switch_to_locale($locale) {
        error_log("DirectPay Translation Helper: Switching to locale: " . $locale);
        
        // Ensure translation is available
        $translation_available = self::ensure_translation($locale);
        error_log("DirectPay Translation Helper: Translation available: " . ($translation_available ? 'yes' : 'no'));
        
        $original_locale = get_locale();
        
        // For REST API context, we need to use a different approach
        // switch_to_locale() doesn't work well in REST API requests
        // Instead, we'll directly load the translation files
        
        if ($locale !== $original_locale) {
            // Method 1: Try switch_to_locale (may fail in REST context)
            $switched = false;
            if (function_exists('switch_to_locale')) {
                $switched = switch_to_locale($locale);
                error_log("DirectPay Translation Helper: switch_to_locale result: " . ($switched ? 'success' : 'failed'));
            }
            
            // Method 2: Direct translation loading (works in REST context)
            if (!$switched) {
                error_log("DirectPay Translation Helper: Using direct translation loading");
                
                // Unload current WooCommerce translations
                unload_textdomain('woocommerce');
                
                // Load specific locale translation files directly
                $mofile = WP_LANG_DIR . '/plugins/woocommerce-' . $locale . '.mo';
                
                if (file_exists($mofile)) {
                    $loaded = load_textdomain('woocommerce', $mofile);
                    error_log("DirectPay Translation Helper: Direct load of $mofile: " . ($loaded ? 'success' : 'failed'));
                    
                    if ($loaded) {
                        // Also set the global locale for this request
                        global $locale;
                        $locale = $locale;
                        $switched = true;
                    }
                } else {
                    error_log("DirectPay Translation Helper: Translation file not found: $mofile");
                }
            }
            
            return ['switched' => $switched, 'original' => $original_locale];
        }
        
        return ['switched' => false, 'original' => $original_locale];
    }
    
    /**
     * Restore original locale
     */
    public static function restore_locale($original_locale) {
        error_log("DirectPay Translation Helper: Restoring locale to: " . $original_locale);
        
        // Try to restore using restore_previous_locale
        if (function_exists('restore_previous_locale')) {
            restore_previous_locale();
        }
        
        // Reload original WooCommerce translations
        unload_textdomain('woocommerce');
        
        // Load original locale if not en_US
        if ($original_locale !== 'en_US') {
            $mofile = WP_LANG_DIR . '/plugins/woocommerce-' . $original_locale . '.mo';
            if (file_exists($mofile)) {
                load_textdomain('woocommerce', $mofile);
            }
        }
        
        error_log("DirectPay Translation Helper: Locale restored");
    }
}
