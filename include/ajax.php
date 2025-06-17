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

        return $final;
    } catch (\Exception $e) {
        // If the library throws an exception (invalid file, parse error, etc.), return empty
        return [];
    }
}

add_action('wp_ajax_get_holiday_data', 'catering_ajax_get_holiday_data');
function catering_ajax_get_holiday_data(){

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
    // --- revised type handling ---
    $raw_type = isset($_POST['type']) ? $_POST['type'] : '';
    if ( is_string($raw_type) ) {
        // explode comma-separated string
        $types = array_map('trim', explode(',', $raw_type));
    } elseif ( is_array($raw_type) ) {
        $types = $raw_type;
    } else {
        $types = [];
    }
    // sanitize and remove empties
    $types = array_filter( array_map('sanitize_text_field', $types) );
    $serialized_types = empty($types)? '' : maybe_serialize( $types );

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
          'tag'        => $tag,
          'type'       => $serialized_types
        ],
        ['%d','%d','%s','%s','%s','%s']
    );
    if( false === $ok ){
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . 'DB insert failed' );
    }
    wp_send_json_success();
}

add_action('wp_ajax_get_meal_schedule_week','catering_ajax_get_meal_schedule_week');
function catering_ajax_get_meal_schedule_week(){
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $start = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end   = isset($_POST['end_date'])   ? sanitize_text_field($_POST['end_date'])   : '';
    // NEW: obtain booking_id to calculate day remaining
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
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
    // NEW: calculate day remaining if booking_id provided
    $day_remaining = null;
    if($booking_id){
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT plan_days, user_id FROM {$wpdb->prefix}catering_booking WHERE ID=%d
        ", $booking_id));
        if($booking){
            $choice_count = (int)$wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND user_id=%d
            ", $booking_id, $booking->user_id ));
            $day_remaining = (int)$booking->plan_days - $choice_count;
            if($day_remaining < 0) $day_remaining = 0;
        }
    }

    wp_send_json_success([
        'schedule'      => $data,
        'terms'         => $term_map,
        'day_remaining' => $day_remaining
    ]);
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
        SELECT date, meal_id, cat_id, tag, type
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

    // Clear all output buffers to prevent leading blank lines
    while ( ob_get_level() ) {
        ob_end_clean();
    }

    // output CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="schedule-'.$start.'-'.$end.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','SKU','Meal_title','Category','Type','Date','Tag']);
    foreach($rows as $r){
        $cats   = (array) maybe_unserialize($r['cat_id']);
        $titles = [];
        foreach($cats as $cid){
            if(isset($term_map[$cid])) $titles[] = $term_map[$cid];
        }
        $type   = maybe_unserialize($r['type']);
        $meal = isset($meals[$r['meal_id']]) ? $meals[$r['meal_id']] : null;
        fputcsv($out, [
            $r['meal_id'],
            $meal ? $meal->sku : '',
            $meal ? $meal->title : '',
            implode('|',$titles),
            is_array($type) ? implode('|',$type) : $type,
            $r['date'],
            $r['tag']
        ]);
    }
    fclose($out);
    exit;
}

add_action('wp_ajax_catering_import_csv_row','catering_ajax_import_csv_row');
function catering_ajax_import_csv_row(){
    if(! current_user_can('manage_options')) {
        wp_send_json_error(__('No permission','catering-booking-and-scheduling'));
    }
    // collect and sanitize
    $mode  = isset($_POST['mode'])  ? sanitize_text_field($_POST['mode']) : '';
    $id    = isset($_POST['id'])    ? absint($_POST['id']) : 0;
    $sku   = isset($_POST['sku'])   ? sanitize_text_field($_POST['sku']) : '';
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $desc  = isset($_POST['description']) ? sanitize_text_field($_POST['description']) : '';
    $cost  = isset($_POST['cost'])  ? floatval($_POST['cost']) : 0;
    $photo = isset($_POST['photo']) ? esc_url_raw($_POST['photo']) : '';
    $row   = isset($_POST['row'])   ? absint($_POST['row']) : 0;
    global $wpdb;
    $table = $wpdb->prefix.'catering_meal';

    // SKU uniqueness
    if ( $sku ) {
        if ( $mode === 'new' ) {
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE sku=%s",
                $sku
            ) );
            if ( $count > 0 ) {
                wp_send_json_error( __( 'Duplicated SKU', 'catering-booking-and-scheduling' ) );
            }
        } elseif ( $mode === 'update' ) {
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE sku=%s AND ID!=%d",
                $sku, $id
            ) );
            
            if ( $count > 0 ) {
                wp_send_json_error( __( 'Duplicated SKU' , 'catering-booking-and-scheduling' ) );
            }
        }
    } else {
        wp_send_json_error( __('SKU required','catering-booking-and-scheduling') );
        
    }

    // common validation
    if($mode==='new'){
        if($title==='') wp_send_json_error(__('Title required','catering-booking-and-scheduling'));
    } elseif($mode==='update'){
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if(!$id || ! $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE ID=%d",$id))){
            wp_send_json_error( __('Invalid ID','catering-booking-and-scheduling'));
        }
        if($title==='') wp_send_json_error(__('Title required','catering-booking-and-scheduling'));
    } else {
        wp_send_json_error( __('Invalid mode','catering-booking-and-scheduling'));
    }
    // handle photo: check exists in media
    if(!empty($photo)){
        $attach_id = attachment_url_to_postid($photo);
        if(!$attach_id){
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
            // sideload
            $new = media_sideload_image($photo, 0, null, 'src');
            if(is_wp_error($new) || ! $new){
                wp_send_json_error( __('Image fetch failed','catering-booking-and-scheduling'));
            }
            $attach_id = attachment_url_to_postid($new);
            $photo = $new;
        }
    }
    
    // perform DB operation
    if($mode==='new'){
        $res = $wpdb->insert($table,[
            'sku'=>$sku,'title'=>$title,'description'=>$desc,
            'cost'=>$cost,'photo'=>$photo,
            'date_amended_gmt'=>current_time('mysql',1)
        ],['%s','%s','%s','%f','%s','%s']);
    } else {
        $res = $wpdb->update($table,
            ['sku'=>$sku,'title'=>$title,'description'=>$desc,'cost'=>$cost,'photo'=>$photo,'date_amended_gmt'=>current_time('mysql',1)],
            ['ID'=>$id],['%s','%s','%s','%f','%s','%s'],['%d']);
    }
    if(false === $res){
        wp_send_json_error( __('DB error','catering-booking-and-scheduling'));
    }
    wp_send_json_success();
}

