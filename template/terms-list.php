<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

if ( ! current_user_can( 'manage_catering' ) ) {
    return;
}

// Enqueue the centralized CSS file
wp_enqueue_style('catering-styles', plugin_dir_url(dirname(__FILE__)) . 'public/catering.css', array(), '1.0.0', 'all');

global $wpdb;
$table                 = $wpdb->prefix . 'catering_terms';
$relationship_table    = $wpdb->prefix . 'catering_term_relationships';
// fetch locked‐category IDs
$opt_table    = $wpdb->prefix . 'catering_options';
$soup_cat_id   = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT option_value FROM {$opt_table} WHERE option_name=%s",
    'category_id_soup'
) );
$others_cat_id = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT option_value FROM {$opt_table} WHERE option_name=%s",
    'category_id_others'
) );

// If we are listing "category," only then show color and ordering features
$is_category = ($term_type === 'category');

// Handle Bulk Action (Delete)
if ( isset( $_POST['catering_bulk_term_action'] ) && !empty( $_POST['catering_bulk_term_action']) ) {
    catering_handle_bulk_term_actions( $term_type, $page_slug );
}

// 1) "Add New" submission
$nonce_action_add    = "catering_add_{$term_type}_action";
$nonce_name_add      = "catering_add_{$term_type}_nonce";
$post_add_field_name = "catering_add_{$term_type}";   // e.g. 'catering_add_category'
$post_add_field_text = "{$term_type}_title";          // e.g. 'category_title'

if ( isset( $_POST[ $post_add_field_name ] ) ) {

    check_admin_referer( $nonce_action_add, $nonce_name_add );
    $new_title = isset( $_POST[ $post_add_field_text ] ) ? sanitize_text_field( $_POST[ $post_add_field_text ] ) : '';
    if ( $new_title ) {

        // check duplicate for categories
        if ( $is_category ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE type=%s AND title=%s",
                $term_type, $new_title
            ) );
            if ( $exists > 0 ) {
                wp_redirect( add_query_arg([
                    'page'    => $page_slug,
                    'message' => 'term_exists'
                ], admin_url( 'admin.php' ) ) );
                exit;
            }
        }

        $wpdb->insert(
            $table,
            [
                'title' => $new_title,
                'type'  => $term_type,
                'color' => null,  // default color is null
                'ordering' => 0
            ],
            [ '%s', '%s', '%s', '%d' ]
        );
        wp_redirect( add_query_arg([
            'page'    => $page_slug,
            'message' => 'term_added'
        ], admin_url( 'admin.php' )) );
        exit;
    }
}

