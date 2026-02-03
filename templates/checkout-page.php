<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo get_bloginfo('name'); ?> - Checkout</title>
    
    <?php
    // Dequeue theme styles only, keep plugin scripts
    add_action('wp_enqueue_scripts', function() {
        // Get all registered styles
        global $wp_styles;
        if (!empty($wp_styles->registered)) {
            foreach ($wp_styles->registered as $handle => $style) {
                // Dequeue theme styles (usually in theme directory)
                if (strpos($style->src, '/themes/') !== false && strpos($handle, 'directpay') === false) {
                    wp_dequeue_style($handle);
                }
                // Dequeue common theme/plugin CSS that might interfere
                if (in_array($handle, ['kadence-global', 'kadence-buttons', 'kadence-header', 'kadence-content'])) {
                    wp_dequeue_style($handle);
                }
            }
        }
    }, 999);
    
    wp_head();
    ?>
</head>
<body <?php body_class('directpay-checkout-page'); ?>>
    
    <div class="directpay-page-wrapper">
        <?php
        // Render the checkout shortcode
        echo do_shortcode('[directpay_checkout]');
        ?>
    </div>
    
    <?php wp_footer(); ?>
</body>
</html>
