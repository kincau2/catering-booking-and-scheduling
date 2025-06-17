<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

// Enqueue the centralized CSS file
wp_enqueue_style('catering-styles', plugin_dir_url(dirname(__FILE__)) . 'public/catering.css', array(), '1.0.0', 'all');

// Process form submission
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['catering_meal_settings_nonce']) && wp_verify_nonce( $_POST['catering_meal_settings_nonce'], 'save_meal_settings' ) ) {
    global $wpdb;
    $table = $wpdb->prefix . 'catering_options';
    
    // Process min advance day
    $min_day = isset( $_POST['catering_min_day_before_order'] ) ? absint( $_POST['catering_min_day_before_order'] ) : 0;
    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $table WHERE option_name = %s", 'catering_min_day_before_order' ) );
    if ( $existing === null ) {
        $wpdb->insert( $table, [
            'option_name'  => 'catering_min_day_before_order',
            'option_value' => $min_day
        ] );
    } else {
        $wpdb->update( $table, [
            'option_value' => $min_day
        ], [
            'option_name'  => 'catering_min_day_before_order'
        ] );
    }
    
    // Process meal category for alphabet prefix (save a serialized array)
    $selected_categories = isset( $_POST['catering_category_id_require_prefix'] ) ? array_map('absint', (array) $_POST['catering_category_id_require_prefix']) : [];
    $serialized_categories = maybe_serialize( $selected_categories );
    
    $existing2 = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $table WHERE option_name = %s", 'catering_category_id_require_prefix' ) );
    
    if ( $existing2 === null ) { 
        
        $wpdb->insert( $table, [
            'option_name'  => 'catering_category_id_require_prefix',
            'option_value' => $serialized_categories
        ] );
    } else {
        $wpdb->update( $table, [
            'option_value' => $serialized_categories
        ], [
            'option_name'  => 'catering_category_id_require_prefix'
        ] );
    }
    
    $message = __( 'Settings saved.', 'catering-booking-and-scheduling' );
}

// Fetch current option values
global $wpdb;
$table       = $wpdb->prefix . 'catering_options';
$current_min = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $table WHERE option_name = %s", 'catering_min_day_before_order' ) );
$current_min = $current_min !== null ? absint( $current_min ) : 0;

$current_categories_serialized = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $table WHERE option_name = %s", 'catering_category_id_require_prefix' ) );
$current_categories = !empty( $current_categories_serialized ) ? maybe_unserialize( $current_categories_serialized ) : [];

// Query all available categories (for select2 options)
$categories = $wpdb->get_results( "SELECT ID, title FROM " . $wpdb->prefix . "catering_terms WHERE type='category' ORDER BY title ASC", ARRAY_A );
?>
<div class="wrap meal-settings-wrap">
    <!-- Header Section -->
    <div class="header-section">
        <div class="catering-header"><?php echo esc_html__( 'Meal Settings', 'catering-booking-and-scheduling' ); ?></div>
    </div>

    <!-- Hidden h1 for notices -->
    <h1 class="wp-heading-inline" style="display:none;"></h1>

    <?php if ( isset($message) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>

    <div class="content-section">
        <form method="post" class="settings-form">
            <?php wp_nonce_field( 'save_meal_settings', 'catering_meal_settings_nonce' ); ?>
            
            <div class="form-group">
                <label for="catering_min_day_before_order">
                    <?php echo esc_html__( 'Min Advance Day for Meal Booking', 'catering-booking-and-scheduling' ); ?>
                </label>
                <div class="field-wrapper">
                    <input type="number" 
                        id="catering_min_day_before_order" 
                        name="catering_min_day_before_order" 
                        class="form-control" 
                        value="<?php echo esc_attr( $current_min ); ?>" 
                        min="0"/>
                </div>
            </div>

            <div class="form-group">
                <label for="catering_category_id_require_prefix">
                    <?php _e( 'Meal Categories for Alphabet Prefix', 'catering-booking-and-scheduling' ); ?>
                </label>
                <div class="field-wrapper">
                    <select id="catering_category_id_require_prefix" 
                        name="catering_category_id_require_prefix[]" 
                        multiple="multiple" 
                        class="form-control select2">
                        <?php foreach ( $categories as $cat ) : 
                            $selected = in_array( intval( $cat['ID'] ), (array)$current_categories ) ? 'selected' : '';
                        ?>
                            <option value="<?php echo esc_attr( $cat['ID'] ); ?>" <?php echo $selected; ?>>
                                <?php echo esc_html( $cat['title'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?php _e('Save Settings', 'catering-booking-and-scheduling'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#catering_category_id_require_prefix').select2({
        placeholder: '<?php _e("Select categories", "catering-booking-and-scheduling"); ?>',
        width: '100%'
    });
});
</script>
