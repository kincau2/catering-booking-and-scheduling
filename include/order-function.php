<?php

// Adds plan day and catering metadata for catering_plan products
add_filter('woocommerce_add_cart_item_data', 'add_plan_day_to_cart_item', 10, 3);
function add_plan_day_to_cart_item($cart_item_data, $product_id, $variation_id) {
    $product = wc_get_product($product_id);
    if ($product && $product->get_type() === 'catering_plan') {
        // assumes attribute name "Plan Days"
        $variation_product = wc_get_product($variation_id ? $variation_id : $product_id);
        $plan_days = $variation_product->get_attribute('pa_plan-days');
        if ($plan_days) {
            $cart_item_data['plan_days']   = sanitize_text_field($plan_days);
        }
        // add product meta catering_cat_qty
        $cat_qty = $product->get_meta('catering_cat_qty');
        if($cat_qty){
            $cart_item_data['catering_cat_qty']   = sanitize_text_field($cat_qty);
        }

        // add product meta catering_cat_qty
        $catering_type = $product->get_meta('catering_type');
        if($catering_type){
            $cart_item_data['catering_type']   = sanitize_text_field($catering_type);
        }

        // add product meta catering_is_set_menu
        $is_set_menu = $product->get_meta('catering_is_set_menu');
        if($is_set_menu){
            $cart_item_data['catering_is_set_menu']   = sanitize_text_field($is_set_menu);
        }
    }

    return $cart_item_data;
}

// Adds custom meta data from cart items to order line items
add_action('woocommerce_checkout_create_order_line_item', 'add_custom_meta_to_order_items', 10, 4);
function add_custom_meta_to_order_items($item, $cart_item_key, $values, $order) {

    if (isset($values['plan_days'])) {
        $item->add_meta_data('plan_days', sanitize_text_field($values['plan_days']), true);
    }

    // replace serialized catch‑all with per‑category metas
    if ( isset( $values['catering_cat_qty'] ) ) {
        $item->add_meta_data('catering_cat_qty', sanitize_text_field($values['catering_cat_qty']), true);
        global $wpdb;
        $terms_table = $wpdb->prefix . 'catering_terms';
        $raw = $values['catering_cat_qty'];
        $arr = is_array($raw) ? $raw : @unserialize($raw);
        if (is_array($arr)) {
            foreach ($arr as $term_id => $max_pick) {
                $term_id = absint($term_id);
                $title = $wpdb->get_var(
                    $wpdb->prepare("SELECT title FROM {$terms_table} WHERE ID=%d", $term_id)
                );
                if ($title) {
                    $item->add_meta_data( 
                        __('Daily picks for', 'catering-booking-and-scheduling') .' '. sanitize_text_field($title) , 
                        sanitize_text_field($max_pick) , 
                        true);
                }
            }
        }
    }

    if (isset($values['catering_type'])) {
        $item->add_meta_data('catering_type', sanitize_text_field($values['catering_type']), true);
    }
    if (isset($values['catering_is_set_menu'])) {
        $item->add_meta_data('catering_is_set_menu', sanitize_text_field($values['catering_is_set_menu']), true);
    }

}

// Displays custom cart item meta details for catering cart items
add_filter('woocommerce_get_item_data', 'display_custom_cart_item_data', 10, 2);
function display_custom_cart_item_data($item_data, $cart_item) {

    // display each category’s max pick
    if (!empty($cart_item['catering_cat_qty'])) {
        global $wpdb;
        $terms_table = $wpdb->prefix . 'catering_terms';
        $raw = $cart_item['catering_cat_qty'];
        $arr = is_array($raw) ? $raw : @unserialize($raw);
        if (is_array($arr)) {
            foreach ($arr as $term_id => $max_pick) {
                $term_id = absint($term_id);
                $title = $wpdb->get_var(
                    $wpdb->prepare("SELECT title FROM {$terms_table} WHERE ID=%d", $term_id)
                );
                if ($title) {
                    $item_data[] = array(
                        'key'   => __('Daily picks for', 'catering-booking-and-scheduling') .' '. sanitize_text_field($title) ,
                        'value' => sanitize_text_field($max_pick),
                    );
                }
            }
        }
    }

    return $item_data;
}

// Hides raw metadata from front-end order item meta display
add_filter('woocommerce_order_item_get_formatted_meta_data', 'catering_formatted_order_itemmeta', 10, 2);
function catering_formatted_order_itemmeta($formatted_meta, $item) {
    foreach ($formatted_meta as $meta_id => $meta) {
        if (in_array($meta->key, ['catering_cat_qty','plan_days','catering_type','catering_is_set_menu'], true)) {
            unset($formatted_meta[$meta_id]);
        } elseif ($meta->key === 'due_date') {
            $meta->display_key = __('Due Date', 'catering-booking-and-scheduling');
        }
    }
    return $formatted_meta;
}

