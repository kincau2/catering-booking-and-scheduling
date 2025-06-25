<?php

// Register endpoint and add tab to My Account menu
add_filter('woocommerce_account_menu_items', 'add_catering_bookings_tab');
function add_catering_bookings_tab($items) {
    $new_items = array();
    foreach ($items as $key => $value) {
         $new_items[$key] = $value;
         if ($key === 'orders') {
             $new_items['catering-bookings'] = __('Catering Bookings', 'catering-booking-and-scheduling');
         }
    }
    return $new_items;
}

add_action('init', 'add_catering_bookings_endpoint');
function add_catering_bookings_endpoint() {
    add_rewrite_endpoint('catering-bookings', EP_ROOT | EP_PAGES);
}

// Content callback for the Catering Bookings tab
add_action('woocommerce_account_catering-bookings_endpoint', 'catering_bookings_content');
function catering_bookings_content() {
    include CATERING_PLUGIN_DIR . '/template/catering-booking.php';
}

// 1) Replace default addresses list with two shipping slots
add_filter('woocommerce_my_account_get_addresses','catering_custom_my_account_addresses');
function catering_custom_my_account_addresses($addresses){
    return array(
        'shipping'    => __('Shipping Address', 'catering-booking-and-scheduling'),
        'shipping_2'  => __('Second Shipping Address', 'catering-booking-and-scheduling'),
    );
}

// 2) Hide postcode and add phone & remarks to first shipping form
add_filter('woocommerce_shipping_fields','catering_custom_shipping_fields', 10, 2);
function catering_custom_shipping_fields($fields, $load_address){
    // remove ZIP/postcode
    unset($fields['shipping_postcode']);
    unset($fields['shipping_address_2']);
    unset($fields['shipping_state']);

    // make city a select with fixed HK districts
    $fields['shipping_city']['type']    = 'select';
    $fields['shipping_city']['label']   = __('District', 'catering-booking-and-scheduling');
    $fields['shipping_city']['required']= true;
    $fields['shipping_city']['options'] = array(
        ''   => '請選擇地區',
        '灣仔區'   => '灣仔區',
        '東區'     => '東區',
        '中西區'   => '中西區',
        '南區'     => '南區',
        '北區'     => '北區',
        '觀塘區'   => '觀塘區',
        '油尖旺區' => '油尖旺區',
        '黃大仙區' => '黃大仙區',
        '深水埗區' => '深水埗區',
        '九龍城區' => '九龍城區',
        '荃灣區'   => '荃灣區',
        '離島區'   => '離島區',
        '葵青區'   => '葵青區',
        '西貢區'   => '西貢區',
        '沙田區'   => '沙田區',
        '元朗區'   => '元朗區',
        '屯門區'   => '屯門區',
        '大埔區'   => '大埔區',
    );

    // add phone
    $fields['shipping_phone'] = array(
        'label'      => __('Phone', 'catering-booking-and-scheduling'),
        'required'   => true,
        'class'      => array('form-row-wide'),
        'clear'      => true,
        'validate'   => array('phone'),
        'priority'   => 100,
    );
    // add remarks textarea
    $fields['shipping_remarks'] = array(
        'type'        => 'textarea',
        'label'       => __('Shipping remarks', 'catering-booking-and-scheduling'),
        'required'    => false,
        'class'       => array('form-row-wide'),
        'clear'       => true,
        'priority'    => 110,
    );
    return $fields;
}

