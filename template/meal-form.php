<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}


global $wpdb;

$table_meal    = $wpdb->prefix . 'catering_meal';
$table_relationships = $wpdb->prefix . 'catering_term_relationships';

// Are we editing or adding a new meal?
$is_edit = ( $meal_id > 0 );

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

// 1) Handle form submission
if ( isset( $_POST[ $submit_field_name ] ) ) {
    check_admin_referer( $nonce_action, $nonce_name );

    // Basic meal fields
    $title       = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '';
    $plan_id     = isset( $_POST['plan_id'] ) ? intval( $_POST['plan_id'] ) : 0;
    $description = isset( $_POST['description'] ) ? sanitize_text_field( $_POST['description'] ) : '';
    $photo       = isset( $_POST['photo'] ) ? esc_url_raw( $_POST['photo'] ) : '';
    $sku         = isset( $_POST['sku'] ) ? sanitize_text_field( $_POST['sku'] ) : '';

    // Multi-select arrays for categories & tags
    $category_ids_arr = isset( $_POST['category_ids'] ) ? (array) $_POST['category_ids'] : [];
    $tag_ids_arr      = isset( $_POST['tag_ids'] ) ? (array) $_POST['tag_ids'] : [];

    // Insert or Update the meal row
    if ( $is_edit ) {
        // UPDATE the main meal row
        $wpdb->update(
            $table_meal,
            [
                'title'       => $title,
                'plan_id'     => $plan_id,
                'description' => $description,
                'photo'       => $photo,
                'sku'         => $sku
            ],
            [ 'ID' => $meal_id ],
            [ '%s','%d','%s','%s','%s' ],
            [ '%d' ]
        );

        // Now update the relationships
        // 1) Delete old rows for this meal_id
        $wpdb->delete( $table_relationships, [ 'meal_id' => $meal_id ], [ '%d' ] );

        // 2) Insert new rows for each category
        foreach ( $category_ids_arr as $cat_id ) {
            $wpdb->insert(
                $table_relationships,
                [
                    'meal_id'   => $meal_id,
                    'term_type' => 'category',
                    'term_id'   => intval( $cat_id )
                ],
                [ '%d','%s','%d' ]
            );
        }
        // 3) Insert new rows for each tag
        foreach ( $tag_ids_arr as $tag_id ) {
            $wpdb->insert(
                $table_relationships,
                [
                    'meal_id'   => $meal_id,
                    'term_type' => 'tag',
                    'term_id'   => intval( $tag_id )
                ],
                [ '%d','%s','%d' ]
            );
        }

        // Redirect
        wp_redirect( admin_url( 'admin.php?page=meal-item&message=meal_updated' ) );
        exit;

    } else {
        // ADD new meal
        $date_created_gmt = current_time( 'mysql', 1 );

        $wpdb->insert(
            $table_meal,
            [
                'title'            => $title,
                'plan_id'          => $plan_id,
                'description'      => $description,
                'photo'            => $photo,
                'sku'              => $sku,
                'date_created_gmt' => $date_created_gmt,
            ],
            [ '%s','%d','%s','%s','%s','%s' ]
        );

        // Get the newly inserted meal ID
        $new_meal_id = $wpdb->insert_id;

        // Insert relationships
        foreach ( $category_ids_arr as $cat_id ) {
            $wpdb->insert(
                $table_relationships,
                [
                    'meal_id'   => $new_meal_id,
                    'term_type' => 'category',
                    'term_id'   => intval( $cat_id )
                ],
                [ '%d','%s','%d' ]
            );
        }
        foreach ( $tag_ids_arr as $tag_id ) {
            $wpdb->insert(
                $table_relationships,
                [
                    'meal_id'   => $new_meal_id,
                    'term_type' => 'tag',
                    'term_id'   => intval( $tag_id )
                ],
                [ '%d','%s','%d' ]
            );
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
    $plan_id     = $meal['plan_id'];
    $description = $meal['description'];
    $photo       = $meal['photo'];
} else {
    // defaults for add new
    $sku         = '';
    $title       = '';
    $plan_id     = '';
    $description = '';
    $photo       = '';
}

// For preselecting categories/tags in edit mode, read from relationship table
$cat_ids = [];
$tag_ids = [];
if ( $is_edit ) {
    // Query relationships
    $sql_categories = $wpdb->prepare(
        "SELECT term_id FROM $table_relationships
         WHERE meal_id=%d AND term_type='category'",
        $meal_id
    );
    $cat_ids = $wpdb->get_col( $sql_categories ); // returns array of term_id

    $sql_tags = $wpdb->prepare(
        "SELECT term_id FROM $table_relationships
         WHERE meal_id=%d AND term_type='tag'",
        $meal_id
    );
    $tag_ids = $wpdb->get_col( $sql_tags );
}

// Build arrays of {id, text} for categories, tags
$cat_preselected = [];
$tag_preselected = [];
if ( $is_edit ) {
    // We'll fetch titles from your existing function `catering_get_term_titles_by_ids(...)`
    $cat_assoc = catering_get_term_titles_by_ids( $cat_ids, 'category' );
    foreach ( $cat_assoc as $cid => $ctitle ) {
        $cat_preselected[] = [ 'id' => $cid, 'text' => $ctitle ];
    }
    $tag_assoc = catering_get_term_titles_by_ids( $tag_ids, 'tag' );
    foreach ( $tag_assoc as $tid => $ttitle ) {
        $tag_preselected[] = [ 'id' => $tid, 'text' => $ttitle ];
    }
}

// Titles for the form
$page_title      = $is_edit ? __( 'Edit Meal', 'catering-booking-and-scheduling' ) : __( 'Add New Meal', 'catering-booking-and-scheduling' );
$submit_btn_label= $is_edit ? __( 'Update Meal', 'catering-booking-and-scheduling' ) : __( 'Save Meal', 'catering-booking-and-scheduling' );
?>
<div class="wrap">
    <h1><?php echo esc_html( $page_title ); ?></h1>

    <form method="post">
        <?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
        <table class="form-table">
            <tr>
                <th><label for="sku"><?php _e( 'SKU', 'catering-booking-and-scheduling' ); ?></label></th>
                <td><input type="text" name="sku" id="sku" value="<?php echo esc_attr( $sku ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="title"><?php _e( 'Meal Title', 'catering-booking-and-scheduling' ); ?></label></th>
                <td><input type="text" name="title" id="title" required value="<?php echo esc_attr( $title ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="plan_id"><?php _e( 'Plan ID', 'catering-booking-and-scheduling' ); ?></label></th>
                <td><input type="number" name="plan_id" id="plan_id" value="<?php echo esc_attr( $plan_id ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="description"><?php _e( 'Description', 'catering-booking-and-scheduling' ); ?></label></th>
                <td>
                    <textarea name="description" id="description"><?php echo esc_textarea( $description ); ?></textarea>
                </td>
            </tr>
            <tr>
                <th><label><?php _e( 'Categories', 'catering-booking-and-scheduling' ); ?></label></th>
                <td>
                    <select name="category_ids[]" id="meal_categories" multiple="multiple" style="width: 300px;"></select>
                    <p class="description">
                        <?php _e( 'Select or search existing categories', 'catering-booking-and-scheduling' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label><?php _e( 'Tags', 'catering-booking-and-scheduling' ); ?></label></th>
                <td>
                    <select name="tag_ids[]" id="meal_tags" multiple="multiple" style="width: 300px;"></select>
                    <p class="description">
                        <?php _e( 'Select or search existing tags', 'catering-booking-and-scheduling' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="photo"><?php _e( 'Photo', 'catering-booking-and-scheduling' ); ?></label></th>
                <td>
                    <input type="text" name="photo" id="photo_url" value="<?php echo esc_attr( $photo ); ?>" readonly />
                    <button type="button" class="button" id="select_photo_button">
                        <?php _e( 'Select Image', 'catering-booking-and-scheduling' ); ?>
                    </button>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit"
                   name="<?php echo esc_attr( $submit_field_name ); ?>"
                   id="<?php echo esc_attr( $submit_field_name ); ?>"
                   class="button button-primary"
                   value="<?php echo esc_attr( $submit_btn_label ); ?>">
        </p>
    </form>
</div>

<!-- Pre-fill select2 with existing categories/tags in Edit mode -->
<script>
var catPreselected = <?php echo wp_json_encode( $cat_preselected ); ?>;
var tagPreselected = <?php echo wp_json_encode( $tag_preselected ); ?>;

(function($){
    $(document).ready(function(){
        // If you initialize your select2 in a separate file or inline, ensure this runs after initialization
        if (Array.isArray(catPreselected)) {
            catPreselected.forEach(function(item){
                let opt = new Option(item.text, item.id, true, true);
                $('#meal_categories').append(opt);
            });
            $('#meal_categories').trigger('change');
        }
        if (Array.isArray(tagPreselected)) {
            tagPreselected.forEach(function(item){
                let opt = new Option(item.text, item.id, true, true);
                $('#meal_tags').append(opt);
            });
            $('#meal_tags').trigger('change');
        }

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
            });
            file_frame.open();
        });
    });
})(jQuery);
</script>
