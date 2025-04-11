<?php
/**
 * @link
 * @since             1.0.0
 * @package           catering-booking-and-scheduling
 *
 * Plugin Name:       Catering Booking and Scheduling
 * Description:       Customized plugin for ms. lo soups website revamp requirement.
 * Author:            Louis Au
 * Version:           0.0.1
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

define('CATERING_PLUGIN_DIR',  dirname(__FILE__)  );

?>