// Creates a booking for catering_plan products when order status is processing or completed
add_action('woocommerce_order_status_processing', 'catering_create_booking_on_order_status', 10, 2);
add_action('woocommerce_order_status_completed',  'catering_create_booking_on_order_status', 10, 2);
function catering_create_booking_on_order_status($order_id, $order = null) {
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order_id);
    }
    $user_id = $order->get_user_id();
    if (!$user_id) {
        // no user, cannot create booking
        return;
    }
    // fetch health status meta
    $health_status = $order->get_meta('catering_health_status');
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        
        if (is_catering_product($product)) {
            $parent_product = wc_get_product($product->get_parent_id());
            $plan_days      = (int) $item->get_meta('plan_days');
            $raw            = $item->get_meta('catering_cat_qty');
            $type           = $item->get_meta('catering_type');
            $is_set_menu    = $item->get_meta('catering_is_set_menu');
            $cat_qty        = is_array($raw) ? $raw : @maybe_unserialize($raw);
            $order_item_id  = $item->get_id();
            $expiry         = get_post_meta($parent_product->get_id(), 'catering_expiry', true);
            if( !$plan_days || !$user_id || !$order_item_id || !$cat_qty ) {
                // missing required data, skip this item
                continue;
            }
            
            try {
                // include health_status
                $booking_id = create_booking( $user_id, $order_item_id, $plan_days, $expiry, $cat_qty, $health_status, $type, $is_set_menu );
                $booking    = new Booking($booking_id);
                $booking->set('status', 'active');
                $item->add_meta_data( 'due_date', ($health_status['due_date']) ?? '' , true );
                $item->save();
            } catch (Exception $e) {
                
                error_log($e->getMessage());

            }
        }
    }
}

// Inactivates booking if the order status changes away from processing or completed
add_action('woocommerce_order_status_changed', 'catering_inactivate_booking_on_status_change', 10, 4);
function catering_inactivate_booking_on_status_change($order_id, $old_status, $new_status, $order) {
    // only act on non-processing/completed
    if (in_array($new_status, ['processing','completed'], true)) {
        return;
    }
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order_id);
    }
    $user_id = $order->get_user_id();
    global $wpdb;
    $table = $wpdb->prefix . 'catering_booking';
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if( $product && $product->get_type() === 'variation' ){
            $parent_product = wc_get_product($product->get_parent_id());
        } else{
            continue;
        }
        // check catering_plan or its variation
        if ($parent_product && $parent_product->get_type() === 'catering_plan') {
            $order_item_id = $item->get_id();
            $booking = get_booking_by_order_item_id($order_item_id);
            if (!$booking) {
                continue;
            }
            try {
                $booking->set('status', 'inactive');
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        }
    }
}

// Renders custom allergy info section on checkout for catering_plan products
add_action('woocommerce_checkout_before_customer_details', 'catering_custom_allergy_section');
function catering_custom_allergy_section(){

    include_once plugin_dir_path(__FILE__) . '../template/wc-checkout-allergy.php';

}

// Saves custom checkout fields (allergy info and due date) to order meta
add_action('woocommerce_checkout_create_order', 'catering_save_custom_fields', 20, 2);
function catering_save_custom_fields( WC_Order $order, $data ) {
    // food allergies
    $health_status = array();
    if ( ! empty( $_POST['catering_food_allergy'] ) ) {
        $allergies = array_map( 'sanitize_text_field', (array) $_POST['catering_food_allergy'] );
        $health_status['allergy'] = $allergies;
    }
    // due date
    if ( ! empty( $_POST['catering_due_date'] ) ) {
        $health_status['due_date'] = sanitize_text_field( $_POST['catering_due_date'] );
    }
    if(!empty($health_status)){
        $order->update_meta_data( 'catering_health_status', $health_status );
    }
    $order->save();
}

// Hides billing fields on the checkout page using custom CSS
add_action('wp_head','catering_hide_billing_css');
function catering_hide_billing_css(){
    if(is_checkout() && ! is_order_received_page()){
        echo '<style>
            .woocommerce-billing-fields, .woocommerce-billing-fields h3 { display:none !important; }
            #ship-to-different-address { display:none !important; }
        </style>';
    }
}

add_filter('woocommerce_ship_to_different_address_checked','__return_true');

/*
 * 5) Let user pick which saved shipping address to use at checkout
 */
