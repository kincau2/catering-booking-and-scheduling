<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

add_action( 'admin_menu', 'meal_schedule_add_admin_menu' );

function meal_schedule_add_admin_menu() {
    // Top-level menu
    add_menu_page(
        __( 'Meal schedule', 'catering-booking-and-scheduling' ), // Page Title
        __( 'Meal schedule', 'catering-booking-and-scheduling' ), // Menu Title
        'manage_options',                              // Capability
        'meal-schedule',                               // Menu slug
        'catering_render_meal_schedule_page',               // Callback for main page
        'dashicons-calendar-alt',                      // Icon
        2                                              // Position
    );

    // Submenu #1: "Meal schedule" (points to the same callback for now)
    add_submenu_page(
        'meal-schedule',                    // Parent slug
        __( 'Meal schedule', 'catering-booking-and-scheduling' ), // Page title
        __( 'Meal schedule', 'catering-booking-and-scheduling' ), // Menu title
        'manage_options',                   // Capability
        'meal-schedule',                    // Submenu slug
        'catering_render_meal_schedule_page'     // Callback
    );

    // Submenu #2: "Meal schedule" (points to the same callback for now)
    add_submenu_page(
        'meal-schedule',                    // Parent slug
        __( 'Holiday Setting', 'catering-booking-and-scheduling' ), // Page title
        __( 'Holiday Setting', 'catering-booking-and-scheduling' ), // Menu title
        'manage_options',                   // Capability
        'holiday-setting',                    // Submenu slug
        'catering_render_holiday_setting_page'     // Callback
    );

    // Submenu #3: "Meal item"
    add_submenu_page(
        'meal-schedule',                   // Parent slug
        __( 'Meal item', 'catering-booking-and-scheduling' ), // Page title
        __( 'Meal item', 'catering-booking-and-scheduling' ), // Menu title
        'manage_options',                  // Capability
        'meal-item',                       // Submenu slug
        'catering_render_meal_item_page'        // Callback
    );

    // Submenu #4: "Meal category"
    add_submenu_page(
        'meal-schedule',                          // Parent slug
        __( 'Meal category', 'catering-booking-and-scheduling' ), // Page title
        __( 'Meal category', 'catering-booking-and-scheduling' ), // Menu title
        'manage_options',                         // Capability
        'meal-category',                          // Submenu slug
        'catering_render_meal_category_subpage'      // Callback function
    );

    // Submenu #5: "Meal tag"
    add_submenu_page(
        'meal-schedule',                          // Parent slug
        __( 'Meal tag', 'catering-booking-and-scheduling' ),      // Page title
        __( 'Meal tag', 'catering-booking-and-scheduling' ),      // Menu title
        'manage_options',                         // Capability
        'meal-tag',                               // Submenu slug
        'catering_render_meal_tag_subpage'           // Callback function
    );

}

function catering_render_meal_schedule_page() {
  include CATERING_PLUGIN_DIR . '/template/meal-schedule.php';
}

function catering_render_holiday_setting_page() {
  include CATERING_PLUGIN_DIR . '/template/holiday-setting.php';
}

function catering_render_meal_item_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $action  = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
    $meal_id = isset( $_GET['meal_id'] ) ? absint( $_GET['meal_id'] ) : 0;

    if ( $action === 'add' ) {
        // Show the unified form in "Add New" mode
        catering_render_meal_form( 0 );
    } elseif ( $action === 'edit' && $meal_id ) {
        // Show the unified form in "Edit" mode
        catering_render_meal_form( $meal_id );
    } else {
        // Otherwise, display the meal listing
        catering_render_meal_list();
    }
}

function catering_render_meal_form( $meal_id = 0 ) {
  include CATERING_PLUGIN_DIR . '/template/meal-form.php';
}

