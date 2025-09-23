<?php

// passing $item (WC_Order_Item_Product) and $product (WC_Product) to this template

if (is_catering_product($product)) {
        // if product is a variation, get the parent product
    $parent_product = wc_get_product($product->get_parent_id());

    global $wpdb;
    // Retrieve booking ID from catering_booking table using order_item_id
    $booking = get_booking_by_order_item_id( $item->get_id() );

    // Compute remaining day count as in template/catering-booking.php
    if($booking){
        $choice_count = $booking->get_choice_count();
        $day_remaining = (int)$booking->plan_days - $choice_count;
        if($day_remaining < 0) $day_remaining = 0;

        echo '<button type="button" class="button catering-pick-meal-btn" style="margin-top:5px;" '
        . 'data-product-id="' . esc_attr($parent_product->get_id()) . '" '
        . 'data-booking-id="' . esc_attr($booking->id) . '" '
        . 'data-product-title="' . esc_attr($parent_product->get_title()) . '" '
        . 'data-days-left="' . esc_attr($day_remaining) . '">'
        . __('Open booking','catering-booking-and-scheduling') 
        . '</button>';
    
        include_once plugin_dir_path(__FILE__) . '../template/catering-booking-popup.php';

    } else {
        // No booking found, display a message
        echo '<p class="catering-no-booking">' . esc_html__('No booking found for this item.', 'catering-booking-and-scheduling') . '</p>';
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
    <div class="due-date-field" style="display:none;margin: 10px 0;">
        <input type="date" class="new-due-date"
                value="<?php echo esc_attr( $item->get_meta('due_date') ); ?>" />
        <button type="button" class="save-due-date button button-primary" style="background:#2271b1;border-color:#135e96;"><?php esc_html_e('Save', 'catering-booking-and-scheduling'); ?></button>
        <button type="button" class="cancel-due-date button" style="background:#f0f0f1;border-color:#c3c4c7;color:#2c3338;"><?php esc_html_e('Cancel', 'catering-booking-and-scheduling'); ?></button>
        <button type="button" class="delete-due-date button" style="background:#d63638;border-color:#d63638;color:#fff;"><?php esc_html_e('Delete', 'catering-booking-and-scheduling'); ?></button>
    </div>
    <div class="tracking-number-field" style="display:none;margin: 10px 0;".>
        <input type="text" class="new-tracking-number"
                value="<?php echo esc_attr( $item->get_meta('tracking_number') ); ?>"
                placeholder="<?php esc_attr_e('Enter tracking number', 'catering-booking-and-scheduling'); ?>" />
        <button type="button" class="save-tracking-number button button-primary" style="background:#2271b1;border-color:#135e96;"><?php esc_html_e('Save', 'catering-booking-and-scheduling'); ?></button>
        <button type="button" class="cancel-tracking-number button" style="background:#f0f0f1;border-color:#c3c4c7;color:#2c3338;"><?php esc_html_e('Cancel', 'catering-booking-and-scheduling'); ?></button>
        <button type="button" class="delete-tracking-number button" style="background:#d63638;border-color:#d63638;color:#fff;"><?php esc_html_e('Delete', 'catering-booking-and-scheduling'); ?></button>
    </div>
    <button type="button" class="button update-delivery-date-btn" style="margin-top:5px;" data-order-item-id="<?php echo esc_attr($item->get_id()); ?>"><?php esc_html_e('Update Delivery Date', 'catering-booking-and-scheduling'); ?></button>
    <button type="button" class="button update-due-date-btn" style="margin-top:5px; margin-left:5px;" data-order-item-id="<?php echo esc_attr($item->get_id()); ?>"><?php esc_html_e('Update Due Date', 'catering-booking-and-scheduling'); ?></button>
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
                    alert("<?php echo esc_js(__('Delivery date updated', 'catering-booking-and-scheduling')); ?>");
                    location.reload();
                } else {
                    alert(resp.data);
                }
            });
        });
        container.on("click", ".delete-delivery-date", function(){
            if (!confirm('<?php esc_html_e('Are you sure you want to delete this delivery date?', 'catering-booking-and-scheduling'); ?>')) {
                return;
            }
            var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
            $.post(ajaxurl, { action: "delete_item_delivery_date", order_item_id: orderItemId }, function(resp){
                if(resp.success){
                    alert("<?php echo esc_js(__('Delivery date deleted', 'catering-booking-and-scheduling')); ?>");
                    location.reload();
                } else {
                    alert(resp.data);
                }
            });
        });
        
        container.on("click", ".update-due-date-btn", function(){
            container.find(".due-date-field").slideDown();
        });
        container.on("click", ".cancel-due-date", function(){
            container.find(".due-date-field").slideUp();
        });
        container.on("click", ".save-due-date", function(){
            var newDate = container.find(".new-due-date").val();
            // using parent().siblings() retrieval remains unchanged
            var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
            $.post(ajaxurl, { action: "update_item_due_date", order_item_id: orderItemId, due_date: newDate }, function(resp){
                if(resp.success){
                    alert("<?php echo esc_js(__('Due date updated', 'catering-booking-and-scheduling')); ?>");
                    location.reload();
                } else {
                    alert(resp.data);
                }
            });
        });
        container.on("click", ".delete-due-date", function(){
            if (!confirm('<?php esc_html_e('Are you sure you want to delete this due date?', 'catering-booking-and-scheduling'); ?>')) {
                return;
            }
            var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
            $.post(ajaxurl, { action: "delete_item_due_date", order_item_id: orderItemId }, function(resp){
                if(resp.success){
                    alert("<?php echo esc_js(__('Due date deleted', 'catering-booking-and-scheduling')); ?>");
                    location.reload();
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
                    alert("<?php echo esc_js(__('Tracking number updated', 'catering-booking-and-scheduling')); ?>");
                    location.reload();
                } else {
                    alert(resp.data);
                }
            });
        });
        container.on("click", ".delete-tracking-number", function(){
            if (!confirm('<?php esc_html_e('Are you sure you want to delete this tracking number?', 'catering-booking-and-scheduling'); ?>')) {
                return;
            }
            var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
            $.post(ajaxurl, { action: "delete_item_tracking_number", order_item_id: orderItemId }, function(resp){
                if(resp.success){
                    alert("<?php echo esc_js(__('Tracking number deleted', 'catering-booking-and-scheduling')); ?>");
                    location.reload();
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
                
                // Update hidden form fields to prevent WooCommerce from overwriting
                var metaKeyField = $('input[name^="meta_key[' + orderItemId + ']["][value="cs_note"]');

                console.log(metaKeyField);
                if (metaKeyField.length > 0) {
                    // Find the corresponding meta_value field by extracting the meta ID from the name attribute
                    var metaKeyName = metaKeyField.attr('name');
                    var metaValueName = metaKeyName.replace('meta_key', 'meta_value');
                    $('textarea[name="' + metaValueName + '"]').val(newNote);
                } else if (newNote) {
                    // Add new hidden fields if they don't exist - WooCommerce will assign a new meta ID
                    $('#woocommerce-order-items').append(
                        '<input type="hidden" name="meta_key[' + orderItemId + '][]" value="cs_note">' +
                        '<input type="hidden" name="meta_value[' + orderItemId + '][]" value="' + newNote + '">'
                    );
                }
                
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
                    return $(this).find('th').text().trim() === '<?php echo esc_js(__('CS Note', 'catering-booking-and-scheduling')).':'; ?>';
                }).remove();
                viewDiv.find('tbody')
                        .append('<tr><th><?php echo esc_js(__('CS Note', 'catering-booking-and-scheduling')).':'; ?></th><td><p>'+ newNote +'</p></td></tr>');
                alert("<?php echo esc_js(__('CS note saved', 'catering-booking-and-scheduling')); ?>");
            } else {
                alert(resp.data);
            }
        });
    });
    container.on("click", ".delete-cs-note", function(){
        if (!confirm('<?php esc_html_e('Are you sure you want to delete this CS note?', 'catering-booking-and-scheduling'); ?>')) {
            return;
        }
        var orderItemId = $(this).parent().siblings("[data-order-item-id]").data("order-item-id");
        $.post(ajaxurl, {
            action:        "delete_item_cs_note",
            order_item_id: orderItemId
        }, function(resp){
            if (resp.success) {
                container.find(".cs-note-field").slideUp();
                
                // Update hidden form fields to prevent WooCommerce from overwriting
                var metaKeyField = $('input[name^="meta_key[' + orderItemId + ']["][value="cs_note"]');
                console.log(metaKeyField);
                if (metaKeyField.length > 0) {
                    // Find the corresponding meta_value field and remove both
                    var metaKeyName = metaKeyField.attr('name');
                    var metaValueName = metaKeyName.replace('meta_key', 'meta_value');
                    metaKeyField.remove();
                    $('textarea[name="' + metaValueName + '"]').remove();
                }
                
                var viewDiv = container.find('.view');
                if (!viewDiv.length) {
                    container.append('<div class="view"><table class="display_meta"><tbody></tbody></table></div>');
                    viewDiv = container.find('.view');
                }
                // remove CS Note row
                viewDiv.find('tr').filter(function(){
                    return $(this).find('th').text().trim() === '<?php echo esc_js(__('CS Note', 'catering-booking-and-scheduling')).':'; ?>';
                }).remove();
                alert("<?php echo esc_js(__('CS note deleted', 'catering-booking-and-scheduling')); ?>");
            } else {
                alert(resp.data);
            }
        });
    });
});
</script>