add_action('wp_ajax_delete_schedule_day','catering_ajax_delete_schedule_day');
function catering_ajax_delete_schedule_day(){
    if (! current_user_can('manage_options')) {
        wp_send_json_error('No permission');
    }
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $date       = isset($_POST['date'])       ? sanitize_text_field($_POST['date']) : '';
    if (!$product_id || ! preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
        wp_send_json_error('Invalid parameters');
    }
    global $wpdb;
    $table  = $wpdb->prefix . 'catering_schedule';
    $deleted = $wpdb->delete( $table, [ 'product_id'=>$product_id, 'date'=>$date ], [ '%d','%s' ] );
    if ( false === $deleted ) {
        wp_send_json_error('DB error');
    }
    wp_send_json_success();
}

// get schedule for a single day, grouped by category with meal list and qty limits
add_action('wp_ajax_get_day_schedule','catering_ajax_get_day_schedule');
add_action('wp_ajax_nopriv_get_day_schedule','catering_ajax_get_day_schedule');
function catering_ajax_get_day_schedule(){
    global $wpdb;
    $product_id  = isset($_GET['product_id'])   ? absint($_GET['product_id'])   : 0;
    $date        = isset($_GET['date'])         ? sanitize_text_field($_GET['date']) : '';
    $booking_id  = isset($_GET['booking_id'])   ? absint($_GET['booking_id'])   : 0;
    if( ! $product_id || ! preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) ){
        wp_send_json_error('Invalid parameters');
    }
    
    // New: Check minimum day requirement
    if( !current_user_can('manage_options') && !is_min_day_requirement_met($date) ){
        wp_send_json_error('Your selected date does not meet the minimum advance order requirement.');
    }

    $booking    = new Booking($booking_id);
    if(!$booking){
        wp_send_json_error('Booking not found.');
    }

    if(!current_user_can('manage_options') && !empty($booking->expiry) ){
        // calculate plan expiry date based on first choice

        if( $booking->is_date_expired($date) ) {
            wp_send_json_error('Your plan has expired on this day ('.$date.').');
        }
        
    }
    
    $error = $booking->checkHealthDate($date);
    if($error){
        wp_send_json_error($error);
    }
    
    // fetch raw schedule rows (include their own type)
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT cat_id, meal_id, tag, type
         FROM {$wpdb->prefix}catering_schedule
         WHERE product_id=%d AND date=%s",
        $product_id, $date
    ), ARRAY_A);
    // hybrid filter
    
    if( $booking->type==='hybird' && $booking->health_status['due_date'] ){
        $due_date = $booking->health_status['due_date'];
        $filtered = [];
        foreach($rows as $r){

            $type = maybe_unserialize($r['type']);
            if(!is_array($type)) $type = $type ? [$type] : [];

            if($date <  $due_date && in_array('產前',$type) ){
                $filtered[] = $r;
            }
            if($date >= $due_date && in_array('產後',$type)){
                $filtered[] = $r;
            }
        }
        $rows = $filtered;
        
    }

    if(empty($rows)){
        // Even if there is no schedule row, we still need to output proportion.
        $proportion = [];
        $plan_day = (int)$booking->plan_days;
                $table_choice = $wpdb->prefix.'catering_choice';
                $choice_dates = $wpdb->get_col($wpdb->prepare("SELECT date FROM $table_choice WHERE booking_id=%d AND user_id=%d ORDER BY date ASC", $booking_id, $booking->user_id));
                $current_day = 1;
                foreach($choice_dates as $d){
                    if($d < $date){
                        $current_day++;
                    }
                }
                $proportion = [
                   'current_day' => $current_day,
                   'plan_day'    => $plan_day
                ];
        wp_send_json_success([
            'categories' => [],
            'address'    => [],
            'proportion' => $proportion
        ]);
    }
    // group meal_ids and capture tags per category
    $cat_map = [];
    $tag_map = [];
    foreach($rows as $r){
        $cats = maybe_unserialize($r['cat_id']);
        if(!is_array($cats)) $cats = $cats?[$cats]:[];
        foreach($cats as $cid){
            $cat_map[$cid][]               = $r['meal_id'];
            $tag_map[$cid][$r['meal_id']] = $r['tag'];
        }
    }
    $cat_ids  = array_keys($cat_map);
    // get category titles
    $ph = implode(',', array_fill(0,count($cat_ids),'%d'));
    $terms = $wpdb->get_results($wpdb->prepare(
        "SELECT ID,title FROM {$wpdb->prefix}catering_terms WHERE ID IN ($ph)",
        $cat_ids
    ), OBJECT_K);
    // fetch per-category qty limit from booking’s order_item meta
    $qty_map = [];
    
    $qty_map = maybe_unserialize($booking->cat_qty);


    // fetch all meal titles
    $all_mids = array_unique(call_user_func_array('array_merge', $cat_map));
    $meal_map = [];
    if($all_mids){
        $ph2 = implode(',', array_fill(0,count($all_mids),'%d'));
        $meals = $wpdb->get_results($wpdb->prepare(
            "SELECT ID,title FROM {$wpdb->prefix}catering_meal WHERE ID IN ($ph2)",
            $all_mids
        ), OBJECT_K);
        foreach($meals as $m) $meal_map[$m->ID] = $m->title;
    }
    // build response categories array
    $categories = [];
    foreach($cat_map as $cid => $mids){
        $options = [];
        foreach(array_unique($mids) as $mid){
            if(isset($meal_map[$mid])){
                $options[] = [
                    'id'    => $mid,
                    'title' => $meal_map[$mid],
                    'tag'   => isset($tag_map[$cid][$mid]) ? $tag_map[$cid][$mid] : ''
                ];
            }
        }
        $categories[] = [
            'cat_id'    => $cid,
            'cat_title' => isset($terms[$cid]) ? $terms[$cid]->title : '',
            'max_qty'   => isset($qty_map[$cid]) ? absint($qty_map[$cid]) : 0,
            'meals'     => $options
        ];
    }
    // NEW: fetch delivery address
    $address = [];
    $table_choice = $wpdb->prefix.'catering_choice';
    $addr = $wpdb->get_var($wpdb->prepare(
        "SELECT address FROM $table_choice WHERE booking_id=%d AND date=%s",
        $booking_id, $date
    ));
    if($addr){
        $address = maybe_unserialize($addr);
    } else {
        // obtain shipping address from order via booking record
        $table_booking = $wpdb->prefix.'catering_booking';
        $booking_row = $wpdb->get_row($wpdb->prepare(
            "SELECT order_item_id FROM $table_booking WHERE ID=%d", $booking_id
        ));
        if($booking_row && function_exists('wc_get_order_id_by_order_item_id')){
            $order_id = wc_get_order_id_by_order_item_id($booking_row->order_item_id);
            if($order_id){
                $order = wc_get_order($order_id);
                if($order){
                    $address = [
                        'first_name'     => $order->get_shipping_first_name(),
                        'last_name'      => $order->get_shipping_last_name(),
                        'address'        => $order->get_shipping_address_1(),
                        'city'           => $order->get_shipping_city(),
                        'phone'          => $order->get_shipping_phone(),
                        'delivery_note'  => $order->get_meta('_shipping_remarks') ?? '',
                        'order_note'     => $order->get_customer_note() ?? ''
                    ];
                }
            }
        }
    }
    
    // --- NEW: Calculate proportion ---
    $proportion = [];
    $plan_day = (int)$booking->plan_days;
    $table_choice = $wpdb->prefix.'catering_choice';
    $choice_dates = $wpdb->get_col($wpdb->prepare(
        "SELECT date FROM $table_choice WHERE booking_id=%d AND user_id=%d ORDER BY date ASC",
        $booking_id, $booking->user_id
    ));
    $current_day = 1;
    foreach($choice_dates as $d){
        if($d < $date){
            $current_day++;
        }
    }
    $proportion = [
        'current_day' => $current_day,
        'plan_day'    => $plan_day
    ];

    $catering_product_id = $booking->get_linked_product();
    if($catering_product_id){
        $meal_setting = array(
            'soup_container' => get_post_meta($catering_product_id, 'catering_soup_container', true)
        );
    }

    wp_send_json_success([
        'categories' => $categories,
        'address'    => $address,
        'proportion' => $proportion,
        'meal_setting' => $meal_setting
    ]);
}