add_action('woocommerce_checkout_billing', 'catering_shipping_address_selector', 10);
function catering_shipping_address_selector() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    $uid   = get_current_user_id();
    $addr1 = wc_get_account_formatted_address('shipping',   $uid);
    $addr2 = wc_get_account_formatted_address('shipping_2', $uid);
    if ( $addr1 || $addr2 ) {
        echo '<div id="catering_shipping_selector"><h3>2. '
         . esc_html__('Choose shipping address','catering-booking-and-scheduling')
         . '</h3>';
        if ( $addr1 ) {
            printf(
                '<p><label><input type="radio" name="catering_shipping_choice" value="shipping" checked /> %s</label></p>',
                wp_kses_post($addr1)
            );
        }
        if ( $addr2 ) {
            printf(
                '<p><label><input type="radio" name="catering_shipping_choice" value="shipping_2" /> %s</label></p>',
                wp_kses_post($addr2)
            );
        }
        echo '</div>';
    }
    
    ?>
    <script>
    jQuery(function($){
        // 1) on load, copy whatever is in shipping_* into billing_*
        $('[id^="shipping_"]').each(function(){
            var id  = this.id,                                  // e.g. "shipping_first_name"
                val = $(this).val(),
                key = id.replace(/^shipping(?:_2)?_/, '');     // strips to "first_name"
            $('#billing_'+key).val(val);
        });

        // 2) when user picks a different saved address, AJAX-load it then copy
        $('input[name="catering_shipping_choice"]').change(function(){
            var choice = $(this).val(),
                data   = { action: 'catering_load_address', addr: choice };
            $.post('<?php echo esc_url(admin_url("admin-ajax.php")); ?>', data, function(r){
                if (r && r.success) {
                    $.each(r.data, function(field, val){
                        // set shipping field and trigger change to fire sync
                        $('#shipping_' + field).val(val).trigger('change');
                    });
                }
            });
        });

        // 3) any manual edit to shipping_* also syncs immediately
        $(document).on('input change', '[id^="shipping_"]', function(){
            var id  = this.id,
                val = $(this).val(),
                key = id.replace(/^shipping(?:_2)?_/, '');
            $('#billing_'+key).val(val);
        });
    });
    </script>
    <?php
}

// Displays a custom action button and includes booking popup template for catering_plan products
add_action('woocommerce_after_order_itemmeta', 'add_custom_button_after_order_itemmeta', 10, 3);
function add_custom_button_after_order_itemmeta($item_id, $item, $product) {
    if (!$product) {
        return;
    }
    include CATERING_PLUGIN_DIR . '/template/wc-order-item-meta.php';
}

// Modifies formatted meta labels for delivery date and tracking number fields
add_filter('woocommerce_order_item_get_formatted_meta_data', 'catering_modify_formatted_meta_labels', 20, 2);
function catering_modify_formatted_meta_labels($formatted_meta, $item) {
    foreach ($formatted_meta as $meta_id => $meta) {
        if ($meta->key === 'delivery_date') {
            $meta->display_key = __('Delivery Date', 'catering-booking-and-scheduling');
        } elseif ($meta->key === 'due_date') {
            $meta->display_key = __('Due Date', 'catering-booking-and-scheduling');
        } elseif ($meta->key === 'tracking_number') {
            $meta->display_key = __('Tracking Number', 'catering-booking-and-scheduling');
        }
        elseif ($meta->key === 'cs_note') {
            $meta->display_key = __('CS Note', 'catering-booking-and-scheduling');
        }
    }
    return $formatted_meta;
}

// Add delivery status select box on order edit page in admin
add_action('woocommerce_admin_order_data_after_order_details', 'add_delivery_status_select_order_admin');
function add_delivery_status_select_order_admin( $order ) {
    // Get current delivery status meta
    $delivery_status = $order->get_meta( '_delivery_status', true );
    ?>
    <p class="form-field form-field-wide wc-delivery-status">
        <label for="delivery_status"><?php _e('Delivery Status', 'catering-booking-and-scheduling'); ?></label>
        <select id="delivery_status" name="delivery_status">
            <option value="pending" <?php selected($delivery_status, 'pending'); ?>>
                <?php _e('Pending', 'catering-booking-and-scheduling'); ?>
            </option>
            <option value="partially_delivered" <?php selected($delivery_status, 'partially_delivered'); ?>>
                <?php _e('Partially delivered', 'catering-booking-and-scheduling'); ?>
            </option>
            <option value="awaiting_fulfillment" <?php selected($delivery_status, 'awaiting_fulfillment'); ?>>
                <?php _e('Awaiting fulfillment', 'catering-booking-and-scheduling'); ?>
            </option>
            <option value="completed" <?php selected($delivery_status, 'completed'); ?>>
                <?php _e('Completed', 'catering-booking-and-scheduling'); ?>
            </option>
            <option value="not_applicable" <?php selected($delivery_status, 'not_applicable'); ?>>
                <?php _e('Not applicable', 'catering-booking-and-scheduling'); ?>
            </option>
        </select>
    </p>
    <script>
        jQuery(function($){
            var elemDelivery = $('.wc-delivery-status'),
                elemStatus  = $('.wc-order-status');
            if ( elemDelivery.length && elemStatus.length ) {
                elemDelivery.insertAfter( elemStatus );
            }
        });
    </script>
    <?php
}

// Save delivery status field when order is saved
add_action('woocommerce_process_shop_order_meta', 'save_delivery_status_order_meta', 45, 2);
function save_delivery_status_order_meta( $order_id, $post ) {
    if ( isset($_POST['delivery_status']) ) {
        $order = wc_get_order( $order_id );
        $order->update_meta_data( '_delivery_status', sanitize_text_field($_POST['delivery_status']) );
        $order->save();
    }
}

