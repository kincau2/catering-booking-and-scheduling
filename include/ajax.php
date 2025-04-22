<?php

use ICal\ICal; // from the library

add_action( 'wp_ajax_catering_search_categories', 'catering_search_categories' );
function catering_search_categories() {
    global $wpdb;

    // Check user capabilities if needed
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'No permission' );
    }

    $search = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';

    // Query categories from catering_terms where type='category'
    $table = $wpdb->prefix . 'catering_terms';

    if ( ! empty( $search ) ) {
        // With a search filter
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, title FROM $table WHERE type='category' AND title LIKE %s ORDER BY ordering ASC",
                $like
            ),
            ARRAY_A
        );
    } else {
        // No search, return all
        $results = $wpdb->get_results(
            "SELECT ID, title FROM $table WHERE type='category' ORDER BY ordering ASC",
            ARRAY_A
        );
    }

    // Return data in a format that select2 can understand:
    // e.g. [ { id: 1, text: "Category Name" }, ... ]
    $formatted = [];
    foreach ( $results as $row ) {
        $formatted[] = [
            'id'   => $row['ID'],
            'text' => $row['title'],
        ];
    }

    wp_send_json( $formatted );
}

add_action('wp_ajax_bulk_edit_set_terms','my_ajax_bulk_edit_set_terms');
function my_ajax_bulk_edit_set_terms(){

    // collect POST data
    $termType = isset($_POST['termType']) ? sanitize_text_field($_POST['termType']) : '';
    $mealIDs  = isset($_POST['mealIDs']) ? array_map('intval', (array)$_POST['mealIDs']) : [];
    $chosen   = isset($_POST['chosenTermIDs']) ? array_map('intval', (array)$_POST['chosenTermIDs']) : [];

    if (empty($mealIDs) || empty($chosen)) {
       wp_send_json_error('No meals or terms selected.');
    }
    if ( $termType!=='category' && $termType!=='tag' ) {
       wp_send_json_error('Invalid termType');
    }

    global $wpdb;
    $table_rel = $wpdb->prefix.'catering_term_relationships';

    // 1) Remove old relationships of this type
    foreach($mealIDs as $mid){
       $wpdb->delete($table_rel, [
         'meal_id' => $mid,
         'term_type' => $termType
       ], ['%d','%s']);
    }

    // 2) Insert new
    foreach($mealIDs as $mid){
      foreach($chosen as $tid){
        // "INSERT IGNORE" or do a check to avoid duplicates
        $wpdb->query(
          $wpdb->prepare("
             INSERT IGNORE INTO $table_rel (meal_id, term_type, term_id)
             VALUES (%d, %s, %d)
          ", $mid, $termType, $tid)
        );
      }
    }

    wp_send_json_success('Set terms done.');
}

add_action('wp_ajax_bulk_edit_add_terms','my_ajax_bulk_edit_add_terms');
function my_ajax_bulk_edit_add_terms(){

    $termType = isset($_POST['termType']) ? sanitize_text_field($_POST['termType']) : '';
    $mealIDs  = isset($_POST['mealIDs']) ? array_map('intval', (array)$_POST['mealIDs']) : [];
    $chosen   = isset($_POST['chosenTermIDs']) ? array_map('intval', (array)$_POST['chosenTermIDs']) : [];

    if (empty($mealIDs) || empty($chosen)) {
       wp_send_json_error('No meals or terms selected.');
    }
    if ( $termType!=='category' && $termType!=='tag' ) {
       wp_send_json_error('Invalid termType');
    }

    global $wpdb;
    $table_rel = $wpdb->prefix.'catering_term_relationships';

    // We do NOT remove old relationships.
    // We only insert new ones if not present
    foreach($mealIDs as $mid){
      foreach($chosen as $tid){
        $wpdb->query(
          $wpdb->prepare("
             INSERT IGNORE INTO $table_rel (meal_id, term_type, term_id)
             VALUES (%d, %s, %d)
          ", $mid, $termType, $tid)
        );
      }
    }

    wp_send_json_success('Add terms done.');
}

add_action('wp_ajax_get_all_terms','my_ajax_get_all_terms');
function my_ajax_get_all_terms(){

    $termType = isset($_POST['termType']) ? sanitize_text_field($_POST['termType']) : '';
    if ( $termType !== 'category' && $termType !== 'tag' ) {
        wp_send_json_error('Invalid termType');
    }

    // Fetch all categories or all tags from your "catering_terms" table
    global $wpdb;
    $table = $wpdb->prefix.'catering_terms';

    $rows = $wpdb->get_results( $wpdb->prepare("
        SELECT ID, title
        FROM $table
        WHERE type=%s
        ORDER BY title ASC
    ", $termType), ARRAY_A );

    // build a nice array of {id, title}
    $terms = [];
    foreach($rows as $r){
       $terms[] = [
         'id'    => (int)$r['ID'],
         'title' => $r['title']
       ];
    }

    wp_send_json_success($terms);
}

add_action('wp_ajax_import_ical','catering_ajax_import_ical');
function catering_ajax_import_ical(){
    // check_ajax_referer('import_ical_nonce');

    if (!isset($_FILES['ical_file']) || $_FILES['ical_file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('No iCal file uploaded or upload error.');
    }
    $tmpName = $_FILES['ical_file']['tmp_name'];
    if (!file_exists($tmpName)) {
        wp_send_json_error('Uploaded file not found on server.');
    }

    // parse
    $events = my_parse_ical_file($tmpName);
    // $events is an array of { date:'YYYY-MM-DD', name:'Event Title'}

    if(empty($events)){
        wp_send_json_error('No valid events found in iCal file.');
    }

    wp_send_json_success($events);
}

function my_parse_ical_file($filePath) {
    try {
        // Instantiate the ICS parser with recommended config
        // (Adjust config to your preference: skipRecurrence, timezones, etc.)
        $ical = new ICal($filePath, [
            'defaultSpan'      => 2,    // How many days events last if not specified
            'defaultTimeZone'  => 'UTC',
            'skipRecurrence'   => false,
            'useTimeZoneWithRRules' => false
        ]);

        $events = $ical->events();
        $result = [];

        foreach ($events as $event) {
            /**
             * $event->dtstart, $event->dtstart_tz, $event->summary are typical properties.
             * Use the iCal library’s iCalDateToDateTime() to convert the dtstart to a PHP DateTime.
             */
            $startDateTime = $ical->iCalDateToDateTime(
                $event->dtstart,
                isset($event->dtstart_tz) ? $event->dtstart_tz : null
            );

            // If the event is missing dtstart or is invalid
            if (!$startDateTime) {
                continue;
            }

            // Format as YYYY-MM-DD
            $dateStr = $startDateTime->format('Y-m-d');

            // The event name from ICS summary
            $summary = isset($event->summary) ? $event->summary : '';
            if (!$summary) {
                $summary = 'Unnamed'; // fallback
            }

            $result[] = [
                'date' => $dateStr,
                'name' => sanitize_text_field($summary),
            ];
        }

        // If you want to remove duplicates (in case an ICS has the same exact date+summary repeated):
        $unique = [];
        foreach ($result as $ev) {
            $key = $ev['date'].'__'.$ev['name'];
            $unique[$key] = $ev;
        }
        $final = array_values($unique);

        set_transient('debug',$final,30);

        return $final;
    } catch (\Exception $e) {
        // If the library throws an exception (invalid file, parse error, etc.), return empty
        return [];
    }
}

add_action('wp_ajax_get_holiday_data', 'catering_ajax_get_holiday_data');
function catering_ajax_get_holiday_data(){
    // check_ajax_referer('some_nonce');

    $year  = isset($_POST['year']) ? absint($_POST['year']) : 0;
    $month = isset($_POST['month'])? absint($_POST['month']): 0;
    if($year<2000 || $year>2100 || $month<1 || $month>12){
        wp_send_json_error('Invalid year/month');
    }

    global $wpdb;
    $table = $wpdb->prefix.'catering_holiday';

    // figure range
    $lastDay = date('t', strtotime("$year-$month-01"));
    $startDate = sprintf('%04d-%02d-01', $year,$month);
    $endDate   = sprintf('%04d-%02d-%02d',$year,$month,$lastDay);

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT date, holiday_name
        FROM $table
        WHERE date >= %s AND date <= %s
    ", $startDate, $endDate), ARRAY_A);

    $data = [];
    foreach($rows as $r){
        $dateStr = $r['date'];
        $hn = maybe_unserialize($r['holiday_name']);
        if(!is_array($hn)) {
            // if it’s a single string, convert to array
            // or handle if empty
            $hn = $hn ? [ $hn ] : [];
        }
        $data[] = [
            'date'         => $dateStr,
            'holidayNames' => $hn
        ];
    }

    wp_send_json_success($data);
}

add_action('wp_ajax_update_holiday_batch','catering_ajax_update_holiday_batch');
function catering_ajax_update_holiday_batch(){
    // check_ajax_referer('some_nonce');

    if(!isset($_POST['changes_json'])){
        wp_send_json_error('No changes_json provided');
    }

    // JSON decode
    $changes_str = stripslashes($_POST['changes_json']); // remove escape slashes
    $changes = json_decode($changes_str, true);

    if(!is_array($changes)){
        wp_send_json_error('Invalid JSON for changes');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'catering_holiday';

    foreach($changes as $dateStr => $namesArr){
        // $namesArr might be [] if the user deleted the last holiday
        // If empty => remove row. Otherwise => store the array as serialized
        $dateStr = sanitize_text_field($dateStr);
        if(!is_array($namesArr)){
            $namesArr = [];
        }
        $namesArr = array_map('sanitize_text_field',$namesArr);
        $namesArr = array_filter($namesArr); // remove any empty strings
        $namesArr = array_unique($namesArr);

        // check row exist
        $row = $wpdb->get_row($wpdb->prepare("
          SELECT ID, holiday_name FROM $table WHERE date=%s
        ", $dateStr));

        if(empty($namesArr)){
            // user removed all holiday names => delete row
            if($row){
                $wpdb->delete($table, ['ID'=>$row->ID], ['%d']);
            }
        } else {
            // store them
            $serialized = maybe_serialize($namesArr);
            if($row){
                // update
                $wpdb->update($table,
                  ['holiday_name'=>$serialized],
                  ['ID'=>$row->ID],
                  ['%s'],['%d']
                );
            } else {
                // insert
                $wpdb->insert($table,
                  ['date'=>$dateStr, 'holiday_name'=>$serialized],
                  ['%s','%s']
                );
            }
        }
    }

    wp_send_json_success();
}

add_action('wp_ajax_batch_holiday_set', 'catering_ajax_batch_holiday_set');
function catering_ajax_batch_holiday_set(){
    // check_ajax_referer('some_nonce');

    if (!isset($_POST['events']) || !is_array($_POST['events'])) {
        wp_send_json_error('No events array provided');
    }
    $events = $_POST['events']; // each is { date, name }

    global $wpdb;
    $table = $wpdb->prefix . 'catering_holiday';

    foreach($events as $ev){
        // sanitize
        $dateStr = isset($ev['date']) ? sanitize_text_field($ev['date']) : '';
        $holidayName = isset($ev['name']) ? sanitize_text_field($ev['name']) : '';
        if(!$dateStr || !$holidayName){
            continue; // skip invalid
        }

        // check if row exists
        $row = $wpdb->get_row( $wpdb->prepare("
            SELECT ID, holiday_name
            FROM $table
            WHERE date=%s
        ", $dateStr) );

        if($row){
            // decode existing array
            $arr = maybe_unserialize($row->holiday_name);
            if(!is_array($arr)) {
                $arr = $arr ? [$arr] : [];
            }
            // add new name if not present
            if(!in_array($holidayName, $arr)){
                $arr[] = $holidayName;
            }
            $serialized = maybe_serialize($arr);
            // update
            $wpdb->update(
                $table,
                [ 'holiday_name' => $serialized ],
                [ 'ID' => $row->ID ],
                [ '%s' ],
                [ '%d' ]
            );
        } else {
            // insert
            $arr = [ $holidayName ];
            $wpdb->insert(
                $table,
                [
                  'date'         => $dateStr,
                  'holiday_name' => maybe_serialize($arr)
                ],
                [ '%s','%s' ]
            );
        }
    }

    wp_send_json_success('All iCal events set as holiday');
}

add_action('wp_ajax_update_term_color','my_update_term_color');
function my_update_term_color(){
    if(!current_user_can('manage_options')){
        wp_send_json_error('No permission');
    }
    global $wpdb;
    $table = $wpdb->prefix.'catering_terms';

    $term_id   = isset($_POST['term_id'])   ? absint($_POST['term_id']) : 0;
    $color_code= isset($_POST['color_code'])? sanitize_text_field($_POST['color_code']) : '#FFFFFF';

    // If color_code is invalid or empty, default to '#FFFFFF'
    if(!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_code)){
        $color_code = '#FFFFFF';
    }

    // update
    $res = $wpdb->update(
        $table,
        [ 'color' => $color_code ],
        [ 'ID'=>$term_id, 'type'=>'category' ],
        [ '%s' ],
        [ '%d','%s' ]
    );
    if(false === $res){
        wp_send_json_error('DB error or invalid term_id');
    }
    wp_send_json_success();
}

add_action('wp_ajax_update_category_ordering','my_update_category_ordering');
function my_update_category_ordering(){
    if(!current_user_can('manage_options')){
        wp_send_json_error('No permission');
    }
    global $wpdb;
    $table = $wpdb->prefix.'catering_terms';

    $json = isset($_POST['new_order']) ? stripslashes($_POST['new_order']) : '';
    $arr  = json_decode($json, true);
    if(!is_array($arr)){
        wp_send_json_error('Invalid data');
    }

    // $arr => [ { term_id:X, ordering:0 }, { term_id:Y, ordering:1 }, ... ]
    foreach($arr as $item){
        $term_id  = absint($item['term_id']);
        $ordering = absint($item['ordering']);
        // update the ordering
        $wpdb->update(
            $table,
            [ 'ordering'=>$ordering ],
            [ 'ID'=>$term_id, 'type'=>'category' ],
            [ '%d' ],
            [ '%d','%s' ]
        );
    }

    wp_send_json_success();
}

add_action('wp_ajax_clear_catering_schedule', 'catering_ajax_clear_catering_schedule');
function catering_ajax_clear_catering_schedule(){
    if( ! current_user_can('manage_options') ){
        wp_send_json_error('No permission');
    }
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $dates = isset($_POST['dates']) && is_array($_POST['dates']) ? $_POST['dates'] : [];
    if( ! $product_id || empty($dates) ){
        wp_send_json_error('Invalid parameters');
    }
    global $wpdb;
    $table = $wpdb->prefix . 'catering_schedule';
    // Build dynamic placeholders for dates array.
    $placeholders = implode(',', array_fill(0, count($dates), '%s'));
    $query = "DELETE FROM $table WHERE product_id = %d AND date IN ($placeholders)";
    $params = array_merge([$product_id], $dates);
    $result = $wpdb->query($wpdb->prepare($query, $params));
    if($result === false){
        wp_send_json_error('DB error');
    }
    wp_send_json_success('Schedule cleared');
}

add_action('wp_ajax_check_catering_schedule', 'catering_ajax_check_catering_schedule');
function catering_ajax_check_catering_schedule(){
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $unique_dates = isset($_POST['unique_dates']) && is_array($_POST['unique_dates']) ? array_map('sanitize_text_field', $_POST['unique_dates']) : [];
    if(!$product_id || empty($unique_dates)){
        wp_send_json_error('Invalid parameters');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'catering_schedule';
    $placeholders = implode(',', array_fill(0, count($unique_dates), '%s'));
    $query = "SELECT date FROM $table WHERE product_id = %d AND date IN ($placeholders)";
    $params = array_merge([$product_id], $unique_dates);
    $rows = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
    set_transient('debug', $wpdb->prepare($query, $params), 30); // for debugging

    // extract dates and remove duplicates
    $existing = array_map(function($row){ return $row['date']; }, $rows);
    $existing = array_values(array_unique($existing));

    wp_send_json_success(['existing' => $existing]);
}

add_action('wp_ajax_validate_catering_meals','catering_ajax_validate_catering_meals');
function catering_ajax_validate_catering_meals(){
    if(!current_user_can('manage_options')){
        wp_send_json_error('No permission');
    }
    if(! isset($_POST['meals']) || ! is_array($_POST['meals']) ){
        wp_send_json_error('Invalid meals data');
    }
    global $wpdb;
    $table   = $wpdb->prefix . 'catering_meal';
    $meals   = $_POST['meals'];
    $ids     = array_map('intval', array_column($meals,'id'));
    if(empty($ids)){
        wp_send_json_success(['invalid'=>[]]);
    }
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $sql = "SELECT ID, sku, title FROM $table WHERE ID IN ($placeholders)";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $ids), OBJECT_K);
    $invalid = [];
    foreach($meals as $m){
        $rownum = absint($m['row']);
        $mid    = absint($m['id']);
        $sku    = sanitize_text_field($m['sku']);
        $title  = sanitize_text_field($m['title']);
        if( ! isset($rows[$mid]) 
         || $rows[$mid]->sku   !== $sku 
         || $rows[$mid]->title !== $title ){
            $invalid[] = ['row'=>$rownum,'id'=>$mid,'sku'=>$sku,'title'=>$title];
        }
    }
    wp_send_json_success(['invalid'=>$invalid]);
}

add_action('wp_ajax_process_catering_schedule_row','catering_ajax_process_catering_schedule_row');
function catering_ajax_process_catering_schedule_row(){
    if(! current_user_can('manage_options') ){
        wp_send_json_error('No permission');
    }
    // capture row index
    $rownum = isset($_POST['row']) ? absint($_POST['row']) : 0;
    $post_id    = isset($_POST['post_id'])    ? absint($_POST['post_id'])    : 0;
    $meal_id    = isset($_POST['meal_id'])    ? absint($_POST['meal_id'])    : 0;
    $date_str   = isset($_POST['date'])       ? sanitize_text_field($_POST['date']) : '';
    $cats       = isset($_POST['cats'])       ? (array) $_POST['cats']       : [];
    $tag        = isset($_POST['tag'])        ? sanitize_text_field($_POST['tag'])   : '';
    if( ! $post_id || ! $meal_id || ! $date_str ){
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . 'Missing parameters' );
    }
    // validate date format
    if( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str) ){
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . 'Invalid date' );
    }
    global $wpdb;
    // verify meal exists
    $exists = $wpdb->get_var( $wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->prefix}catering_meal WHERE ID=%d
    ", $meal_id) );
    if( ! $exists ){
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . 'Meal ID not found' );
    }
    // Skip if schedule entry already exists
    $dup = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}catering_schedule
         WHERE product_id=%d AND meal_id=%d AND date=%s",
        $post_id, $meal_id, $date_str
    ) );
    if( $dup ) {
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . "Duplicate meal on {$date_str}" );
    }
    // lookup category IDs
    if( $cats ){
        $placeholders = implode(',', array_fill(0, count($cats), '%s'));
        $sql = "SELECT title,ID FROM {$wpdb->prefix}catering_terms 
                WHERE type='category' AND title IN ($placeholders)";
        $rows = $wpdb->get_results( $wpdb->prepare($sql, $cats), ARRAY_A );
        $map  = [];
        foreach($rows as $r){
            $map[ $r['title'] ] = $r['ID'];
        }
        $cat_ids = [];
        foreach($cats as $c){
            if( ! isset($map[$c]) ){
                wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . "Category '{$c}' not found" );
            }
            $cat_ids[] = $map[$c];
        }
    } else {
        $cat_ids = [];
    }
    $serialized_cats = maybe_serialize($cat_ids);
    // insert into schedule
    $ok = $wpdb->insert(
        "{$wpdb->prefix}catering_schedule",
        [
          'meal_id'    => $meal_id,
          'product_id' => $post_id,
          'date'       => $date_str,
          'cat_id'     => $serialized_cats,
          'tag'        => $tag
        ],
        ['%d','%d','%s','%s','%s']
    );
    if( false === $ok ){
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . 'DB insert failed' );
    }
    wp_send_json_success();
}

