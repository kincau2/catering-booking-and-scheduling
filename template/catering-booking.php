<?php

global $wpdb;
$user_id = get_current_user_id();
$table   = $wpdb->prefix . 'catering_booking';
// Fetch bookings for current user
$booking_ids = $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$table} WHERE user_id=%d ORDER BY ID DESC", $user_id));
echo '<div class="my-account-catering-booking">';
echo '<table>';
echo '<thead><tr>';
echo '<th>' . __('Order Number', 'catering-booking-and-scheduling') . '</th>';
echo '<th>' . __('Status', 'catering-booking-and-scheduling') . '</th>';  // added status header
echo '<th>' . __('Order Item Title', 'catering-booking-and-scheduling') . '</th>';
echo '<th>' . __('Day Left', 'catering-booking-and-scheduling') . '</th>';
echo '<th>' . __('Expiry Date', 'catering-booking-and-scheduling') . '</th>';
echo '<th>' . __('Action', 'catering-booking-and-scheduling') . '</th>';
echo '</tr></thead>';
echo '<tbody>';
if ($booking_ids) {
    foreach ($booking_ids as $booking_id) {
        $booking = new Booking($booking_id->ID);
        // Assuming wc_get_order_item() returns a WC_Order_Item instance
        $order_id = wc_get_order_id_by_order_item_id($booking->order_item_id);
        // Skip if order_id is not found
        if (!$order_id) {
            continue;
        }
        $order_item = new WC_Order_Item_Product($booking->order_item_id);
        // Get Order Number via order item function get_order_id()
        $order_id = $order_item->get_order_id();
        $order = wc_get_order($order_id);
        $order_number = $order ? $order->get_order_number() : $order_id;
        // Get Order Item Title (assumes get_name() method exists)
        $order_item_title = $order_item->get_name();
        $product_id       = $order_item->get_product_id();  // new
        // Calculate Day Left: plan_days minus count from catering_choice table
        $day_left = $booking->get_remaining_days(); // new method to get remaining days
        $order_url = wc_get_endpoint_url(
            'view-order',
            $order_id,
            wc_get_page_permalink('myaccount')
        );
        $status      = isset($booking->status) ? sanitize_text_field($booking->status) : '';
        $disabled    = $status !== 'active' ? ' disabled' : '';
        echo '<tr>';
        echo '<td><a href="' . esc_url($order_url) . '">' . esc_html($order_number) . '</a></td>';
        echo '<td>' . __(esc_html(ucfirst($status)),'catering-booking-and-scheduling') . '</td>';  // output status
        echo '<td>' . $order_item_title ." ". __('days','catering-booking-and-scheduling') . '</td>';
        echo '<td>' . esc_html($day_left)." ".__('days','catering-booking-and-scheduling') . '</td>';
        // calculate plan expiry date based on first choice
        $first_choice_date = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MIN(`date`) FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND user_id=%d",
                $booking->ID,
                $user_id
            )
        );
        if ( $first_choice_date ) {
            // subtract 1 so first day is inclusive
            $offset = max( (int)$booking->expiry - 1, 0 );
            $display_expiry_date = date_i18n(
                get_option('date_format'),
                strtotime( "{$first_choice_date} +{$offset} days" )
            );
            $button_expiry_date = date( 'Y-m-d' ,strtotime( "{$first_choice_date} +{$offset} days"));
            
        } else {
            $display_expiry_date = '-';
            $button_expiry_date = '';
        }

        echo '<td>' . esc_html( $display_expiry_date ) . '</td>';
        echo '<td><button'
             .' class="catering-pick-meal-btn"'
             . $disabled
             .' data-booking-id="'.esc_attr($booking->id).'"'
             .' data-product-id="'.esc_attr($product_id).'"'
             .' data-days-left="'.esc_attr($day_left).'"'
             .' data-product-title="'.esc_attr($order_item_title) . ' ' . __('days','catering-booking-and-scheduling') .'"'
             .' data-expiry-date="'.esc_attr($button_expiry_date).'"'
             .'>'.__('Pick Meal','catering-booking-and-scheduling').'</button></td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="4">' . __('No bookings found', 'catering-booking-and-scheduling') . '</td></tr>';
}
echo '</tbody></table>';
echo '</div>';
include CATERING_PLUGIN_DIR . '/template/catering-booking-popup.php';

?>