// Save catering_plan order item metadata (for admin order edit page on manual order creation)
add_action('woocommerce_process_shop_order_meta', 'save_catering_plan_item_meta', 46, 2);
function save_catering_plan_item_meta( $order_id, $post ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    global $wpdb;
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) {
            continue;
        }

        // Get parent product if this is a variation
        $parent_product = ( $product->get_type() === 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
        
        if ( $parent_product && $parent_product->get_type() === 'catering_plan' ) {
            
            // 1. Check and add catering_cat_qty meta
            if ( ! $item->get_meta( 'catering_cat_qty' ) ) {
                $cat_qty = $parent_product->get_meta( 'catering_cat_qty' );
                if ( $cat_qty ) {
                    $item->add_meta_data( 'catering_cat_qty', sanitize_text_field( $cat_qty ), true );
                    
                    // Add per-category metas based on catering_cat_qty value
                    global $wpdb;
                    $terms_table = $wpdb->prefix . 'catering_terms';
                    $raw = $cat_qty;
                    $arr = is_array( $raw ) ? $raw : @unserialize( $raw );
                    if ( is_array( $arr ) ) {
                        foreach ( $arr as $term_id => $max_pick ) {
                            $term_id = absint( $term_id );
                            $title = $wpdb->get_var(
                                $wpdb->prepare( "SELECT title FROM {$terms_table} WHERE ID=%d", $term_id )
                            );
                            if ( $title ) {
                                $item->add_meta_data( 
                                    __( 'Daily picks for', 'catering-booking-and-scheduling' ) . ' ' . sanitize_text_field( $title ), 
                                    sanitize_text_field( $max_pick ), 
                                    true
                                );
                            }
                        }
                    }
                }
            }

            // 2. Check and add plan_days meta
            if ( ! $item->get_meta( 'plan_days' ) ) {
                $variation_product = ( $product->get_type() === 'variation' ) ? $product : $parent_product;
                $plan_days = $variation_product->get_attribute( 'pa_plan-days' );
                if ( $plan_days ) {
                    $item->add_meta_data( 'plan_days', sanitize_text_field( $plan_days ), true );
                }
            }

            // 3. Check and add catering_type meta
            if ( ! $item->get_meta( 'catering_type' ) ) {
                $catering_type = $parent_product->get_meta( 'catering_type' );
                if ( $catering_type ) {
                    $item->add_meta_data( 'catering_type', sanitize_text_field( $catering_type ), true );
                }
            }

            // Save the item with new metadata
            $item->save();
            
            if( $order->get_status() == 'processing' || $order->get_status() == 'completed' ) {
                // If order is processing or completed, create bookings for catering_plan items
                $user_id        = $order->get_user_id();
                $plan_days      = (int) $item->get_meta('plan_days');
                $raw            = $item->get_meta('catering_cat_qty');
                $type           = $item->get_meta('catering_type');
                $cat_qty        = is_array($raw) ? $raw : @maybe_unserialize($raw);
                $order_item_id  = $item->get_id();
                $expiry         = get_post_meta($parent_product->get_id(), 'catering_expiry', true);
                if( !$plan_days || !$user_id || !$order_item_id || !$cat_qty ) {
                    // missing required data, skip this item
                    continue;
                }
                try {
                    $booking_id = create_booking( $user_id, $order_item_id, $plan_days, $expiry, $cat_qty, $health_status, $type );
                    $booking    = new Booking($booking_id);
                    $booking->set('status', 'active');
                } catch (Exception $e) {
                    error_log($e->getMessage());
                }
            }

        }
    }

}

// Set delivery status when a new order is created based on its product types
add_action('woocommerce_new_order', 'set_delivery_status_based_on_product', 10, 2);
function set_delivery_status_based_on_product( $order_id, $order ) {
    $not_all_catering = false;
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if( $product && $product->get_type() === 'variation' ){
            $parent_product = wc_get_product($product->get_parent_id());
        } else{
            $not_all_catering = true;
            break;
        }
        // check catering_plan or its variation
        if ($parent_product && $parent_product->get_type() !== 'catering_plan') {
            $not_all_catering = true;
            break;
        }
    }
    $status = $not_all_catering ? 'pending' : 'not_applicable';
    $order->update_meta_data( '_delivery_status', $status );
    $order->save();
}

// Add a "Delivery Status" column after Order Status in the shop order list
add_filter('manage_edit-shop_order_columns', 'add_delivery_status_column', 20);
function add_delivery_status_column($columns) {
    $new_columns = array();
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        if ($key === 'order_status') {
            $new_columns['delivery_status'] = __('Delivery Status', 'catering-booking-and-scheduling');
        }
    }
    return $new_columns;
}

// Populate the Delivery Status column with the _delivery_status meta
add_action('manage_shop_order_posts_custom_column', 'render_delivery_status_column', 20, 2);
function render_delivery_status_column($column, $post_id) {
    if ($column === 'delivery_status') {
        $order = wc_get_order( $post_id );
        $delivery_status = $order->get_meta( '_delivery_status', true );
        echo esc_html( $delivery_status );
    }
}

// Add "Delivery Status" column to HPOS orders list
add_filter('manage_woocommerce_page_wc-orders_columns', 'catering_add_delivery_status_column', 20);
function catering_add_delivery_status_column( $columns ) {
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( 'order_status' === $key ) {
            $new['delivery_status'] = __( 'Delivery Status', 'catering-booking-and-scheduling' );
        }
    }
    return $new;
}

