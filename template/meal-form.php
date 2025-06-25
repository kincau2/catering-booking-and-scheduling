<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

// Enqueue the centralized CSS file
wp_enqueue_style('catering-styles', plugin_dir_url(dirname(__FILE__)) . 'public/catering.css', array(), '1.0.0', 'all');

global $wpdb;

$table_meal    = $wpdb->prefix . 'catering_meal';

// Are we editing or adding a new meal?
$is_edit = ( $meal_id > 0 );

// Check if delete action was submitted
if (isset($_POST['cbs_delete_meal']) && isset($_POST['meal_id']) && $_POST['meal_id'] > 0) {
    check_admin_referer('cbs_delete_meal_action', 'cbs_delete_meal_nonce');
    
    $delete_id = absint($_POST['meal_id']);
    
    // Delete from meal table
    $wpdb->delete($table_meal, ['ID' => $delete_id], ['%d']);
    
    // Delete relationships if needed
    $table_rel = $wpdb->prefix . 'catering_term_relationships';
    $wpdb->delete($table_rel, ['meal_id' => $delete_id], ['%d']);
    
    // Redirect after deletion
    wp_redirect(admin_url('admin.php?page=meal-item&message=meal_deleted'));
    exit;
}

// If we're editing, fetch the row from DB
$meal = null;
if ( $is_edit ) {
    $meal = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table_meal WHERE ID = %d", $meal_id ),
        ARRAY_A
    );
    if ( ! $meal ) {
        echo '<div class="error"><p>' . __( 'Meal not found.', 'catering-booking-and-scheduling' ) . '</p></div>';
        return;
    }
}

// Nonce/action/submit fields
$nonce_action      = $is_edit ? 'cbs_update_meal_action' : 'cbs_add_meal_action';
$nonce_name        = $is_edit ? 'cbs_update_meal_nonce'  : 'cbs_add_meal_nonce';
$submit_field_name = $is_edit ? 'cbs_update_meal'        : 'cbs_add_meal';

$message = isset( $_GET['message'] ) ? sanitize_text_field( $_GET['message'] ) : '';

// 1) Handle form submission
if ( isset( $_POST[ $submit_field_name ] ) ) {

    check_admin_referer( $nonce_action, $nonce_name );

    // Basic meal field
    $title       = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '';
    $description = isset( $_POST['description'] ) ? sanitize_text_field( $_POST['description'] ) : '';
    $photo       = isset( $_POST['photo'] ) ? esc_url_raw( $_POST['photo'] ) : '';
    $sku         = isset( $_POST['sku'] ) ? sanitize_text_field( $_POST['sku'] ) : '';
    $cost        = isset( $_POST['cost'] ) ? floatval( $_POST['cost'] ) : 0;

    // Insert or Update the meal row
    if ( $is_edit ) {

      $meal = $wpdb->get_row(
          $wpdb->prepare( "SELECT * FROM $table_meal WHERE SKU = %s", $sku ),
          ARRAY_A
      );

      if( $meal && $meal_id != $meal['ID'] ){
        wp_redirect( admin_url( 'admin.php?page=meal-item&action=edit&&meal_id='.$meal_id.'&message=duplicated_sku' ) );
        exit;
      }

        // UPDATE the main meal row
        $respond = $wpdb->update(
            $table_meal,
            [
                'title'       => $title,
                'description' => $description,
                'photo'       => $photo,
                'sku'         => $sku,
                'cost'        => $cost,
                'date_amended_gmt' => current_time( 'mysql', 1 )
            ],
            [ 'ID' => $meal_id ],
            [ '%s','%s','%s','%s','%f','%s' ],
            [ '%d' ]
        );

        if ( $respond === false ) {
            wp_redirect( admin_url( 'admin.php?page=meal-item&action=edit&&meal_id='.$meal_id.'&message=meal_error' ) );
            exit;
        }
        // Redirect
        wp_redirect( admin_url( 'admin.php?page=meal-item&message=meal_updated' ) );
        exit;

    } else {
        // ADD new meal

        $meal = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table_meal WHERE SKU = %s", $sku ),
            ARRAY_A
        );

        if($meal){
          wp_redirect( admin_url( 'admin.php?page=meal-item&action=add&message=duplicated_sku' ) );
          exit;
        }

        $respond = $wpdb->insert(
            $table_meal,
            [
                'title'            => $title,
                'description'      => $description,
                'photo'            => $photo,
                'sku'              => $sku,
                'cost'             => $cost,
                'date_amended_gmt' => current_time( 'mysql', 1 )
            ],
            [ '%s','%s','%s','%s','%f','%s' ]
        );

        if ( $respond === false ) {
            wp_redirect( admin_url( 'admin.php?page=meal-item&action=add&message=meal_error' ) );
            exit;
        }

        // Redirect
        wp_redirect( admin_url( 'admin.php?page=meal-item&message=meal_added' ) );
        exit;
    }
}

// 2) Display form if not submitting

// Prefilled values
if ( $is_edit ) {
    $sku         = $meal['sku'];
    $title       = $meal['title'];
    $description = $meal['description'];
    $photo       = $meal['photo'];
    $cost        = isset( $meal['cost'] ) ? $meal['cost'] : '';
} else {
    // defaults for add new
    $sku         = '';
    $title       = '';
    $description = '';
    $photo       = '';
    $cost        = '';
}

