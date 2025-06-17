<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

add_shortcode('debug','display_debug_message');

function display_debug_message(){


    // echo "<pre>";
    // echo print_r($booking->get_linked_product(),1);
    // echo "</pre>";
    // // is_min_day_requirement_met('2025-05-22');
    // save_daily_catering_meal_count();
    // $order = wc_get_order( 145 ); // replace with a valid order ID
    // echo "<pre>";
    // echo print_r(wp_next_scheduled('save_daily_catering_meal_count'),1);
    // echo "</pre>";

    echo "<pre>";
    echo print_r(get_transient('debug'),1);
    echo "</pre>";

}

function create_booking($user_id, $order_item_id, $plan_days, $expiry, $cat_qty, $health_status,$type = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'catering_booking';

    // skip if already exists
    $existing = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM {$table} WHERE user_id=%d AND order_item_id=%d",
            $user_id,
            $order_item_id
        )
    );
    if ($existing) {
        return (int) $existing;
    }
    $data = [
        'status'            => 'active',
        'user_id'           => $user_id,
        'order_item_id'     => $order_item_id,
        'plan_days'         => $plan_days,
        'expiry'            => $expiry,  
        'date_created_gmt'  => current_time('mysql', true),    // new GMT timestamp
        'cat_qty'           => maybe_serialize($cat_qty),
        'type'              => $type,
        'health_status'     => maybe_serialize($health_status),
    ];
    $format = ['%s', '%d', '%d', '%d', '%d' , '%s', '%s','%s', '%s'];  // status, user_id, order_item_id, plan_days, date_amended_gmt, cat_qty, health_status

    $inserted = $wpdb->insert($table, $data, $format);
    if (false === $inserted) {
        throw new Exception(__('Failed to create booking', 'catering-booking-and-scheduling'));
    }

    return (int) $wpdb->insert_id;
}

function is_min_day_requirement_met($target_date) {
    global $wpdb;
    $table = $wpdb->prefix . 'catering_options';
    $min = $wpdb->get_var( $wpdb->prepare("SELECT option_value FROM $table WHERE option_name=%s", 'catering_min_day_before_order') );
    $min_days = is_numeric($min) ? intval($min) : 0;
    $now = time();
    $timezone = 'Asia/Hong_Kong';
    $target = strtotime($target_date) + 60*60*8;
    if($target === false){
        return false;
    }
    return (($target - $now) >= ($min_days * 86400));
}

add_action('admin_post_generate_delivery_report', 'generate_delivery_report');
function generate_delivery_report() {
    require_once CATERING_PLUGIN_DIR . '/template/delivery-report.php';
}

// Add [schedule_preview] shortcode to show preview button on catering_plan products
add_shortcode('schedule_preview_button', 'display_schedule_preview_button');
function display_schedule_preview_button($atts) {
    if (! is_singular('product') ) {
        return '';
    }
    $product_id = get_the_ID();
    $product = wc_get_product($product_id);
    // only for products in the 'catering_plan' category
    if ( ! $product || $product->get_type() != 'catering_plan' ) {
        return '';
    }
    ob_start();
    
    include CATERING_PLUGIN_DIR . '/template/schedule-preview.php';
    return ob_get_clean();
    
}
?>

