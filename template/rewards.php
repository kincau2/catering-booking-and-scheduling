<?php

echo '<div class="catering-rewards">';
echo '<div class="my-rewards-title" style="
    font-size: 20px;
    color: #333333;
    font-weight: 600;
    margin-bottom: 20px;
">' . __('Ms. Lo soup Points Summary', 'catering-booking-and-scheduling') . '</div>';
// Get the current user ID


// Check if the user is logged in

echo '<p>' . __('Here is your current Ms. Lo soup points balance:', 'catering-booking-and-scheduling') ." ". do_shortcode('[wr_points_balance ]') .  '</p>';
echo '<p>' . __('You current points is equal to value: ','catering-booking-and-scheduling') . do_shortcode('[wr_points_value]') . '</p>';
echo '<p>' . __('You can use your points to redeem rewards or discounts on future orders.','catering-booking-and-scheduling') . '</p>';
// Display the points history
echo '<p>' . __('Here is you history and usage of your rewards points.', 'catering-booking-and-scheduling') . '</p>';
// Link to the points history
echo do_shortcode('[wr_show_history]');
echo '</div>';



?>