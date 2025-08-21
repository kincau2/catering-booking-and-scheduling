<?php

class Booking {
    public $id;
    public $ID; // for backward compatibility
    public $user_id;
    public $order_item_id;
    public $plan_days;
    public $expiry; 
    public $cat_qty;
    public $status;
    public $health_status;
    public $type;
    public $date_amended;

    public function __construct($id = null) {
        global $wpdb;
        $this->id = $id;
        $this->ID = $id; // for backward compatibility
        if ($id) {
            $table = $wpdb->prefix . 'catering_booking';
            // remove ARRAY_A so we get an object
            $booking = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE ID = %d", $id)
            );
            if (! $booking) {
                return false; // booking not found
            }
            $this->user_id       = $booking->user_id;
            $this->order_item_id = $booking->order_item_id;
            $this->plan_days     = $booking->plan_days;
            $this->expiry        = $booking->expiry;
            $this->cat_qty       = maybe_unserialize($booking->cat_qty);
            $this->status        = $booking->status;
            $this->health_status = maybe_unserialize($booking->health_status);
            $this->type          = $booking->type;
            $this->date_amended  = $booking->date_amended_gmt;
        }
    }

    public function set($param, $value) {
        global $wpdb;
        $table = $wpdb->prefix . 'catering_booking';

        // map property names to actual columns
        $map = [
            'plan_days'         => 'plan_days',
            'cat_qty'           => 'cat_qty',
            'status'            => 'status',
            'health_status'     => 'health_status',
            'type'              => 'type',
        ];
        if (! isset($map[$param])) {
            throw new Exception(__('Invalid parameter name', 'catering-booking-and-scheduling'));
        }
        $column = $map[$param];

        // prepare data & formats
        if ($param === 'plan_days') {
            $data   = [ $column => (int) $value ];
            $format = ['%d'];
        } else { // status
            $data   = [ $column => sanitize_text_field($value) ];
            $format = ['%s'];
        }

        $where        = ['ID' => $this->id];
        $where_format = ['%d'];

        $updated = $wpdb->update($table, $data, $where, $format, $where_format);
        if ( !$updated) {
            return false; // update failed
        }

        // update object property
        $this->{$param} = $value;
        return true;
    }

    public function is_date_expired($date) {
        if(!empty($this->expiry) ){

            global $wpdb;

            $first_choice_date = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT MIN(`date`) FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND user_id=%d",
                    $this->id,
                    $this->user_id
                )
            );
            
            if ( $first_choice_date ) {
                // subtract 1 so first day is inclusive
                $offset = max( (int)$this->expiry - 1, 0 );
                $expiry_datetime = strtotime( "{$first_choice_date} +{$offset} days" );
                if( $expiry_datetime < strtotime($date) ){
                    return true; // expired
                } else {
                    return false; // not expired
                }
            } else{
                return false; // no choices made, so not expired
            }
            
        } else{
            return false; // no expiry set, so not expired
        }
    }

    // new: validate date against booking health_status
    public function checkHealthDate($date) {
        global $wpdb;
        $bk = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT type, health_status FROM {$wpdb->prefix}catering_booking WHERE ID=%d",
                $this->id
            ),
            ARRAY_A
        );
        $type    = $bk['type'] ?? '';
        $hs      = isset($bk['health_status'])
                   ? maybe_unserialize($bk['health_status'])
                   : [];
        $dueDate = $hs['due_date'] ?? '';

        if ($type === 'prenatal' && $dueDate && $date > $dueDate) {
            return __('Your selected date is later than your due date; meals not suitable.', 'catering-booking-and-scheduling');
        }
        if ($type === 'postpartum' && $dueDate && $date < $dueDate) {
            return __('Your selected date is before your due date; meals not suitable.', 'catering-booking-and-scheduling');
        }
        return '';
    }

    public function get_linked_product() {
        // get the parent order ID for this order item
        $order_id = wc_get_order_id_by_order_item_id( $this->order_item_id );
        if ( ! $order_id ) {
            return 0;
        }

        // load the order
        $order = wc_get_order( $order_id );
        if ( $order ) {
            // find the matching item and return its product ID
            foreach ( $order->get_items() as $item ) {
                if ( (int)$item->get_id() === (int)$this->order_item_id ) {
                    $product = $item->get_product();
                    if( $product && $product->get_type() === 'variation' ){
                        return $product->get_parent_id();
                    } else{
                        // error: not a variation
                        return 0;
                    }
                }
            }
        } else {
            // error: order not found
            return 0;
        }

    }

    /**
     * Retrieve the WooCommerce order ID for this booking.
     * 
     * @return int|null
     */
    public function get_order_id() {
        if ( ! empty( $this->order_item_id ) && function_exists( 'wc_get_order_id_by_order_item_id' ) ) {
            return wc_get_order_id_by_order_item_id( $this->order_item_id );
        }
        return null;
    }

    /**
     * Retrieve the WC_Order_Item object tied to this booking.
     *
     * @return WC_Order_Item|null
     */
    public function get_order_item() {
        $order_id = $this->get_order_id();
        if ( ! $order_id ) {
            return null;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }
        foreach ( $order->get_items() as $item ) {
            if ( (int) $item->get_id() === (int) $this->order_item_id ) {
                return $item;
            }
        }
        return null;
    }

    public function get_meal_choices() {
        global $wpdb;
        $table = $wpdb->prefix . 'catering_choice';
        $choices = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE booking_id=%d AND user_id=%d ORDER BY `date` ASC",
                $this->id,
                $this->user_id
            ),
            ARRAY_A
        );
        return $choices ?: [];
    }

    public function get_choice_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'catering_choice';
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE booking_id=%d AND user_id=%d",
                $this->id,
                $this->user_id
            )
        );
        return (int) $count;
    }

    public function delete() {
        global $wpdb;
        $table = $wpdb->prefix . 'catering_booking';
        $deleted = $wpdb->delete(
            $table,
            ['ID' => $this->id],
            ['%d']
        );
        if (false === $deleted) {
            throw new Exception(__('Failed to delete booking', 'catering-booking-and-scheduling'));
        }
        return true;
    }

    public function delete_meal_choices() {
        global $wpdb;
        $table = $wpdb->prefix . 'catering_choice';
        $deleted = $wpdb->delete(
            $table,
            ['booking_id' => $this->id, 'user_id' => $this->user_id],
            ['%d', '%d']
        );
        if (false === $deleted) {
            throw new Exception(__('Failed to delete meal choices', 'catering-booking-and-scheduling'));
        }
        return true;
    }

    public function get_remaining_days() {
        global $wpdb;
        $table = $wpdb->prefix . 'catering_choice';
        $count = $this->get_choice_count();
        return max(0, (int)$this->plan_days - (int)$count);
    }

}