// 2) single-item delete
if ( isset( $_GET['delete_id'] ) && is_numeric( $_GET['delete_id'] ) ) {
    $delete_id = absint( $_GET['delete_id'] );

    // LOCKED check
    if ( in_array( $delete_id, [ $soup_cat_id, $others_cat_id ], true ) ) {
        wp_redirect( add_query_arg( [
            'page'    => $page_slug,
            'message' => 'locked_term'
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    $paged    = isset( $_GET['paged'] )    ? absint( $_GET['paged'] ) : 1;
    $per_page = isset( $_GET['per_page'] ) ? sanitize_text_field( $_GET['per_page'] ) : 'all';
    $search   = isset( $_GET['search'] )   ? sanitize_text_field( $_GET['search'] ) : '';

    $wpdb->delete( $table, [ 'ID' => $delete_id, 'type' => $term_type ], [ '%d','%s' ] );
    $wpdb->delete( $relationship_table, [ 'term_id' => $delete_id ], [ '%d' ] );
    wp_redirect( add_query_arg([
        'page'    => $page_slug,
        'message' => 'term_deleted',
        'paged'   => $paged,
        'per_page'=> $per_page,
        'search'  => $search,
    ], admin_url( 'admin.php' )) );
    exit;
}

// 3) inline edit updates
$nonce_action_update    = "catering_update_{$term_type}_action";
$nonce_name_update      = "catering_update_{$term_type}_nonce";
$post_update_field_name = "catering_update_{$term_type}";
$post_update_term_id    = "{$term_type}_id";
$post_update_new_title  = "new_{$term_type}_title";

if ( isset( $_POST[ $post_update_field_name ] ) ) {

    check_admin_referer( $nonce_action_update, $nonce_name_update );

    $update_id    = isset( $_POST[ $post_update_term_id ] ) ? absint( $_POST[ $post_update_term_id ] ) : 0;
    $update_title = isset( $_POST[ $post_update_new_title ] ) ? sanitize_text_field( $_POST[ $post_update_new_title ] ) : '';

    $redirect_paged    = isset( $_POST['_paged'] )    ? absint( $_POST['_paged'] ) : 1;
    $redirect_per_page = isset( $_POST['_per_page'] ) ? sanitize_text_field( $_POST['_per_page'] ) : 'all';
    $redirect_search   = isset( $_POST['_search'] )   ? sanitize_text_field( $_POST['_search'] ) : '';

    if ( $update_id && $update_title ) {

        // check duplicate for categories (exclude current ID)
        if ( $is_category ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE type=%s AND title=%s AND ID!=%d",
                $term_type, $update_title, $update_id
            ) );
            if ( $exists > 0 ) {
                wp_redirect( add_query_arg([
                    'page'     => $page_slug,
                    'edit_id'  => $update_id,
                    'paged'    => $redirect_paged,
                    'per_page' => $redirect_per_page,
                    'search'   => $redirect_search,
                    'message'  => 'term_exists'
                ], admin_url( 'admin.php' ) ) );
                exit;
            }
        }

        $wpdb->update(
            $table,
            [ 'title' => $update_title ],
            [ 'ID' => $update_id, 'type' => $term_type ],
            [ '%s' ],
            [ '%d', '%s' ]
        );
        wp_redirect( add_query_arg([
            'page'     => $page_slug,
            'message'  => 'term_edited',
            'paged'    => $redirect_paged,
            'per_page' => $redirect_per_page,
            'search'   => $redirect_search,
        ], admin_url( 'admin.php' )) );
        exit;
    } else {
        wp_redirect( admin_url( "admin.php?page=$page_slug" ) );
        exit;
    }
}

// 4) Are we editing a row inline?
$edit_id = isset( $_GET['edit_id'] ) ? absint( $_GET['edit_id'] ) : 0;

// 5) Search widget
$search   = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';
$label_singular = __( $label_singular, 'catering-booking-and-scheduling' );

// ============== PAGINATION LOGIC ==============
$valid_per_pages   = [ '30', '50', '100', 'all' ];
$default_per_page  = 'all';
$per_page          = isset( $_GET['per_page'] ) ? sanitize_text_field( $_GET['per_page'] ) : $default_per_page;
if ( ! in_array( $per_page, $valid_per_pages, true ) ) {
    $per_page = $default_per_page;
}
$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
if ( $paged < 1 ) {
    $paged = 1;
}

// build final query
// if $is_category => we ORDER BY ordering ASC, then fallback ID
// else => your original order by ID desc
$order_by_clause = $is_category
    ? "ORDER BY ordering ASC, ID ASC"
    : "ORDER BY ID DESC";

$sql = "SELECT * FROM $table WHERE type=%s";
$params_for_sql = [ $term_type ];

$where_clauses = [];
if ($search !== '') {
    $where_clauses[] = "title LIKE %s";
    $params_for_sql[] = '%' . $wpdb->esc_like($search) . '%';
}
if(!empty($where_clauses)) {
    $sql .= ' AND ' . implode(' AND ', $where_clauses);
}
$sql .= " $order_by_clause"; // or "ORDER BY ordering ASC, ID ASC"

// Also build a count query - SIMPLIFIED, removed duplicate code
$sql_count = "SELECT COUNT(*) FROM $table WHERE type=%s";
$params_for_count = [ $term_type ];

if (!empty($where_clauses)) {
    $sql_count .= " AND " . implode(" AND ", $where_clauses);
    // Fix: Use the correct params array that contains the search parameter
    if ($search !== '') {
        $params_for_count[] = '%' . $wpdb->esc_like($search) . '%';
    }
}

// We'll do limit if per_page != 'all'
if ( $per_page !== 'all' ) {
    $sql .= " LIMIT %d, %d";
}

// get total
$total_items = 0;
if ( $per_page === 'all' ) {
    $total_items = (int) $wpdb->get_var( $wpdb->prepare( $sql_count, $params_for_count ) );
    // get results with no limit
    $results = $wpdb->get_results( $wpdb->prepare( $sql, $params_for_sql ), ARRAY_A );
} else {
    $per_page_int = (int) $per_page;
    $offset = ( $paged - 1 ) * $per_page_int;

    $total_items = (int) $wpdb->get_var( $wpdb->prepare( $sql_count, $params_for_count ) );

    // add offset, limit to the param array
    $params_for_sql[] = $offset;
    $params_for_sql[] = $per_page_int;

    $results = $wpdb->get_results( $wpdb->prepare($sql, $params_for_sql), ARRAY_A );
}

// compute total_pages
$total_pages = 1;
if ( $per_page !== 'all' ) {
    $per_page_int = (int) $per_page;
    $total_pages  = max( 1, ceil( $total_items / $per_page_int ) );
}

?>
<div class="wrap">
    <!-- Header Section -->
    <div class="header-section">
        <div class="catering-header"><?php printf( esc_html__( '%s', 'catering-booking-and-scheduling' ), esc_html( __($label_singular,'catering-booking-and-scheduling' ) ) ); ?></div>
    </div>

    <!-- Hidden h1 for notices -->
    <h1 class="wp-heading-inline" style="display:none;"></h1>

<?php
// messages
if ( isset( $_GET['message'] ) ) {
    $message_class = 'notice-success';
    $message = '';
    
    switch ($_GET['message']) {
        case 'term_added':
            $message = sprintf( __( 'New %s added.', 'catering-booking-and-scheduling' ), esc_html( __( $label_singular,'catering-booking-and-scheduling' ) ) );
            break;
        case 'term_deleted':
            $message = sprintf( __( '%s deleted.', 'catering-booking-and-scheduling' ), esc_html(  __($label_singular,'catering-booking-and-scheduling' ) ) );
            break;
        case 'term_edited':
            $message = sprintf( __( '%s updated.', 'catering-booking-and-scheduling' ), esc_html(  __($label_singular,'catering-booking-and-scheduling' ) ) );
            break;
        case 'bulk_deleted':
            $message = sprintf( __( 'Selected %s have been deleted.', 'catering-booking-and-scheduling' ), esc_html(  __($label_singular,'catering-booking-and-scheduling' ) ) );
            break;
        case 'bulk_no_action':
            $message = __( 'Please select an action to perform.', 'catering-booking-and-scheduling' );
            $message_class = 'notice-warning';
            break;
        case 'bulk_no_item_selected':
            $message = __( 'Please select at least one term.', 'catering-booking-and-scheduling' );
            $message_class = 'notice-warning';
            break;
        case 'term_exists':
            $message = sprintf( __( '%s with this title already exists.', 'catering-booking-and-scheduling' ), esc_html(  __($label_singular,'catering-booking-and-scheduling' ) ) );
            $message_class = 'notice-error';
            break;
        case 'locked_term':
            $message = __( 'This term is protected and cannot be deleted.', 'catering-booking-and-scheduling' );
            $message_class = 'notice-error';
            break;
        case 'locked_term_bulk':
            $message = __( 'One or more selected terms are protected and cannot be deleted.', 'catering-booking-and-scheduling' );
            $message_class = 'notice-error';
            break;
    }
    
    if (!empty($message)) {
        echo '<div class="notice ' . esc_attr($message_class) . ' is-dismissible"><p>' . $message . '</p></div>';
    }
}
?>

    <div class="content-section">
        <!-- Add New Form -->
        <div class="add-new-section">
            <form method="post" class="add-new-form">
                <?php wp_nonce_field( $nonce_action_add, $nonce_name_add ); ?>
                <?php
                $placeholder_text = sprintf( __( '%s Name', 'catering-booking-and-scheduling' ), esc_html( __($label_singular,'catering-booking-and-scheduling' ) ) );
                ?>
                <div class="form-row" style=" width: 400px; ">
                    <div class="form-group">
                        <input type="text"
                            class="form-control"
                            name="<?php echo esc_attr( $post_add_field_text ); ?>"
                            placeholder="<?php echo esc_attr( $placeholder_text ); ?>"
                            required />
                        <button type="submit"
                            name="<?php echo esc_attr( $post_add_field_name ); ?>"
                            class="btn btn-primary">
                            <?php echo esc_attr( sprintf( __( 'Add New %s', 'catering-booking-and-scheduling' ), __($label_singular,'catering-booking-and-scheduling' ) ) ); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Search and Pagination -->
        <div class="search-pagination-bar">
            <form method="get" class="search-form">
                <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
                <input type="hidden" name="paged" value="1" />
                <input type="hidden" name="per_page" 
                    value="<?php echo esc_attr( isset($_GET['per_page']) ? sanitize_text_field($_GET['per_page']) : 'all' ); ?>" />

                <div class="search-input-group">
                    <input type="text" id="search_terms" name="search" value="<?php echo esc_attr($search); ?>" 
                        placeholder="<?php echo __('Search', 'catering-booking-and-scheduling') . __($label_plural, 'catering-booking-and-scheduling'); ?>" />
                    <button type="submit" class="btn btn-primary"><?php _e( 'Search', 'catering-booking-and-scheduling' ); ?></button>
                </div>
            </form>

            <div class="pagination-top">
                <?php /* Pagination removed
                catering_render_term_pagination_widget(
                    $page_slug, $label_plural, $total_items, $per_page, $paged, $total_pages, $search
                );
                */ ?>
            </div>
        </div>

        <!-- Bulk Actions and Table -->
        <form method="post" id="catering-terms-bulk-form">
            <input type="hidden" name="paged" value="<?php echo esc_attr($paged); ?>" />
            <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page); ?>" />
            <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>" />

            <div class="bulk-actions-bar">
                <select name="catering_bulk_term_action" id="catering-bulk-term-action-select" class="bulk-action-select">
                    <option value=""><?php _e( 'Bulk action', 'catering-booking-and-scheduling' ); ?></option>
                    <option value="delete"><?php _e( 'Delete', 'catering-booking-and-scheduling' ); ?></option>
                </select>
                <button type="button" class="btn btn-secondary" id="bulk-term-apply-btn">
                    <?php _e( 'Apply', 'catering-booking-and-scheduling' ); ?>
                </button>
            </div>

            <?php if ( ! empty( $results ) ) : ?>
            <div class="table-container">
                <table class="term-items-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="catering-check-all" />
                            </th>
                            <th class="id-column"><?php _e( 'ID', 'catering-booking-and-scheduling' ); ?></th>
                            <th class="title-column"><?php echo esc_html( $label_singular ); ?></th>
                            <?php if($is_category): ?>
                            <th class="color-column"><?php _e( 'Color', 'catering-booking-and-scheduling' ); ?></th>
                            <?php endif; ?>
                            <th class="actions-column"><?php _e( 'Actions', 'catering-booking-and-scheduling' ); ?></th>
                        </tr>
                    </thead>
                    <tbody <?php if($is_category) echo 'id="category-tbody"'; ?>>
                    <?php foreach ( $results as $row ) :
                        $term_id = (int) $row['ID'];
                        $color_code = $row['color'];
                        if(!$color_code){
                            $color_code = '#FFFFFF';
                        }
                        $is_locked = in_array($term_id, [$soup_cat_id, $others_cat_id], true);
                        ?>
                        <tr class="term-row" data-termid="<?php echo esc_attr($term_id); ?>">
                            <td class="check-column">
                                <?php if (!$is_locked): ?>
                                <input type="checkbox" class="catering-term-checkbox"
                                    name="catering_bulk_items[]" value="<?php echo esc_attr($term_id); ?>" />
                                <?php endif; ?>
                            </td>
                            <td class="id-column"><?php echo esc_html( $term_id ); ?></td>
                            <td class="title-column">
                                <?php if ( $edit_id === $term_id ) : ?>
                                    <form method="post" class="inline-edit-form">
                                        <?php wp_nonce_field( $nonce_action_update, $nonce_name_update ); ?>
                                        <input type="hidden" name="<?php echo esc_attr( $post_update_term_id ); ?>" value="<?php echo esc_attr( $term_id ); ?>" />
                                        <input type="hidden" name="_paged" value="<?php echo esc_attr( $paged ); ?>" />
                                        <input type="hidden" name="_per_page" value="<?php echo esc_attr( $per_page ); ?>" />
                                        <input type="hidden" name="_search" value="<?php echo esc_attr( $search ); ?>" />

                                        <div class="inline-edit-controls">
                                            <input type="text" name="<?php echo esc_attr( $post_update_new_title ); ?>" 
                                                value="<?php echo esc_attr( $row['title'] ); ?>" class="form-control" />
                                            <div class="button-group">
                                                <button type="submit" name="<?php echo esc_attr( $post_update_field_name ); ?>" 
                                                    class="btn btn-primary">
                                                    <?php esc_html_e( 'Save', 'catering-booking-and-scheduling' ); ?>
                                                </button>
                                                <a class="btn btn-secondary" href="<?php echo esc_url( add_query_arg([
                                                    'page'     => $page_slug,
                                                    'paged'    => $paged,
                                                    'per_page' => $per_page,
                                                    'search'   => $search,
                                                ], admin_url( 'admin.php' )) ); ?>">
                                                    <?php esc_html_e( 'Cancel', 'catering-booking-and-scheduling' ); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </form>
                                <?php else : ?>
                                    <span class="term-title">
                                        <?php echo esc_html( $row['title'] ); ?>
                                        <?php if ($is_locked): ?>
                                        <span class="locked-icon"><i class="fa-solid fa-lock"></i></span>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <?php if($is_category): ?>
                            <td class="color-column">
                                <div class="color-swatch" data-termid="<?php echo esc_attr($term_id); ?>"
                                    style="background:<?php echo esc_attr($color_code); ?>;"
                                    title="<?php echo esc_attr($color_code); ?>">
                                </div>
                            </td>
                            <?php endif; ?>

                            <td class="actions-column">
                                <?php if (!$is_locked): ?>
                                    <?php if ( $edit_id !== $term_id ) :
                                        $edit_url = add_query_arg([
                                            'page' => $page_slug,
                                            'edit_id' => $term_id,
                                            'paged' => $paged,
                                            'per_page' => $per_page,
                                            'search' => $search,
                                        ], admin_url('admin.php'));
                                    ?>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" class="action-link edit-link">
                                        <span class="dashicons dashicons-edit"></span>
                                        <span class="action-text"><?php _e( 'Edit', 'catering-booking-and-scheduling' ); ?></span>
                                    </a>
                                    <?php endif;

                                    $delete_url = add_query_arg([
                                        'page'     => $page_slug,
                                        'delete_id'=> $term_id,
                                        'paged'    => $paged,
                                        'per_page' => $per_page,
                                        'search'   => $search,
                                    ], admin_url( 'admin.php' ));
                                    ?>
                                    <a href="<?php echo esc_url( $delete_url ); ?>"
                                    class="action-link delete-link"
                                    onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this item?', 'catering-booking-and-scheduling' ); ?>');">
                                        <span class="dashicons dashicons-trash"></span>
                                        <span class="action-text"><?php _e( 'Delete', 'catering-booking-and-scheduling' ); ?></span>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else : ?>
                <div class="no-results">
                    <p><?php printf( esc_html__( 'No %s found.', 'catering-booking-and-scheduling' ), esc_html( $label_plural ) ); ?></p>
                </div>
            <?php endif; ?>
        </form>

        <div class="bottom-pagination">
            <?php /* Pagination removed
            catering_render_term_pagination_widget(
                $page_slug, $label_plural, $total_items, $per_page, $paged, $total_pages, $search
            );
            */ ?>
        </div>
    </div>