// Titles for the form
$page_title      = $is_edit ? __( 'Edit Meal', 'catering-booking-and-scheduling' ) : __( 'Add New Meal', 'catering-booking-and-scheduling' );
$submit_btn_label= $is_edit ? __( 'Update Meal', 'catering-booking-and-scheduling' ) : __( 'Save Meal', 'catering-booking-and-scheduling' );
?>

<div class="wrap meal-form-wrap">
    <div class="header-section">
        <div class="catering-header"><?php echo esc_html( $page_title ); ?></div>
        <a href="<?php echo esc_url(admin_url('admin.php?page=meal-item')); ?>" class="back-btn">
            <?php _e('Back to Meal Items', 'catering-booking-and-scheduling'); ?>
        </a>
    </div>
    <!-- hidden h1 for native message placeholder  -->
    <h1 class="wp-heading-inline" style=" display: none; "></h1>

    <?php if ( $message === 'duplicated_sku' ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( 'Error: Duplicated SKU with other meal item, please double check.', 'catering-booking-and-scheduling' ); ?></p>
        </div>
    <?php elseif ( $message === 'meal_error' ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e( 'Error: Unable to save the meal item. Please try again.', 'catering-booking-and-scheduling' ); ?></p>
        </div>
    <?php endif; ?>

    <div class="content-section">
        <form method="post" class="meal-edit-form">
            <?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
            <?php if ($is_edit): ?>
                <?php wp_nonce_field('cbs_delete_meal_action', 'cbs_delete_meal_nonce'); ?>
                <input type="hidden" name="meal_id" value="<?php echo esc_attr($meal_id); ?>">
            <?php endif; ?>
            
            <div class="form-fields-container">
                <div class="form-group">
                    <label for="sku"><?php _e( 'SKU', 'catering-booking-and-scheduling' ); ?></label>
                    <input type="text" name="sku" id="sku" value="<?php echo esc_attr( $sku ); ?>" class="regular-text" />
                </div>
                
                <div class="form-group">
                    <label for="title"><?php _e( 'Meal Title', 'catering-booking-and-scheduling' ); ?> <span class="required">*</span></label>
                    <input type="text" name="title" id="title" required value="<?php echo esc_attr( $title ); ?>" class="regular-text" />
                </div>
                
                <div class="form-group">
                    <label for="description"><?php _e( 'Description', 'catering-booking-and-scheduling' ); ?></label>
                    <textarea name="description" id="description" class="large-text" rows="4"><?php echo esc_textarea( $description ); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="cost"><?php _e( 'Cost', 'catering-booking-and-scheduling' ); ?></label>
                    <input type="number" name="cost" id="cost" step="0.01" value="<?php echo esc_attr( $cost ); ?>" class="regular-text" />
                </div>
                
                <div class="form-group">
                    <label for="photo"><?php _e( 'Photo', 'catering-booking-and-scheduling' ); ?></label>
                    <div class="media-field-container">
                        <input type="text" name="photo" id="photo_url" value="<?php echo esc_attr( $photo ); ?>" readonly class="regular-text" />
                        <button type="button" class="btn btn-secondary" id="select_photo_button">
                            <?php _e( 'Select Image', 'catering-booking-and-scheduling' ); ?>
                        </button>
                    </div>
                    <?php if (!empty($photo)) : ?>
                        <div class="image-preview">
                            <img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($title); ?>" />
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="<?php echo esc_attr( $submit_field_name ); ?>" id="<?php echo esc_attr( $submit_field_name ); ?>" class="btn btn-primary">
                    <?php echo esc_html( $submit_btn_label ); ?>
                </button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=meal-item')); ?>" class="btn btn-secondary">
                    <?php _e('Cancel', 'catering-booking-and-scheduling'); ?>
                </a>
                <?php if ($is_edit): ?>
                <button type="button" class="btn btn-danger" id="delete-meal-btn">
                    <?php _e('Delete', 'catering-booking-and-scheduling'); ?>
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
(function($){
    $(document).ready(function(){
      // WP Media logic for the photo field
      let file_frame;
      $('#select_photo_button').on('click', function(e){
          e.preventDefault();
          if (file_frame) {
              file_frame.open();
              return;
          }
          file_frame = wp.media({
              title: '<?php echo esc_js( __( 'Select an image', 'catering-booking-and-scheduling' ) ); ?>',
              button: { text: '<?php echo esc_js( __( 'Use this image', 'catering-booking-and-scheduling' ) ); ?>' },
              multiple: false
          });
          file_frame.on('select', function(){
              const attachment = file_frame.state().get('selection').first().toJSON();
              $('#photo_url').val(attachment.url);
              
              // Update image preview if it exists, otherwise create it
              if ($('.image-preview').length) {
                  $('.image-preview img').attr('src', attachment.url);
              } else {
                  $('<div class="image-preview"><img src="' + attachment.url + '" alt=""></div>').insertAfter('.media-field-container');
              }
          });
          file_frame.open();
      });

      // Delete meal button logic - fixed to actually delete the meal
      $('#delete-meal-btn').on('click', function(e) {
          e.preventDefault();
          
          if (confirm('<?php echo esc_js(__('Are you sure you want to delete this meal?', 'catering-booking-and-scheduling')); ?>')) {
              // Create a hidden input for the delete action and submit the form
              var form = $(this).closest('form');
              $('<input>').attr({
                  type: 'hidden',
                  name: 'cbs_delete_meal',
                  value: '1'
              }).appendTo(form);
              form.submit();
          }
      });
    });
})(jQuery);
</script>
