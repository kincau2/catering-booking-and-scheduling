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
add_filter('woocommerce_order_item_get_formatted_meta_data', 'catering_hide_formatted_order_itemmeta', 10, 2);
function catering_hide_formatted_order_itemmeta($formatted_meta, $item) {
    foreach ($formatted_meta as $meta_id => $meta) {
        if (in_array($meta->key, ['catering_cat_qty','plan_days','catering_type'], true)) {
            unset($formatted_meta[$meta_id]);
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
    
    // fetch health status meta
    $health_status = $order->get_meta('catering_health_status');

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if( $product && $product->get_type() === 'variation' ){
            $parent_product = wc_get_product($product->get_parent_id());
        } else{
            continue;
        }
        
        if ($parent_product && $parent_product->get_type() === 'catering_plan') {
            $plan_days      = (int) $item->get_meta('plan_days');
            $raw            = $item->get_meta('catering_cat_qty');
            $type           = $item->get_meta('catering_type');
            $cat_qty        = is_array($raw) ? $raw : @maybe_unserialize($raw);
            $order_item_id  = $item->get_id();
            $expiry         = get_post_meta($parent_product->get_id(), 'catering_expiry', true);
            
            try {
                // include health_status
                $booking_id = create_booking( $user_id, $order_item_id, $plan_days, $expiry, $cat_qty, $health_status, $type );
                $booking    = new Booking($booking_id);
                $booking->set('status', 'active');
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
            $booking_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$table} WHERE user_id=%d AND order_item_id=%d",
                    $user_id,
                    $order_item_id
                )
            );
            if (! $booking_id) {
                continue;
            }
            try {
                $booking = new Booking($booking_id);
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

/*
 * AJAX handler: retrieves shipping address meta data for user
 */
add_action('wp_ajax_catering_load_address',     'catering_load_address');
add_action('wp_ajax_nopriv_catering_load_address','catering_load_address');
function catering_load_address() {
    $addr = sanitize_text_field($_POST['addr'] ?? '');
    $uid  = get_current_user_id();
    $fields = [ 'first_name','last_name','company','address_1','address_2','city','state','postcode','country','phone','remarks' ];
    $out = [];
    foreach ($fields as $f) {
        $meta = get_user_meta($uid, $addr . '_' . $f, true);
        if ($meta) {
            $out[ $f ] = $meta;
        }
    }
    wp_send_json_success($out);
}

// Displays a custom booking action button and includes booking popup template for catering_plan products
add_action('woocommerce_after_order_itemmeta', 'add_custom_button_after_order_itemmeta', 10, 3);
function add_custom_button_after_order_itemmeta($item_id, $item, $product) {
    if (!$product) {
        return;
    }
    // if product is a variation, get the parent product
    $parent_product = ($product->get_type() === 'variation') ? wc_get_product($product->get_parent_id()) : $product;
    if ($parent_product && $parent_product->get_type() === 'catering_plan') {
        global $wpdb;
        // Retrieve booking ID from catering_booking table using order_item_id
        $booking_id = $wpdb->get_var(
            $wpdb->prepare("SELECT ID FROM {$wpdb->prefix}catering_booking WHERE order_item_id = %d", $item->get_id())
        );
        // Compute remaining day count as in template/catering-booking.php
        if($booking_id){
            $booking_row = $wpdb->get_row($wpdb->prepare(
                "SELECT plan_days, user_id FROM {$wpdb->prefix}catering_booking WHERE ID=%d", $booking_id
            ));
            if($booking_row){
                $choice_count = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND user_id=%d",
                    $booking_id, $booking_row->user_id
                ));
                $day_remaining = (int)$booking_row->plan_days - $choice_count;
            } else {
                $day_remaining = $item->get_meta('plan_days');
            }

            echo '<button type="button" class="button catering-pick-meal-btn" style="margin-top:5px;" '
           . 'data-product-id="' . esc_attr($parent_product->get_id()) . '" '
           . 'data-booking-id="' . esc_attr($booking_id) . '" '
           . 'data-product-title="' . esc_attr($parent_product->get_title()) . '" '
           . 'data-days-left="' . esc_attr($day_remaining) . '">'
           . __('Open booking','catering-booking-and-scheduling') 
           . '</button>';
        
            include_once plugin_dir_path(__FILE__) . '../template/catering-booking-popup.php';

        } else {
            $day_remaining = $item->get_meta('plan_days');
        }
        

    } else {
        ?>
        <div class="delivery-date-field" style="display:none;margin: 10px 0;">
            <input type="date" class="new-delivery-date"
                   value="<?php echo esc_attr( $item->get_meta('delivery_date') ); ?>" />
            <button type="button" class="save-delivery-date button button-primary" style="background:#2271b1;border-color:#135e96;"><?php esc_html_e('Save', 'catering-booking-and-scheduling'); ?></button>
            <button type="button" class="cancel-delivery-date button" style="background:#f0f0f1;border-color:#c3c4c7;color:#2c3338;"><?php esc_html_e('Cancel', 'catering-booking-and-scheduling'); ?></button>
            <button type="button" class="delete-delivery-date button" style="background:#d63638;border-color:#d63638;color:#fff;"><?php esc_html_e('Delete', 'catering-booking-and-scheduling'); ?></button>
        </div>
        <div class="tracking-number-field" style="display:none;margin: 10px 0;">
            <input type="text" class="new-tracking-number"
                   value="<?php echo esc_attr( $item->get_meta('tracking_number') ); ?>"
                   placeholder="<?php esc_attr_e('Enter tracking number', 'catering-booking-and-scheduling'); ?>" />
            <button type="button" class="save-tracking-number button button-primary" style="background:#2271b1;border-color:#135e96;"><?php esc_html_e('Save', 'catering-booking-and-scheduling'); ?></button>
            <button type="button" class="cancel-tracking-number button" style="background:#f0f0f1;border-color:#c3c4c7;color:#2c3338;"><?php esc_html_e('Cancel', 'catering-booking-and-scheduling'); ?></button>
            <button type="button" class="delete-tracking-number button" style="background:#d63638;border-color:#d63638;color:#fff;"><?php esc_html_e('Delete', 'catering-booking-and-scheduling'); ?></button>
        </div>
        <button type="button" class="button update-delivery-date-btn" style="margin-top:5px;" data-order-item-id="<?php echo esc_attr($item->get_id()); ?>"><?php esc_html_e('Update Delivery Date', 'catering-booking-and-scheduling'); ?></button>
        <button type="button" class="button update-tracking-number-btn" style="margin-top:5px; margin-left:5px;" data-order-item-id="<?php echo esc_attr($item->get_id()); ?>"><?php esc_html_e('Update Tracking Number', 'catering-booking-and-scheduling'); ?></button>

        <script>
        jQuery(function($){
            var container = $('[data-order-item-id="<?php echo esc_attr($item->get_id()); ?>"]').parent();
            
            container.on("click", ".update-delivery-date-btn", function(){
                container.find(".delivery-date-field").slideDown();
            });
            container.on("click", ".cancel-delivery-date", function(){
                container.find(".delivery-date-field").slideUp();
            });
            container.on("click", ".save-delivery-date", function(){
                var newDate = container.find(".new-delivery-date").val();
                // using parent().siblings() retrieval remains unchanged
                var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
                $.post(ajaxurl, { action: "update_item_delivery_date", order_item_id: orderItemId, delivery_date: newDate }, function(resp){
                    if(resp.success){
                        container.find(".delivery-date-field").slideUp();
                        var viewDiv = container.find('.view');
                        if(viewDiv.length === 0){
                            container.append('<div class="view"></div>');
                            viewDiv = container.find('.view');
                        }
                        // Retrieve the tracking number row; now matching capitalized text
                        var currentTN = viewDiv.find('tr').filter(function(){
                            return $(this).find('th').text().trim() === '<?php echo esc_js(__('Tracking Number:', 'catering-booking-and-scheduling')); ?>';
                        }).find('td p').text();
                        var html = '<table cellspacing="0" class="display_meta"><tbody>';
                        if(newDate){
                            // Changed header text: 
                            html += '<tr><th><?php echo esc_js(__('Delivery Date:', 'catering-booking-and-scheduling')); ?></th><td><p>' + newDate + '</p></td></tr>';
                        }
                        if(currentTN){
                            html += '<tr><th><?php echo esc_js(__('Tracking Number:', 'catering-booking-and-scheduling')); ?></th><td><p>' + currentTN + '</p></td></tr>';
                        }
                        html += '</tbody></table>';
                        viewDiv.html(html);
                        alert("<?php echo esc_js(__('Delivery date updated', 'catering-booking-and-scheduling')); ?>");
                    } else {
                        alert(resp.data);
                    }
                });
            });
            container.on("click", ".delete-delivery-date", function(){
                var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
                $.post(ajaxurl, { action: "delete_item_delivery_date", order_item_id: orderItemId }, function(resp){
                    if(resp.success){
                        container.find(".delivery-date-field").slideUp();
                        var viewDiv = container.find('.view');
                        if(viewDiv.length === 0){
                            container.append('<div class="view"></div>');
                            viewDiv = container.find('.view');
                        }
                        // Retrieve the tracking number row; now matching capitalized text
                        var currentTN = viewDiv.find('tr').filter(function(){
                            return $(this).find('th').text().trim() === '<?php echo esc_js(__('Tracking Number:', 'catering-booking-and-scheduling')); ?>';
                        }).find('td p').text();
                        var html = '<table cellspacing="0" class="display_meta"><tbody>';
                        if(currentTN){
                            html += '<tr><th><?php echo esc_js(__('Tracking Number:', 'catering-booking-and-scheduling')); ?></th><td><p>' + currentTN + '</p></td></tr>';
                        }
                        html += '</tbody></table>';
                        viewDiv.html(html);
                        alert("<?php echo esc_js(__('Delivery date deleted', 'catering-booking-and-scheduling')); ?>");
                    } else {
                        alert(resp.data);
                    }
                });
            });
            
            container.on("click", ".update-tracking-number-btn", function(){
                container.find(".tracking-number-field").slideDown();
            });
            container.on("click", ".cancel-tracking-number", function(){
                container.find(".tracking-number-field").slideUp();
            });
            container.on("click", ".save-tracking-number", function(){
                var newTracking = container.find(".new-tracking-number").val();
                var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
                $.post(ajaxurl, { action: "update_item_tracking_number", order_item_id: orderItemId, tracking_number: newTracking }, function(resp){
                    if(resp.success){
                        container.find(".tracking-number-field").slideUp();
                        var viewDiv = container.find('.view');
                        if(viewDiv.length === 0){
                            container.append('<div class="view"></div>');
                            viewDiv = container.find('.view');
                        }
                        // Retrieve the delivery date row; now matching capitalized text
                        var currentDD = viewDiv.find('tr').filter(function(){
                            return $(this).find('th').text().trim() === '<?php echo esc_js(__('Delivery Date:', 'catering-booking-and-scheduling')); ?>';
                        }).find('td p').text();
                        var html = '<table cellspacing="0" class="display_meta"><tbody>';
                        if(currentDD){
                            html += '<tr><th><?php echo esc_js(__('Delivery Date:', 'catering-booking-and-scheduling')); ?></th><td><p>' + currentDD + '</p></td></tr>';
                        }
                        if(newTracking){
                            html += '<tr><th><?php echo esc_js(__('Tracking Number:', 'catering-booking-and-scheduling')); ?></th><td><p>' + newTracking + '</p></td></tr>';
                        }
                        html += '</tbody></table>';
                        viewDiv.html(html);
                        alert("<?php echo esc_js(__('Tracking number updated', 'catering-booking-and-scheduling')); ?>");
                    } else {
                        alert(resp.data);
                    }
                });
            });
            container.on("click", ".delete-tracking-number", function(){
                var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
                $.post(ajaxurl, { action: "delete_item_tracking_number", order_item_id: orderItemId }, function(resp){
                    if(resp.success){
                        container.find(".tracking-number-field").slideUp();
                        var viewDiv = container.find('.view');
                        if(viewDiv.length === 0){
                            container.append('<div class="view"></div>');
                            viewDiv = container.find('.view');
                        }
                        // Retrieve the delivery date row; now matching capitalized text
                        var currentDD = viewDiv.find('tr').filter(function(){
                            return $(this).find('th').text().trim() === '<?php echo esc_js(__('Delivery Date:', 'catering-booking-and-scheduling')); ?>';
                        }).find('td p').text();
                        var html = '<table cellspacing="0" class="display_meta"><tbody>';
                        if(currentDD){
                            html += '<tr><th><?php echo esc_js(__('Delivery Date:', 'catering-booking-and-scheduling')); ?></th><td><p>' + currentDD + '</p></td></tr>';
                        }
                        html += '</tbody></table>';
                        viewDiv.html(html);
                        alert("<?php echo esc_js(__('Tracking number deleted', 'catering-booking-and-scheduling')); ?>");
                    } else {
                        alert(resp.data);
                    }
                });
            });
        });
        </script>
        <?php
    }

    // add CS note button and textarea
    echo '<button type="button" class="button cs-note-btn" style="margin-top:5px;" data-order-item-id="' . esc_attr($item->get_id()) . '">' . esc_html__('CS Note', 'catering-booking-and-scheduling') . '</button>';
    ?>
    <div class="cs-note-field" style="display:none;margin:10px 0;">
        <textarea class="new-cs-note" rows="3" style="width:100%;"><?php echo esc_textarea( $item->get_meta('cs_note') ); ?></textarea>
        <button type="button" class="save-cs-note button button-primary" style="background:#2271b1;border-color:#135e96;"><?php esc_html_e('Save', 'catering-booking-and-scheduling'); ?></button>
        <button type="button" class="cancel-cs-note button" style="background:#f0f0f1;border-color:#c3c4c7;color:#2c3338;"><?php esc_html_e('Cancel', 'catering-booking-and-scheduling'); ?></button>
        <button type="button" class="delete-cs-note button" style="background:#d63638;border-color:#d63638;color:#fff;"><?php esc_html_e('Delete', 'catering-booking-and-scheduling'); ?></button>
    </div>
    <script>
    jQuery(function($){
        var container = $('[data-order-item-id="<?php echo esc_attr($item->get_id()); ?>"]').parent();
        container.on("click", ".cs-note-btn", function(){
            container.find(".cs-note-field").slideDown();
        });
        container.on("click", ".cancel-cs-note", function(){
            container.find(".cs-note-field").slideUp();
        });
        container.on("click", ".save-cs-note", function(){
            var newNote     = container.find(".new-cs-note").val();
            var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
            $.post(ajaxurl, {
                action:        "update_item_cs_note",
                order_item_id: orderItemId,
                cs_note:       newNote
            }, function(resp){
                if (resp.success) {
                    container.find(".cs-note-field").slideUp();
                    // ensure viewDiv/table exists
                    var viewDiv = container.find('.view');
                    if (!viewDiv.length) {
                        container.append('<div class="view"><table class="display_meta"><tbody></tbody></table></div>');
                        viewDiv = container.find('.view');
                    }
                    if (!viewDiv.find('table.display_meta').length) {
                        viewDiv.html('<table class="display_meta"><tbody></tbody></table>');
                    }
                    // remove previous CS Note row, then append updated
                    viewDiv.find('tr').filter(function(){
                        return $(this).find('th').text().trim() === '<?php echo esc_js(__('CS Note:', 'catering-booking-and-scheduling')); ?>';
                    }).remove();
                    viewDiv.find('tbody')
                            .append('<tr><th><?php echo esc_js(__('CS Note:', 'catering-booking-and-scheduling')); ?></th><td><p>'+ newNote +'</p></td></tr>');
                    alert("<?php echo esc_js(__('CS note saved', 'catering-booking-and-scheduling')); ?>");
                } else {
                    alert(resp.data);
                }
            });
        });
        container.on("click", ".delete-cs-note", function(){
            var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
            $.post(ajaxurl, {
                action:        "delete_item_cs_note",
                order_item_id: orderItemId
            }, function(resp){
                if (resp.success) {
                    container.find(".cs-note-field").slideUp();
                    var viewDiv = container.find('.view');
                    if (!viewDiv.length) {
                        container.append('<div class="view"><table class="display_meta"><tbody></tbody></table></div>');
                        viewDiv = container.find('.view');
                    }
                    // remove CS Note row
                    viewDiv.find('tr').filter(function(){
                        return $(this).find('th').text().trim() === '<?php echo esc_js(__('CS Note:', 'catering-booking-and-scheduling')); ?>';
                    }).remove();
                    alert("<?php echo esc_js(__('CS note deleted', 'catering-booking-and-scheduling')); ?>");
                } else {
                    alert(resp.data);
                }
            });
        });
    });
    </script>
    <?php


}

// Modifies formatted meta labels for delivery date and tracking number fields
add_filter('woocommerce_order_item_get_formatted_meta_data', 'catering_modify_formatted_meta_labels', 20, 2);
function catering_modify_formatted_meta_labels($formatted_meta, $item) {
    foreach ($formatted_meta as $meta_id => $meta) {
        if ($meta->key === 'delivery_date') {
            $meta->display_key = __('Delivery Date', 'catering-booking-and-scheduling');
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

?>