</div>

<script src="<?php echo plugins_url( '/catering-booking-and-scheduling/lib/jQuery/jquery-3.7.1.js'); ?>"></script>
<script src="<?php echo plugins_url( '/catering-booking-and-scheduling/lib/jQuery-ui/jquery-ui.min.js'); ?>"></script>
<script src="<?php echo plugins_url( '/catering-booking-and-scheduling/lib/jscolor/jscolor.js'); ?>"></script>
<script>
(function($){
    $(document).ready(function(){

      // "Select all" toggles
     jQuery('#catering-check-all').on('change', function(){
         var isChecked = jQuery(this).is(':checked');
         jQuery('.catering-term-checkbox').prop('checked', isChecked);
     });

     // Bulk action handling
     jQuery('#bulk-term-apply-btn').on('click', function(e){
         var actionVal = jQuery('#catering-bulk-term-action-select').val();
         if ( actionVal === 'delete' ) {
             var anyChecked = jQuery('.catering-term-checkbox:checked').length > 0;
             if ( anyChecked ) {
                 var sure = confirm('<?php echo esc_js( __( 'Are you sure you want to delete the selected terms?', 'catering-booking-and-scheduling' ) ); ?>');
                 if(!sure){
                     e.preventDefault();
                     return false;
                 } else {
                     jQuery(this).closest('form').submit();
                 }
             } else {
                 jQuery(this).closest('form').submit();
             }
         } else {
             jQuery(this).closest('form').submit();
         }
     });

      <?php if($is_category): ?>
      // 3) If it's category listing => do color column & reorder feature

      // A global or higher-scope variable to track the current popup
      let currentColorPopup = null;

      $('.color-swatch').on('click', function(){
        var $this = $(this);
        var termId= $this.data('termid');
        var initialColor = $this.attr('title') || '#FFFFFF';

        // If there's already a popup open, remove it
        if (currentColorPopup) {
          currentColorPopup.remove();
          currentColorPopup = null;
        }

        // Create a small popup near the swatch
        var popup = $('<div/>').css({
          position:'absolute', background:'#fff', border:'1px solid #ccc', padding:'10px',
          borderRadius: '4px', boxShadow: '0 2px 10px rgba(0,0,0,0.1)', zIndex: 100
        }).appendTo('body');

        // Keep track so we can remove it later if another swatch is clicked
        currentColorPopup = popup;

        var offset = $this.offset();
        popup.css({ top: offset.top+30, left: offset.left });

        // Input with data-jscolor
        var colorInput = $('<input type="text">')
          .attr('data-jscolor', '{"closeButton":true}')
          .val(initialColor)
          .appendTo(popup);

        // Install the picker on this element
        jscolor.install();

        var btnContainer = $('<div/>').css({
          display: 'flex', gap: '5px', marginTop: '10px'
        }).appendTo(popup);

        var btnSave = $('<button class="btn btn-primary">Save</button>').appendTo(btnContainer);
        var btnCancel = $('<button class="btn btn-secondary">Cancel</button>').appendTo(btnContainer);

        btnSave.on('click', function(e){
          e.preventDefault();
          var newColor = colorInput.val();
          $.post(ajaxurl, {
            action:'update_term_color',
            term_id: termId,
            color_code: newColor
          }, function(resp){
            if(resp.success){
              $this.css('background', newColor).attr('title', newColor);
              popup.remove();
              currentColorPopup = null;
            } else {
              alert('Error: '+resp.data);
            }
          });
        });

        btnCancel.on('click', function(e){
          e.preventDefault();
          popup.remove();
          currentColorPopup = null;
        });
      });

      var currentPerPage = <?php echo json_encode($per_page); ?>;

      if(currentPerPage === 'all') {
          jQuery('#category-tbody').sortable({
              update: function(){
                  // Gather new ordering for each row
                  var newOrder = [];
                  jQuery('#category-tbody .term-row').each(function(index){
                      var termId = jQuery(this).data('termid');
                      newOrder.push({ term_id: termId, ordering: index });
                  });
                  // Ajax call to update the new ordering
                  jQuery.post(ajaxurl, {
                      action: 'update_category_ordering',
                      new_order: JSON.stringify(newOrder)
                  }, function(resp){
                      if(resp.success){
                          // Optionally refresh or provide success feedback
                      } else {
                          alert('Error reordering: ' + resp.data);
                      }
                  });
              }
          });
          jQuery('#category-tbody').disableSelection();
      }

      <?php endif; // end if is_category ?>
    });
})(jQuery);
</script>

