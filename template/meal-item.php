<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

// Enqueue the centralized CSS file
wp_enqueue_style('catering-styles', plugin_dir_url(dirname(__FILE__)) . 'public/catering.css', array(), '1.0.0', 'all');

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

?>
<div class="wrap">
    
    <?php
    // 4) "Add new meal" button
    $add_new_url = admin_url( 'admin.php?page=meal-item&action=add' );
    ?>

    <div class="header-section">
        <div class="catering-header"><?php _e( 'Meal Item', 'catering-booking-and-scheduling' ); ?></div>
        <a class="page-title-action add-new-btn" href="<?php echo esc_url( $add_new_url ); ?>">
            <?php _e( 'Add new meal', 'catering-booking-and-scheduling' ); ?>
        </a>
    </div>

    <!-- hidden h1 for native message placeholder  -->
    <h1 class="wp-heading-inline" style=" display: none; "></h1>

    <?php if ( $message === 'meal_added' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'New meal added successfully!', 'catering-booking-and-scheduling' ); ?></p>
        </div>
    <?php elseif ( $message === 'meal_updated' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Meal updated successfully!', 'catering-booking-and-scheduling' ); ?></p>
        </div>
    <?php elseif ( $message === 'meal_deleted' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Meal deleted successfully!', 'catering-booking-and-scheduling' ); ?></p>
        </div>
    <?php elseif ( $message === 'bulk_meals_deleted' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e( 'Selected meals have been deleted.', 'catering-booking-and-scheduling' ); ?></p>
        </div>
    <?php elseif ( $message === 'bulk_no_action' ) : ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php _e( 'Please select an action to perform.', 'catering-booking-and-scheduling' ); ?></p>
        </div>
    <?php elseif ( $message === 'bulk_no_item_selected' ) : ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php _e( 'Please select at least one meal.', 'catering-booking-and-scheduling' ); ?></p>
        </div>
    <?php endif; ?>

    <?php
    // ============== PAGINATION ===================
    $valid_per_pages  = [ '30','50','100','all' ];
    $default_per_page = '30';
    $per_page         = isset($_GET['per_page']) ? sanitize_text_field($_GET['per_page']) : $default_per_page;
    if ( ! in_array($per_page, $valid_per_pages, true) ) {
        $per_page = $default_per_page;
    }
    $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
    if ( $paged < 1 ) $paged = 1;

    // ============== SEARCH ======================
    $search = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';

    // ============== BUILD MAIN QUERY ==============
    $join_clauses  = [];
    $where_clauses = [];
    $params        = [];

    $sql_base = "SELECT DISTINCT m.* FROM $table_meal m";

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
    ?>

    <div class="content-section">
        <div class="search-pagination-bar">
            <form method="get" class="search-form">
                <input type="hidden" name="page" value="meal-item" />
                <input type="hidden" name="paged" value="1" />
                <input type="hidden" name="tag_id" value="<?php echo isset($_GET['tag_id']) ? absint($_GET['tag_id']) : 0; ?>" />
                <input type="hidden" name="per_page" value="<?php echo esc_attr( isset($_GET['per_page']) ? sanitize_text_field($_GET['per_page']) : '30' ); ?>" />
                <input type="hidden" name="filter_cat" value="<?php echo isset($_GET['filter_cat']) ? absint($_GET['filter_cat']) : 0; ?>" />

                <div class="search-input-group">
                    <input type="text" id="search_meal" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e( 'Search meals...', 'catering-booking-and-scheduling' ); ?>" />
                    <button type="submit" class="btn btn-primary"><?php _e( 'Search', 'catering-booking-and-scheduling' ); ?></button>
                </div>
            </form>

            <?php catering_render_meals_pagination_widget( $paged, $per_page, $total_pages, $total_items ); ?>
        </div>

        <form method="post" id="catering-meals-bulk-form">
            <input type="hidden" name="paged" value="<?php echo esc_attr($paged); ?>" />
            <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page); ?>" />
            <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>" />

            <div class="bulk-actions-bar">
                <select name="catering_bulk_meal_action" id="catering-bulk-meal-action-select" class="bulk-action-select">
                    <option value=""><?php _e( 'Bulk action', 'catering-booking-and-scheduling' ); ?></option>
                    <option value="delete"><?php _e( 'Delete', 'catering-booking-and-scheduling' ); ?></option>
                    <option value="export"><?php _e( 'Export', 'catering-booking-and-scheduling' ); ?></option>
                </select>
                <button type="button" class="btn btn-secondary" id="bulk-meal-apply-btn">
                    <?php _e( 'Apply', 'catering-booking-and-scheduling' ); ?>
                </button>
                <button type="button" class="btn btn-primary" id="import-meal-btn">
                    <?php _e( 'Import', 'catering-booking-and-scheduling' ); ?>
                </button>
            </div>

            <?php if ( ! empty($results) ) : ?>
                <div class="table-container">
                    <table class="meal-items-table">
                        <thead>
                            <tr>
                                <th class="check-column">
                                    <input type="checkbox" id="catering-meal-check-all" />
                                </th>
                                <th class="id-column"><?php _e( 'ID', 'catering-booking-and-scheduling' ); ?></th>
                                <th class="sku-column"><?php _e( 'SKU', 'catering-booking-and-scheduling' ); ?></th>
                                <th class="title-column"><?php _e( 'Title', 'catering-booking-and-scheduling' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        foreach ( $results as $row ) {
                            $meal_id = $row['ID'];
                            $sku     = esc_html( $row['sku'] );
                            $title   = esc_html( $row['title'] );

                            $edit_url = add_query_arg([
                                'page'       => 'meal-item',
                                'action'     => 'edit',
                                'meal_id'    => $meal_id,
                                'paged'      => $paged,
                                'per_page'   => $per_page,
                                'search'     => $search,
                            ], admin_url('admin.php'));

                            $delete_url = add_query_arg([
                                'page'       => 'meal-item',
                                'delete_id'  => $meal_id,
                                'paged'      => $paged,
                                'per_page'   => $per_page,
                                'search'     => $search,
                            ], admin_url('admin.php'));

                            echo '<tr>';
                            echo '<td><input type="checkbox" class="catering-meal-checkbox" name="catering_bulk_meal_items[]" value="' . esc_attr($meal_id) . '"/></td>';
                            echo '<td>' . esc_html( $meal_id ) . '</td>';
                            echo '<td><a href="' . esc_url($edit_url) . '">' . $sku . '</a></td>';
                            echo '<td><a href="' . esc_url($edit_url) . '">' . $title . '</a></td>';
                            echo '</tr>';
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="no-results">
                    <p><?php _e( 'No meals found.', 'catering-booking-and-scheduling' ); ?></p>
                </div>
            <?php endif; ?>
        </form>

        <div class="bottom-pagination">
            <?php catering_render_meals_pagination_widget( $paged, $per_page, $total_pages, $total_items ); ?>
        </div>
    </div>
</div>

<!-- Import CSV Modal -->
<div id="import-meal-overlay"></div>
<div id="import-meal-modal">
    <h2><?php _e( 'Import Meals CSV', 'catering-booking-and-scheduling' ); ?></h2>
    <div class="form-group">
        <label for="import-action"><?php _e( 'Mode', 'catering-booking-and-scheduling' ); ?></label>
        <select id="import-action" name="import_action">
            <option value="new"><?php _e( 'Import New Meals', 'catering-booking-and-scheduling' ); ?></option>
            <option value="update"><?php _e( 'Update Existing Meals', 'catering-booking-and-scheduling' ); ?></option>
        </select>
    </div>
    <div class="form-group">
        <label for="import-file"><?php _e( 'CSV File', 'catering-booking-and-scheduling' ); ?></label>
        <input type="file" id="import-file" accept=".csv" />
    </div>
    <div id="import-progress">
        <div id="import-status"></div>
        <progress id="import-progress-bar" value="0" max="100"></progress>
        <span id="import-progress-text">0%</span>
    </div>
    <div id="import-error-box"></div>
    <div class="modal-actions">
        <button type="button" class="btn btn-secondary" id="import-cancel-btn"><?php _e( 'Cancel', 'catering-booking-and-scheduling' ); ?></button>
        <button type="button" class="btn btn-primary" id="import-submit-btn"><?php _e( 'Submit', 'catering-booking-and-scheduling' ); ?></button>
    </div>
</div>

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

            } 
            if ( actionVal === 'export' ) {
              //export function
              $(this).parent().parent().submit();
            } 
        });
    });
})(jQuery);
</script>

