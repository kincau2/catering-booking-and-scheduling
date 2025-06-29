<?php

add_action('kadence_woomail_designer_email_details', 'catering_booking_order_email', 10, 4);

function catering_booking_order_email($order, $sent_to_admin, $plain_text, $email ) {

    

    $key = $email->id;
    $output = '';


    switch ($key) {
        case 'customer_completed_order':
            $output = __('Your order has been completed. Thank you for choosing our catering service! We hope you enjoy your meals.','catering-booking-and-scheduling');
            $output .= '<br><br>' . __('You may also contact us for any comment about our services.','catering-booking-and-scheduling');
            $output .= '<br><br><a style="font-weight: normal;padding: 10px 30px 10px 30px;background: #25D366;text-decoration: unset;color: #FFF;font-weight: 500!important;"
                            href="https://web.whatsapp.com/send?phone=85267905786" class="button">
                        <img style="width: 20px;vertical-align: middle;" src="'. home_url().'/wp-content/plugins/kadence-woocommerce-email-designer/assets/images/white/whatsapp.png"> Whatsapp</a><br>';
            $output .= '<br><br>' . __('Your order details are shown below for your reference:','catering-booking-and-scheduling');
            break;

        case 'customer_processing_order':
            if(is_order_contains_catering_product($order)){
                $output = __('Thank you for choosing Ms. Lo Soup! We have received you order and confirmed your payment.','catering-booking-and-scheduling');
                $output .= __('You can now book you meal via following button, or on catering booking tab on my account page.','catering-booking-and-scheduling');
                $output .= '<br><br><a style="font-weight: normal;color: #FFF;padding: 8px 30px;background: #932331;text-decoration: unset;font-weight: 500!important;"
                            href="' . esc_url( get_permalink( wc_get_page_id( 'myaccount' ) ) . 'catering-bookings' ) . '" class="button">' . __('Book Meal', 'catering-booking-and-scheduling') . '</a>';
                $output .= '<br><br>' . __('If you have any questions or special requests, please don\'t hesitate to contact us.','catering-booking-and-scheduling');
                $output .= '<br><br><a style="font-weight: normal;padding: 10px 30px 10px 30px;background: #25D366;text-decoration: unset;color: #FFF;font-weight: 500!important;"
                                href="https://web.whatsapp.com/send?phone=85267905786" class="button">
                            <img style="width: 20px;vertical-align: middle;" src="'. home_url().'/wp-content/plugins/kadence-woocommerce-email-designer/assets/images/white/whatsapp.png"> Whatsapp</a><br>';
                $output .= '<br><br>' . __('Your order details are shown below for your reference:','catering-booking-and-scheduling');
                break;
            }else{
                $output = __('Thank you for choosing Ms. Lo Soup! We have received you order and confirmed your payment.','catering-booking-and-scheduling');
                $output .= '<br><br>' . __('If you have any questions or special requests, please don\'t hesitate to contact us.','catering-booking-and-scheduling');
                $output .= '<br><br><a style="font-weight: normal;padding: 10px 30px 10px 30px;background: #25D366;text-decoration: unset;color: #FFF;font-weight: 500!important;"
                                href="https://web.whatsapp.com/send?phone=85267905786" class="button">
                            <img style="width: 20px;vertical-align: middle;" src="'. home_url().'/wp-content/plugins/kadence-woocommerce-email-designer/assets/images/white/whatsapp.png"> Whatsapp</a><br>';
                $output .= '<br><br>' . __('Your order details are shown below for your reference:','catering-booking-and-scheduling');
                break;
            }
            
        case 'customer_on_hold_order':
            $output = __('Thank you for choosing Ms. Lo Soup! We have received your order and now waiting for payment to be settled. Please be awared that you order will be on-hold until we confirm payment has been received.','catering-booking-and-scheduling');
            $output .= '<br><br>' . __('If you have any questions or special requests, please don\'t hesitate to contact us.','catering-booking-and-scheduling');
            $output .= '<br><br><a style="font-weight: normal;padding: 10px 30px 10px 30px;background: #25D366;text-decoration: unset;color: #FFF;font-weight: 500!important;"
                            href="https://web.whatsapp.com/send?phone=85267905786" class="button">
                        <img style="width: 20px;vertical-align: middle;" src="'. home_url().'/wp-content/plugins/kadence-woocommerce-email-designer/assets/images/white/whatsapp.png"> Whatsapp</a><br>';
            $output .= '<br><br>' . __('Your order details are shown below for your reference:','catering-booking-and-scheduling');
            break;

        case 'customer_refunded_order': 
            $output = __('Your order has been refunded. If you have any questions or concerns, please contact us.','catering-booking-and-scheduling');
            $output .= '<br><br><a style="font-weight: normal;padding: 10px 30px 10px 30px;background: #25D366;text-decoration: unset;color: #FFF;font-weight: 500!important;"
                            href="https://web.whatsapp.com/send?phone=85267905786" class="button">
                        <img style="width: 20px;vertical-align: middle;" src="'. home_url().'/wp-content/plugins/kadence-woocommerce-email-designer/assets/images/white/whatsapp.png"> Whatsapp</a><br>';
            break;
        case 'customer_invoice':
            $output = '<br><br>' . __('Your order details are shown below for your reference:','catering-booking-and-scheduling');
            $output .= '<br><br>' . __('If you have any questions or special requests, please don\'t hesitate to contact us.','catering-booking-and-scheduling');
            $output .= '<br><br><a style="font-weight: normal;padding: 10px 30px 10px 30px;background: #25D366;text-decoration: unset;color: #FFF;font-weight: 500!important;"
                            href="https://web.whatsapp.com/send?phone=85267905786" class="button">
                        <img style="width: 20px;vertical-align: middle;" src="'. home_url().'/wp-content/plugins/kadence-woocommerce-email-designer/assets/images/white/whatsapp.png"> Whatsapp</a><br>';
            break;
        case 'customer_note':
            $output = __('A note has been added to your order, the details are in the following:','catering-booking-and-scheduling');
            $output .= '<br><br>' . __('If you have any questions or special requests, please don\'t hesitate to contact us.','catering-booking-and-scheduling');
            $output .= '<br><br><a style="font-weight: normal;padding: 10px 30px 10px 30px;background: #25D366;text-decoration: unset;color: #FFF;font-weight: 500!important;"
                            href="https://web.whatsapp.com/send?phone=85267905786" class="button">
                        <img style="width: 20px;vertical-align: middle;" src="'. home_url().'/wp-content/plugins/kadence-woocommerce-email-designer/assets/images/white/whatsapp.png"> Whatsapp</a><br>';
            break;

        default:

    }

    echo $output;

}

add_action('kadence_woomail_designer_email_text', 'catering_booking_no_order_email', 10, 1);

function catering_booking_no_order_email($email) {

    $key = $email->id;
    $output = '';
    
    switch ($key) {
        case 'customer_reset_password':
            $output = __('Someone requested that the password be reset for the following account:','catering-booking-and-scheduling');
            $output .= '<br><br>' . __('Username: {customer_username}','catering-booking-and-scheduling');
            $output .= '<br><br>' . __('If you did not request a password reset, please ignore this email.','catering-booking-and-scheduling');
            $output .= '<br><br>' . __('To reset your password, visit the following address:','catering-booking-and-scheduling').'<br><br>';
            break;

        case 'customer_new_account':
            $output = __('Thank you for joining Ms. Lo Soup!','catering-booking-and-scheduling').'<br><br>';
            break;


        default:
            $output = '';
    }
    
    echo $output;

}
?>