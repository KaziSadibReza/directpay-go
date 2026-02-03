<?php
/**
 * Template: Order Preview Modal - DirectPay Information
 * 
 * This is a Backbone.js template using Underscore.js syntax
 * Variables come from the order preview data
 */

if (!defined('ABSPATH')) exit;
?>

<# if (data.directpay_reference || data.directpay_pickup_name) { #>
<div class="wc-order-preview-directpay" style="padding: 1.5em 2em; background: #f8fafc; border-top: 1px solid #e2e8f0; margin-top: 1em;">
    <h3 style="margin: 0 0 1em 0; font-size: 14px; color: #0f172a; font-weight: 600;"><?php _e('DirectPay Information', 'directpay-go'); ?></h3>
    <div style="display: grid; gap: 1em;">
        
        <# if (data.directpay_reference) { #>
        <div>
            <strong style="display: block; font-size: 12px; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25em;"><?php _e('Reference', 'directpay-go'); ?></strong>
            <span style="font-family: 'Courier New', monospace; font-weight: 600; font-size: 14px; letter-spacing: 1px; color: #0f172a;">{{ data.directpay_reference }}</span>
        </div>
        <# } #>
        
        <# if (data.directpay_pickup_name) { #>
        <div>
            <strong style="display: block; font-size: 12px; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25em;"><?php _e('Shipping Carrier', 'directpay-go'); ?></strong>
            <span style="color: #0f172a; font-size: 13px;">{{ data.directpay_carrier }} <span style="display: inline-block; padding: 2px 8px; background: #e0f2fe; color: #0369a1; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-left: 6px;">{{ data.directpay_delivery_type }}</span></span>
        </div>
        
        <div>
            <strong style="display: block; font-size: 12px; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25em;"><?php _e('Pickup Point', 'directpay-go'); ?></strong>
            <span style="color: #0f172a; font-size: 13px;">{{ data.directpay_pickup_name }}</span>
        </div>
        
        <div>
            <strong style="display: block; font-size: 12px; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25em;"><?php _e('Address', 'directpay-go'); ?></strong>
            <span style="color: #0f172a; font-size: 13px;">{{ data.directpay_pickup_address }}<br>{{ data.directpay_pickup_zipcode }} {{ data.directpay_pickup_city }}</span>
        </div>
        <# } #>
        
    </div>
</div>
<# } #>