add_action('wp_ajax_get_meal_schedule_week','catering_ajax_get_meal_schedule_week');
function catering_ajax_get_meal_schedule_week(){
    if(! current_user_can('manage_options') ){
        wp_send_json_error('No permission');
    }
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $start = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end   = isset($_POST['end_date'])   ? sanitize_text_field($_POST['end_date'])   : '';
    if(!$product_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)){
        wp_send_json_error('Invalid parameters');
    }
    global $wpdb;
    // 1) load category meta
    $terms = $wpdb->get_results("
       SELECT ID,title,color,ordering
       FROM {$wpdb->prefix}catering_terms
       WHERE type='category'
       ORDER BY ordering ASC
    ", ARRAY_A);
    $term_map = [];
    foreach($terms as $t){
        $term_map[$t['ID']] = [
            'title'    => $t['title'],
            'color'    => $t['color'],
            'ordering' => (int)$t['ordering']
        ];
    }
    // 2) fetch schedule rows
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT date, meal_id, cat_id, tag
        FROM {$wpdb->prefix}catering_schedule
        WHERE product_id=%d AND date BETWEEN %s AND %s
        ORDER BY date ASC
    ", $product_id, $start, $end), ARRAY_A);
    $data = [];
    // meal title lookup
    $meal_ids = array_unique(array_column($rows,'meal_id'));
    if($meal_ids){
        $ph = implode(',', array_fill(0,count($meal_ids),'%d'));
        $meals = $wpdb->get_results($wpdb->prepare("
          SELECT ID,title FROM {$wpdb->prefix}catering_meal
          WHERE ID IN ($ph)
        ", $meal_ids), OBJECT_K);
    } else {
        $meals = [];
    }
    foreach($rows as $r){
        $date = $r['date'];
        $cat_ids = maybe_unserialize($r['cat_id']);
        if(!is_array($cat_ids)) $cat_ids = $cat_ids ? [$cat_ids] : [];
        foreach($cat_ids as $cid){
            if(!isset($term_map[$cid])) continue;
            $data[$date][$cid][] = [
                'id'    => (int)$r['meal_id'],
                'title' => isset($meals[$r['meal_id']]) ? $meals[$r['meal_id']]->title : '',
                'tag'   => $r['tag']
            ];
        }
    }
    wp_send_json_success(['schedule'=>$data,'terms'=>$term_map]);
}

add_action('wp_ajax_export_catering_schedule','export_catering_schedule');
function export_catering_schedule(){
    if(! current_user_can('manage_options') ) wp_die('No permission');
    $product_id = isset($_GET['product_id'])?absint($_GET['product_id']):0;
    $start      = isset($_GET['start_date'])?sanitize_text_field($_GET['start_date']):'';
    $end        = isset($_GET['end_date'])?sanitize_text_field($_GET['end_date']):'';
    if(!$product_id 
     || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$start) 
     || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)
    ) wp_die('Invalid parameters');
    global $wpdb;
    // fetch schedule rows
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT date, meal_id, cat_id, tag
        FROM {$wpdb->prefix}catering_schedule
        WHERE product_id=%d AND date BETWEEN %s AND %s
        ORDER BY date ASC
    ", $product_id,$start,$end), ARRAY_A);
    // meal lookup
    $meal_ids = array_unique(array_column($rows,'meal_id'));
    if($meal_ids){
        $ph = implode(',', array_fill(0,count($meal_ids),'%d'));
        $meals = $wpdb->get_results($wpdb->prepare("
            SELECT ID,sku,title FROM {$wpdb->prefix}catering_meal
            WHERE ID IN ($ph)
        ", $meal_ids), OBJECT_K);
    } else {
        $meals = [];
    }
    // term lookup
    $all_terms = [];
    foreach($rows as $r){
        foreach((array) maybe_unserialize($r['cat_id']) as $cid){
            $all_terms[$cid]=1;
        }
    }
    $term_map = [];
    if($all_terms){
        $ids = array_keys($all_terms);
        $ph  = implode(',', array_fill(0,count($ids),'%d'));
        $terms = $wpdb->get_results($wpdb->prepare("
            SELECT ID,title FROM {$wpdb->prefix}catering_terms
            WHERE type='category' AND ID IN ($ph)
        ", $ids), OBJECT_K);
        foreach($terms as $t){
            $term_map[$t->ID] = $t->title;
        }
    }
    // output CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="schedule-'.$start.'-'.$end.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Date','Category','ID','SKU','Meal_title','Tag']);
    foreach($rows as $r){
        $cats = (array) maybe_unserialize($r['cat_id']);
        $titles = [];
        foreach($cats as $cid){
            if(isset($term_map[$cid])) $titles[] = $term_map[$cid];
        }
        $meal = isset($meals[$r['meal_id']]) ? $meals[$r['meal_id']] : null;
        fputcsv($out, [
            $r['date'],
            implode('|',$titles),
            $r['meal_id'],
            $meal ? $meal->sku : '',
            $meal ? $meal->title : '',
            $r['tag']
        ]);
    }
    fclose($out);
    exit;
}
