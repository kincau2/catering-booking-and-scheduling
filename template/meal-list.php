<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}


if ( ! current_user_can( 'manage_options' ) ) {
    return;
}

global $wpdb;
$table_meal = $wpdb->prefix . 'catering_meal';
$table_rel  = $wpdb->prefix . 'catering_term_relationships';

// 1) Handle bulk actions
if ( isset( $_POST['catering_bulk_meal_action'] ) ) {
    catering_handle_bulk_meals_action();
}

// 2) Single delete
if ( isset( $_GET['delete_id'] ) && is_numeric( $_GET['delete_id'] ) ) {
    $delete_id = absint( $_GET['delete_id'] );

    // preserve these states:
    $paged      = isset( $_GET['paged'] )      ? absint( $_GET['paged'] ) : 1;
    $per_page   = isset( $_GET['per_page'] )   ? sanitize_text_field( $_GET['per_page'] ) : '30';
    $filter_cat = isset( $_GET['filter_cat'] ) ? absint( $_GET['filter_cat'] ) : 0;
    $filter_tag = isset( $_GET['tag_id'] )     ? absint( $_GET['tag_id'] ) : 0;
    $search     = isset( $_GET['search'] )     ? sanitize_text_field( $_GET['search'] ) : '';

    // Delete from meal table
    $wpdb->delete( $table_meal, [ 'ID' => $delete_id ], [ '%d' ] );
    // Delete relationships
    $wpdb->delete( $table_rel, [ 'meal_id' => $delete_id ], [ '%d' ] );

    // Redirect
    wp_redirect( add_query_arg([
        'page'       => 'meal-item',
        'message'    => 'meal_deleted',
        'paged'      => $paged,
        'per_page'   => $per_page,
        'filter_cat' => $filter_cat,
        'tag_id'     => $filter_tag,
        'search'     => $search,
    ], admin_url( 'admin.php' ) ) );
    exit;
}

// 3) Show success / notice messages
$message = isset( $_GET['message'] ) ? sanitize_text_field( $_GET['message'] ) : '';

echo '<div class="wrap">';
echo '<h1>' . __( 'Meal Item', 'catering-booking-and-scheduling' ) . '</h1>';

if ( $message === 'meal_added' ) {
    echo '<div class="updated notice notice-success is-dismissible"><p>'
         . __( 'New meal added successfully!', 'catering-booking-and-scheduling' )
         . '</p></div>';
} elseif ( $message === 'meal_updated' ) {
    echo '<div class="updated notice notice-success is-dismissible"><p>'
         . __( 'Meal updated successfully!', 'catering-booking-and-scheduling' )
         . '</p></div>';
} elseif ( $message === 'meal_deleted' ) {
    echo '<div class="updated notice notice-success is-dismissible"><p>'
         . __( 'Meal deleted successfully!', 'catering-booking-and-scheduling' )
         . '</p></div>';
} elseif ( $message === 'bulk_meals_deleted' ) {
    echo '<div class="updated notice notice-success is-dismissible"><p>'
         . __( 'Selected meals have been deleted.', 'catering-booking-and-scheduling' )
         . '</p></div>';
} elseif ( $message === 'bulk_no_action' ) {
    echo '<div class="notice notice-warning is-dismissible"><p>'
         . __( 'Please select an action to perform.', 'catering-booking-and-scheduling' )
         . '</p></div>';
} elseif ( $message === 'bulk_no_item_selected' ) {
    echo '<div class="notice notice-warning is-dismissible"><p>'
         . __( 'Please select at least one meal.', 'catering-booking-and-scheduling' )
         . '</p></div>';
}

// 4) "Add new meal" button
$add_new_url = admin_url( 'admin.php?page=meal-item&action=add' );
echo '<p><a class="button button-primary" href="' . esc_url( $add_new_url ) . '">';
_e( 'Add new meal', 'catering-booking-and-scheduling' );
echo '</a></p>';

// ============== SEARCH WIDGET ==============
// We always display the search bar
$search = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';