// 3) Hide postcode & add phone & remarks to second shipping form
add_filter('woocommerce_shipping_2_fields', 'catering_custom_shipping_2_fields', 10, 2);
function catering_custom_shipping_2_fields($fields, $load_address) {
    // remove ZIP/postcode
    unset($fields['shipping_2_postcode']);
    unset($fields['shipping_2_address_2']);
    unset($fields['shipping_2_state']);

    // make city a select with fixed HK districts
    $fields['shipping_2_city']['type']    = 'select';
    $fields['shipping_2_city']['label']   = __('District', 'catering-booking-and-scheduling');
    $fields['shipping_2_city']['required']= true;
    $fields['shipping_2_city']['options'] = array(
        ''   => '請選擇地區',
        '灣仔區'   => '灣仔區',
        '東區'     => '東區',
        '中西區'   => '中西區',
        '南區'     => '南區',
        '北區'     => '北區',
        '觀塘區'   => '觀塘區',
        '油尖旺區' => '油尖旺區',
        '黃大仙區' => '黃大仙區',
        '深水埗區' => '深水埗區',
        '九龍城區' => '九龍城區',
        '荃灣區'   => '荃灣區',
        '離島區'   => '離島區',
        '葵青區'   => '葵青區',
        '西貢區'   => '西貢區',
        '沙田區'   => '沙田區',
        '元朗區'   => '元朗區',
        '屯門區'   => '屯門區',
        '大埔區'   => '大埔區',
    );

    // add phone
    $fields['shipping_2_phone'] = array(
        'label'      => __('Phone', 'catering-booking-and-scheduling'),
        'required'   => true,
        'class'      => array('form-row-wide'),
        'clear'      => true,
        'validate'   => array('phone'),
        'priority'   => 100,
    );
    // add remarks textarea
    $fields['shipping_2_remarks'] = array(
        'type'        => 'textarea',
        'label'       => __('Shipping remarks', 'catering-booking-and-scheduling'),
        'required'    => false,
        'class'       => array('form-row-wide'),
        'clear'       => true,
        'priority'    => 110,
    );
    return $fields;
}

// 2) Hide postcode and add phone & remarks to first shipping form
add_filter('woocommerce_billing_fields','catering_custom_billing_fields', 10, 2);
function catering_custom_billing_fields($fields, $load_address){
    // remove ZIP/postcode
    unset($fields['billing_postcode']);
    unset($fields['billing_address_2']);
    unset($fields['billing_state']);

    $fields['billing_phone']['required'] = true;

    $fields['billing_city']['type']    = 'select';
    $fields['billing_city']['label']   = __('District', 'catering-booking-and-scheduling');
    $fields['billing_city']['required']= true;
    $fields['billing_city']['options'] = array(
        ''   => '請選擇地區',
        '灣仔區'   => '灣仔區',
        '東區'     => '東區',
        '中西區'   => '中西區',
        '南區'     => '南區',
        '北區'     => '北區',
        '觀塘區'   => '觀塘區',
        '油尖旺區' => '油尖旺區',
        '黃大仙區' => '黃大仙區',
        '深水埗區' => '深水埗區',
        '九龍城區' => '九龍城區',
        '荃灣區'   => '荃灣區',
        '離島區'   => '離島區',
        '葵青區'   => '葵青區',
        '西貢區'   => '西貢區',
        '沙田區'   => '沙田區',
        '元朗區'   => '元朗區',
        '屯門區'   => '屯門區',
        '大埔區'   => '大埔區',
    );
    
    $fields['billing_remarks'] = array(
        'type'        => 'textarea',
        'label'       => __('Billing remarks', 'catering-booking-and-scheduling'),
        'required'    => false,
        'class'       => array('form-row-wide'),
        'clear'       => true,
        'priority'    => 110,
    );
    return $fields;
}

/*
 * Fix display of the Second Shipping Address on My Account
 */
add_filter('woocommerce_my_account_my_address_formatted_address','catering_formatted_shipping_2', 10, 3);
function catering_formatted_shipping_2($address, $customer_id, $address_name) {
    if ($address_name !== 'shipping_2') {
        return $address;
    }
    $customer_id = $customer_id ?: get_current_user_id();
    // build the array keys that WC expects
    $address = array(
        'first_name' => get_user_meta($customer_id, 'shipping_2_first_name', true),
        'last_name'  => get_user_meta($customer_id, 'shipping_2_last_name',  true),
        'company'    => get_user_meta($customer_id, 'shipping_2_company',    true),
        'address_1'  => get_user_meta($customer_id, 'shipping_2_address_1',  true),
        'address_2'  => get_user_meta($customer_id, 'shipping_2_address_2',  true),
        'city'       => get_user_meta($customer_id, 'shipping_2_city',       true),
        'state'      => get_user_meta($customer_id, 'shipping_2_state',      true),
        'postcode'   => get_user_meta($customer_id, 'shipping_2_postcode',   true),
        'country'    => get_user_meta($customer_id, 'shipping_2_country',    true),
    );
    // if no address lines, return empty so WC shows "not set up" message
    if ( empty($address['address_1']) && empty($address['address_2']) ) {
        return array();
    }
    return $address;
}

?>