// Render the content for our Delivery Status column
add_action('manage_woocommerce_page_wc-orders_custom_column', 'catering_render_delivery_status_column', 10, 2);
function catering_render_delivery_status_column( $column, $order ) {
    if( $column == 'delivery_status' ) {
        $delivery_status = $order->get_meta( '_delivery_status', true );
        switch($delivery_status){
            case 'pending':
                $label = __('Pending', 'catering-booking-and-scheduling');
                break;
            case 'partially_delivered':
                $label = __('Partially delivered', 'catering-booking-and-scheduling');
                break;
            case 'awaiting_fulfillment':
                $label = __('Awaiting fulfillment', 'catering-booking-and-scheduling');
                break;
            case 'completed':
                $label = __('Completed', 'catering-booking-and-scheduling');
                break;
            case 'not_applicable':
                $label = __('Not applicable', 'catering-booking-and-scheduling');
                break;
            default:
                $label = '';
        }
        echo '<span class="order-status delivery-status-label ' . $delivery_status . ' ">' . esc_html( $label ) . '</span>';
    }
}

// Make Delivery Status column sortable for legacy orders table
add_filter('manage_edit-shop_order_sortable_columns', 'add_delivery_status_sortable_column');
function add_delivery_status_sortable_column($columns) {
    $columns['delivery_status'] = '_delivery_status';
    return $columns;
}

// Make Delivery Status column sortable for HPOS orders table
add_filter('manage_woocommerce_page_wc-orders_sortable_columns', 'catering_add_delivery_status_sortable_column');
function catering_add_delivery_status_sortable_column($columns) {
    $columns['delivery_status'] = '_delivery_status';
    return $columns;
}

// Handle sorting for legacy orders table
add_action('pre_get_posts', 'catering_delivery_status_orderby');
function catering_delivery_status_orderby($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    
    if ($query->get('orderby') === '_delivery_status') {
        $query->set('meta_key', '_delivery_status');
        $query->set('orderby', 'meta_value');
    }
}

// Handle sorting for HPOS orders table
add_filter('woocommerce_orders_table_query_clauses', 'catering_delivery_status_hpos_orderby', 10, 2);
function catering_delivery_status_hpos_orderby($clauses, $query) {
    global $wpdb;
    
    if (isset($_GET['orderby']) && $_GET['orderby'] === '_delivery_status') {
        $order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
        
        // Join with orders meta table
        $clauses['join'] .= " LEFT JOIN {$wpdb->prefix}wc_orders_meta AS delivery_meta ON {$wpdb->prefix}wc_orders.id = delivery_meta.order_id AND delivery_meta.meta_key = '_delivery_status'";
        
        // Order by delivery status
        $clauses['orderby'] = "delivery_meta.meta_value {$order}";
    }
    
    return $clauses;
}

// Add delivery status filtering for HPOS orders table using the correct action hook
add_action('woocommerce_order_list_table_restrict_manage_orders', 'add_delivery_status_filter_dropdown', 20, 2);
function add_delivery_status_filter_dropdown($order_type, $which) {
    // Only show on top and for shop_order type
    if ('top' !== $which || 'shop_order' !== $order_type) {
        return;
    }
    
    global $wpdb;
    
    // Get current delivery status filter
    $current_delivery_status = isset($_GET['delivery_status']) ? sanitize_text_field($_GET['delivery_status']) : '';
    
    // Count orders by delivery status
    $delivery_statuses = array(
        'pending' => __('Pending', 'catering-booking-and-scheduling'),
        'partially_delivered' => __('Partially delivered', 'catering-booking-and-scheduling'),
        'awaiting_fulfillment' => __('Awaiting fulfillment', 'catering-booking-and-scheduling'),
        'completed' => __('Completed', 'catering-booking-and-scheduling'),
        'not_applicable' => __('Not applicable', 'catering-booking-and-scheduling'),
    );
    
    $delivery_counts = array();
    foreach (array_keys($delivery_statuses) as $status) {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(o.id) FROM {$wpdb->prefix}wc_orders o 
             LEFT JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id AND om.meta_key = '_delivery_status'
             WHERE o.type = 'shop_order' 
             AND o.status != 'trash'
             AND (om.meta_value = %s OR (om.meta_value IS NULL AND %s = 'pending'))",
            $status, $status
        ));
        if ($count > 0) {
            $delivery_counts[$status] = $count;
        }
    }
    
    // Only show dropdown if there are orders with delivery statuses
    if (!empty($delivery_counts)) {
        echo '<select name="delivery_status" id="filter-by-delivery-status">';
        echo '<option value="">' . esc_html__('All delivery statuses', 'catering-booking-and-scheduling') . '</option>';
        
        foreach ($delivery_counts as $status => $count) {
            $selected = ($status === $current_delivery_status) ? 'selected="selected"' : '';
            echo '<option value="' . esc_attr($status) . '" ' . $selected . '>';
            echo esc_html($delivery_statuses[$status]) . ' (' . number_format_i18n($count) . ')';
            echo '</option>';
        }
        
        echo '</select>';
    }
}