?>
<form method="get" style="margin-bottom:10px;">
    <input type="hidden" name="page" value="meal-item" />
    <input type="hidden" name="paged" value="1" />
    <input type="hidden" name="tag_id" value="<?php echo isset($_GET['tag_id']) ? absint($_GET['tag_id']) : 0; ?>" />
    <input type="hidden" name="per_page" value="<?php
        echo esc_attr( isset($_GET['per_page']) ? sanitize_text_field($_GET['per_page']) : '30' );
    ?>" />
    <input type="hidden" name="filter_cat" value="<?php
        echo isset($_GET['filter_cat']) ? absint($_GET['filter_cat']) : 0;
    ?>" />

    <label for="search_meal"><?php _e( 'Search Meals:', 'catering-booking-and-scheduling' ); ?></label>
    <input type="text" id="search_meal" name="search" value="<?php echo esc_attr($search); ?>" />
    <button type="submit" class="button"><?php _e( 'Search', 'catering-booking-and-scheduling' ); ?></button>
</form>
<?php

// ============== CATEGORY FILTER ==============
// If there's a search, we set filter_cat = 0 automatically
// But we still show the dropdown. If user picks a category, we remove the search param
// by not including it in the category form.

$filter_cat = isset( $_GET['filter_cat'] ) ? absint( $_GET['filter_cat'] ) : 0;
if ( $search !== '' ) {
    // forcibly override category to 0
    $filter_cat = 0;
}