add_action('wp_ajax_get_min_day_before_order','catering_ajax_get_min_day_before_order');
function catering_ajax_get_min_day_before_order(){
    if(current_user_can('manage_options')){
        wp_send_json_success(['min_days'=>-1]);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'catering_options';
    $val = $wpdb->get_var( $wpdb->prepare(
        "SELECT option_value FROM $table WHERE option_name=%s",
        'catering_min_day_before_order'
    ) );
    $min = is_numeric($val) ? intval($val) : 0;
    wp_send_json_success(['min_days'=>$min]);
}

add_action('wp_ajax_validate_booking','catering_ajax_validate_booking');
function catering_ajax_validate_booking(){
    if(current_user_can('manage_options')){
        wp_send_json_success();
    }
    $current_user = get_current_user_id();
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    if(!$booking_id){
         wp_send_json_error('Invalid booking ID.');
    }
    global $wpdb;
    $table = $wpdb->prefix . 'catering_booking';
    $booking = $wpdb->get_row(
       $wpdb->prepare("SELECT user_id FROM $table WHERE ID=%d", $booking_id)
    );
    if(!$booking){
         wp_send_json_error('Booking not found.');
    }
    if($booking->user_id != $current_user){
         wp_send_json_error('Booking does not belong to current user.');
    }
    wp_send_json_success();
}

add_action('wp_ajax_save_user_choice','catering_ajax_save_user_choice');
function catering_ajax_save_user_choice(){
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $date       = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $choice     = isset($_POST['choice']) && is_array($_POST['choice']) ? $_POST['choice'] : [];
    $address = isset($_POST['address']) && is_array($_POST['address']) ? $_POST['address'] : [];

    $booking    = new Booking($booking_id);

    if(!$booking){
        wp_send_json_error('Booking not found.');
    }

    $user_id = $booking->user_id;

    global $wpdb;

    // NEW: grab preference array
    $preference = isset($_POST['preference']) && is_array($_POST['preference'])
                  ? $_POST['preference'] : [];
    
    if(!$booking_id || !$user_id || !$date || empty($choice) || empty($address) ){
        wp_send_json_error('Missing or invalid parameters.');
    }

    // --- address validation ---
    $first = sanitize_text_field($address['first_name'] ?? '');
    $last  = sanitize_text_field($address['last_name']  ?? '');
    $adr   = sanitize_text_field($address['address']    ?? '');
    $city  = sanitize_text_field($address['city']       ?? '');
    $phone = sanitize_text_field($address['phone']      ?? '');

    $allowed_cities = [
        '灣仔區','東區','中西區','南區','北區','觀塘區','油尖旺區',
        '黃大仙區','深水埗區','九龍城區','荃灣區','離島區','葵青區',
        '西貢區','沙田區','元朗區','屯門區','大埔區'
    ];
    $phone_re = '/^(?!999)[4569]\d{7}$/';
    $name_re  = '/^\D+$/';

    if (!$first) {
        wp_send_json_error('First name is required.');
    }
    if (!preg_match($name_re, $first)) {
        wp_send_json_error('First name cannot contain numbers.');
    }
    if (!$last) {
        wp_send_json_error('Last name is required.');
    }
    if (!preg_match($name_re, $last)) {
        wp_send_json_error('Last name cannot contain numbers.');
    }
    if (!$adr) {
        wp_send_json_error('Address is required.');
    }
    if (!$city) {
        wp_send_json_error('City is required.');
    }
    if (!in_array($city, $allowed_cities, true)) {
        wp_send_json_error('Please select a valid city.');
    }
    if (!$phone) {
        wp_send_json_error('Phone is required.');
    }
    if (!preg_match($phone_re, $phone)) {
        wp_send_json_error('Please enter a valid mobile number.');
    }
    // --- end address validation ---

    if( ! current_user_can('manage_options') && ! is_min_day_requirement_met($date) ){
        wp_send_json_error('Your selected date does not meet the minimum advance order requirement.');
    }
    
    // NEW VALIDATION: Check that the number of meals selected per category matches the expected cat_qty.
    if(!current_user_can('manage_options')){
        $expected = maybe_unserialize($booking->cat_qty);
        if(is_array($expected)) {
            foreach($expected as $cat_id => $requiredCount) {
                $selectedCount = isset($choice[$cat_id]) ? count($choice[$cat_id]) : 0;
                if($selectedCount !== (int)$requiredCount) {
                    // Retrieve category title from the database
                    $term = $wpdb->get_row($wpdb->prepare("SELECT title FROM {$wpdb->prefix}catering_terms WHERE ID=%d", $cat_id));
                    $cat_title = $term ? $term->title : $cat_id;
                    wp_send_json_error("Please select exactly {$requiredCount} meal(s) for category {$cat_title}.");
                }
            }
        }
    }

    if(!current_user_can('manage_options') && !empty($booking->expiry) ){
        // calculate plan expiry date based on first choice

        if( $booking->is_date_expired($date) ) {
            wp_send_json_error('Your plan has expired on this day ('.$date.').');
        }
        
    }
    
    global $wpdb;
    // New: check remaining days on plan
    
    $plan_days = (int)$booking->plan_days;
    $table_choice = $wpdb->prefix . 'catering_choice';
    // Check if an entry exists for this booking, user and date
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_choice WHERE booking_id=%d AND user_id=%d AND date=%s",
        $booking_id, $user_id, $date
    ));
    // If no entry exists, it's a new submission; check total count
    if(!$existing){
        $total_choices = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_choice WHERE booking_id=%d AND user_id=%d",
            $booking_id, $user_id
        ));
        if( !current_user_can('manage_options') && ($total_choices >= $plan_days) ){
            wp_send_json_error('No remaining days on your plan.');
        }
    }

    // Add order note to $address if it exists
    $order = wc_get_order($booking->get_order_id());
    $address['order_note'] = $order->get_customer_note();
    

    $serialized_choice     = maybe_serialize($choice);
    $serialized_address    = maybe_serialize($address);
    $serialized_preference = maybe_serialize($preference); // serialize preference

    // --- HYBIRD TYPE LOGIC UPDATED ---
    $type_value = '';
    if ($booking->type === 'hybird') {
        $health_status = (is_array($booking->health_status)) ? $booking->health_status : maybe_unserialize($booking->health_status);
        if(!empty($health_status) && isset($health_status['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $health_status['due_date'])) {
            $due_date = $health_status['due_date'];
            if(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)){
                $type_value = ($date < $due_date) ? 'prenatal' : 'postpartum';
            }
        }
    } elseif ($booking->type === 'postpartum') {
        $type_value = 'postpartum';
    } elseif ($booking->type === 'prenatal') {
        $type_value = 'prenatal';
    }

    // Save/update meal choice with type column
    if($existing){
        $update_data   = [
            'choice'     => $serialized_choice,
            'address'    => $serialized_address,
            'preference' => $serialized_preference  // add preference
        ];
        $update_format = ['%s','%s','%s'];
        if( !empty($booking->type) ){
            $update_data['type'] = $type_value;
            $update_format[] = '%s';
        }
        // NEW: add lock column if current user can manage options
        if(current_user_can('manage_options')){
            $update_data['locked'] = 'true';
            $update_format[] = '%s';
        }
        $res = $wpdb->update(
            $table_choice,
            $update_data,
            ['booking_id'=>$booking_id, 'user_id'=>$user_id, 'date'=>$date],
            $update_format,
            ['%d','%d','%s','%s']
        );
    } else {
        $insert_data   = [
            'booking_id' => $booking_id,
            'user_id'    => $user_id,
            'date'       => $date,
            'choice'     => $serialized_choice,
            'address'    => $serialized_address,
            'preference' => $serialized_preference   // add preference
        ];
        $insert_format = ['%d','%d','%s','%s','%s','%s'];
        if( !empty($booking->type) ){
            $insert_data['type'] = $type_value;
            $insert_format[] = '%s';
        }
        // NEW: add lock column if current user can manage options
        if(current_user_can('manage_options')){
            $insert_data['locked'] = 'true';
            $insert_format[] = '%s';
        }
        $res = $wpdb->insert(
            $table_choice,
            $insert_data,
            $insert_format
        );
    }

    if(false === $res){
         wp_send_json_error('DB insert/update failed.');
    }
    wp_send_json_success('User choice saved.');
}