// Add delivery status filtering for legacy orders table
add_action('restrict_manage_posts', 'add_delivery_status_filter_dropdown_legacy');
function add_delivery_status_filter_dropdown_legacy() {
    global $typenow;
    
    // Only show on shop_order post type
    if ('shop_order' !== $typenow) {
        return;
    }
    
    global $wpdb;
    
    // Get current delivery status filter
    $current_delivery_status = isset($_GET['delivery_status']) ? sanitize_text_field($_GET['delivery_status']) : '';
    
    // Count orders by delivery status
    $delivery_statuses = array(
        'pending' => __('Pending', 'catering-booking-and-scheduling'),
        'partially_delivered' => __('Partially delivered', 'catering-booking-and-scheduling'),
        'awaiting_fulfillment' => __('Awaiting fulfillment', 'catering-booking-and-scheduling'),
        'completed' => __('Completed', 'catering-booking-and-scheduling'),
        'not_applicable' => __('Not applicable', 'catering-booking-and-scheduling'),
    );
    
    $delivery_counts = array();
    foreach (array_keys($delivery_statuses) as $status) {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p 
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_delivery_status'
             WHERE p.post_type = 'shop_order' 
             AND p.post_status != 'trash'
             AND (pm.meta_value = %s OR (pm.meta_value IS NULL AND %s = 'pending'))",
            $status, $status
        ));
        if ($count > 0) {
            $delivery_counts[$status] = $count;
        }
    }
    
    // Only show dropdown if there are orders with delivery statuses
    if (!empty($delivery_counts)) {
        echo '<select name="delivery_status" id="filter-by-delivery-status">';
        echo '<option value="">' . esc_html__('All delivery statuses', 'catering-booking-and-scheduling') . '</option>';
        
        foreach ($delivery_counts as $status => $count) {
            $selected = ($status === $current_delivery_status) ? 'selected="selected"' : '';
            echo '<option value="' . esc_attr($status) . '" ' . $selected . '>';
            echo esc_html($delivery_statuses[$status]) . ' (' . number_format_i18n($count) . ')';
            echo '</option>';
        }
        
        echo '</select>';
    }
}

// Filter orders by delivery status for legacy orders table
add_filter('pre_get_posts', 'filter_orders_by_delivery_status');
function filter_orders_by_delivery_status($query) {
    global $pagenow, $typenow;
    
    if (!is_admin() || $pagenow !== 'edit.php' || $typenow !== 'shop_order') {
        return;
    }
    
    if (!$query->is_main_query()) {
        return;
    }
    
    if (isset($_GET['delivery_status']) && !empty($_GET['delivery_status'])) {
        $delivery_status = sanitize_text_field($_GET['delivery_status']);
        
        $meta_query = $query->get('meta_query');
        if (!is_array($meta_query)) {
            $meta_query = array();
        }
        
        $meta_query[] = array(
            'key'     => '_delivery_status',
            'value'   => $delivery_status,
            'compare' => '='
        );
        
        $query->set('meta_query', $meta_query);
    }
}

// Filter orders by delivery status for HPOS orders table
add_filter('woocommerce_orders_table_query_clauses', 'filter_hpos_orders_by_delivery_status', 10, 2);
function filter_hpos_orders_by_delivery_status($clauses, $query) {
    global $wpdb;
    
    if (isset($_GET['delivery_status']) && !empty($_GET['delivery_status'])) {
        $delivery_status = sanitize_text_field($_GET['delivery_status']);
        
        // Join with orders meta table for delivery status filtering
        $clauses['join'] .= " LEFT JOIN {$wpdb->prefix}wc_orders_meta AS delivery_filter_meta ON {$wpdb->prefix}wc_orders.id = delivery_filter_meta.order_id AND delivery_filter_meta.meta_key = '_delivery_status'";
        
        // Add WHERE clause for delivery status
        $clauses['where'] .= $wpdb->prepare(" AND delivery_filter_meta.meta_value = %s", $delivery_status);
    }
    
    return $clauses;
}