// Distinct category IDs
$distinct_cat_ids = $wpdb->get_col("
    SELECT DISTINCT term_id
    FROM {$table_rel}
    WHERE term_type='category'
    ORDER BY term_id ASC
");
$cat_assoc = [];
if ( $distinct_cat_ids ) {
    $cat_assoc = catering_get_term_titles_by_ids( $distinct_cat_ids, 'category' );
}

// This form does NOT carry `search`, so if user picks a category, we drop the search param
?>
<form method="get" style="margin-bottom:10px;">
    <input type="hidden" name="page" value="meal-item" />
    <input type="hidden" name="paged" value="1" />
    <input type="hidden" name="tag_id" value="<?php
        echo isset($_GET['tag_id']) ? absint($_GET['tag_id']) : 0;
    ?>" />
    <input type="hidden" name="per_page" value="<?php
        echo esc_attr( isset($_GET['per_page']) ? sanitize_text_field($_GET['per_page']) : '30' );
    ?>" />

    <select name="filter_cat">
        <option value="0"><?php _e( 'Categories (All)', 'catering-booking-and-scheduling' ); ?></option>
        <?php
        foreach ( $cat_assoc as $cid => $ctitle ) {
            echo '<option value="' . esc_attr($cid) . '" '
                 . selected($filter_cat, $cid, false)
                 . '>' . esc_html($ctitle) . '</option>';
        }
        ?>
    </select>
    <button type="submit" class="button">
        <?php _e( 'Apply', 'catering-booking-and-scheduling' ); ?>
    </button>
</form>
<?php

// ============== TAG FILTER (with counts) ==============
$filter_tag = isset( $_GET['tag_id'] ) ? absint($_GET['tag_id']) : 0;
$distinct_tag_ids = $wpdb->get_col("
    SELECT DISTINCT term_id
    FROM {$table_rel}
    WHERE term_type='tag'
    ORDER BY term_id ASC
");
if ( $distinct_tag_ids ) {
    $tag_assoc = catering_get_term_titles_by_ids( $distinct_tag_ids, 'tag' );

    // For each tag, count how many meals match the user’s cat+search
    // (No category if $search != '' => we do not forcibly remove cat in the final query below
    // but we said we can't combine them. However user might have a cat from earlier.
    // We'll still do the logic – if $filter_cat>0 or we have a search, we include it in the join)

    $tag_counts = [];
    foreach ( $distinct_tag_ids as $tid ) {
        $sql_count = "SELECT COUNT(DISTINCT m.ID) FROM {$table_meal} m";
        $jc = [];
        $wc = [];
        $pp = [];

        // category if $filter_cat>0
        if ( $filter_cat > 0 ) {
            $jc[] = "INNER JOIN {$table_rel} catjoin
                     ON (m.ID=catjoin.meal_id
                         AND catjoin.term_type='category'
                         AND catjoin.term_id=%d)";
            $pp[] = $filter_cat;
        }

        // This specific tag
        $jc[] = "INNER JOIN {$table_rel} tagjoin
                 ON (m.ID=tagjoin.meal_id
                     AND tagjoin.term_type='tag'
                     AND tagjoin.term_id=%d)";
        $pp[] = $tid;

        // search if present
        if ( $search !== '' ) {
            $wc[] = "(m.sku LIKE %s OR m.title LIKE %s)";
            $pp[] = '%' . $wpdb->esc_like($search) . '%';
            $pp[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if ( ! empty($jc) ) {
            $sql_count .= ' ' . implode(' ', $jc);
        }
        if ( ! empty($wc) ) {
            $sql_count .= ' WHERE ' . implode(' AND ', $wc);
        }

        $count_val = (int) $wpdb->get_var( $wpdb->prepare($sql_count, $pp) );
        $tag_counts[$tid] = $count_val;
    }

    // output horizontal list
    echo '<div style="margin-bottom:10px;"><strong>'
         . __( 'Filter by Tag:', 'catering-booking-and-scheduling' )
         . '</strong> ';

    $keys = array_keys($tag_assoc);
    $i = 0;
    $n = count($keys);
    foreach ( $tag_assoc as $tid => $ttitle ) {
        $count = isset($tag_counts[$tid]) ? $tag_counts[$tid] : 0;
        if ( $count > 0 ) {
            $tag_url = add_query_arg([
                'page'       => 'meal-item',
                'paged'      => 1,
                'tag_id'     => $tid,
                'filter_cat' => $filter_cat,
                'search'     => $search,
                'per_page'   => isset($_GET['per_page']) ? sanitize_text_field($_GET['per_page']) : '30'
            ], admin_url('admin.php'));

            if ( $filter_tag === (int)$tid ) {
                echo '<span style="font-weight:bold;">'
                     . '<a href="' . esc_url($tag_url) . '">'
                     . esc_html($ttitle) . ' (' . $count . ')'
                     . '</a></span>';
            } else {
                echo '<a href="' . esc_url($tag_url) . '">'
                     . esc_html($ttitle) . ' (' . $count . ')'
                     . '</a>';
            }
        } else {
            // 0 => not clickable
            if ( $filter_tag === (int)$tid ) {
                echo '<span style="font-weight:bold;">'
                     . esc_html($ttitle) . ' (0)'
                     . '</span>';
            } else {
                echo esc_html($ttitle) . ' (0)';
            }
        }

        $i++;
        if ( $i < $n ) {
            echo ' | ';
        }
    }

    // If user has a tag filter
    if ( $filter_tag > 0 ) {
        $all_tags_url = remove_query_arg('tag_id');
        echo ' &nbsp; <a href="' . esc_url($all_tags_url) . '">'
             . __( '[Show All Tags]', 'catering-booking-and-scheduling' )
             . '</a>';
    }

    echo '</div>';
}

// ============== PAGINATION ===================
$valid_per_pages  = [ '30','50','100','all' ];
$default_per_page = '30';
$per_page         = isset($_GET['per_page']) ? sanitize_text_field($_GET['per_page']) : $default_per_page;
if ( ! in_array($per_page, $valid_per_pages, true) ) {
    $per_page = $default_per_page;
}
$paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
if ( $paged < 1 ) $paged = 1;

// ============== BUILD MAIN QUERY ==============
$join_clauses  = [];
$where_clauses = [];
$params        = [];

$sql_base = "SELECT DISTINCT m.* FROM $table_meal m";

// If $filter_cat>0
if ( $filter_cat > 0 ) {
    $join_clauses[] = "INNER JOIN {$table_rel} catjoin
                       ON (m.ID=catjoin.meal_id
                           AND catjoin.term_type='category'
                           AND catjoin.term_id=%d)";
    $params[] = $filter_cat;
}

// If $filter_tag>0
if ( $filter_tag > 0 ) {
    $join_clauses[] = "INNER JOIN {$table_rel} tagjoin
                       ON (m.ID=tagjoin.meal_id
                           AND tagjoin.term_type='tag'
                           AND tagjoin.term_id=%d)";
    $params[] = $filter_tag;
}

// Search if present
if ( $search !== '' ) {
    $where_clauses[] = "(m.sku LIKE %s OR m.title LIKE %s)";
    $params[] = '%'.$wpdb->esc_like($search).'%';
    $params[] = '%'.$wpdb->esc_like($search).'%';
}

$sql = $sql_base;
if ( ! empty($join_clauses) ) {
    $sql .= ' ' . implode(' ', $join_clauses);
}
if ( ! empty($where_clauses) ) {
    $sql .= ' WHERE ' . implode(' AND ', $where_clauses);
}
$sql .= ' ORDER BY m.ID DESC';

// A count query
$sql_count = str_replace("SELECT DISTINCT m.*", "SELECT COUNT(DISTINCT m.ID)", $sql);
$sql_count_nolimit = preg_replace('/\s+LIMIT\s+\d+,\s*\d+/i', '', $sql_count);

// Add limit if not 'all'
if ( $per_page !== 'all' ) {
    $sql .= " LIMIT %d, %d";
}

$query_params = $params;
$limit_params = [];

if ( $per_page !== 'all' ) {
    $offset = ( $paged -1 ) * (int)$per_page;
    $limit_params[] = $offset;
    $limit_params[] = (int)$per_page;
}

// final results
$results = $wpdb->get_results(
    $wpdb->prepare($sql, array_merge($query_params, $limit_params)),
    ARRAY_A
);

// total
$total_items = (int)$wpdb->get_var(
    $wpdb->prepare($sql_count_nolimit, $query_params)
);

// total_pages
if ( $per_page === 'all' ) {
    $total_pages = 1;
} else {
    $per_page_int = (int)$per_page;
    $total_pages  = max(1, ceil($total_items / $per_page_int));
}

// show pagination (top)
catering_render_meals_pagination_widget( $paged, $per_page, $total_pages, $total_items );

// bulk + table
?>
<form method="post" id="catering-meals-bulk-form">
    <input type="hidden" name="paged" value="<?php echo esc_attr($paged); ?>" />
    <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page); ?>" />
    <input type="hidden" name="filter_cat" value="<?php echo esc_attr($filter_cat); ?>" />
    <input type="hidden" name="tag_id" value="<?php echo esc_attr($filter_tag); ?>" />
    <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>" />

    <div style="margin:10px 0;">
        <select name="catering_bulk_meal_action" id="catering-bulk-meal-action-select">
            <option value=""><?php _e( 'Bulk action', 'catering-booking-and-scheduling' ); ?></option>
            <option value="edit_category"><?php _e( 'Edit Category', 'catering-booking-and-scheduling' ); ?></option>
            <option value="edit_tag"><?php _e( 'Edit Tag', 'catering-booking-and-scheduling' ); ?></option>
            <option value="delete"><?php _e( 'Delete', 'catering-booking-and-scheduling' ); ?></option>
        </select>
        <button type="button" class="button" id="bulk-meal-apply-btn">
            <?php _e( 'Apply', 'catering-booking-and-scheduling' ); ?>
        </button>
    </div>

    <?php if ( ! empty($results) ) : ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:30px;">
                        <input type="checkbox" id="catering-meal-check-all" />
                    </th>
                    <th><?php _e( 'SKU', 'catering-booking-and-scheduling' ); ?></th>
                    <th><?php _e( 'Title', 'catering-booking-and-scheduling' ); ?></th>
                    <th><?php _e( 'Categories', 'catering-booking-and-scheduling' ); ?></th>
                    <th><?php _e( 'Tags', 'catering-booking-and-scheduling' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ( $results as $row ) {
                $meal_id = $row['ID'];
                $sku     = esc_html( $row['sku'] );
                $title   = esc_html( $row['title'] );

                $cat_ids = $wpdb->get_col( $wpdb->prepare("
                    SELECT term_id FROM $table_rel
                    WHERE meal_id=%d AND term_type='category'
                ", $meal_id) );
                $tag_ids = $wpdb->get_col( $wpdb->prepare("
                    SELECT term_id FROM $table_rel
                    WHERE meal_id=%d AND term_type='tag'
                ", $meal_id) );

                $cat_titles = catering_get_term_titles($cat_ids, 'category');
                $tag_titles = catering_get_term_titles($tag_ids, 'tag');

                $edit_url = add_query_arg([
                    'page'       => 'meal-item',
                    'action'     => 'edit',
                    'meal_id'    => $meal_id,
                    'paged'      => $paged,
                    'per_page'   => $per_page,
                    'filter_cat' => $filter_cat,
                    'tag_id'     => $filter_tag,
                    'search'     => $search,
                ], admin_url('admin.php'));

                $delete_url = add_query_arg([
                    'page'       => 'meal-item',
                    'delete_id'  => $meal_id,
                    'paged'      => $paged,
                    'per_page'   => $per_page,
                    'filter_cat' => $filter_cat,
                    'tag_id'     => $filter_tag,
                    'search'     => $search,
                ], admin_url('admin.php'));

                echo '<tr>';
                echo '<td><input type="checkbox" class="catering-meal-checkbox" name="catering_bulk_meal_items[]" value="' . esc_attr($meal_id) . '"/></td>';
                echo '<td><a href="' . esc_url($edit_url) . '">' . $sku . '</a></td>';
                echo '<td><a href="' . esc_url($edit_url) . '">' . $title . '</a></td>';
                echo '<td>' . esc_html( implode(', ', $cat_titles) ) . '</td>';
                echo '<td>' . esc_html( implode(', ', $tag_titles) ) . '</td>';
                echo '</tr>';
            }
            ?>
            </tbody>
        </table>
    <?php else : ?>
        <p><?php _e( 'No meals found.', 'catering-booking-and-scheduling' ); ?></p>
    <?php endif; ?>
</form>
<?php

// 9) Pagination (bottom)
catering_render_meals_pagination_widget( $paged, $per_page, $total_pages, $total_items );

echo '</div>';
?>

<script>
(function($){
    $(document).ready(function(){
        // "Select all" toggles
        $('#catering-meal-check-all').on('change', function(){
            var isChecked = $(this).is(':checked');
            $('.catering-meal-checkbox').prop('checked', isChecked);
        });

        // Bulk action handling
        $('#bulk-meal-apply-btn').on('click', function(e){
            var actionVal = $('#catering-bulk-meal-action-select').val();
            if ( actionVal === 'delete' ) {
              //Delete function
                var anyChecked = $('.catering-meal-checkbox:checked').length > 0;
                if ( anyChecked ) {
                    var sure = confirm('<?php echo esc_js( __( 'Are you sure you want to delete the selected meals?', 'catering-booking-and-scheduling' ) ); ?>');
                    if ( ! sure ) {
                        e.preventDefault();
                        return false;
                    } else{
                      $(this).parent().parent().submit();
                    }
                } else{
                  $(this).parent().parent().submit();
                }

            } else if (actionVal === 'edit_category' || actionVal === 'edit_tag') {
              // Edit category and tag
              // function location at backend-ajas.js
              e.preventDefault();

              var selectedMeals = jQuery('.catering-meal-checkbox:checked').map(function(){
                return jQuery(this).val();
              }).get();

              if (selectedMeals.length === 0) {
                alert('Please select at least one meal.', 'catering-booking-and-scheduling');
                return false;
              }

              bulkEditTerms(actionVal,selectedMeals);
            }
        });
    });
})(jQuery);
</script>

<?php

function catering_handle_bulk_meals_action() {
    global $wpdb;

    $meal_table = $wpdb->prefix . 'catering_meal';
    $relationship_table = $wpdb->prefix . 'catering_term_relationships';

    $bulk_action = isset( $_POST['catering_bulk_meal_action'] ) ? sanitize_text_field( $_POST['catering_bulk_meal_action'] ) : '';
    $paged       = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
    $per_page    = isset( $_POST['per_page'] ) ? sanitize_text_field( $_POST['per_page'] ) : '30';

    // If no action
    if ( empty( $bulk_action ) ) {
        wp_redirect( add_query_arg([
            'page'    => 'meal-item',
            'message' => 'bulk_no_action',
            'paged'   => $paged,
            'per_page'=> $per_page,
        ], admin_url( 'admin.php' )) );
        exit;
    }

    // If 'delete'
    if ( $bulk_action === 'delete' ) {
        $selected_ids = isset( $_POST['catering_bulk_meal_items'] ) ? (array) $_POST['catering_bulk_meal_items'] : [];
        if ( empty( $selected_ids ) ) {
            // No items selected
            wp_redirect( add_query_arg([
                'page'    => 'meal-item',
                'message' => 'bulk_no_item_selected',
                'paged'   => $paged,
                'per_page'=> $per_page,
            ], admin_url( 'admin.php' )) );
            exit;
        }

        // We have items, let's delete them
        $selected_ids = array_map( 'absint', $selected_ids );

        foreach ( $selected_ids as $id ) {
            $wpdb->delete(
                $meal_table,
                [ 'ID' => $id ],
                [ '%d' ]
            );
            $wpdb->delete(
                $relationship_table,
                [ 'meal_id' => $id ],
                [ '%d' ]
            );
        }

        // Then redirect success
        wp_redirect( add_query_arg([
            'page'    => 'meal-item',
            'message' => 'bulk_meals_deleted',
            'paged'   => $paged,
            'per_page'=> $per_page,
        ], admin_url( 'admin.php' )) );
        exit;
    }

    // If unknown action
    wp_redirect( add_query_arg([
        'page'    => 'meal-item',
        'message' => 'bulk_no_action',
        'paged'   => $paged,
        'per_page'=> $per_page,
    ], admin_url( 'admin.php' )) );
    exit;
}

function catering_render_meals_pagination_widget( $paged, $per_page, $total_pages, $total_items ) {
    $base_url = admin_url( 'admin.php?page=meal-item' );

    echo '<div style="margin:10px 0;">';
    printf(
        esc_html__( '%d meals ', 'catering-booking-and-scheduling' ),
        $total_items
    );

    // "Per page" select
    ?>
    <form method="get" style="display:inline;">
        <input type="hidden" name="page" value="meal-item" />
        <input type="hidden" name="paged" value="1" />
        <select name="per_page" onchange="this.form.submit()">
            <option value="30" <?php selected( $per_page, '30' ); ?>>30</option>
            <option value="50" <?php selected( $per_page, '50' ); ?>>50</option>
            <option value="100" <?php selected( $per_page, '100' ); ?>>100</option>
            <option value="all" <?php selected( $per_page, 'all' ); ?>>
                <?php esc_html_e( 'All', 'catering-booking-and-scheduling' ); ?>
            </option>
        </select>
    </form>
    <?php

    if ( $per_page === 'all' ) {
        echo '</div>';
        return;
    }

    // Build first/prev/next/last
    $first_url = add_query_arg(['paged' => 1, 'per_page' => $per_page], $base_url);
    $prev_url  = add_query_arg(['paged' => max(1, $paged-1), 'per_page' => $per_page], $base_url);
    $next_url  = add_query_arg(['paged' => min($total_pages, $paged+1), 'per_page' => $per_page], $base_url);
    $last_url  = add_query_arg(['paged' => $total_pages, 'per_page' => $per_page], $base_url);

    echo ' ';
    if ( $paged > 1 ) {
        printf( '<a class="button" href="%s">%s</a> ', esc_url( $first_url ), '«' );
        printf( '<a class="button" href="%s">%s</a> ', esc_url( $prev_url ),  '‹' );
    } else {
        printf( '<span class="button disabled">%s</span> ', '«' );
        printf( '<span class="button disabled">%s</span> ', '‹' );
    }

    // Page jump
    ?>
    <form method="get" style="display:inline;">
        <input type="hidden" name="page" value="meal-item" />
        <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page); ?>" />
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