add_action('wp_ajax_get_user_meal_choices_range','catering_ajax_get_user_meal_choices_range');
function catering_ajax_get_user_meal_choices_range(){
    global $wpdb;
    $table_choice = $wpdb->prefix . 'catering_choice';

    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $start      = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end        = isset($_POST['end_date'])   ? sanitize_text_field($_POST['end_date'])   : '';

    if(!$booking_id || !$start || !$end){
         wp_send_json_error('Invalid parameters.');
    }
    
    $booking    = new Booking($booking_id);
    
    if(!$booking){
        wp_send_json_error('Booking not found.');
    }
    $user_id = $booking->user_id;
    
    // fetch date, choice, type, notice, locked AND address
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT date, choice, type, notice, locked, address
       FROM $table_choice
       WHERE booking_id=%d AND user_id=%d AND date BETWEEN %s AND %s",
      $booking_id, $user_id, $start, $end
    ), ARRAY_A);

    $choicesByDate = [];
    foreach($rows as $r){
        $dt = $r['date'];
        $choicesByDate[$dt] = [
            'choice'  => maybe_unserialize($r['choice']),
            'type'    => $r['type'],
            'notice'  => maybe_unserialize($r['notice']),
            'locked'  => $r['locked'],
            'address' => maybe_unserialize($r['address'])
        ];
    }
    
    if(empty($choicesByDate)){
         wp_send_json_success([]);
    }
    
    // Collect all category IDs
    $allCatIds = [];
    foreach($choicesByDate as $dt => $val){
       $mealChoices = is_array($val['choice']) ? $val['choice'] : [];
       $allCatIds = array_merge($allCatIds, array_keys($mealChoices));
    }
    $allCatIds = array_unique($allCatIds);
    if(empty($allCatIds)){
         wp_send_json_success([]);
    }
    
    // Get category details ordered by 'ordering'
    $placeholders = implode(',', array_fill(0, count($allCatIds), '%d'));
    $catRows = $wpdb->get_results(
       $wpdb->prepare("SELECT ID, title, color, ordering FROM {$wpdb->prefix}catering_terms WHERE ID IN ($placeholders) ORDER BY ordering ASC", $allCatIds),
       ARRAY_A
    );
    $catMap = [];
    foreach($catRows as $cat){
       $catMap[$cat['ID']] = $cat;
    }

    // Gather all meal IDs from all choices
    $allMealIds = [];
    foreach($choicesByDate as $dt => $day_meal_data){
       if(is_array($day_meal_data['choice'])){
           foreach($day_meal_data['choice'] as $cat_id => $mealIds){
               if(is_array($mealIds)){
                   $allMealIds = array_merge($allMealIds, $mealIds);
               }
           }
       }
    }
    $allMealIds = array_unique($allMealIds);
    if(empty($allMealIds)){
         wp_send_json_success([]);
    }
    $ph = implode(',', array_fill(0, count($allMealIds), '%d'));
    $mealRows = $wpdb->get_results(
      $wpdb->prepare("SELECT ID, title FROM {$wpdb->prefix}catering_meal WHERE ID IN ($ph)", $allMealIds),
      OBJECT_K
    );

    // Build the final output per date:
    // For each date, for each category (sorted by ordering from catMap), build object with cat_title, color, and meals (titles)
    
    // Build final output per date by sorting categories and attaching type data
    $result = [];
    foreach($choicesByDate as $dt => $data){
       $mealChoices = is_array($data['choice']) ? $data['choice'] : [];
       $catArr = [];
       $catIds = array_keys($mealChoices);
       usort($catIds, function($a, $b) use ($catMap){
           return $catMap[$a]['ordering'] <=> $catMap[$b]['ordering'];
       });
       foreach($catIds as $cat_id){
           if(!isset($catMap[$cat_id])) continue;
           // meal IDs for this category
           $mealIds = $mealChoices[$cat_id];
           $mealitem = [];
           foreach($mealIds as $mid){
                if(isset($mealRows[$mid])){
                    // Updated: return meal as array with keys id and title.
                    $mealitem[] = [
                        'id' => $mealRows[$mid]->ID,
                        'title' => $mealRows[$mid]->title
                    ];
                }
           }
           $catArr[] = [
             'cat_id'    => $cat_id,
             'cat_title' => $catMap[$cat_id]['title'],
             'color'     => $catMap[$cat_id]['color'],
             'meals'     => $mealitem
           ];
       }
       $result[$dt] = [
           'choices' => $catArr,
           'type'    => isset($data['type'])    ? $data['type']    : '',
           'notice'  => isset($data['notice'])  ? $data['notice']  : '',
           'locked'  => isset($data['locked'])  ? $data['locked']  : '',
           'address' => isset($data['address']) ? $data['address'] : []
       ];
    }
    wp_send_json_success($result);
}