// Customize admin billing fields
add_filter('woocommerce_admin_billing_fields', 'catering_admin_address_fields');
add_filter('woocommerce_admin_shipping_fields', 'catering_admin_address_fields');
function catering_admin_address_fields($fields) {
    // Disable address_2, postcode, and state fields
    unset($fields['address_2']);
    unset($fields['postcode']);
    unset($fields['state']);
    unset($fields['country']);
    unset($fields['company']);
    
    // Make city a select field with Hong Kong districts
    if (isset($fields['city'])) {
        $fields['city']['type'] = 'select';
        $fields['city']['label'] = __('District', 'catering-booking-and-scheduling');
        $fields['city']['class'] = 'test';
        $fields['city']['options'] = array(
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
    }
    
    return $fields;
}

// Handle booking status when order is trashed
add_action('woocommerce_trash_order', 'catering_on_wc_order_trash', 10, 1);
function catering_on_wc_order_trash($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
        $user_id = $order->get_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'catering_booking';
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ( is_catering_product($product) ) {
                $order_item_id = $item->get_id();
                $booking = get_booking_by_order_item_id($order_item_id);
                
                if ($booking) {
                    try {
                        // Mark booking as inactive when order is trashed
                        $booking->set('status', 'inactive');
                    } catch (Exception $e) {
                        error_log("Error handling trashed order booking: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

// Handle booking status when order being restored from trash
add_action('woocommerce_untrash_order', 'catering_on_wc_order_untrash', 10, 1);
function catering_on_wc_order_untrash($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
        // Check if order status should reactivate bookings
        $order_status = $order->get_status();
        if (in_array($order_status, ['processing', 'completed'], true)) {

            $user_id = $order->get_user_id();
    
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                // Check if it's a catering_plan product
                if ( is_catering_product($product) ) {
                    $order_item_id = $item->get_id();
                    $booking = get_booking_by_order_item_id($order_item_id);
                    
                    if ($booking) {
                        try {
                            // Mark booking as active when order is restored and has proper status
                            $booking->set('status', 'active');
                            error_log("Booking ID {$booking->id} set to active due to order restore from trash");
                        } catch (Exception $e) {
                            error_log("Error handling untrashed order booking: " . $e->getMessage());
                        }
                    } 
                }
            }
        }
    }
}

// Handle permanently deleted order - delete bookings and all related meal choices
add_action('woocommerce_before_delete_order', 'catering_on_order_permanent_delete', 10, 2);
function catering_on_order_permanent_delete($order_id,$order) {
    if ($order) {
        global $wpdb;
        $table_booking = $wpdb->prefix . 'catering_booking';
        $table_choice = $wpdb->prefix . 'catering_choice';
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();        
            // Check if it's a catering_plan product
            if (is_catering_product($product)) {
                $order_item_id = $item->get_id();
                // Get booking ID for this order item
                $booking = get_booking_by_order_item_id($order_item_id);
                if ($booking) {
                    try {

                        $booking->delete_meal_choices();
                        $booking->delete();
                        // Then delete the booking itself
                        $deleted_booking = $wpdb->delete(
                            $table_booking,
                            array('ID' => $booking_id),
                            array('%d')
                        );
                    } catch (Exception $e) {
                        error_log("Error handling permanently deleted order: " . $e->getMessage());
                    }
                }
                
            }
        }
    }
}



// Handle custom sequential order numbers
add_action('woocommerce_before_order_object_save', function (WC_Order $order) {
    if ($order->get_type() !== 'shop_order') return;
    if ($order->get_parent_id()) return;                 // avoid sub-orders
    if ($order->get_meta('_seq_order_number')) return;    // already set

    // Assign even for drafts (auto-draft/checkout-draft)
    $next = my_seq_next_number('my_hpos_order_seq', 49999);
    $order->update_meta_data('_seq_order_number', $next);
}, 5);

// Display filter
add_filter('woocommerce_order_number', function ($display, $order) {
    $n = $order->get_meta('_seq_order_number', true);
    return $n ? $n : $display;
}, 10, 2);

// Make sequential order numbers searchable in admin order listing
add_filter('woocommerce_shop_order_search_fields', 'add_seq_order_number_to_search');
function add_seq_order_number_to_search($search_fields) {
    $search_fields[] = '_seq_order_number';
    return $search_fields;
}

// Add search support for HPOS (High-Performance Order Storage)
add_filter('woocommerce_order_table_search_query_meta_keys', 'add_seq_order_number_to_hpos_search');
function add_seq_order_number_to_hpos_search($meta_keys) {
    $meta_keys[] = '_seq_order_number';
    return $meta_keys;
}

// Convert sequential order number to actual order ID for search
add_action('admin_init', 'convert_seq_number_to_order_id_in_search');
function convert_seq_number_to_order_id_in_search() {
    // Only apply on order listing pages
    if (!is_admin() || empty($_GET['s']) || !is_numeric($_GET['s'])) {
        return;
    }
    
    // Check if we're on an order page
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'])) {
        return;
    }
    
    $search_term = sanitize_text_field($_GET['s']);
    
    // Look up the actual order ID by sequential number
    global $wpdb;
    
    // Try HPOS first
    $order_id = $wpdb->get_var($wpdb->prepare(
        "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta 
         WHERE meta_key = '_seq_order_number' AND meta_value = %s",
        $search_term
    ));
    
    // If not found in HPOS, try legacy postmeta
    if (!$order_id) {
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_seq_order_number' AND meta_value = %s",
            $search_term
        ));
    }
    
    // If we found a matching order, replace the search term
    if ($order_id) {
        $_GET['s'] = $order_id;
        $_REQUEST['s'] = $order_id;
    }
}

// Atomic counter in wp_options
function my_seq_next_number($option, $start_from) {
    global $wpdb;
    $table = $wpdb->options;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (option_name, option_value, autoload)
         VALUES (%s, %s, 'no')
         ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(option_value + 1)",
        $option, (string) $start_from
    ));
    return (int) $wpdb->insert_id;
}

