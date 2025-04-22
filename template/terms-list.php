<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

if ( ! current_user_can( 'manage_options' ) ) {
    return;
}

global $wpdb;
$table = $wpdb->prefix . 'catering_terms';
$relationship_table = $wpdb->prefix . 'catering_term_relationships';

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

    $paged    = isset( $_GET['paged'] )    ? absint( $_GET['paged'] ) : 1;
    $per_page = isset( $_GET['per_page'] ) ? sanitize_text_field( $_GET['per_page'] ) : '30';
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
    $redirect_per_page = isset( $_POST['_per_page'] ) ? sanitize_text_field( $_POST['_per_page'] ) : '30';
    $redirect_search   = isset( $_POST['_search'] )   ? sanitize_text_field( $_POST['_search'] ) : '';

    if ( $update_id && $update_title ) {
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

?>
<div class="wrap">
    <h1><?php printf( esc_html__( 'Meal %s', 'catering-booking-and-scheduling' ), esc_html( $label_singular ) ); ?></h1>
<?php

// messages
if ( isset( $_GET['message'] ) ) {
    // same as your code ...
    // omitted for brevity
}

// "Add new" form
?>
<h2><?php echo sprintf( __( 'Add New %s', 'catering-booking-and-scheduling' ), esc_html( $label_singular ) ); ?></h2>
<form method="post" style="margin-bottom:20px;">
    <?php wp_nonce_field( $nonce_action_add, $nonce_name_add ); ?>
    <?php
    $placeholder_text = sprintf( __( '%s Name', 'catering-booking-and-scheduling' ), esc_html( $label_singular ) );
    ?>
    <input type="text"
           name="<?php echo esc_attr( $post_add_field_text ); ?>"
           placeholder="<?php echo esc_attr( $placeholder_text ); ?>"
           required />
    <input type="submit"
           name="<?php echo esc_attr( $post_add_field_name ); ?>"
           class="button button-primary"
           value="<?php echo esc_attr( sprintf( __( 'Add New %s', 'catering-booking-and-scheduling' ), $label_singular ) ); ?>" />
</form>
<?php

// ============== SEARCH FORM ==============
// A small form to search by title
?>
<form method="get" style="margin-bottom:10px;">
    <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
    <!-- If we want to reset pagination to 1 on new search, do: -->
    <input type="hidden" name="paged" value="1" />
    <input type="hidden" name="per_page"
           value="<?php echo esc_attr( isset($_GET['per_page']) ? sanitize_text_field($_GET['per_page']) : '30' ); ?>" />

    <label for="search_terms"><?php _e( 'Search Titles:', 'catering-booking-and-scheduling' ); ?></label>
    <input type="text" id="search_terms" name="search" value="<?php echo esc_attr($search); ?>" />
    <button type="submit" class="button"><?php _e( 'Search', 'catering-booking-and-scheduling' ); ?></button>
</form>
<?php

// ============== PAGINATION LOGIC ==============
$valid_per_pages   = [ '30', '50', '100', 'all' ];
$default_per_page  = '30';
$per_page          = isset( $_GET['per_page'] ) ? sanitize_text_field( $_GET['per_page'] ) : $default_per_page;
if ( ! in_array( $per_page, $valid_per_pages, true ) ) {
    $per_page = $default_per_page;
}
$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
if ( $paged < 1 ) {
    $paged = 1;
}

// We'll do a dynamic query for the listing
$join_clauses = []; // not needed if there's no relationships
$where_clauses= [];
$params       = [];

// If searching
if ( $search !== '' ) {
    // We only search 'title'
    $where_clauses[] = "title LIKE %s";
    $params[] = '%' . $wpdb->esc_like($search) . '%';
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

$sql_count = "SELECT COUNT(*) FROM $table WHERE type=%s";
$params_for_count = [ $term_type ];
if(!empty($where_clauses)){
    $sql_count .= " AND " . implode(' AND ', $where_clauses);
}

// We'll do limit if per_page != 'all'
if ( $per_page !== 'all' ) {
    $sql .= " LIMIT %d, %d";
}

// Also build a count query
$sql_count = "SELECT COUNT(*) FROM $table WHERE type=%s";
$params_for_count = [ $term_type ];

if ( ! empty($where_clauses) ) {
    $sql_count .= " AND " . implode(" AND ", $where_clauses);
    $params_for_count = array_merge($params_for_count, $params);
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

// 9) Build page
?>
<!-- Show a top pagination widget if you want...  -->
<?php catering_render_term_pagination_widget(
    $page_slug, $label_plural, $total_items, $per_page, $paged, $total_pages, $search
); ?>

<h2><?php printf( __( 'All Meal %s', 'catering-booking-and-scheduling' ), esc_html( $label_plural ) ); ?></h2>

<!-- Bulk form -->
<form method="post" id="catering-terms-bulk-form">
    <input type="hidden" name="paged" value="<?php echo esc_attr($paged); ?>" />
    <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page); ?>" />
    <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>" />

    <div style="margin:10px 0;">
        <select name="catering_bulk_term_action" id='catering-bulk-term-action-select'>
            <option value=""><?php _e( 'Bulk action', 'catering-booking-and-scheduling' ); ?></option>
            <option value="delete"><?php _e( 'Delete', 'catering-booking-and-scheduling' ); ?></option>
        </select>
        <button type="button" class="button" id='bulk-term-apply-btn'><?php _e( 'Apply', 'catering-booking-and-scheduling' ); ?></button>
    </div>

    <?php if ( ! empty( $results ) ) : ?>
        <table class="widefat striped" id="terms-table">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" id="catering-check-all" /></th>
                    <th><?php _e( 'ID', 'catering-booking-and-scheduling' ); ?></th>
                    <th><?php echo esc_html( $label_singular ); ?></th>

                    <?php if($is_category): ?>
                    <!-- New Color Column -->
                    <th><?php _e( 'Color', 'catering-booking-and-scheduling' ); ?></th>
                    <?php endif; ?>

                    <th><?php _e( 'Actions', 'catering-booking-and-scheduling' ); ?></th>
                </tr>
            </thead>
            <tbody <?php if($is_category) echo 'id="category-tbody"'; ?>>
            <?php foreach ( $results as $row ) :
                $term_id = (int) $row['ID'];
                $color_code = $row['color'];
                if(!$color_code){
                    $color_code = '#FFFFFF';
                }
                ?>
                <tr class="term-row" data-termid="<?php echo esc_attr($term_id); ?>">
                    <td><input type="checkbox" class="catering-term-checkbox" name="catering_bulk_items[]" value="<?php echo esc_attr( $term_id ); ?>" /></td>
                    <td><?php echo esc_html( $term_id ); ?></td>

                    <td>
                        <?php if ( $edit_id === $term_id ) : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( $nonce_action_update, $nonce_name_update ); ?>
                                <input type="hidden" name="<?php echo esc_attr( $post_update_term_id ); ?>" value="<?php echo esc_attr( $term_id ); ?>" />
                                <input type="hidden" name="_paged" value="<?php echo esc_attr( $paged ); ?>" />
                                <input type="hidden" name="_per_page" value="<?php echo esc_attr( $per_page ); ?>" />
                                <input type="hidden" name="_search" value="<?php echo esc_attr( $search ); ?>" />

                                <input type="text" name="<?php echo esc_attr( $post_update_new_title ); ?>" value="<?php echo esc_attr( $row['title'] ); ?>" />
                                <input type="submit"
                                       name="<?php echo esc_attr( $post_update_field_name ); ?>"
                                       class="button button-secondary"
                                       value="<?php esc_attr_e( 'Save', 'catering-booking-and-scheduling' ); ?>" />
                                <a class="button" href="<?php echo esc_url( add_query_arg([
                                    'page'     => $page_slug,
                                    'paged'    => $paged,
                                    'per_page' => $per_page,
                                    'search'   => $search,
                                ], admin_url( 'admin.php' )) ); ?>">
                                    <?php esc_html_e( 'Cancel', 'catering-booking-and-scheduling' ); ?>
                                </a>
                            </form>
                        <?php else : ?>
                            <?php echo esc_html( $row['title'] ); ?>
                        <?php endif; ?>
                    </td>

                    <?php if($is_category): ?>
                    <!-- The color column (a small color block user can click) -->
                    <td>
                        <div class="color-swatch" data-termid="<?php echo esc_attr($term_id); ?>"
                             style="width:25px; height:25px; background:<?php echo esc_attr($color_code); ?>; cursor:pointer;border:1px solid #EAEAEA;"
                             title="<?php echo esc_attr($color_code); ?>">
                        </div>
                    </td>
                    <?php endif; ?>

                    <td>
                        <?php if ( $edit_id !== $term_id ) :
                            $edit_url = add_query_arg([
                                'page' => $page_slug,
                                'edit_id' => $term_id,
                                'paged' => $paged,
                                'per_page' => $per_page,
                                'search' => $search,
                            ], admin_url('admin.php'));
                            ?>
                            <a href="<?php echo esc_url( $edit_url ); ?>">
                                <?php _e( 'Edit', 'catering-booking-and-scheduling' ); ?>
                            </a> |
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
                           onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this item?', 'catering-booking-and-scheduling' ); ?>');">
                            <?php _e( 'Delete', 'catering-booking-and-scheduling' ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p><?php printf( esc_html__( 'No %s found.', 'catering-booking-and-scheduling' ), esc_html( $label_plural ) ); ?></p>
    <?php endif; ?>
</form>

<?php
// bottom pagination
catering_render_term_pagination_widget(
    $page_slug, $label_plural, $total_items, $per_page, $paged, $total_pages, $search
);
?>
</div>

<!-- We'll include a color picker library, e.g. jscolor -->
<script src="<?php echo plugins_url( '/catering-booking-and-scheduling/lib/jQuery/jquery-3.7.1.js'); ?>"></script>
<script src="<?php echo plugins_url( '/catering-booking-and-scheduling/lib/jQuery-ui/jquery-ui.min.js'); ?>"></script>
<script src="<?php echo plugins_url( '/catering-booking-and-scheduling/lib/jscolor/jscolor.js'); ?>"></script>
<script>
  $( function() {
    $( "#sortable" ).sortable();
  } );
  </script>
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
          position:'absolute', background:'#fff', border:'1px solid #ccc', padding:'10px'
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

        var btnSave = $('<button class="button button-primary">Save</button>').appendTo(popup);
        var btnCancel = $('<button class="button">Cancel</button>').appendTo(popup);

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
    $per_page = isset( $_POST['per_page'] ) ? sanitize_text_field( $_POST['per_page'] ) : '30';

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

    echo '<div style="margin:10px 0;">';
    printf(
        esc_html__( '%d %s ', 'catering-booking-and-scheduling' ),
        $total_items,
        $label_plural
    );

    // "Per page" selector
    ?>
    <form method="get" style="display:inline;">
        <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>" />
        <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>" />
        <input type="hidden" name="paged" value="1" />
        <select name="per_page" onchange="this.form.submit()">
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

    // Build nav URLs
    $first_url = add_query_arg([
        'paged' => 1,
        'per_page' => $per_page
    ], $base_url);
    $prev_url  = add_query_arg([
        'paged' => max(1, $paged-1),
        'per_page' => $per_page
    ], $base_url);
    $next_url  = add_query_arg([
        'paged' => min($total_pages, $paged+1),
        'per_page' => $per_page
    ], $base_url);
    $last_url  = add_query_arg([
        'paged' => $total_pages,
        'per_page' => $per_page
    ], $base_url);

    echo ' ';
    if ( $paged > 1 ) {
        printf( '<a class="button" href="%s">%s</a> ', esc_url( $first_url ), '«' );
        printf( '<a class="button" href="%s">%s</a> ', esc_url( $prev_url ),  '‹' );
    } else {
        printf( '<span class="button disabled">%s</span> ', '«' );
        printf( '<span class="button disabled">%s</span> ', '‹' );
    }

    // Page jump form
    ?>
    <form method="get" style="display:inline;">
        <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
        <input type="hidden" name="per_page" value="<?php echo esc_attr( $per_page ); ?>" />
        <?php esc_html_e( 'The', 'catering-booking-and-scheduling' ); ?>
        <input type="number" min="1" max="<?php echo esc_attr( $total_pages ); ?>"
               name="paged" value="<?php echo esc_attr( $paged ); ?>"
               style="width:60px;" />
        <?php
        printf(
            ' %s %d %s ',
            esc_html__( 'of', 'catering-booking-and-scheduling' ),
            $total_pages,
            esc_html__( 'pages', 'catering-booking-and-scheduling' )
        );
        ?>
    </form>
    <?php

    // Next / Last
    if ( $paged < $total_pages ) {
        printf( ' <a class="button" href="%s">%s</a> ', esc_url( $next_url ), '›' );
        printf( '<a class="button" href="%s">%s</a> ', esc_url( $last_url ),  '»' );
    } else {
        printf( ' <span class="button disabled">%s</span> ', '›' );
        printf( '<span class="button disabled">%s</span> ',  '»' );
    }

    echo '</div>';


}

?>
