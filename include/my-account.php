<?php

// Custom phone validation function
function catering_validate_phone_with_country($phone, $country_code) {
    // Remove any spaces, dashes, or brackets
    $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
    
    switch ($country_code) {
        case '+852': // Hong Kong
            // Hong Kong mobile: 4/5/6/9 followed by 7 digits
            return preg_match('/^[4569]\d{7}$/', $phone);
            
        case '+853': // Macau
            // Macau mobile: 6 followed by 7 digits
            return preg_match('/^6\d{7}$/', $phone);
            
        case '+86': // China
            // China mobile: 1 followed by 10 digits (starting with 3,4,5,6,7,8,9)
            return preg_match('/^1[3456789]\d{9}$/', $phone);
            
        default:
            return false;
    }
}

// Add custom validation for phone fields
add_action('woocommerce_checkout_process', 'catering_validate_checkout_phone');
function catering_validate_checkout_phone() {
    // Validate shipping phone
    $shipping_phone_country = $_POST['shipping_phone_country'] ?? '';
    $shipping_phone = $_POST['shipping_phone'] ?? '';
    
    if ($shipping_phone && $shipping_phone_country) {
        if (!catering_validate_phone_with_country($shipping_phone, $shipping_phone_country)) {
            $error_msg = catering_get_phone_error_message($shipping_phone_country, 'shipping');
            wc_add_notice($error_msg, 'error');
        }
    }
    
    // Validate billing phone
    $billing_phone_country = $_POST['billing_phone_country'] ?? '';
    $billing_phone = $_POST['billing_phone'] ?? '';
    
    if ($billing_phone && $billing_phone_country) {
        if (!catering_validate_phone_with_country($billing_phone, $billing_phone_country)) {
            $error_msg = catering_get_phone_error_message($billing_phone_country, 'billing');
            wc_add_notice($error_msg, 'error');
        }
    }
}

function catering_get_phone_error_message($country_code, $field_type = '') {
    $prefix = $field_type ? ucfirst($field_type) . ' ' : '';
    
    switch ($country_code) {
        case '+852':
            return sprintf(__('%sphone: Please enter a valid Hong Kong mobile number (8 digits starting with 4, 5, 6, or 9)', 'catering-booking-and-scheduling'), $prefix);
        case '+853':
            return sprintf(__('%sphone: Please enter a valid Macau mobile number (8 digits starting with 6)', 'catering-booking-and-scheduling'), $prefix);
        case '+86':
            return sprintf(__('%sphone: Please enter a valid China mobile number (11 digits starting with 1)', 'catering-booking-and-scheduling'), $prefix);
        default:
            return sprintf(__('%sphone: Please enter a valid phone number', 'catering-booking-and-scheduling'), $prefix);
    }
}

// Save custom phone country field
add_action('woocommerce_checkout_update_user_meta', 'catering_save_phone_country_field');
add_action('woocommerce_checkout_update_order_meta', 'catering_save_phone_country_order_meta');

function catering_save_phone_country_field($user_id) {
    if (isset($_POST['shipping_phone_country'])) {
        update_user_meta($user_id, 'shipping_phone_country', sanitize_text_field($_POST['shipping_phone_country']));
    }
    if (isset($_POST['shipping_2_phone_country'])) {
        update_user_meta($user_id, 'shipping_2_phone_country', sanitize_text_field($_POST['shipping_2_phone_country']));
    }
    if (isset($_POST['billing_phone_country'])) {
        update_user_meta($user_id, 'billing_phone_country', sanitize_text_field($_POST['billing_phone_country']));
    }
}

function catering_save_phone_country_order_meta($order_id) {
    if (isset($_POST['shipping_phone_country'])) {
        update_post_meta($order_id, '_shipping_phone_country', sanitize_text_field($_POST['shipping_phone_country']));
    }
    if (isset($_POST['shipping_2_phone_country'])) {
        update_post_meta($order_id, '_shipping_2_phone_country', sanitize_text_field($_POST['shipping_2_phone_country']));
    }
    if (isset($_POST['billing_phone_country'])) {
        update_post_meta($order_id, '_billing_phone_country', sanitize_text_field($_POST['billing_phone_country']));
    }
}