add_action('wp_ajax_delete_user_meal_choice','catering_ajax_delete_user_meal_choice');
function catering_ajax_delete_user_meal_choice(){
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $date     = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
  
    if(!$booking_id || !$date){
         wp_send_json_error('Invalid parameters');
    }

    $booking = new Booking($booking_id);

    if(!$booking){
        wp_send_json_error('Booking not found.');
    }

    $user_id = $booking->user_id;

    // NEW: Check min day before deletion requirement
    global $wpdb;
    $table_options = $wpdb->prefix . 'catering_options';
    $min_val = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM $table_options WHERE option_name=%s",
        'catering_min_day_before_order'
    ));
    $min_days = is_numeric($min_val) ? intval($min_val) : 0;
    // Compare target date with current time
    $now = time();
    $target = strtotime($date);
    if($target === false){
        wp_send_json_error('Invalid date format.');
    }
    
    if( !current_user_can('manage_options') && !is_min_day_requirement_met($date) ){
        wp_send_json_error("Deletion not allowed: must be at least $min_days days (excluding today) in advance.");
    }

    // Proceed to delete if requirement met
    $table = $wpdb->prefix . 'catering_choice';
    $result = $wpdb->delete($table, [
         'booking_id' => $booking_id,
         'user_id'    => $user_id,
         'date'       => $date
    ], ['%d','%d','%s']);
    if($result === false){
         wp_send_json_error('DB error');
    }
    wp_send_json_success('Meal choice deleted');
}

// NEW: Endpoint to get booking health status
add_action('wp_ajax_get_health_status', 'catering_ajax_get_health_status');
function catering_ajax_get_health_status(){
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    if(!$booking_id){
        wp_send_json_error('Invalid booking ID');
    }
    global $wpdb;
    $table = $wpdb->prefix . 'catering_booking';
    $booking = $wpdb->get_row($wpdb->prepare("SELECT health_status FROM $table WHERE ID=%d", $booking_id));
    if(!$booking){
        wp_send_json_error('Booking not found');
    }
    $health_status = maybe_unserialize($booking->health_status);
    wp_send_json_success($health_status);
}

