<?php
/**
 * @link
 * @since             1.0.0
 * @package           catering-booking-and-scheduling
 *
 * Plugin Name:       Catering Booking and Scheduling
 * Description:       Customized plugin for ms. lo soups website revamp requirement.
 * Author:            Louis Au
 * Version:           1.0.1
 * Author URI:
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       catering-booking-and-scheduling
*/

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

//composer autoload
require __DIR__ . '/vendor/autoload.php';

//initialize plugin
include dirname(__FILE__) . '/include/init.php' ;

// Load translation files

add_action( 'plugins_loaded', 'catering_booking_load_textdomain' );
function catering_booking_load_textdomain() {
    load_plugin_textdomain( 'catering-booking-and-scheduling', false, dirname( plugin_basename(__FILE__) ) . '/languages' );
}

define('CATERING_PLUGIN_DIR',  dirname(__FILE__)  );
define('CATERING_PLUGIN_URL',  home_url() . '/wp-content/plugins/catering-booking-and-scheduling' );
define('CATERING_PLUGIN_PATH',  plugin_dir_path(__FILE__)  );


// daily check and delete upload file under that cart item in session.
register_activation_hook( __FILE__ , 'schedule_daily_catering_meal_count' );
function schedule_daily_catering_meal_count() {
    if (!wp_next_scheduled('save_daily_catering_meal_count')) {
        wp_schedule_event(time(), 'daily', 'save_daily_catering_meal_count');
    }
}

add_action( 'save_daily_catering_meal_count', 'save_daily_catering_meal_count');
function save_daily_catering_meal_count() {
    global $wpdb;
    $choice_table = $wpdb->prefix . 'catering_choice';
    $daily_table  = $wpdb->prefix . 'catering_daily_meal_count';

    // compute "yesterday" in HKT
    $dt = new DateTime('now', new DateTimeZone('Asia/Hong_Kong'));
    $dt->modify('-1 day');
    $date_str = $dt->format('Y-m-d');

    // fetch all choice rows for that date
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT choice FROM {$choice_table} WHERE date = %s",
            $date_str
        )
    );

    $counts = [];
    if ($rows) {
        foreach ($rows as $row) {
            $raw = maybe_unserialize($row->choice);
            if (is_array($raw)) {
                foreach ($raw as $cat_id => $meal_ids) {
                    if (is_array($meal_ids)) {
                        foreach ($meal_ids as $meal_id) {
                            $mid = absint($meal_id);
                            if ($mid) {
                                $counts[$mid] = (! isset($counts[$mid])) ? 1 : $counts[$mid] + 1;
                            }
                        }
                    }
                }
            }
        }
    }
    set_transient('debug', $counts);
    // upsert daily counts, avoid duplicate date+meal_id
    foreach ($counts as $meal_id => $meal_count) {
        // check if a record already exists for this date & meal_id
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$daily_table} WHERE date = %s AND meal_id = %d",
                $date_str,
                $meal_id
            )
        );
        if ($exists) {
            // update existing row
            $wpdb->update(
                $daily_table,
                [ 'count' => $meal_count ],
                [ 'date' => $date_str, 'meal_id' => $meal_id ],
                [ '%d' ],
                [ '%s', '%d' ]
            );
        } else {
            // insert new row
            $wpdb->insert(
                $daily_table,
                [
                    'date'    => $date_str,
                    'meal_id' => $meal_id,
                    'count'   => $meal_count,
                ],
                [ '%s', '%d', '%d' ]
            );
        }
    }
}

register_deactivation_hook(__FILE__, 'unschedule_daily_catering_meal_count');
function unschedule_daily_catering_meal_count() {
    $timestamp = wp_next_scheduled('save_daily_catering_meal_count');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'save_daily_catering_meal_count');
    }
}
?>
