<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

include dirname(__FILE__) . '/ajax.php' ;
include dirname(__FILE__) . '/class-meal.php' ;
include dirname(__FILE__) . '/class-booking.php' ;
include dirname(__FILE__) . '/core-functions.php' ;
include dirname(__FILE__) . '/my-account.php' ;
include dirname(__FILE__) . '/order-function.php' ;
include dirname(__FILE__) . '/interface.php' ;
include dirname(__FILE__) . '/wc-product-catering-plan.php' ;


add_action( 'wp_enqueue_scripts', 'catering_enqueue_plugin_assets', 20 );
add_action( 'admin_enqueue_scripts', 'catering_enqueue_plugin_assets', 20 );

function catering_enqueue_plugin_assets() {
	wp_enqueue_script( 'catering-js',  plugins_url( '/catering-booking-and-scheduling/public/catering.js'));
	wp_enqueue_style( 'catering-css', plugins_url( '/catering-booking-and-scheduling/public/catering.css'));

    wp_enqueue_script(
        'catering-i18n',
        plugins_url('/catering-booking-and-scheduling/public/catering-i18n.js'),
        array('wp-i18n'),
        false,
        true
    );

    wp_set_script_translations(
        'catering-i18n', 
        'catering-booking-and-scheduling',  
        CATERING_PLUGIN_PATH . '/languages' );

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
add_action( 'admin_enqueue_scripts', 'catering_enqueue_plugin_assets_frontend', 20 );

function catering_enqueue_plugin_assets_frontend() {
	wp_enqueue_script(
		'catering_frontend_ajax',
		plugins_url( '/catering-booking-and-scheduling/include/js/frontend-ajax.js' ),
		[ 'jquery' ],
		'1.0',
		true
	);
	wp_localize_script(
		'catering_frontend_ajax',
		'catering_frontend_ajax',
		[ 'ajaxurl' => admin_url( 'admin-ajax.php' ) ]
	);
}



add_action( 'init', 'init_plugin' );

function init_plugin(){
  ob_start();
  maybe_install_plugin_table();
  init_plugin_data();
  add_manage_catering_capability();
  register_catering_custom_roles();
}


function maybe_install_plugin_table() {

  global $wpdb;
  $prefix = $wpdb->prefix;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $create_ddl = "CREATE TABLE {$prefix}catering_options (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
      option_name varchar(255) NOT NULL,
      option_value varchar(255) NOT NULL,
      PRIMARY KEY (ID),
      UNIQUE (option_name)
  );";

  maybe_create_table( $prefix."catering_options" , $create_ddl);

  $create_ddl = "CREATE TABLE {$prefix}catering_meal (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
      title varchar(255) NOT NULL,
      description varchar(255),
      photo varchar(255),
      sku varchar(255),
      cost INT,
      date_amended_gmt varchar(255) NOT NULL,
      PRIMARY KEY (ID),
      UNIQUE (sku)
  );";

  maybe_create_table( $prefix."catering_meal" , $create_ddl);

  $create_ddl = "CREATE TABLE {$prefix}catering_schedule (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
      product_id INT NOT NULL,
      date date NOT NULL,
      meal_id varchar(255) NOT NULL,
      cat_id varchar(255) NOT NULL,
      tag varchar(255) NOT NULL,
      type varchar(255),
      PRIMARY KEY (ID)
  );";

  maybe_create_table( $prefix."catering_schedule" , $create_ddl);

  $create_ddl = "CREATE TABLE {$prefix}catering_daily_meal_count (
    ID INT unsigned NOT NULL AUTO_INCREMENT,
    date date NOT NULL,
    meal_id varchar(255),
    count INT NOT NULL,
    PRIMARY KEY (ID)
  );";

  maybe_create_table( $prefix."catering_daily_meal_count" , $create_ddl);

  $create_ddl = "CREATE TABLE {$prefix}catering_choice (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
	  booking_id INT NOT NULL,
	  user_id INT NOT NULL,
      date date NOT NULL,
	  locked varchar(255),
      notice varchar(255),
      type varchar(255),
	  choice varchar(255) NOT NULL,
      address mediumtext,
      preference varchar(255),
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

  $create_ddl = "CREATE TABLE {$prefix}catering_booking (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
      status varchar(255) NOT NULL,
      user_id INT NOT NULL,
      order_item_id INT NOT NULL,
	  plan_days INT NOT NULL,
      expiry INT,
      cat_qty varchar(255) NOT NULL,
      health_status varchar(255),
      type varchar(255),
      date_created_gmt date,
      PRIMARY KEY (ID)
  );";

  maybe_create_table( $prefix."catering_booking" , $create_ddl);

  $create_ddl = "CREATE TABLE {$prefix}catering_holiday (
      ID INT unsigned NOT NULL AUTO_INCREMENT,
      date date NOT NULL,
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

function init_plugin_data(){
    global $wpdb;
    $prefix       = $wpdb->prefix;
    $term_table   = $prefix . 'catering_terms';
    $option_table = $prefix . 'catering_options';

    // 1) Ensure "足料靚湯" term exists
    $soup_id = $wpdb->get_var(
        $wpdb->prepare("SELECT ID FROM {$term_table} WHERE title=%s AND type=%s", '足料靚湯', 'category')
    );
    if ( ! $soup_id ) {
        // compute next ordering
        $cnt      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$term_table} WHERE type='category'");
        $ordering = $cnt + 1;
        $wpdb->insert(
            $term_table,
            [ 'title'=>'足料靚湯','type'=>'category','color'=>'#8BE38F','ordering'=>$ordering ],
            [ '%s','%s','%s','%d']
        );
        $soup_id = $wpdb->insert_id;

        $wpdb->replace(
            $option_table,
            [ 'option_name' => 'category_id_soup',   'option_value' => $soup_id ],
            [ '%s','%s']
        );
    }

    // 2) Ensure "其他" term exists
    $other_id = $wpdb->get_var(
        $wpdb->prepare("SELECT ID FROM {$term_table} WHERE title=%s AND type=%s", '其他', 'category')
    );
    if ( ! $other_id ) {
        // compute next ordering
        $cnt2      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$term_table} WHERE type='category'");
        $ordering2 = $cnt2 + 1;
        $wpdb->insert(
            $term_table,
            [ 'title'=>'其他','type'=>'category','color'=>'#FFE8E8','ordering'=>$ordering2 ],
            [ '%s','%s','%s','%d']
        );
        $other_id = $wpdb->insert_id;

        $wpdb->replace(
            $option_table,
            [ 'option_name' => 'category_id_others','option_value' => $other_id ],
            [ '%s','%s']
        );
    }

}

/**
 * Create 'marketing' and 'shop_assistant' roles with manage_catering cap.
 */
function register_catering_custom_roles() {
    add_role(
        'marketing',
        'Marketing',
        [
            'read'              => true,
        ]
    );
    add_role(
        'shop_assistant',
        'Shop Assistant',
        [
            'read'              => true,
            'manage_catering'   => true,
        ]
    );
}

function add_manage_catering_capability() {
    $roles = [ 'administrator', 'shop_manager', 'shop_assistant' ];
    foreach ( $roles as $role_name ) {
        $role = get_role( $role_name );
        if ( $role && ! $role->has_cap( 'manage_catering' ) ) {
            $role->add_cap( 'manage_catering' );
        }
    }
}