// NEW: Endpoint to update booking health status
add_action('wp_ajax_update_health_status', 'catering_ajax_update_health_status');
function catering_ajax_update_health_status(){
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $health_status = isset($_POST['health_status']) ? $_POST['health_status'] : '';
    if(!$booking_id || empty($health_status)){
        wp_send_json_error('Invalid parameters');
    }
    global $wpdb;
    $table = $wpdb->prefix . 'catering_booking';
    // Update query now retrieves full booking info (including type)
    $booking = $wpdb->get_row($wpdb->prepare("SELECT user_id, type FROM $table WHERE ID=%d", $booking_id));
    if( !$booking || ( $booking->user_id != get_current_user_id() && !current_user_can('manage_options') ) ){
        wp_send_json_error('Booking not found or permission denied');
    }
    $serialized = maybe_serialize($health_status);
    $res = $wpdb->update($table, ['health_status' => $serialized], ['ID' => $booking_id], ['%s'], ['%d']);
    if(false === $res){
        wp_send_json_error('Failed to update health status');
    }
    $message = 'Health status updated.';
    if(isset($health_status['due_date']) && !empty($health_status['due_date'])){
        $new_due_date = $health_status['due_date'];
        $booking_type = strtolower($booking->type);
        // Fetch minimum days setting
        $min_val = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->prefix}catering_options WHERE option_name=%s", 'catering_min_day_before_order'));
        $min_days = is_numeric($min_val) ? intval($min_val) : 0;
        $today = time();
        $alert_dates = [];
        if($booking_type === 'prenatal'){
            // Flag meal choices with date after the new due date
            $choices = $wpdb->get_results($wpdb->prepare("SELECT ID, date FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND date >= %s", $booking_id, $new_due_date), ARRAY_A);
            foreach($choices as $choice){
                $notice = maybe_serialize([
                    'type' => 'warning',
                    'Message' => 'This meal is for customer under prenatal status. It may not suitable for you anymore'
                ]);
                $wpdb->update("{$wpdb->prefix}catering_choice", ['notice' => $notice], ['ID' => $choice['ID']], ['%s'], ['%d']);
                $choice_date = strtotime($choice['date']) + 60*60*8; // Adjust for timezone

                $diff_days = ($choice_date - $today) / 86400;
                if($diff_days < $min_days){
                    $alert_dates[] = $choice['date'];
                } else {
                    $alert_dates[] = $choice['date'];
                }
            }
            if(!empty($alert_dates)){
                $alert_msg = __('There are unsuitable meal choices after the new due date on following dates: ','catering-booking-and-scheduling') .
                             implode(", ", $alert_dates) .
                             '. '. 
                             __('Please delete unsuitable meal(s). Please contact CS Team if some of the meal(s) are not able to delete.','catering-booking-and-scheduling');
                $message .= " " . $alert_msg;
            }
            // Clear notice on meal choices before due date if they have notice
            $clear_choices = $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND date < %s AND notice <> ''", $booking_id, $new_due_date), ARRAY_A);
            foreach($clear_choices as $cc){
                $wpdb->update("{$wpdb->prefix}catering_choice", ['notice' => ''], ['ID' => $cc['ID']], ['%s'], ['%d']);
            }
        } elseif($booking_type === 'postpartum'){
            // Flag meal choices with date before the new due date
            $choices = $wpdb->get_results($wpdb->prepare("SELECT ID, date FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND date < %s", $booking_id, $new_due_date), ARRAY_A);
            foreach($choices as $choice){
                $notice = maybe_serialize([
                    'type' => 'warning',
                    'Message' => 'This meal is for customer under postpartum status. It may not suitable for you anymore'
                ]);
                $wpdb->update("{$wpdb->prefix}catering_choice", ['notice' => $notice], ['ID' => $choice['ID']], ['%s'], ['%d']);
                $choice_date = strtotime($choice['date'])  + 60*60*8; // Adjust for timezone
                $diff_days = ($choice_date - $today) / 86400;
                if($diff_days < $min_days){
                    $alert_dates[] = $choice['date'];
                } else {
                    $alert_dates[] = $choice['date'];
                }
            }
            if(!empty($alert_dates)){
                $alert_msg = __('There are unsuitable meal choices after the new due date on following dates: ','catering-booking-and-scheduling') .
                             implode(", ", $alert_dates) .
                             '. '. 
                             __('Please delete unsuitable meal(s). Please contact CS Team if some of the meal(s) are not able to delete.','catering-booking-and-scheduling');
                $message .= " " . $alert_msg;
            }
            // Clear notice on meal choices after due date if they have notice
            $clear_choices = $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND date > %s AND notice <> ''", $booking_id, $new_due_date), ARRAY_A);
            foreach($clear_choices as $cc){
                $wpdb->update("{$wpdb->prefix}catering_choice", ['notice' => ''], ['ID' => $cc['ID']], ['%s'], ['%d']);
            }
        } elseif($booking_type === 'hybird'){
            // For Hybird: check prenatal meal choices after the new due date
            $choices_prenatal = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, date FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND type=%s AND date >= %s",
                $booking_id, 'prenatal', $new_due_date
            ), ARRAY_A);
            foreach($choices_prenatal as $choice){
                $notice = maybe_serialize([
                    'type'    => 'warning',
                    'Message' => 'This meal is marked as prenatal but its date is after the new due date. It may not be suitable.'
                ]);
                $wpdb->update("{$wpdb->prefix}catering_choice", ['notice' => $notice], ['ID' => $choice['ID']], ['%s'], ['%d']);
                $choice_date = strtotime($choice['date'])  + 60*60*8; // Adjust for timezone
                $diff_days = ($choice_date - $today) / 86400;
                if($diff_days < $min_days){
                    $alert_dates[] = $choice['date'] . " (unable to delete, please contact CS)";
                } else {
                    $alert_dates[] = $choice['date'];
                }
            }
            // Then check postpartum meal choices before the new due date
            $choices_postpartum = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, date FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND type=%s AND date < %s",
                $booking_id, 'postpartum', $new_due_date
            ), ARRAY_A);
            foreach($choices_postpartum as $choice){
                $notice = maybe_serialize([
                    'type'    => 'warning',
                    'Message' => 'This meal is marked as postpartum but its date is before the new due date. It may not be suitable.'
                ]);
                $wpdb->update("{$wpdb->prefix}catering_choice", ['notice' => $notice], ['ID' => $choice['ID']], ['%s'], ['%d']);
                $choice_date = strtotime($choice['date'])  + 60*60*8; // Adjust for timezone
                $diff_days = ($today - $choice_date) / 86400;
                if($diff_days < $min_days){
                    $alert_dates[] = $choice['date'] . " (unable to delete, please contact CS)";
                } else {
                    $alert_dates[] = $choice['date'];
                }
            }
            if(!empty($alert_dates)){
                $alert_msg = __('There are unsuitable meal choices after the new due date on following dates: ','catering-booking-and-scheduling') .
                             implode(", ", $alert_dates) .
                             '. '. 
                             __('Please delete unsuitable meal(s). Please contact CS Team if some of the meal(s) are not able to delete.','catering-booking-and-scheduling');
                $message .= " " . $alert_msg;
            }
            // NEW: Clear valid prenatal meal choices (those before new due date) that have an existing notice
            $clear_prenatal = $wpdb->get_results($wpdb->prepare(
                "SELECT ID FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND type=%s AND date < %s AND notice <> ''",
                $booking_id, 'prenatal', $new_due_date
            ), ARRAY_A);
            foreach($clear_prenatal as $cc){
                $wpdb->update("{$wpdb->prefix}catering_choice", ['notice' => ''], ['ID' => $cc['ID']], ['%s'], ['%d']);
            }
            // NEW: Clear valid postpartum meal choices (those on/after new due date) that have an existing notice
            $clear_postpartum = $wpdb->get_results($wpdb->prepare(
                "SELECT ID FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND type=%s AND date >= %s AND notice <> ''",
                $booking_id, 'postpartum', $new_due_date
            ), ARRAY_A);
            foreach($clear_postpartum as $cc){
                $wpdb->update("{$wpdb->prefix}catering_choice", ['notice' => ''], ['ID' => $cc['ID']], ['%s'], ['%d']);
            }
        }
    }
    wp_send_json_success([
        "alert"   => !empty($alert_dates),
        "message" => $message
    ]);
}