<script>
(function($){
    // show/import modal
    $('#import-meal-btn').on('click',function(){
        $('#import-meal-overlay, #import-meal-modal').show();
    });
    $('#import-cancel-btn').on('click',function(){
        $('#import-meal-overlay, #import-meal-modal').hide();
        $('#import-progress, #import-error-box').hide();
    });

    // CSV line splitter (handles quoted commas)
    function parseCSVLine(line) {
        var pattern = /("([^"]*(?:""[^"]*)*)"|[^",\r\n]*)(,|$)/g;
        var result = [], match;
        while ((match = pattern.exec(line)) !== null) {
            var v = match[1];
            if (v.charAt(0) === '"' && v.slice(-1) === '"') {
                v = v.slice(1,-1).replace(/""/g, '"');
            }
            result.push(v);
            if (match[3] === '') break;
        }
        return result;
    }

    $('#import-submit-btn').on('click',function(){
        $('#import-error-box').hide().text('');
        var file = $('#import-file')[0].files[0];
        var mode = $('#import-action').val();
        var errors = [];

        if (!file) {
            errors.push('<?php echo esc_js( __( 'Please select a CSV file.', 'catering-booking-and-scheduling' ) ); ?>');
        } else if (file.type !== 'text/csv' && !file.name.match(/\.csv$/i)) {
            errors.push('<?php echo esc_js( __( 'Invalid file type, only CSV allowed.', 'catering-booking-and-scheduling' ) ); ?>');
        }

        if (errors.length) {
            $('#import-error-box').html(errors.join('<br>')).show();
            return;
        }

        var reader = new FileReader();
        reader.onload = function(e){
            var lines   = e.target.result.trim().split(/\r?\n/);
            var headers = parseCSVLine(lines[0]);
            var idxID        = headers.indexOf('ID'),
                idxSKU       = headers.indexOf('SKU'),
                idxTitle     = headers.indexOf('Meal_title'),
                idxDesc      = headers.indexOf('description'),
                idxCost      = headers.indexOf('cost'),
                idxPhoto     = headers.indexOf('photo');
            if ( idxID<0 || idxSKU<0 || idxTitle<0 || idxDesc<0 || idxCost<0 || idxPhoto<0 ) {
                $('#import-error-box')
                    .text('<?php echo esc_js( __( 'Invalid CSV headers: missing required columns.', 'catering-booking-and-scheduling' ) ); ?>')
                    .show();
                return;
            }

            if (lines.length < 2) {
                $('#import-error-box').text('<?php echo esc_js( __( 'CSV is empty or missing data rows.', 'catering-booking-and-scheduling' ) ); ?>').show();
                return;
            }

            var headers = parseCSVLine(lines[0]);
            var required = ['ID','SKU','Meal_title','description','cost','photo'];
            if (headers.length !== required.length ||
                headers.some(function(h,i){ return h.trim()!==required[i]; })
            ) {
                $('#import-error-box').text(
                    '<?php echo esc_js( __( 'Invalid CSV headers. Accepted: ID, SKU, Meal_title, description, cost, photo', 'catering-booking-and-scheduling' ) ); ?>'
                ).show();
                return;
            }

            

            // row-by-row validation
            lines.slice(1).forEach(function(line, idx){
                var cols = parseCSVLine(line);
                var rowNum = idx + 2; // account for header
                var prefix = '<?php echo esc_js( __( 'Row', 'catering-booking-and-scheduling' ) ); ?>' + ' ' + rowNum + ': ';
                if (mode === 'new') {
                    if (cols[0].trim() !== '') {
                        errors.push(prefix + '<?php echo esc_js( __( 'ID must be empty for importing new meal.', 'catering-booking-and-scheduling' ) ); ?>');
                    }
                    if (!cols[2].trim()) {
                        errors.push(prefix + '<?php echo esc_js( __( 'Meal title is required.', 'catering-booking-and-scheduling' ) ); ?>');
                    }
                } else {
                    if (!/^\d+$/.test(cols[0].trim())) {
                        errors.push(prefix + '<?php echo esc_js( __( 'Valid numeric ID required for update.', 'catering-booking-and-scheduling' ) ); ?>');
                    }
                    if (!cols[2].trim()) {
                        errors.push(prefix + '<?php echo esc_js( __( 'Meal title is required.', 'catering-booking-and-scheduling' ) ); ?>');
                    }
                }
            });

            if (errors.length) {
                $('#import-error-box').html(errors.join('<br>')).show();
                return;
            }

            // passed validation
            $('#import-error-box').hide();
            $('#import-status').show().text('0/' + total);
            $('#import-progress').show();
            var total = lines.length - 1,
                done  = 0;
            errors = [];  // reuse outer errors array

            // sequential AJAX calls to avoid concurrency issues
            var rows = lines.slice(1);
            function processRow(i) {
              if (i >= rows.length) return;
              var rowNum = i + 2;
              var cols   = parseCSVLine(rows[i]);
              var data   = {
                action      : 'catering_import_csv_row',
                mode        : mode,
                row         : rowNum,
                id          : cols[idxID].trim(),
                sku         : cols[idxSKU].trim(),
                title       : cols[idxTitle].trim(),
                description : cols[idxDesc].trim(),
                cost        : cols[idxCost].trim(),
                photo       : cols[idxPhoto].trim()
              };
              $.post(ajaxurl, data, function(resp){
                if (!resp.success) {
                  errors.push('<?php echo esc_js( __( 'Row', 'catering-booking-and-scheduling' ) ); ?> ' + rowNum + ': ' + resp.data);
                }
              }).always(function(){
                done++;
                $('#import-status').text(done + '/' + total);
                var pct = Math.round(done/total*100);
                $('#import-progress-bar').val(pct);
                $('#import-progress-text').text(pct + '%');
                if (i === rows.length - 1) {
                  if (errors.length) {
                    $('#import-error-box').html(errors.join('<br>')).show();
                  } else {
                    location.reload();
                  }
                } else {
                  processRow(i + 1);
                }
              });
            }
            processRow(0);
        };
        reader.readAsText(file);
    });

    // click outside modal to close & reset
    $('#import-meal-overlay').on('click', function(){
        $('#import-meal-overlay, #import-meal-modal').hide();
        $('#import-progress, #import-error-box').hide();
        $('#import-file').val('');
    });

})(jQuery);
</script>

<?php
function catering_render_meals_pagination_widget( $paged, $per_page, $total_pages, $total_items ) {
    // build base URL with current filters
    $base_args = [ 'page' => 'meal-item' ];
    if ( isset($_GET['search']) )     $base_args['search']     = sanitize_text_field($_GET['search']);
    if ( isset($_GET['filter_cat']) ) $base_args['filter_cat'] = absint($_GET['filter_cat']);
    if ( isset($_GET['tag_id']) )     $base_args['tag_id']     = absint($_GET['tag_id']);
    $base_url = add_query_arg( $base_args, admin_url('admin.php') );

    // Count display
    echo '<div class="pagination-widget">';
    printf( esc_html__( '%d meals', 'catering-booking-and-scheduling' ), $total_items );

    // per-page selector: preserve filters
    ?>
    <form method="get" class="per-page-form">
        <input type="hidden" name="page" value="meal-item" />
        <input type="hidden" name="paged" value="1" />
        <input type="hidden" name="search"     value="<?php echo esc_attr( isset($_GET['search'])     ? sanitize_text_field($_GET['search'])     : '' ); ?>" />
        <input type="hidden" name="filter_cat" value="<?php echo esc_attr( isset($_GET['filter_cat']) ? absint($_GET['filter_cat']) : '' ); ?>" />
        <input type="hidden" name="tag_id"     value="<?php echo esc_attr( isset($_GET['tag_id'])     ? absint($_GET['tag_id'])     : '' ); ?>" />
        <select name="per_page" class="per-page-select" onchange="this.form.submit()">
            <option value="30"  <?php selected( $per_page, '30' ); ?>>30</option>
            <option value="50"  <?php selected( $per_page, '50' ); ?>>50</option>
            <option value="100" <?php selected( $per_page, '100' ); ?>>100</option>
            <option value="all" <?php selected( $per_page, 'all' ); ?>><?php esc_html_e( 'All', 'catering-booking-and-scheduling' ); ?></option>
        </select>
    </form>

    <div class="pagination-controls">
    <?php
    // build paging URLs from $base_url
    $first_url = add_query_arg(['paged'=>1,'per_page'=>$per_page], $base_url);
    $prev_url  = add_query_arg(['paged'=>max(1, $paged-1),'per_page'=>$per_page], $base_url);
    $next_url  = add_query_arg(['paged'=>min($total_pages, $paged+1),'per_page'=>$per_page], $base_url);
    $last_url  = add_query_arg(['paged'=>$total_pages,'per_page'=>$per_page], $base_url);

    if ( $paged > 1 ) {
        printf( '<a class="pagination-button" href="%s">%s</a> ', esc_url( $first_url ), '«' );
        printf( '<a class="pagination-button" href="%s">%s</a> ', esc_url( $prev_url ),  '‹' );
    } else {
        printf( '<span class="pagination-button disabled">%s</span> ', '«' );
        printf( '<span class="pagination-button disabled">%s</span> ', '‹' );
    }

    // Page jump
    ?>
    <form method="get" class="page-jump-form">
        <input type="hidden" name="page" value="meal-item" />
        <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page); ?>" />
        <?php esc_html_e( 'The', 'catering-booking-and-scheduling' ); ?>
        <input type="number" min="1" max="<?php echo esc_attr( $total_pages ); ?>"
               name="paged" value="<?php echo esc_attr( $paged ); ?>"
               class="page-number-input" />
        <?php
        printf(
            '%s %s %s %d %s ',
            esc_html__( 'pages', 'catering-booking-and-scheduling' ),
            ",",
            esc_html__( 'of', 'catering-booking-and-scheduling' ),
            $total_pages,
            esc_html__( 'pages', 'catering-booking-and-scheduling' )
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
    ?>
    </div>
    </div>
    
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
            cursor: pointer;
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
            width: 60px;
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

function catering_handle_bulk_meals_action() {
    global $wpdb;

    $meal_table = $wpdb->prefix . 'catering_meal';
    $relationship_table = $wpdb->prefix . 'catering_term_relationships';

    $bulk_action = isset( $_POST['catering_bulk_meal_action'] ) ? sanitize_text_field( $_POST['catering_bulk_meal_action'] ) : '';
    $paged       = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
    $per_page    = isset( $_POST['per_page'] ) ? sanitize_text_field( $_POST['per_page'] ) : '30';

    $selected_ids= isset( $_POST['catering_bulk_meal_items'] ) ? (array) $_POST['catering_bulk_meal_items'] : [];

    // Export CSV
    if ( $bulk_action === 'export' ) {
        if ( empty( $selected_ids ) ) {
            wp_redirect( add_query_arg([
                'page'     => 'meal-item',
                'message'  => 'bulk_no_item_selected',
                'paged'    => $paged,
                'per_page' => $per_page,
            ], admin_url( 'admin.php' )) );
            exit;
        }
        // clear any prior output to prevent HTML in CSV
        while ( ob_get_level() ) {
            ob_end_clean();
        }
        $ids = implode( ',', array_map( 'absint', $selected_ids ) );
        $rows = $wpdb->get_results(
            "SELECT ID, sku, title, description, cost, photo FROM $meal_table WHERE ID IN ($ids) ORDER BY ID ASC",
            ARRAY_A
        );
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="meals_export_' . date('Y-m-d') . '.csv"' );
        $fp = fopen( 'php://output', 'w' );
        fputcsv( $fp, [ 'ID', 'SKU', 'Meal_title', 'description','cost','photo' ] );
        foreach ( $rows as $r ) {
            fputcsv( $fp, [ $r['ID'], $r['sku'], $r['title'] , $r['description'] , $r['cost'] , $r['photo'] ] );
        }
        exit;
    }

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