// Change minimum input length for product search in order edit page from 3 to 1 character
// add_action('admin_footer', 'change_product_search_minimum_input_length');
function change_product_search_minimum_input_length() {
    global $pagenow, $post;
    
    // Only on order edit pages
    if (($pagenow === 'post.php' && isset($post->post_type) && $post->post_type === 'shop_order') || 
        ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['action']) && $_GET['action'] === 'edit') || 
        ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['action']) && $_GET['action'] === 'new')) {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Listen for the backbone modal loading event for add products modal
            $(document.body).on('wc_backbone_modal_loaded', function(e, target) {
                if (target === 'wc-modal-add-products') {
                    // Wait for WooCommerce to initialize its product search, then modify it
                    setTimeout(function() {
                        $('.wc-product-search').each(function() {
                            var $productSearch = $(this);
                            
                            // Check if SelectWoo is already initialized
                            if ($productSearch.hasClass('select2-hidden-accessible')) {
                                // Get the existing options
                                var existingOptions = $productSearch.data('select2').options.options;
                                
                                // Modify only the minimumInputLength
                                if (existingOptions.ajax) {
                                    existingOptions.minimumInputLength = 1;
                                }
                                
                                // Update the language settings
                                if (existingOptions.language) {
                                    existingOptions.language.inputTooShort = function(args) {
                                        return 'Please enter 1 or more characters';
                                    };
                                }
                                
                                // Reinitialize with modified options
                                $productSearch.selectWoo('destroy');
                                $productSearch.selectWoo(existingOptions);
                            }
                        });
                    }, 100); // Small delay to ensure WooCommerce has initialized first
                }
            });
        });
        </script>
        <?php
    }
}

// Send email to admin when health status update would cause unsuitable meals
function catering_send_unsuitable_meal_alert($booking_id, $customer_name, $order_number, $new_due_date, $unsuitable_dates) {
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');
    
    $subject = sprintf(__('[%s] Customer Due Date Change Alert - Unsuitable Meals Detected', 'catering-booking-and-scheduling'), $site_name);
    
    $message = sprintf(
        __('Dear Admin,

A customer has attempted to update their due date, but this change would make some of their existing meal choices unsuitable.

Customer Details:
- Name: %s
- Order Number: %s
- Booking ID: %s
- New Due Date: %s

Unsuitable Meal Dates:
%s

The customer has been informed to contact the CS team for assistance with this change.

Please review this case and contact the customer if necessary.

Best regards,
%s System', 'catering-booking-and-scheduling'),
        $customer_name,
        $order_number,
        $booking_id,
        $new_due_date,
        '- ' . implode("\n- ", $unsuitable_dates),
        $site_name
    );
    
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    
    wp_mail($admin_email, $subject, $message, $headers);
}

add_filter( 'user_has_cap', 'allow_shop_manager_pay_for_order', 10, 3 );

function allow_shop_manager_pay_for_order( $allcaps, $caps, $args ) {
    // Only apply to pay_for_order capability
    if ( isset( $caps[0] ) && $caps[0] === 'pay_for_order' ) {
        $user_id = intval( $args[1] );
        
        // Check if user is shop manager or administrator
        if ( user_can( $user_id, 'manage_woocommerce' ) ) {
            $allcaps['pay_for_order'] = true;
        }
    }
    
    return $allcaps;
}

add_filter('woocommerce_order_received_verify_known_shoppers', function($verify) {
    // Allow shop managers and administrators to bypass verification
    if (current_user_can('manage_woocommerce')) {
        return false;
    }
    return $verify;
});

// Handle catering booking cleanup when order items are deleted via admin order edit
add_action( 'woocommerce_ajax_order_items_removed', 'catering_handle_order_item_deletion', 10, 4 );
function catering_handle_order_item_deletion( $item_id, $item, $changed_stock, $order ) {
    // Check if the deleted item was a catering product
    if ( $item && $item->get_product() ) {
        $product = $item->get_product();
        if ( is_catering_product( $product ) ) {
            error_log("Catering order item deleted: ID = $item_id, Order = " . $order->get_id());
            
            // Get and cleanup associated booking
            $booking = get_booking_by_order_item_id( $item_id );
            if ( $booking ) {
                try {
                    // Delete associated meal choices and booking
                    $booking->delete_meal_choices();
                    $booking->delete();
                    error_log("Deleted booking ID: " . $booking->id . " for order item ID: " . $item_id);
                } catch ( Exception $e ) {
                    error_log("Error deleting booking for order item $item_id: " . $e->getMessage());
                }
            }
        }
    }
}

// get user rewards points
function get_user_rewards_points($user_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'lws_wr_historic';
    
    // Sanitize user_id
    $user_id = absint($user_id);
    
    if (!$user_id) {
        return 0;
    }
    
    // Get the most recent record for this user based on the latest mvt_date
    $current_points = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT new_total 
             FROM {$table} 
             WHERE user_id = %d 
             ORDER BY mvt_date DESC
             LIMIT 1",
            $user_id
        )
    );
    
    // Return the current points or 0 if no records found
    return $current_points !== null ? intval($current_points) : 0;
}