<?php


function catering_handle_bulk_term_actions( $term_type, $page_slug ) {
    // Read the chosen action
    $bulk_action = isset( $_POST['catering_bulk_term_action'] ) ? sanitize_text_field( $_POST['catering_bulk_term_action'] ) : '';
    // Also preserve pagination
    $paged    = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
    $per_page = isset( $_POST['per_page'] ) ? sanitize_text_field( $_POST['per_page'] ) : 'all';

    // If no action
    if ( empty( $bulk_action ) ) {
        wp_redirect( add_query_arg([
            'page'    => $page_slug,
            'message' => 'bulk_no_action',
            'paged'   => $paged,
            'per_page'=> $per_page,
        ], admin_url( 'admin.php' )) );
        exit;
    }

    // If "delete"
    if ( $bulk_action === 'delete' ) {
        $selected_ids = isset( $_POST['catering_bulk_items'] ) ? (array) $_POST['catering_bulk_items'] : [];
        // LOCKED bulk check
        $locked = array_intersect( array_map('absint', $selected_ids), [ $GLOBALS['soup_cat_id'], $GLOBALS['others_cat_id'] ] );
        if ( ! empty( $locked ) ) {
            wp_redirect( add_query_arg( [
                'page'    => $page_slug,
                'message' => 'locked_term_bulk'
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( empty( $selected_ids ) ) {
            // No items selected
            wp_redirect( add_query_arg([
                'page'    => $page_slug,
                'message' => 'bulk_no_item_selected',
                'paged'   => $paged,
                'per_page'=> $per_page,
            ], admin_url( 'admin.php' )) );
            exit;
        }

        // We have items, let's delete them
        global $wpdb;
        $terms_table = $wpdb->prefix . 'catering_terms';
        $relationship_table = $wpdb->prefix . 'catering_term_relationships';

        $selected_ids = array_map( 'absint', $selected_ids );

        foreach ( $selected_ids as $id ) {
            $wpdb->delete(
                $terms_table,
                [ 'ID' => $id, 'type' => $term_type ],
                [ '%d', '%s' ]
            );
            $wpdb->delete(
                $relationship_table,
                [ 'term_id' => $id],
                [ '%d']
            );
        }

        // Then redirect to success
        wp_redirect( add_query_arg([
            'page'    => $page_slug,
            'message' => 'bulk_deleted',
            'paged'   => $paged,
            'per_page'=> $per_page,
        ], admin_url( 'admin.php' )) );
        exit;
    }

    // If user picks some unknown action
    wp_redirect( add_query_arg([
        'page'    => $page_slug,
        'message' => 'bulk_no_action',
        'paged'   => $paged,
        'per_page'=> $per_page
    ], admin_url( 'admin.php' )) );
    exit;
}


function catering_render_term_pagination_widget( $page_slug, $label_plural, $total_items, $per_page, $paged, $total_pages, $search ) {
    $base_url = admin_url( 'admin.php?page=' . $page_slug );

    echo '<div class="pagination-widget">';
    // Translate the label_plural separately before using it in printf
    printf(
        esc_html__( '%d %s', 'catering-booking-and-scheduling' ),
        $total_items,
        __( $label_plural, 'catering-booking-and-scheduling' )
    );

    // "Per page" selector
    ?>
    <form method="get" class="per-page-form">
        <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>" />
        <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>" />
        <input type="hidden" name="paged" value="1" />
        <select name="per_page" class="per-page-select" onchange="this.form.submit()">
            <option value="30" <?php selected($per_page, '30'); ?>>30</option>
            <option value="50" <?php selected($per_page, '50'); ?>>50</option>
            <option value="100" <?php selected($per_page, '100'); ?>>100</option>
            <option value="all" <?php selected($per_page, 'all'); ?>>
                <?php esc_html_e( 'All', 'catering-booking-and-scheduling' ); ?>
            </option>
        </select>
    </form>
    <?php

    // If per_page='all', no further nav
    if ( $per_page === 'all' ) {
        echo '</div>';
        return;
    }

    echo '<div class="pagination-controls">';

    // Build nav URLs
    $first_url = add_query_arg([
        'paged' => 1,
        'per_page' => $per_page,
        'search' => $search
    ], $base_url);
    $prev_url  = add_query_arg([
        'paged' => max(1, $paged-1),
        'per_page' => $per_page,
        'search' => $search
    ], $base_url);
    $next_url  = add_query_arg([
        'paged' => min($total_pages, $paged+1),
        'per_page' => $per_page,
        'search' => $search
    ], $base_url);
    $last_url  = add_query_arg([
        'paged' => $total_pages,
        'per_page' => $per_page,
        'search' => $search
    ], $base_url);

    if ( $paged > 1 ) {
        printf( '<a class="pagination-button" href="%s">%s</a> ', esc_url( $first_url ), '«' );
        printf( '<a class="pagination-button" href="%s">%s</a> ', esc_url( $prev_url ),  '‹' );
    } else {
        printf( '<span class="pagination-button disabled">%s</span> ', '«' );
        printf( '<span class="pagination-button disabled">%s</span> ', '‹' );
    }

    // Page jump form
    ?>
    <form method="get" class="page-jump-form">
        <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
        <input type="hidden" name="per_page" value="<?php echo esc_attr( $per_page ); ?>" />
        <input type="hidden" name="search" value="<?php echo esc_attr( $search ); ?>" />
        <?php esc_html_e( 'Page', 'catering-booking-and-scheduling' ); ?>
        <input type="number" min="1" max="<?php echo esc_attr( $total_pages ); ?>"
               name="paged" value="<?php echo esc_attr( $paged ); ?>"
               class="page-number-input" />
        <?php
        printf(
            ' %s %d ',
            esc_html__( 'of', 'catering-booking-and-scheduling' ),
            $total_pages
        );
        ?>
    </form>
    <?php

    // Next / Last
    if ( $paged < $total_pages ) {
        printf( ' <a class="pagination-button" href="%s">%s</a> ', esc_url( $next_url ), '›' );
        printf( '<a class="pagination-button" href="%s">%s</a> ', esc_url( $last_url ),  '»' );
    } else {
        printf( ' <span class="pagination-button disabled">%s</span> ', '›' );
        printf( '<span class="pagination-button disabled">%s</span> ',  '»' );
    }

    echo '</div></div>';
    
    ?>
    <style>
        .pagination-widget {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .per-page-form {
            display: inline-block;
            margin: 0 8px;
        }
        
        .per-page-select {
            padding: 6px;
            border: 1px solid #eaeaea;
            border-radius: var(--border-radius);
            margin-left: 4px;
        }
        
        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .pagination-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 6px;
            background: var(--secondary-color);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            border: none;
            font-size: 14px;
        }
        
        .pagination-button:hover {
            background: var(--primary-light);
            color: var(--white);
        }
        
        .pagination-button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .page-jump-form {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin: 0;
            white-space: nowrap;
        }
        
        .page-number-input {
            width: 50px;
            padding: 6px;
            border: 1px solid #eaeaea;
            border-radius: var(--border-radius);
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .pagination-widget {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .page-jump-form {
                margin-top: 8px;
            }
        }
    </style>
    <?php
}

?>