<?php

// Block direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get PayU Refund Setting Securely
function is_payu_refund_disabled()
{
    $payu_settings = get_option('woocommerce_payubiz_settings');
    return isset($payu_settings['payu_disable_refund']) && sanitize_text_field($payu_settings['payu_disable_refund']) === 'yes';
}

// Hide Refund Button via JS (Only if checkbox is checked)
add_action('admin_footer', function () {
    if (is_payu_refund_disabled()) {
        echo '<script>
            jQuery(document).ready(function($) {
                $(".do-api-refund").each(function() {
                    if ($(this).text().trim().includes("via PayUBiz")) {
                        $(this).hide();
                    }
                });
            });
        </script>';
    }
});

// Block Manual Refund (Only if checkbox is checked)
add_action('woocommerce_process_manual_refund', function ($order_id) {
    if (is_payu_refund_disabled()) {
        wp_die(esc_html__('Refund is disabled for PayU transactions.', 'woocommerce'), esc_html__('Refund Blocked', 'woocommerce'));
    }
}, 10, 1);

// Block Refund via REST API (Only if checkbox is checked)
add_filter('woocommerce_rest_check_permissions', function ($permission, $context, $object_id, $post_type) {
    if (is_payu_refund_disabled() && $post_type === 'shop_order_refund') {
        return false;
    }
    return $permission;
}, 10, 4);