add_action('wp_ajax_search_meals', 'catering_ajax_search_meals');
function catering_ajax_search_meals(){
    if( ! current_user_can('manage_options') ){
        wp_send_json_error('No permission');
    }
    $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    global $wpdb;
    $table = $wpdb->prefix.'catering_meal';
    if(empty($search)){
        $results = [];
    } else {
        $like = '%'.$wpdb->esc_like($search).'%';
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, title, sku FROM $table WHERE title LIKE %s OR CAST(ID AS CHAR) LIKE %s OR sku LIKE %s LIMIT 20", 
                
                $like, $like, $like
            ),
            ARRAY_A
        );
    }
    $formatted = [];
    foreach($results as $row){
        $formatted[] = [
            'id'   => $row['ID'],
            'text' => $row['title']
        ];
    }
    wp_send_json_success($formatted);
}

// NEW: Endpoint to update order item's delivery date
add_action('wp_ajax_update_item_delivery_date', 'catering_ajax_update_item_delivery_date');
function catering_ajax_update_item_delivery_date(){
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error('No permission');
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    $delivery_date = isset($_POST['delivery_date']) ? sanitize_text_field($_POST['delivery_date']) : '';
    if(!$order_item_id || !$delivery_date){
        wp_send_json_error('Missing parameters');
    }
    // Update order item meta (using WooCommerce function)
    if( function_exists('wc_update_order_item_meta') ){
        wc_update_order_item_meta($order_item_id, 'delivery_date', $delivery_date);
        wp_send_json_success();
    }
    wp_send_json_error('Failed to update');
}

// NEW: Endpoint to update order item's tracking number
add_action('wp_ajax_update_item_tracking_number', 'catering_ajax_update_item_tracking_number');
function catering_ajax_update_item_tracking_number(){
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error('No permission');
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    $tracking_number = isset($_POST['tracking_number']) ? sanitize_text_field($_POST['tracking_number']) : '';
    if(!$order_item_id || !$tracking_number){
        wp_send_json_error('Missing parameters');
    }
    if( function_exists('wc_update_order_item_meta') ){
        wc_update_order_item_meta($order_item_id, 'tracking_number', $tracking_number);
        wp_send_json_success();
    }
    wp_send_json_error('Failed to update');
}

