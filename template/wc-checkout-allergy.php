<?php

    $display = false;
    $due_date_required = false;
    // Loop through cart to see if any item is a catering_plan product
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $product = $cart_item['data'];
        if ( $product->get_type() === 'catering_plan' 
             || ( $product->get_type() === 'variation' 
                  && wc_get_product( $product->get_parent_id() )->get_type() === 'catering_plan' ) ) {
            $display = true;
            $parent = wc_get_product( $product->get_parent_id() );
            $due_date_type = $parent->get_meta('catering_type');
            if ( in_array( $due_date_type, array('prenatal','postpartum','hybird'), true ) ) {
                $due_date_required = true;
            }
        }
    }
    if ( ! $display ) {
        return;
    }
    ?>
    <h3 id="catering_custom_toggle" style="cursor:pointer;">1. <?php _e('Food Allergy Information', 'catering-booking-and-scheduling'); ?></h3>
    <div id="catering_custom_info">
        <p><?php _e('We understand the importance of a proper diet for maintaining good health. In order to provide you with soups and meal plans that are best suited to your body, we would like to know your physical condition. When ordering soups or monthly meal plans, please update your physical condition so that we can select meal plans that are best suited to you.','catering-booking-and-scheduling') ;?></p>
        <p><?php _e('Please select all food allergies you have:', 'catering-booking-and-scheduling'); ?></p>
        <p>
            <ul>
                <?php
                    global $wpdb;
                    $terms_table    = $wpdb->prefix . 'catering_terms';
                    $allergy_terms  = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT ID, title FROM {$terms_table} WHERE type=%s ORDER BY ordering ASC, ID ASC",
                            'allergy'
                        ),
                        ARRAY_A
                    );
                    if ( ! empty( $allergy_terms ) ) {
                        foreach ( $allergy_terms as $term ) {
                            echo '<li><label><input type="checkbox" name="catering_food_allergy[]" value="' 
                                . esc_attr( $term['ID'] ) . '"> '
                                . esc_html( $term['title'] ) 
                                . '</label></li>';
                        }
                    ?>
                        <li><label><input type="checkbox" name="catering_food_allergy[]" value="no_allergy"> <?php _e('No allergy', 'catering-booking-and-scheduling'); ?></label></li>
                    <?php
                } else {
                    echo '<li>' . esc_html__( 'No allergies found.', 'catering-booking-and-scheduling' ) . '</li>';
                }
                ?>
            </ul>
        </p>
        <?php if ( $due_date_required ) { ?>
            <p>
                <label for="catering_due_date"><?php _e('Your Due Date', 'catering-booking-and-scheduling'); ?></label>
                <input type="date" id="catering_due_date" name="catering_due_date" required>
            </p>
        <?php } ?>
        <button type="button" id="catering_custom_confirm" class="button"><?php _e('Confirm', 'catering-booking-and-scheduling'); ?></button>
    </div>
    <script>
    jQuery(document).ready(function($){
        // initial disable
        $("#customer_details, #order_review, #order_review_heading, #place_order").css({
            'pointer-events':'none','opacity':'0.5','cursor':'not-allowed'
        });
        $('form.checkout button[name="woocommerce_checkout_place_order"]').prop('disabled',true).css('opacity','0.5');

        // validation & confirm logic
        $('#catering_custom_confirm').click(function(){
            var valid = true;
            // Remove any previous error messages within our form
            $('#catering_custom_info .error-message').remove();

            // Check allergy selection
            if ($("input[name='catering_food_allergy[]']:checked").length === 0) {
                $("<span class='error-message' style='color:red; display:block; margin-top:20px;'><?php _e('Please select at least one option.', 'catering-booking-and-scheduling'); ?></span>")
                    .insertAfter("#catering_custom_info ul");
                valid = false;
            }

            // New validation: 'No allergy' cannot be selected alongside any other allergy
            var $checked = $("input[name='catering_food_allergy[]']:checked");
            if ( $checked.filter("[value='no_allergy']").length && $checked.length > 1 ) {
                $("<span class='error-message' style='color:red; display:block; margin-top:5px;'><?php _e("Cannot select 'No allergy' with other options.", 'catering-booking-and-scheduling'); ?></span>")
                    .insertAfter("#catering_custom_info ul");
                valid = false;
            }

            // Check due date if present
            if ($("#catering_due_date").length && ! $("#catering_due_date").val()) {
                $("<span class='error-message' style='color:red; display:block; margin-top:5px;'><?php _e('This field is required.', 'catering-booking-and-scheduling'); ?></span>")
                    .insertAfter("#catering_custom_info #catering_due_date");
                valid = false;
            }

            if (valid) {
                // Enable checkout sections and Place Order button; collapse the custom form
                $("#customer_details, #order_review, #order_review_heading, #place_order").css({
                    'pointer-events': '',
                    'opacity': '1',
                    'cursor': ''
                });
                $('form.checkout').find('button[name="woocommerce_checkout_place_order"]').prop('disabled', false).css('opacity', '1');
                $('#catering_custom_info').slideUp();
            }
        });

        // only expand on heading click if currently hidden
        $('#catering_custom_toggle').click(function(){
            var form = $('#catering_custom_info');
            if (! form.is(':visible') ) {
                form.slideDown();
                // re-disable checkout sections
                $("#customer_details, #order_review, #order_review_heading, #place_order").css({
                    'pointer-events':'none','opacity':'0.5','cursor':'not-allowed'
                });
                $('form.checkout button[name="woocommerce_checkout_place_order"]')
                    .prop('disabled',true).css('opacity','0.5');
            }
        });
    });
    </script>