// Load saved phone country values
add_filter('woocommerce_checkout_get_value', 'catering_load_phone_country_values', 10, 2);
function catering_load_phone_country_values($value, $input) {
    if (is_admin() && !wp_doing_ajax()) {
        return $value;
    }
    
    $user_id = get_current_user_id();
    if (!$user_id) {
        return $value;
    }
    
    switch ($input) {
        case 'shipping_phone_country':
            $saved_value = get_user_meta($user_id, 'shipping_phone_country', true);
            return $saved_value ?: '+852';
        case 'shipping_2_phone_country':
            $saved_value = get_user_meta($user_id, 'shipping_2_phone_country', true);
            return $saved_value ?: '+852';
        case 'billing_phone_country':
            $saved_value = get_user_meta($user_id, 'billing_phone_country', true);
            return $saved_value ?: '+852';
    }
    
    return $value;
}

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

    // add phone with country code
    $fields['shipping_phone_country'] = array(
        'type'       => 'select',
        'label'      => __('Phone Country/Region Code', 'catering-booking-and-scheduling'),
        'required'   => true,
        'class'      => array('form-row-first'),
        'clear'      => false,
        'options'    => array(
            '+852' => '+852 (Hong Kong)',
            '+853' => '+853 (Macau)',
            '+86'  => '+86 (China)',
        ),
        'default'    => '+852',
        'priority'   => 100,
    );
    $fields['shipping_phone'] = array(
        'label'      => __('Phone Number', 'catering-booking-and-scheduling'),
        'required'   => true,
        'class'      => array('form-row-last', 'catering-phone-input'),
        'clear'      => true,
        'validate'   => array('phone'),
        'priority'   => 101,
        'placeholder' => __('Enter phone number', 'catering-booking-and-scheduling'),
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

    // add phone with country code
    $fields['shipping_2_phone_country'] = array(
        'type'       => 'select',
        'label'      => __('Phone Country/Region Code', 'catering-booking-and-scheduling'),
        'required'   => true,
        'class'      => array('form-row-first'),
        'clear'      => false,
        'options'    => array(
            '+852' => '+852 (Hong Kong)',
            '+853' => '+853 (Macau)',
            '+86'  => '+86 (China)',
        ),
        'default'    => '+852',
        'priority'   => 100,
    );
    $fields['shipping_2_phone'] = array(
        'label'      => __('Phone Number', 'catering-booking-and-scheduling'),
        'required'   => true,
        'class'      => array('form-row-last', 'catering-phone-input'),
        'clear'      => true,
        'validate'   => array('phone'),
        'priority'   => 101,
        'placeholder' => __('Enter phone number', 'catering-booking-and-scheduling'),
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
    
    // add phone country code for billing
    $fields['billing_phone_country'] = array(
        'type'       => 'select',
        'label'      => __('Phone Country/Region Code', 'catering-booking-and-scheduling'),
        'required'   => true,
        'class'      => array('form-row-first'),
        'clear'      => false,
        'options'    => array(
            '+852' => '+852 (Hong Kong)',
            '+853' => '+853 (Macau)',
            '+86'  => '+86 (China)',
        ),
        'default'    => '+852',
        'priority'   => 99,
    );
    
    $fields['billing_phone']['class'] = array('form-row-last', 'catering-phone-input');
    $fields['billing_phone']['clear'] = true;
    $fields['billing_phone']['priority'] = 100;
    $fields['billing_phone']['placeholder'] = __('Enter phone number', 'catering-booking-and-scheduling');

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

add_filter('woocommerce_account_menu_items', 'add_point_history_tab', 99);
function add_point_history_tab($items) {
    $new_items = array();
    foreach ($items as $key => $value) {
         $new_items[$key] = $value;
         if ($key === 'catering-bookings') {
             $new_items['point-history'] = __('Point History', 'catering-booking-and-scheduling');
         }
    }
    return $new_items;
}

add_action('init', 'add_point_history_endpoint');
function add_point_history_endpoint() {
    add_rewrite_endpoint('point-history', EP_ROOT | EP_PAGES);
}

// Content callback for the Catering Bookings tab
add_action('woocommerce_account_point-history_endpoint', 'point_history_content');
function point_history_content() {
    include CATERING_PLUGIN_DIR . '/template/rewards.php';
}