// NEW: save CS note for an order item
add_action('wp_ajax_update_item_cs_note','catering_ajax_update_item_cs_note');
function catering_ajax_update_item_cs_note(){
    if(! current_user_can('manage_options') ){
        wp_send_json_error('No permission');
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    $cs_note       = isset($_POST['cs_note'])       ? sanitize_textarea_field($_POST['cs_note']) : '';
    if(! $order_item_id){
        wp_send_json_error('Missing parameters');
    }
    if(function_exists('wc_update_order_item_meta')){
        wc_update_order_item_meta($order_item_id, 'cs_note', $cs_note);
        wp_send_json_success();
    }
    wp_send_json_error('Failed to update CS note');
}

// NEW: Endpoint to delete order item's delivery date
add_action('wp_ajax_delete_item_delivery_date', 'catering_ajax_delete_item_delivery_date');
function catering_ajax_delete_item_delivery_date(){
    if( ! current_user_can('manage_options') ){
        wp_send_json_error('No permission');
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    if(!$order_item_id){
        wp_send_json_error('Missing order item id');
    }
    if(function_exists('wc_update_order_item_meta')){
        wc_update_order_item_meta($order_item_id, 'delivery_date', '');
        wp_send_json_success();
    }
    wp_send_json_error('Failed to delete delivery date');
}

// NEW: Endpoint to delete order item's tracking number
add_action('wp_ajax_delete_item_tracking_number', 'catering_ajax_delete_item_tracking_number');
function catering_ajax_delete_item_tracking_number(){
    if( ! current_user_can('manage_options') ){
        wp_send_json_error('No permission');
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    if(!$order_item_id){
        wp_send_json_error('Missing order item id');
    }
    if(function_exists('wc_update_order_item_meta')){
        wc_update_order_item_meta($order_item_id, 'tracking_number', '');
        wp_send_json_success();
    }
    wp_send_json_error('Failed to delete tracking number');
}

// NEW: delete CS note for an order item
add_action('wp_ajax_delete_item_cs_note','catering_ajax_delete_item_cs_note');
function catering_ajax_delete_item_cs_note(){
    if(! current_user_can('manage_options') ){
        wp_send_json_error('No permission');
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    if(! $order_item_id){
        wp_send_json_error('Missing parameters');
    }
    if(function_exists('wc_update_order_item_meta')){
        wc_update_order_item_meta($order_item_id, 'cs_note', '');
        wp_send_json_success();
    }
    wp_send_json_error('Failed to delete CS note');
}

add_action('wp_ajax_get_daily_meal_choices','catering_ajax_get_daily_meal_choices');
function catering_ajax_get_daily_meal_choices(){
    $dateStr = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    if(! preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateStr) ){
        wp_send_json_error('Invalid date');
    }

    global $wpdb;
    $choice_table   = $wpdb->prefix . 'catering_choice';
    $booking_table  = $wpdb->prefix . 'catering_booking';

    // fetch booking_id + choice for that date
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT booking_id, choice FROM $choice_table WHERE date=%s", $dateStr),
        ARRAY_A
    );

    $counts      = [];
    $booking_map = [];
    foreach($rows as $r){
        $bid = absint($r['booking_id']);
        $arr = maybe_unserialize($r['choice']);
        if(is_array($arr)){
            foreach($arr as $mealIds){
                if(is_array($mealIds)){
                    foreach($mealIds as $mid){
                        $mid = absint($mid);
                        if($mid){
                            $counts[$mid] = ($counts[$mid] ?? 0) + 1;
                            $booking_map[$mid][] = $bid;
                        }
                    }
                }
            }
        }
    }

    if(empty($counts)){
        wp_send_json_success([]);
    }

    ksort($counts);
    $ids = array_keys($counts);
    $ph  = implode(',', array_fill(0, count($ids), '%d'));
    $meals = $wpdb->get_results(
        $wpdb->prepare("SELECT ID,title FROM {$wpdb->prefix}catering_meal WHERE ID IN ($ph)", $ids),
        OBJECT_K
    );

    $data = [];
    foreach($ids as $mid){
        if(!isset($meals[$mid])) {
            continue;
        }
        // collect unique order IDs for this meal
        $booking_ids = [];
        if(!empty($booking_map[$mid])){
            foreach(array_unique($booking_map[$mid]) as $bid){
                $booking_ids[] = $bid;
            }
        }
        $data[] = [
            'id'       => $mid,
            'title'    => $meals[$mid]->title,
            'count'    => $counts[$mid],
            'booking_id' => array_values(array_unique($booking_ids))
        ];
    }

    wp_send_json_success($data);
}

// NEW: Endpoint to gather badge info for bookings
add_action('wp_ajax_get_badge_booking_info','catering_ajax_get_badge_booking_info');
function catering_ajax_get_badge_booking_info(){
    global $wpdb;
    $bids   = isset($_POST['booking_ids']) ? (array)$_POST['booking_ids'] : [];
    $mealId = isset($_POST['meal_id'])     ? absint($_POST['meal_id']) : 0;
    $date   = isset($_POST['date'])        ? sanitize_text_field($_POST['date']) : '';
    $out = [];
    foreach($bids as $bid){
        $bid = absint($bid);
        if(!$bid) continue;
        try {
            $booking = new Booking($bid);
        } catch(Exception $e) {
            continue;
        }
        $oi = $booking->order_item_id;
        $order_id = wc_get_order_id_by_order_item_id($oi);
        $order = wc_get_order($order_id);
        if(!$order) continue;
        // customer & phone
        $cust = trim($order->get_shipping_first_name().' '.$order->get_shipping_last_name());
        $phone= $order->get_shipping_phone();
        // due date
        $hs    = is_array($booking->health_status) ? $booking->health_status : maybe_unserialize($booking->health_status);
        $due   = $hs['due_date'] ?? '';
        // product title
        $title = '';
        foreach($order->get_items() as $item){
            if(intval($item->get_id())== $oi){
                $title = $item->get_name();
                break;
            }
        }
        // count meal occurrences
        $ser = $wpdb->get_var($wpdb->prepare(
            "SELECT choice FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND date=%s",
            $bid, $date
        ));
        $arr = maybe_unserialize($ser);
        $cnt = 0;
        if(is_array($arr)){
            foreach($arr as $grp){
                if(is_array($grp)){
                    foreach($grp as $m) if(intval($m)=== $mealId) $cnt++;
                }
            }
        }
        $out[] = [
            'order_id'  => $order_id,
            'user_id'   => $booking->user_id,
            'customer'  => $cust,
            'due_date'  => $due,
            'phone'     => $phone,
            'product'   => $title,
            'count'     => $cnt
        ];
    }
    wp_send_json_success($out);
}

add_action('wp_ajax_get_daily_delivery_items','catering_ajax_get_daily_delivery_items');
function catering_ajax_get_daily_delivery_items(){
    if(! current_user_can('manage_options') ){
        wp_send_json_error('No permission');
    }
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    if(! preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
        wp_send_json_error('Invalid date');
    }
    global $wpdb;
    $meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
    $items = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT order_item_id FROM $meta WHERE meta_key='delivery_date' AND meta_value=%s",
          $date
        ),
        ARRAY_A
    );
    $grouped = [];
    foreach($items as $row){
        $oi = absint($row['order_item_id']);
        $pid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM $meta WHERE order_item_id=%d AND meta_key=%s",
            $oi, '_product_id'
        ));
        $qty = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM $meta WHERE order_item_id=%d AND meta_key=%s",
            $oi, '_qty'
        ));
        $oi_tbl = $wpdb->prefix . 'woocommerce_order_items';
        $title = $wpdb->get_var($wpdb->prepare(
            "SELECT order_item_name FROM $oi_tbl WHERE order_item_id=%d",
            $oi
        ));
        if(!isset($grouped[$pid])){
            $grouped[$pid] = [
                'order_item_id' => [],
                'product_id'   => $pid,
                'count'        => 0,
                'title'        => $title
            ];
        }
        $grouped[$pid]['order_item_id'][] = $oi;
        $grouped[$pid]['order_item_id'] = array_values(array_unique($grouped[$pid]['order_item_id']));
        $grouped[$pid]['count'] += $qty;
    }
    $data = array_values($grouped);
    wp_send_json_success($data);
}

// NEW: Endpoint to get badge delivery info
add_action('wp_ajax_get_badge_delivery_info','catering_ajax_get_badge_delivery_info');
function catering_ajax_get_badge_delivery_info(){
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('No permission');
    }
    $order_item_input = isset($_POST['order_item_id']) ? sanitize_text_field($_POST['order_item_id']) : '';
    if ( empty($order_item_input) ) {
        wp_send_json_error('Missing parameters');
    }
    // Allow multiple order item IDs separated by comma
    $order_item_ids = array_map('absint', array_filter(explode(',', $order_item_input)));
    if ( empty($order_item_ids) ) {
        wp_send_json_error('Invalid order item IDs');
    }
    if ( ! function_exists('wc_get_order_id_by_order_item_id') ) {
        wp_send_json_error('WooCommerce function missing');
    }
    $results = [];
    foreach($order_item_ids as $oi_id){
        $order_id = wc_get_order_id_by_order_item_id($oi_id);
        if ( ! $order_id ) {
            $results[] = ['error' => "Order not found for order item id {$oi_id}"];
            continue;
        }
        $order = wc_get_order($order_id);
        if ( ! $order ) {
            $results[] = ['error' => "Order not found for order item id {$oi_id}"];
            continue;
        }
        $item = null;
        foreach ($order->get_items() as $order_item) {
            if ($order_item->get_id() == $oi_id) {
                $item = $order_item;
                break;
            }
        }
        if ( ! $item ) {
            $results[] = ['error' => "Order item not found for id {$oi_id}"];
            continue;
        }
        $quantity     = $item->get_quantity();
        $product_name = $item->get_name();
        $customer     = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        $phone        = $order->get_shipping_phone();
        $results[] = [
            'order_id' => $order_id,
            'user_id'  => $order->get_user_id(),
            'customer' => $customer,
            'phone'    => $phone,
            'product'  => $product_name,
            'count'    => $quantity
        ];
    }
    wp_send_json_success($results);
}

