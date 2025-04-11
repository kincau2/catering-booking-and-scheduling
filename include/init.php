<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

include dirname(__FILE__) . '/ajax.php' ;
include dirname(__FILE__) . '/class-calender-day.php' ;
include dirname(__FILE__) . '/class-catering-history.php' ;
include dirname(__FILE__) . '/class-choice.php' ;
include dirname(__FILE__) . '/class-meal.php' ;
include dirname(__FILE__) . '/core-functions.php' ;
include dirname(__FILE__) . '/my-account.php' ;
include dirname(__FILE__) . '/order-function.php' ;
include dirname(__FILE__) . '/interface.php' ;
include dirname(__FILE__) . '/product-catering-plan.php' ;


add_action( 'wp_enqueue_scripts', 'catering_enqueue_plugin_assets', 20 );
add_action( 'admin_enqueue_scripts', 'catering_enqueue_plugin_assets', 20 );

function catering_enqueue_plugin_assets() {
	wp_enqueue_script( 'catering-js',  plugins_url( '/catering-booking-and-scheduling/public/catering.js'));
	wp_enqueue_style( 'catering-css', plugins_url( '/catering-booking-and-scheduling/public/catering.css'));
}

add_action( 'admin_enqueue_scripts', 'catering_enqueue_plugin_assets_backend', 20 );

function catering_enqueue_plugin_assets_backend() {
	wp_enqueue_script(
    'catering_backend_ajax',
    plugins_url( '/catering-booking-and-scheduling/include/js/backend-ajax.js' ),
    [ 'jquery', 'select2-js' ],
    '1.0',
    true
  );

  wp_localize_script( 'catering_backend_ajax', 'catering_backend_ajax', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'i18n' => [
            'categoryPlaceholder' => __( 'Search or select categories', 'catering-booking-and-scheduling' ),
            'tagPlaceholder'      => __( 'Search or select tags', 'catering-booking-and-scheduling' ),
        ],
    ]);
}

add_action( 'wp_enqueue_scripts', 'catering_enqueue_plugin_assets_frontend', 20 );

function catering_enqueue_plugin_assets_frontend() {
	wp_enqueue_script( 'catering_frontend_ajax', plugins_url( '/catering-booking-and-scheduling/include/js/frontend-ajax.js' ) );
	wp_localize_script( 'catering_frontend_ajax', 'catering_frontend_ajax', array(
	'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
}


add_action( 'init', 'init_plugin' );

function init_plugin(){
  ob_start();
  maybe_install_plugin_table();
}


function maybe_install_plugin_table() {

  global $wpdb;
  $prefix = $wpdb->prefix;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $create_ddl = "CREATE TABLE {$prefix}catering_options (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
      option_name varchar(255) NOT NULL,
      option_value varchar(255) NOT NULL,
      PRIMARY KEY (ID)
  );";

  maybe_create_table( $prefix."catering_options" , $create_ddl);

  $create_ddl = "CREATE TABLE {$prefix}catering_meal (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
      title varchar(255) NOT NULL,
      plan_id varchar(255),
      description varchar(255),
      photo varchar(255),
      sku varchar(255),
      date_created_gmt varchar(255) NOT NULL,
      PRIMARY KEY (ID)
  );";

  maybe_create_table( $prefix."catering_meal" , $create_ddl);

  $create_ddl = "CREATE TABLE {$prefix}catering_schedule (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
      date varchar(255) NOT NULL,
      meal varchar(255),
      PRIMARY KEY (ID),
      UNIQUE (date)
  );";

  maybe_create_table( $prefix."catering_schedule" , $create_ddl);

	$create_ddl = "CREATE TABLE {$prefix}catering_choice (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
			order_id INT NOT NULL,
			user_id INT NOT NULL,
      date varchar(255) NOT NULL,
			locked varchar(255) NOT NULL,
			choice varchar(255) NOT NULL,
      PRIMARY KEY (ID)
  );";

  maybe_create_table( $prefix."catering_choice" , $create_ddl);

  $create_ddl = "CREATE TABLE {$prefix}catering_terms (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
			title text NOT NULL,
			type text NOT NULL,
      color text,
      ordering INT,
      PRIMARY KEY (ID)
  );";

  maybe_create_table( $prefix."catering_terms" , $create_ddl);

  $create_ddl = "CREATE TABLE {$prefix}catering_term_relationships (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
      meal_id INT NOT NULL,
			term_type text NOT NULL,
			term_id INT NOT NULL,
      PRIMARY KEY (ID)
  );";

  maybe_create_table( $prefix."catering_term_relationships" , $create_ddl);

  $create_ddl = "CREATE TABLE {$prefix}catering_holiday (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
      date varchar(255) NOT NULL,
      holiday_name varchar(255),
      PRIMARY KEY (ID),
      UNIQUE (date)
  );";

  maybe_create_table( $prefix."catering_holiday" , $create_ddl);

}

function my_plugin_admin_scripts($hook){
    // check if we are on the terms list page
    if($hook==='toplevel_page_meal-schedule' ){ // or whichever your page slug is
        wp_enqueue_script('jquery-ui-sortable');
    }
}
add_action('admin_enqueue_scripts','my_plugin_admin_scripts');































?>