add_action( 'admin_enqueue_scripts', 'catering_admin_enqueue_scripts' );
function catering_admin_enqueue_scripts( $hook ) {
     // Check if we're on the "Meal item" page:
     // The $hook might look like "meal-schedule_page_meal-item"
     if ( 'meal-schedule_page_meal-item' !== $hook ) {
         return;
     }

     // Enqueue the WordPress media scripts
     wp_enqueue_media();

     // We can either enqueue an external JS file or add inline JS.
     // For simplicity, let's add a small inline script:
     wp_add_inline_script(
         'jquery', // ensure jQuery is loaded first
         "
         jQuery(document).ready(function($){
             let file_frame;
             $('#select_photo_button').on('click', function(e){
                 e.preventDefault();

                 // If the media frame already exists, reopen it.
                 if (file_frame) {
                     file_frame.open();
                     return;
                 }

                 // Create the media frame.
                 file_frame = wp.media({
                     title: '" . esc_js( __( 'Select an image', 'catering-booking-and-scheduling' ) ) . "',
                     button: {
                         text: '" . esc_js( __( 'Use this image', 'catering-booking-and-scheduling' ) ) . "'
                     },
                     multiple: false
                 });

                 // When an image is selected, run a callback.
                 file_frame.on('select', function(){
                     const attachment = file_frame.state().get('selection').first().toJSON();
                     // Set the field's value to the image URL
                     $('#photo_url').val(attachment.url);
                 });

                 // Finally, open the modal
                 file_frame.open();
             });
         });
         "
     );

     // 1) Enqueue Select2 CSS & JS (CDN or local)
     wp_enqueue_style(
         'select2-css',
         plugins_url( '/catering-booking-and-scheduling/lib/select2/select2.min.css' ),
         [],
         '4.1.0'
     );
     wp_enqueue_script(
         'select2-js',
         plugins_url( '/catering-booking-and-scheduling/lib/select2/select2.min.js' ),
         [ 'jquery' ],
         '4.1.0',
         true
     );

 }

function catering_render_meal_list() {
  include CATERING_PLUGIN_DIR . '/template/meal-list.php';
}

function catering_get_term_titles( $term_ids, $type ) {
    if ( empty( $term_ids ) || ! is_array( $term_ids ) ) {
        return [];
    }

    global $wpdb;
    $table = $wpdb->prefix . 'catering_terms';

    // Sanitize each ID as int
    $term_ids = array_map( 'intval', $term_ids );
    $ids_placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

    // Build a dynamic query: SELECT title FROM catering_terms WHERE ID IN (...) AND type='category'
    $sql = "SELECT title FROM $table WHERE type = %s AND ID IN ($ids_placeholders) ORDER BY title ASC";
    $params = array_merge( [ $type ], $term_ids );

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    if ( ! $rows ) {
        return [];
    }

    // Extract the titles
    $titles = [];
    foreach ( $rows as $r ) {
        $titles[] = $r['title'];
    }
    return $titles;
}

function catering_get_term_titles_by_ids( $ids, $type ) {
    if ( empty( $ids ) || ! is_array( $ids ) ) {
        return [];
    }

    global $wpdb;
    $table = $wpdb->prefix . 'catering_terms';

    $ids = array_map( 'intval', $ids );
    $placeholders = implode( ',', array_fill(0, count($ids), '%d') );

    // Example: SELECT ID, title FROM wp_catering_terms WHERE type='category' AND ID IN (1,2,3)
    $sql = "SELECT ID, title
            FROM $table
            WHERE type = %s
            AND ID IN ($placeholders)
            ORDER BY title ASC";

    // Merge $type + the IDs for the placeholders
    $params = array_merge( [ $type ], $ids );
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

    $result = [];
    foreach ( $rows as $row ) {
        $result[ $row['ID'] ] = $row['title'];
    }
    return $result;
}

function catering_render_meal_category_subpage() {
    // Just call the unified function
    catering_render_terms_page(
        'category',          // $term_type
        'meal-category',     // $page_slug
        'Category',          // $label_singular
        'Categories'         // $label_plural
    );
}

function catering_render_meal_tag_subpage() {
    catering_render_terms_page(
        'tag',
        'meal-tag',
        'Tag',
        'Tags'
    );
}

function catering_render_terms_page( $term_type, $page_slug, $label_singular, $label_plural ) {
    include CATERING_PLUGIN_DIR . '/template/terms-list.php';
}































?>
