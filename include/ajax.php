<?php

use ICal\ICal; // from the library

/**
 * Helper function to process hybrid tags for type-specific meal schedules
 * Converts tag format from "產前:產前湯|產後:產後湯" to JSON storage format
 */
function process_hybrid_tag($tag, $types = []) {
    // If tag contains ":" it's type-specific format
    if (strpos($tag, ':') !== false) {
        $tag_parts = explode('|', $tag);
        $type_tags = [];
        
        foreach ($tag_parts as $part) {
            if (strpos($part, ':') !== false) {
                list($type, $type_tag) = explode(':', $part, 2);
                $type_tags[trim($type)] = trim($type_tag);
            }
        }
        
        // If we have type-specific tags, return JSON
        if (!empty($type_tags)) {
            return wp_json_encode($type_tags, JSON_UNESCAPED_UNICODE);
        }
    }
    
    // If it's a simple tag, return as-is for backward compatibility
    return $tag;
}

/**
 * Helper function to get display tag based on booking type and date
 * Handles both simple tags and type-specific JSON tags
 */
function get_display_tag($stored_tag, $booking, $date) {
    // Try to decode as JSON first
    $decoded_tag = json_decode($stored_tag, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_tag)) {
        
        // It's a type-specific tag array
        if ($booking->type === 'hybird' && !empty($booking->health_status['due_date'])) {
            $due_date = $booking->health_status['due_date'];
            if ($date < $due_date && isset($decoded_tag['產前'])) {
                return $decoded_tag['產前'];
            } elseif ($date >= $due_date && isset($decoded_tag['產後'])) {
                return $decoded_tag['產後'];
            } else{
                return '';
            }
        }
        
        // For non-hybrid or if specific type not found, return first available
        return reset($decoded_tag);
    }
    
    // It's a simple tag, return as-is
    return $stored_tag;
}

/**
 * Helper function to format tag for admin display
 * Shows type-specific tags in readable format for admin interfaces
 */
function format_tag_for_admin($stored_tag) {
    $decoded_tag = json_decode($stored_tag, true);
    
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_tag)) {
        // Show as "產前:產前湯, 產後:產後湯" for admin
        $parts = [];
        foreach ($decoded_tag as $type => $tag) {
            $parts[] = $type . ':' . $tag;
        }
        return implode(', ', $parts);
    }
    
    return $stored_tag;
}

/**
 * Helper function to format tag for CSV export
 * Converts JSON storage back to CSV format "產前:產前湯|產後:產後湯"
 */
function format_tag_for_export($stored_tag) {
    $decoded_tag = json_decode($stored_tag, true);
    
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_tag)) {
        // Convert back to CSV format: "產前:產前湯|產後:產後湯"
        $tag_parts = [];
        foreach ($decoded_tag as $type => $type_tag) {
            $tag_parts[] = $type . ':' . $type_tag;
        }
        return implode('|', $tag_parts);
    }
    
    // Simple tag
    return $stored_tag;
}

add_action('wp_ajax_catering_load_address',     'catering_load_address');
add_action('wp_ajax_nopriv_catering_load_address','catering_load_address');
function catering_load_address() {
    $addr = sanitize_text_field($_POST['addr'] ?? '');
    $uid  = get_current_user_id();
    $fields = [ 'first_name','last_name','company','address_1','address_2','city','state','postcode','country','phone','remarks' ];
    $out = [];
    foreach ($fields as $f) {
        $meta = get_user_meta($uid, $addr . '_' . $f, true);
        if ($meta) {
            $out[ $f ] = $meta;
        }
    }
    wp_send_json_success($out);
}

add_action( 'wp_ajax_catering_search_categories', 'catering_search_categories' );
function catering_search_categories() {
    global $wpdb;

    // Check user capabilities if needed
    if ( ! current_user_can( 'manage_catering' ) ) {
        wp_send_json_error( __('No permission', 'catering-booking-and-scheduling') );
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
       wp_send_json_error(__('No meals or terms selected.', 'catering-booking-and-scheduling'));
    }
    if ( $termType!=='category' && $termType!=='tag' ) {
       wp_send_json_error(__('Invalid termType', 'catering-booking-and-scheduling'));
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

    wp_send_json_success(__('Set terms done.', 'catering-booking-and-scheduling'));
}

add_action('wp_ajax_bulk_edit_add_terms','my_ajax_bulk_edit_add_terms');
function my_ajax_bulk_edit_add_terms(){

    $termType = isset($_POST['termType']) ? sanitize_text_field($_POST['termType']) : '';
    $mealIDs  = isset($_POST['mealIDs']) ? array_map('intval', (array)$_POST['mealIDs']) : [];
    $chosen   = isset($_POST['chosenTermIDs']) ? array_map('intval', (array)$_POST['chosenTermIDs']) : [];

    if (empty($mealIDs) || empty($chosen)) {
       wp_send_json_error(__('No meals or terms selected.', 'catering-booking-and-scheduling'));
    }
    if ( $termType!=='category' && $termType!=='tag' ) {
       wp_send_json_error(__('Invalid termType', 'catering-booking-and-scheduling'));
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

    wp_send_json_success(__('Add terms done.', 'catering-booking-and-scheduling'));
}

add_action('wp_ajax_get_all_terms','my_ajax_get_all_terms');
function my_ajax_get_all_terms(){

    $termType = isset($_POST['termType']) ? sanitize_text_field($_POST['termType']) : '';
    if ( $termType !== 'category' && $termType !== 'tag' ) {
        wp_send_json_error(__('Invalid termType', 'catering-booking-and-scheduling'));
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
        wp_send_json_error(__('No iCal file uploaded or upload error.', 'catering-booking-and-scheduling'));
    }
    $tmpName = $_FILES['ical_file']['tmp_name'];
    if (!file_exists($tmpName)) {
        wp_send_json_error(__('Uploaded file not found on server.', 'catering-booking-and-scheduling'));
    }

    // parse
    $events = my_parse_ical_file($tmpName);
    // $events is an array of { date:'YYYY-MM-DD', name:'Event Title'}

    if(empty($events)){
        wp_send_json_error(__('No valid events found in iCal file.', 'catering-booking-and-scheduling'));
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
add_action('wp_ajax_nopriv_get_holiday_data', 'catering_ajax_get_holiday_data');
function catering_ajax_get_holiday_data(){

    $year  = isset($_POST['year']) ? absint($_POST['year']) : 0;
    $month = isset($_POST['month'])? absint($_POST['month']): 0;
    if($year<2000 || $year>2100 || $month<1 || $month>12){
        wp_send_json_error(__('Invalid year/month', 'catering-booking-and-scheduling'));
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
        wp_send_json_error(__('No changes_json provided', 'catering-booking-and-scheduling'));
    }

    // JSON decode
    $changes_str = stripslashes($_POST['changes_json']); // remove escape slashes
    $changes = json_decode($changes_str, true);

    if(!is_array($changes)){
        wp_send_json_error(__('Invalid JSON for changes', 'catering-booking-and-scheduling'));
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
        wp_send_json_error(__('No events array provided', 'catering-booking-and-scheduling'));
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

    wp_send_json_success(__('All iCal events set as holiday', 'catering-booking-and-scheduling'));
}

add_action('wp_ajax_update_term_color','my_update_term_color');
function my_update_term_color(){
    if(!current_user_can('manage_catering')){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
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
        wp_send_json_error(__('DB error or invalid term_id', 'catering-booking-and-scheduling'));
    }
    wp_send_json_success();
}

add_action('wp_ajax_update_category_ordering','my_update_category_ordering');
function my_update_category_ordering(){
    if(!current_user_can('manage_catering')){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    global $wpdb;
    $table = $wpdb->prefix.'catering_terms';

    $json = isset($_POST['new_order']) ? stripslashes($_POST['new_order']) : '';
    $arr  = json_decode($json, true);
    if(!is_array($arr)){
        wp_send_json_error(__('Invalid data', 'catering-booking-and-scheduling'));
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
    if( ! current_user_can('manage_catering') ){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $dates = isset($_POST['dates']) && is_array($_POST['dates']) ? $_POST['dates'] : [];
    if( ! $product_id || empty($dates) ){
        wp_send_json_error(__('Invalid parameters', 'catering-booking-and-scheduling'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'catering_schedule';
    // Build dynamic placeholders for dates array.
    $placeholders = implode(',', array_fill(0, count($dates), '%s'));
    $query = "DELETE FROM $table WHERE product_id = %d AND date IN ($placeholders)";
    $params = array_merge([$product_id], $dates);
    $result = $wpdb->query($wpdb->prepare($query, $params));
    if($result === false){
        wp_send_json_error(__('DB error', 'catering-booking-and-scheduling'));
    }
    wp_send_json_success(__('Schedule cleared', 'catering-booking-and-scheduling'));
}

add_action('wp_ajax_check_catering_schedule', 'catering_ajax_check_catering_schedule');
function catering_ajax_check_catering_schedule(){
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $unique_dates = isset($_POST['unique_dates']) && is_array($_POST['unique_dates']) ? array_map('sanitize_text_field', $_POST['unique_dates']) : [];
    if(!$product_id || empty($unique_dates)){
        wp_send_json_error(__('Invalid parameters', 'catering-booking-and-scheduling'));
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
    if(!current_user_can('manage_catering')){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    if(! isset($_POST['meals']) || ! is_array($_POST['meals']) ){
        wp_send_json_error(__('Invalid meals data', 'catering-booking-and-scheduling'));
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
    if(! current_user_can('manage_catering') ){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
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
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . __('Missing parameters', 'catering-booking-and-scheduling') );
    }
    // validate date format
    if( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str) ){
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . __('Invalid date', 'catering-booking-and-scheduling') );
    }
    global $wpdb;
    // verify meal exists
    $exists = $wpdb->get_var( $wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->prefix}catering_meal WHERE ID=%d
    ", $meal_id) );
    if( ! $exists ){
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . __('Meal ID not found', 'catering-booking-and-scheduling') );
    }
    // Skip if schedule entry already exists
    $dup = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}catering_schedule
         WHERE product_id=%d AND meal_id=%d AND date=%s",
        $post_id, $meal_id, $date_str
    ) );
    if( $dup ) {
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . sprintf(__("Duplicate meal on %s", 'catering-booking-and-scheduling'), $date_str) );
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
                wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . sprintf(__("Category '%s' not found", 'catering-booking-and-scheduling'), $c) );
            }
            $cat_ids[] = $map[$c];
        }
    } else {
        $cat_ids = [];
    }
    $serialized_cats = maybe_serialize($cat_ids);
    
    // Process tag using helper function for hybrid type-specific tags
    $processed_tag = process_hybrid_tag($tag, $types);
    
    // insert into schedule
    $ok = $wpdb->insert(
        "{$wpdb->prefix}catering_schedule",
        [
          'meal_id'    => $meal_id,
          'product_id' => $post_id,
          'date'       => $date_str,
          'cat_id'     => $serialized_cats,
          'tag'        => $processed_tag,
          'type'       => $serialized_types
        ],
        ['%d','%d','%s','%s','%s','%s']
    );
    if( false === $ok ){
        wp_send_json_error( ($rownum?"Row {$rownum}: ":"") . __('DB insert failed', 'catering-booking-and-scheduling') );
    }
    wp_send_json_success();
}

add_action('wp_ajax_get_meal_schedule_week','catering_ajax_get_meal_schedule_week');
add_action('wp_ajax_nopriv_get_meal_schedule_week','catering_ajax_get_meal_schedule_week');
function catering_ajax_get_meal_schedule_week(){
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $start = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end   = isset($_POST['end_date'])   ? sanitize_text_field($_POST['end_date'])   : '';
    // NEW: obtain booking_id to calculate day remaining
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    // NEW: check if this is a schedule preview request
    $is_preview = isset($_POST['is_preview']) && $_POST['is_preview'];
    
    if(!$product_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)){
        wp_send_json_error(__('Invalid parameters', 'catering-booking-and-scheduling'));
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
    
    // NEW: Get product type to determine if filtering is needed
    $product_type = '';
    if($is_preview) {
        $product_type = get_post_meta($product_id, 'catering_type', true);
    }
    
    // 2) fetch schedule rows - include type field for filtering
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT date, meal_id, cat_id, tag, type
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
        
        // NEW: Filter meals by type for hybrid plan preview
        if($is_preview && $product_type === 'hybird') {
            $meal_types = maybe_unserialize($r['type']);
            if(!is_array($meal_types)) $meal_types = $meal_types ? [$meal_types] : [];
            
            // Only show meals marked as '產前' (prenatal) for hybrid plan preview
            // Also include meals with no type specified (universal meals)
            if(!empty($meal_types) && !in_array('產前', $meal_types)) {
                continue; // Skip this meal if it's not marked as prenatal
            }
        }
        
        foreach($cat_ids as $cid){
            if(!isset($term_map[$cid])) continue;
            
            // For admin view, show formatted tags (type-specific tags visible)
            // For preview, show appropriate tag based on context
            if($is_preview && $product_type === 'hybird') {
                // For hybrid preview, show prenatal-specific tag if available
                $decoded_tag = json_decode($r['tag'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_tag) && isset($decoded_tag['產前'])) {
                    $display_tag = $decoded_tag['產前'];
                } else {
                    $display_tag = $r['tag']; // Fallback to original tag
                }
            } elseif($is_preview) {
                $display_tag = $r['tag']; // Use simple tag for other previews
            } else {
                $display_tag = format_tag_for_admin($r['tag']); // Use formatted tag for admin
            }
            
            $data[$date][$cid][] = [
                'id'    => (int)$r['meal_id'],
                'title' => isset($meals[$r['meal_id']]) ? $meals[$r['meal_id']]->title : '',
                'tag'   => $display_tag
            ];
        }
    }
    // NEW: calculate day remaining if booking_id provided
    $day_remaining = null;
    if($booking_id){
        $booking = new Booking($booking_id);
        if($booking){
            $day_remaining = $booking->get_remaining_days();
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
    if(! current_user_can('manage_catering') ) wp_die('No permission');
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
        
        // Process tag for export using helper function
        $tag_display = format_tag_for_export($r['tag']);
        
        fputcsv($out, [
            $r['meal_id'],
            $meal ? $meal->sku : '',
            $meal ? $meal->title : '',
            implode('|',$titles),
            is_array($type) ? implode('|',$type) : $type,
            $r['date'],
            $tag_display
        ]);
    }
    fclose($out);
    exit;
}

add_action('wp_ajax_catering_import_csv_row','catering_ajax_import_csv_row');
function catering_ajax_import_csv_row(){
    if(! current_user_can('manage_catering')) {
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
    if (! current_user_can('manage_catering')) {
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $date       = isset($_POST['date'])       ? sanitize_text_field($_POST['date']) : '';
    if (!$product_id || ! preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
        wp_send_json_error(__('Invalid parameters', 'catering-booking-and-scheduling'));
    }
    global $wpdb;
    $table  = $wpdb->prefix . 'catering_schedule';
    $deleted = $wpdb->delete( $table, [ 'product_id'=>$product_id, 'date'=>$date ], [ '%d','%s' ] );
    if ( false === $deleted ) {
        wp_send_json_error(__('DB error', 'catering-booking-and-scheduling'));
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
        wp_send_json_error(__('Invalid parameters', 'catering-booking-and-scheduling'));
    }
    
    // New: Check minimum day requirement
    if( !current_user_can('manage_catering') && !is_min_day_requirement_met($date) ){
        wp_send_json_error(__('Your selected date does not meet the minimum advance order requirement.', 'catering-booking-and-scheduling'));
    }

    $booking    = new Booking($booking_id);
    if(!$booking){
        wp_send_json_error(__('Booking not found.', 'catering-booking-and-scheduling'));
    }

    if(!current_user_can('manage_catering') && !empty($booking->expiry) ){
        // calculate plan expiry date based on first choice

        if( $booking->is_date_expired($date) ) {
            wp_send_json_error(sprintf(__('Your plan has expired on this day (%s).', 'catering-booking-and-scheduling'), $date));
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
        $current_day = $booking->get_current_day_compare_to_plan_days($date);

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
            // Use helper function to get appropriate tag based on booking type and date
            $display_tag = get_display_tag($r['tag'], $booking, $date);
            $tag_map[$cid][$r['meal_id']] = $display_tag;
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
        $booking = new Booking($booking_id);
        if($booking && function_exists('wc_get_order_id_by_order_item_id')){
            $order_id = wc_get_order_id_by_order_item_id($booking->order_item_id);
            if($order_id){
                $order = wc_get_order($order_id);
                if($order){
                    $address = [
                        'first_name'     => $order->get_shipping_first_name(),
                        'last_name'      => $order->get_shipping_last_name(),
                        'address'        => $order->get_shipping_address_1(),
                        'city'           => $order->get_shipping_city(),
                        'phone_country'  => $order->get_meta('_shipping_phone_country') ?? '',
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
    if(current_user_can('manage_catering')){
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
    if(current_user_can('manage_catering')){
        wp_send_json_success();
    }
    $current_user = get_current_user_id();
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    if(!$booking_id){
         wp_send_json_error(__('Invalid booking ID.', 'catering-booking-and-scheduling'));
    }
    $booking = new Booking($booking_id);
    if(!$booking){
         wp_send_json_error(__('Booking not found.', 'catering-booking-and-scheduling'));
    }
    if($booking->user_id != $current_user){
         wp_send_json_error(__('Booking does not belong to current user.', 'catering-booking-and-scheduling'));
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
        wp_send_json_error(__('Booking not found.', 'catering-booking-and-scheduling'));
    }

    $user_id = $booking->user_id;

    global $wpdb;

    // NEW: grab preference array
    $preference = isset($_POST['preference']) && is_array($_POST['preference'])
                  ? $_POST['preference'] : [];
    
    if(!$booking_id || !$user_id || !$date || empty($choice) || empty($address) ){
        wp_send_json_error(__('Missing or invalid parameters.', 'catering-booking-and-scheduling'));
    }

    // --- address validation ---
    $first = sanitize_text_field($address['first_name'] ?? '');
    $last  = sanitize_text_field($address['last_name']  ?? '');
    $adr   = sanitize_text_field($address['address']    ?? '');
    $city  = sanitize_text_field($address['city']       ?? '');
    $phone = sanitize_text_field($address['phone']      ?? '');
    $phone_country = sanitize_text_field($address['phone_country'] ?? '+852');

    $allowed_cities = [
        '灣仔區','東區','中西區','南區','北區','觀塘區','油尖旺區',
        '黃大仙區','深水埗區','九龍城區','荃灣區','離島區','葵青區',
        '西貢區','沙田區','元朗區','屯門區','大埔區'
    ];
    
    // Updated phone validation with country code support
    function validate_phone_with_country($phone, $country_code) {
        // Remove any spaces, dashes, or brackets
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
        
        switch ($country_code) {
            case '+852': // Hong Kong
                return preg_match('/^[4569]\d{7}$/', $phone);
            case '+853': // Macau
                return preg_match('/^6\d{7}$/', $phone);
            case '+86': // China
                return preg_match('/^1[3456789]\d{9}$/', $phone);
            default:
                return false;
        }
    }
    
    $name_re  = '/^\D+$/';

    if (!$first) {
        wp_send_json_error(__('First name is required.', 'catering-booking-and-scheduling'));
    }
    if (!preg_match($name_re, $first)) {
        wp_send_json_error(__('First name cannot contain numbers.', 'catering-booking-and-scheduling'));
    }
    if (!$last) {
        wp_send_json_error(__('Last name is required.', 'catering-booking-and-scheduling'));
    }
    if (!preg_match($name_re, $last)) {
        wp_send_json_error(__('Last name cannot contain numbers.', 'catering-booking-and-scheduling'));
    }
    if (!$adr) {
        wp_send_json_error(__('Address is required.', 'catering-booking-and-scheduling'));
    }
    if (!$city) {
        wp_send_json_error(__('City is required.', 'catering-booking-and-scheduling'));
    }
    if (!in_array($city, $allowed_cities, true)) {
        wp_send_json_error(__('Please select a valid city.', 'catering-booking-and-scheduling'));
    }
    if (!$phone) {
        wp_send_json_error(__('Phone is required.', 'catering-booking-and-scheduling'));
    }
    if (!validate_phone_with_country($phone, $phone_country)) {
        switch ($phone_country) {
            case '+852':
                wp_send_json_error(__('Please enter a valid Hong Kong mobile number (8 digits starting with 4, 5, 6, or 9).', 'catering-booking-and-scheduling'));
                break;
            case '+853':
                wp_send_json_error(__('Please enter a valid Macau mobile number (8 digits starting with 6).', 'catering-booking-and-scheduling'));
                break;
            case '+86':
                wp_send_json_error(__('Please enter a valid China mobile number (11 digits starting with 1).', 'catering-booking-and-scheduling'));
                break;
            default:
                wp_send_json_error(__('Please enter a valid mobile number.', 'catering-booking-and-scheduling'));
        }
    }
    // --- end address validation ---

    if( ! current_user_can('manage_catering') && ! is_min_day_requirement_met($date) ){
        wp_send_json_error(__('Your selected date does not meet the minimum advance order requirement.', 'catering-booking-and-scheduling'));
    }
    
    // NEW VALIDATION: Check that the number of meals selected per category matches the expected cat_qty.
    if(!current_user_can('manage_catering')){
        $expected = maybe_unserialize($booking->cat_qty);
        if(is_array($expected)) {
            foreach($expected as $cat_id => $requiredCount) {
                $selectedCount = isset($choice[$cat_id]) ? count($choice[$cat_id]) : 0;
                if($selectedCount !== (int)$requiredCount) {
                    // Retrieve category title from the database
                    $term = $wpdb->get_row($wpdb->prepare("SELECT title FROM {$wpdb->prefix}catering_terms WHERE ID=%d", $cat_id));
                    $cat_title = $term ? $term->title : $cat_id;
                    wp_send_json_error(sprintf(__("Please select exactly %d meal(s) for category %s.", 'catering-booking-and-scheduling'), $requiredCount, $cat_title));
                }
            }
        }
    }

    if(!current_user_can('manage_catering') && !empty($booking->expiry) ){
        // calculate plan expiry date based on first choice

        if( $booking->is_date_expired($date) ) {
            wp_send_json_error(sprintf(__('Your plan has expired on this day (%s).', 'catering-booking-and-scheduling'), $date));
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
    
    // Get previous choice for logging (if updating)
    $previous_choice_data = null;
    if($existing) {
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT choice FROM $table_choice WHERE booking_id=%d AND user_id=%d AND date=%s",
            $booking_id, $user_id, $date
        ));
        $previous_choice_data = $prev ? $prev->choice : null;
    }
    // If no entry exists, it's a new submission; check total count
    if(!$existing){
        $total_choices = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_choice WHERE booking_id=%d AND user_id=%d",
            $booking_id, $user_id
        ));
        if( !current_user_can('manage_catering') && ($total_choices >= $plan_days) ){
            wp_send_json_error(__('No remaining days on your plan.', 'catering-booking-and-scheduling'));
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
        if(current_user_can('manage_catering')){
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
        if(current_user_can('manage_catering')){
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
         wp_send_json_error(__('DB insert/update failed.', 'catering-booking-and-scheduling'));
    }
    
    // Log the change to catering_log
    if($res !== false) {
        $action_type = $existing ? 'meal_choice_update' : 'initial_choice';
        $change_reason = current_user_can('manage_catering') ? 'Admin modification' : 'Customer selection';
        
        log_meal_choice_change(
            $booking_id,
            $date,
            $previous_choice_data,
            $serialized_choice,
            get_current_user_id(),
            $action_type,
            $change_reason
        );
    }
    
    wp_send_json_success(__('User choice saved.', 'catering-booking-and-scheduling'));
}

add_action('wp_ajax_get_user_meal_choices_range','catering_ajax_get_user_meal_choices_range');
function catering_ajax_get_user_meal_choices_range(){
    global $wpdb;
    $table_choice = $wpdb->prefix . 'catering_choice';

    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $start      = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end        = isset($_POST['end_date'])   ? sanitize_text_field($_POST['end_date'])   : '';

    if(!$booking_id || !$start || !$end){
         wp_send_json_error(__('Invalid parameters.', 'catering-booking-and-scheduling'));
    }
    
    $booking    = new Booking($booking_id);
    
    if(!$booking){
        wp_send_json_error(__('Booking not found.', 'catering-booking-and-scheduling'));
    }
    $user_id = $booking->user_id;
    
    // fetch date, choice, type, notice, locked, address AND preference
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT date, choice, type, notice, locked, address, preference
       FROM $table_choice
       WHERE booking_id=%d AND user_id=%d AND date BETWEEN %s AND %s",
      $booking_id, $user_id, $start, $end
    ), ARRAY_A);

    $choicesByDate = [];
    foreach($rows as $r){
        $dt = $r['date'];
        $choicesByDate[$dt] = [
            'choice'     => maybe_unserialize($r['choice']),
            'type'       => $r['type'],
            'notice'     => maybe_unserialize($r['notice']),
            'locked'     => $r['locked'],
            'address'    => maybe_unserialize($r['address']),
            'preference' => maybe_unserialize($r['preference'])
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
           'choices'    => $catArr,
           'type'       => isset($data['type'])       ? $data['type']       : '',
           'notice'     => isset($data['notice'])     ? $data['notice']     : '',
           'locked'     => isset($data['locked'])     ? $data['locked']     : '',
           'address'    => isset($data['address'])    ? $data['address']    : [],
           'preference' => isset($data['preference']) ? $data['preference'] : []
       ];
    }
    wp_send_json_success($result);
}

add_action('wp_ajax_delete_user_meal_choice','catering_ajax_delete_user_meal_choice');
function catering_ajax_delete_user_meal_choice(){
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $date     = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
  
    if(!$booking_id || !$date){
         wp_send_json_error(__('Invalid parameters', 'catering-booking-and-scheduling'));
    }

    $booking = new Booking($booking_id);

    if(!$booking){
        wp_send_json_error(__('Booking not found.', 'catering-booking-and-scheduling'));
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
        wp_send_json_error(__('Invalid date format.', 'catering-booking-and-scheduling'));
    }
    
    if( !current_user_can('manage_catering') && !is_min_day_requirement_met($date) ){
        wp_send_json_error("Deletion not allowed: must be at least $min_days days (excluding today) in advance.");
    }

    // Proceed to delete if requirement met
    $table = $wpdb->prefix . 'catering_choice';
    
    // Get current choice for logging before deletion
    $current_choice = $wpdb->get_var($wpdb->prepare(
        "SELECT choice FROM $table WHERE booking_id=%d AND user_id=%d AND date=%s",
        $booking_id, $user_id, $date
    ));
    
    $result = $wpdb->delete($table, [
         'booking_id' => $booking_id,
         'user_id'    => $user_id,
         'date'       => $date
    ], ['%d','%d','%s']);
    if($result === false){
         wp_send_json_error(__('DB error', 'catering-booking-and-scheduling'));
    }
    
    // Log the deletion to catering_log
    if($result !== false && $result > 0) {
        log_meal_choice_change(
            $booking_id,
            $date,
            $current_choice,
            null, // new_choice is null for deletion
            get_current_user_id(),
            'meal_choice_deletion',
            'Choice deleted by ' . (current_user_can('manage_catering') ? 'admin' : 'customer')
        );
    }
    
    wp_send_json_success(__('Meal choice deleted', 'catering-booking-and-scheduling'));
}

// NEW: Endpoint to get booking health status
add_action('wp_ajax_get_health_status', 'catering_ajax_get_health_status');
function catering_ajax_get_health_status(){
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    if(!$booking_id){
        wp_send_json_error(__('Invalid booking ID', 'catering-booking-and-scheduling'));
    }
    $booking = new Booking($booking_id);
    if(!$booking){
        wp_send_json_error(__('Booking not found', 'catering-booking-and-scheduling'));
    }
    $health_status = maybe_unserialize($booking->health_status);
    wp_send_json_success($health_status);
}

// NEW: Endpoint to update booking health status
add_action('wp_ajax_update_health_status', 'catering_ajax_update_health_status');
function catering_ajax_update_health_status(){
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $health_status = isset($_POST['health_status']) ? $_POST['health_status'] : '';
    $auto_delete_confirmed = isset($_POST['auto_delete_confirmed']) ? filter_var($_POST['auto_delete_confirmed'], FILTER_VALIDATE_BOOLEAN) : false;
    
    if(!$booking_id || empty($health_status)){
        wp_send_json_error(__('Invalid parameters', 'catering-booking-and-scheduling'));
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'catering_booking';
    
    $booking = new Booking($booking_id);
    if( !$booking || ( $booking->user_id != get_current_user_id() && !current_user_can('manage_catering') ) ){
        wp_send_json_error(__('Booking not found or permission denied', 'catering-booking-and-scheduling'));
    }
    
    // NEW: Enhanced unsuitable meal checking with min day requirements
    if(isset($health_status['due_date']) && !empty($health_status['due_date'])){
        $new_due_date = $health_status['due_date'];
        $booking_type = strtolower($booking->type);
        $unsuitable_dates = [];
        
        if($booking_type === 'prenatal'){
            // Check meal choices with date after the new due date
            $choices = $wpdb->get_results($wpdb->prepare("SELECT date FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND date >= %s", $booking_id, $new_due_date), ARRAY_A);
            foreach($choices as $choice){
                $unsuitable_dates[] = $choice['date'];
            }
        } elseif($booking_type === 'postpartum'){
            // Check meal choices with date before the new due date
            $choices = $wpdb->get_results($wpdb->prepare("SELECT date FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND date < %s", $booking_id, $new_due_date), ARRAY_A);
            foreach($choices as $choice){
                $unsuitable_dates[] = $choice['date'];
            }
        } elseif($booking_type === 'hybird'){
            // Check prenatal meal choices after the new due date
            $choices_prenatal = $wpdb->get_results($wpdb->prepare(
                "SELECT date FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND type=%s AND date >= %s",
                $booking_id, 'prenatal', $new_due_date
            ), ARRAY_A);
            foreach($choices_prenatal as $choice){
                $unsuitable_dates[] = $choice['date'];
            }
            
            // Check postpartum meal choices before the new due date
            $choices_postpartum = $wpdb->get_results($wpdb->prepare(
                "SELECT date FROM {$wpdb->prefix}catering_choice WHERE booking_id=%d AND type=%s AND date < %s",
                $booking_id, 'postpartum', $new_due_date
            ), ARRAY_A);
            foreach($choices_postpartum as $choice){
                $unsuitable_dates[] = $choice['date'];
            }
        }
        
        // If there are unsuitable meals, check which ones can be auto-deleted
        if(!empty($unsuitable_dates)){
            $restricted_dates = [];
            $deletable_dates = [];
            
            // Categorize unsuitable dates based on min day requirement
            foreach($unsuitable_dates as $date){
                $clean_date = str_replace([' (prenatal)', ' (postpartum)'], '', $date);
                if(!is_min_day_requirement_met($clean_date)){
                    // Date is within restriction period, cannot be deleted
                    $restricted_dates[] = $date;
                } else {
                    // Date is outside restriction period, can be deleted
                    $deletable_dates[] = $date;
                }
            }
            
            // If there are restricted dates, prevent the update completely
            if(!empty($restricted_dates)){
                // Get customer and order info for email
                $order_item = $booking->get_order_item();
                $order = $order_item ? wc_get_order($order_item->get_order_id()) : null;
                $customer_name = '';
                $order_number = '';
                
                if($order){
                    $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                    $order_number = $order->get_order_number();
                }
                
                // Send email to admin
                catering_send_unsuitable_meal_alert($booking_id, $customer_name, $order_number, $new_due_date, $restricted_dates);
                
                // Return error with specific message
                wp_send_json_error(
                    sprintf(
                        __('Update fail: The new due date will make following date of your meal choices unsuitable: %s. We are not able to delete those meal choices as time constraint. Please contact our CS team as soon as possible for assistance with this change.', 'catering-booking-and-scheduling'),
                        implode(' | ', $restricted_dates)
                    )
                );
            }
            
            // If there are only deletable dates, ask for confirmation or proceed with deletion
            if(!empty($deletable_dates)){
                if(!$auto_delete_confirmed){
                    // Return confirmation request with details
                    wp_send_json([
                        'success' => false,
                        'requires_confirmation' => true,
                        'message' => __('The new due date will make some meal choices unsuitable. These meals can be automatically deleted as they are outside the minimum advance order period.', 'catering-booking-and-scheduling'),
                        'deletable_dates' => $deletable_dates,
                        'confirmation_message' => sprintf(
                            __('Warning: The new due date will make some of your meal choices unsuitable. We must delete the following meal choices before amending your due date: %s. Do you want to continue?', 'catering-booking-and-scheduling'),
                            implode(' | ', $deletable_dates)
                        )
                    ]);
                } else {
                    // User confirmed, proceed with deletion
                    $choice_table = $wpdb->prefix . 'catering_choice';
                    $log_table = $wpdb->prefix . 'catering_log';
                    
                    foreach($deletable_dates as $date){
                        $clean_date = str_replace([' (prenatal)', ' (postpartum)'], '', $date);
                        
                        // Get current choice for logging before deletion
                        $current_choice = $wpdb->get_var($wpdb->prepare(
                            "SELECT choice FROM $choice_table WHERE booking_id=%d AND user_id=%d AND date=%s",
                            $booking_id, $booking->user_id, $clean_date
                        ));
                        
                        // Delete the choice
                        $deleted = $wpdb->delete($choice_table, [
                            'booking_id' => $booking_id,
                            'user_id'    => $booking->user_id,
                            'date'       => $clean_date
                        ], ['%d','%d','%s']);
                        
                        // Log the deletion
                        if($deleted !== false && $deleted > 0) {
                            $wpdb->insert($log_table, [
                                'booking_id'           => $booking_id,
                                'choice_date'          => $clean_date,
                                'previous_choice'      => $current_choice ?: '',
                                'new_choice'           => '',
                                'changed_by_user_id'   => 0, // System user
                                'changed_by_user_type' => 'system',
                                'change_reason'        => 'unsuitable meal auto delete',
                                'action_type'          => 'meal_choice_delete',
                                'amended_time'         => current_time('mysql')
                            ], ['%d','%s','%s','%s','%d','%s','%s','%s','%s']);
                        }
                    }
                }
            }
        }
    }
    
    // If we reach here, either no unsuitable meals found or user confirmed deletion
    // Proceed with health status update
    $serialized = maybe_serialize($health_status);
    $res = $booking->set('health_status', $serialized);
    if( !$res ){
        wp_send_json_error(__('Failed to update health status', 'catering-booking-and-scheduling'));
    }
    
    // Update order item meta if due date is provided
    if(isset($health_status['due_date']) && !empty($health_status['due_date'])){
        $order_item = $booking->get_order_item();
        if($order_item){
            $order_item->add_meta_data( 'due_date', $health_status['due_date'] , true );
            $order_item->save();
        }
    }
    
    $message = __('Health status updated successfully.', 'catering-booking-and-scheduling');
    if(isset($deletable_dates) && !empty($deletable_dates) && $auto_delete_confirmed){
        $message .= ' ' . sprintf(__('%d unsuitable meal choices have been automatically deleted.', 'catering-booking-and-scheduling'), count($deletable_dates));
    }
    
    wp_send_json_success([
        "message" => $message
    ]);
}

add_action('wp_ajax_search_meals', 'catering_ajax_search_meals');
function catering_ajax_search_meals(){
    if( ! current_user_can('manage_catering') ){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
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
    if ( ! current_user_can( 'manage_catering' ) ) {
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    $delivery_date = isset($_POST['delivery_date']) ? sanitize_text_field($_POST['delivery_date']) : '';
    if(!$order_item_id || !$delivery_date){
        wp_send_json_error(__('Missing parameters', 'catering-booking-and-scheduling'));
    }
    // Update order item meta (using WooCommerce function)
    if( function_exists('wc_update_order_item_meta') ){
        wc_update_order_item_meta($order_item_id, 'delivery_date', $delivery_date);
        wp_send_json_success();
    }
    wp_send_json_error(__('Failed to update', 'catering-booking-and-scheduling'));
}

// NEW: Endpoint to update order item's due date
add_action('wp_ajax_update_item_due_date', 'catering_ajax_update_item_due_date');
function catering_ajax_update_item_due_date(){
    if ( ! current_user_can( 'manage_catering' ) ) {
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    $due_date = isset($_POST['due_date']) ? sanitize_text_field($_POST['due_date']) : '';
    if(!$order_item_id || !$due_date){
        wp_send_json_error(__('Missing parameters', 'catering-booking-and-scheduling'));
    }
    // Update order item meta (using WooCommerce function)
    if( function_exists('wc_update_order_item_meta') ){
        wc_update_order_item_meta($order_item_id, 'due_date', $due_date);
        wc_delete_order_item_meta($order_item_id, '_alg_wc_pif_local'); 
        wp_send_json_success();
    }
    wp_send_json_error(__('Failed to update', 'catering-booking-and-scheduling'));
}

// NEW: Endpoint to update order item's tracking number
add_action('wp_ajax_update_item_tracking_number', 'catering_ajax_update_item_tracking_number');
function catering_ajax_update_item_tracking_number(){
    if ( ! current_user_can( 'manage_catering' ) ) {
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    $tracking_number = isset($_POST['tracking_number']) ? sanitize_text_field($_POST['tracking_number']) : '';
    if(!$order_item_id || !$tracking_number){
        wp_send_json_error(__('Missing parameters', 'catering-booking-and-scheduling'));
    }
    if( function_exists('wc_update_order_item_meta') ){
        wc_update_order_item_meta($order_item_id, 'tracking_number', $tracking_number);
        wp_send_json_success();
    }
    wp_send_json_error(__('Failed to update', 'catering-booking-and-scheduling'));
}

// NEW: save CS note for an order item
add_action('wp_ajax_update_item_cs_note','catering_ajax_update_item_cs_note');
function catering_ajax_update_item_cs_note(){
    if(! current_user_can('manage_catering') ){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    $cs_note       = isset($_POST['cs_note'])       ? sanitize_textarea_field($_POST['cs_note']) : '';
    if(! $order_item_id){
        wp_send_json_error(__('Missing parameters', 'catering-booking-and-scheduling'));
    }
    if(function_exists('wc_update_order_item_meta')){
        wc_update_order_item_meta($order_item_id, 'cs_note', $cs_note);
        wp_send_json_success();
    }
    wp_send_json_error(__('Failed to update CS note', 'catering-booking-and-scheduling'));
}

// NEW: Endpoint to delete order item's delivery date
add_action('wp_ajax_delete_item_delivery_date', 'catering_ajax_delete_item_delivery_date');
function catering_ajax_delete_item_delivery_date(){
    if( ! current_user_can('manage_catering') ){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    if(!$order_item_id){
        wp_send_json_error(__('Missing order item id', 'catering-booking-and-scheduling'));
    }
    if(function_exists('wc_update_order_item_meta')){
        wc_update_order_item_meta($order_item_id, 'delivery_date', '');
        wp_send_json_success();
    }
    wp_send_json_error(__('Failed to delete delivery date', 'catering-booking-and-scheduling'));
}

// NEW: Endpoint to delete order item's due date
add_action('wp_ajax_delete_item_due_date', 'catering_ajax_delete_item_due_date');
function catering_ajax_delete_item_due_date(){
    if( ! current_user_can('manage_catering') ){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    if(!$order_item_id){
        wp_send_json_error(__('Missing order item id', 'catering-booking-and-scheduling'));
    }
    if(function_exists('wc_update_order_item_meta')){
        wc_update_order_item_meta($order_item_id, 'due_date', '');
        wc_delete_order_item_meta($order_item_id, '_alg_wc_pif_local'); 
        wp_send_json_success();
    }
    wp_send_json_error(__('Failed to delete due date', 'catering-booking-and-scheduling'));
}

// NEW: Endpoint to delete order item's tracking number
add_action('wp_ajax_delete_item_tracking_number', 'catering_ajax_delete_item_tracking_number');
function catering_ajax_delete_item_tracking_number(){
    if( ! current_user_can('manage_catering') ){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    if(!$order_item_id){
        wp_send_json_error(__('Missing order item id', 'catering-booking-and-scheduling'));
    }
    if(function_exists('wc_update_order_item_meta')){
        wc_update_order_item_meta($order_item_id, 'tracking_number', '');
        wp_send_json_success();
    }
    wp_send_json_error(__('Failed to delete tracking number', 'catering-booking-and-scheduling'));
}

// NEW: delete CS note for an order item
add_action('wp_ajax_delete_item_cs_note','catering_ajax_delete_item_cs_note');
function catering_ajax_delete_item_cs_note(){
    if(! current_user_can('manage_catering') ){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    if(! $order_item_id){
        wp_send_json_error(__('Missing parameters', 'catering-booking-and-scheduling'));
    }
    if(function_exists('wc_update_order_item_meta')){
        wc_update_order_item_meta($order_item_id, 'cs_note', '');
        wp_send_json_success();
    }
    wp_send_json_error(__('Failed to delete CS note', 'catering-booking-and-scheduling'));
}

add_action('wp_ajax_get_daily_meal_choices','catering_ajax_get_daily_meal_choices');
function catering_ajax_get_daily_meal_choices(){
    $dateStr = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    if(! preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateStr) ){
        wp_send_json_error(__('Invalid date', 'catering-booking-and-scheduling'));
    }

    global $wpdb;
    $choice_table   = $wpdb->prefix . 'catering_choice';
    $booking_table  = $wpdb->prefix . 'catering_booking';

    // fetch booking_id + choice for that date, only from active bookings
    $rows = $wpdb->get_results(
        $wpdb->prepare("
            SELECT c.booking_id, c.choice 
            FROM $choice_table c
            INNER JOIN $booking_table b ON c.booking_id = b.ID
            WHERE c.date = %s AND b.status = 'active'
        ", $dateStr),
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
    $bids   = isset($_POST['booking_ids']) ? $_POST['booking_ids'] : [];
    if(empty($bids)){
        wp_send_json_error(__('No booking IDs provided', 'catering-booking-and-scheduling'));
    }   
    $bids = explode( ',', $bids );
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
        $phone_country = $order->get_meta('_shipping_phone_country') ?: '+852';
        $full_phone = $phone_country . ' ' . $phone;
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
        
        // Get sequential order number
        $seq_order_number = $order->get_meta('_seq_order_number', true);
        $display_order_id = $seq_order_number ? $seq_order_number : $order_id;
        
        $out[] = [
            'order_id'  => $display_order_id,
            'internal_order_id' => $order_id, // For admin links
            'user_id'   => $booking->user_id,
            'customer'  => $cust,
            'due_date'  => $due,
            'phone'     => $full_phone,
            'product'   => $title,
            'count'     => $cnt
        ];
    }
    
    wp_send_json_success($out);
}

add_action('wp_ajax_get_daily_delivery_items','catering_ajax_get_daily_delivery_items');
function catering_ajax_get_daily_delivery_items(){
    if(! current_user_can('manage_catering') ){
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    if(! preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
        wp_send_json_error(__('Invalid date', 'catering-booking-and-scheduling'));
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
        
        // Check order status using WooCommerce function
        if(function_exists('wc_get_order_id_by_order_item_id')){
            $order_id = wc_get_order_id_by_order_item_id($oi);
            if($order_id){
                $order = wc_get_order($order_id);
                if($order){
                    $order_status = $order->get_status();
                    // Only process items from orders with 'processing' or 'completed' status
                    if(!in_array($order_status, ['processing', 'completed'])){
                        continue; // Skip this item
                    }
                } else {
                    continue; // Skip if order not found
                }
            } else {
                continue; // Skip if order ID not found
            }
        } else {
            continue; // Skip if WooCommerce function not available
        }
        
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
    if ( ! current_user_can( 'manage_catering' ) ) {
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    $order_item_input = isset($_POST['order_item_id']) ? sanitize_text_field($_POST['order_item_id']) : '';
    if ( empty($order_item_input) ) {
        wp_send_json_error(__('Missing parameters', 'catering-booking-and-scheduling'));
    }
    // Allow multiple order item IDs separated by comma
    $order_item_ids = array_map('absint', array_filter(explode(',', $order_item_input)));
    if ( empty($order_item_ids) ) {
        wp_send_json_error(__('Invalid order item IDs', 'catering-booking-and-scheduling'));
    }
    if ( ! function_exists('wc_get_order_id_by_order_item_id') ) {
        wp_send_json_error(__('WooCommerce function missing', 'catering-booking-and-scheduling'));
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
        $phone_country = $order->get_meta('_shipping_phone_country') ?: '+852';
        $full_phone   = $phone_country . ' ' . $phone;
        
        // Get sequential order number
        $seq_order_number = $order->get_meta('_seq_order_number', true);
        $display_order_id = $seq_order_number ? $seq_order_number : $order_id;
        
        $results[] = [
            'order_id' => $display_order_id,
            'internal_order_id' => $order_id, // For admin links
            'user_id'  => $order->get_user_id(),
            'customer' => $customer,
            'phone'    => $full_phone,
            'product'  => $product_name,
            'count'    => $quantity
        ];
    }
    wp_send_json_success($results);
}

// AJAX function to retrieve meal choice history from catering_log table
add_action('wp_ajax_get_meal_choice_history', 'catering_ajax_get_meal_choice_history');
function catering_ajax_get_meal_choice_history() {
    if (!current_user_can('manage_catering')) {
        wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
    }
    
    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $choice_date = isset($_POST['choice_date']) ? sanitize_text_field($_POST['choice_date']) : '';
    
    if (!$booking_id || !$choice_date) {
        wp_send_json_error(__('Invalid parameters', 'catering-booking-and-scheduling'));
    }
    
    global $wpdb;
    
    // Get meal choice history from catering_log table
    $log_table = $wpdb->prefix . 'catering_log';
    $history = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $log_table 
         WHERE booking_id = %d AND choice_date = %s 
         ORDER BY amended_time DESC",
        $booking_id, $choice_date
    ), ARRAY_A);
    
    if (empty($history)) {
        wp_send_json_success([]);
        return;
    }
    
    // Collect all unique category and meal IDs from all entries
    $category_ids = [];
    $meal_ids = [];
    
    foreach ($history as $entry) {
        // Extract IDs from previous_choice
        if ($entry['previous_choice']) {
            $prev_choice = maybe_unserialize($entry['previous_choice']);
            if (is_array($prev_choice)) {
                foreach ($prev_choice as $cat_id => $meals) {
                    $category_ids[] = $cat_id;
                    if (is_array($meals)) {
                        $meal_ids = array_merge($meal_ids, $meals);
                    }
                }
            }
        }
        
        // Extract IDs from new_choice
        if ($entry['new_choice']) {
            $new_choice = maybe_unserialize($entry['new_choice']);
            if (is_array($new_choice)) {
                foreach ($new_choice as $cat_id => $meals) {
                    $category_ids[] = $cat_id;
                    if (is_array($meals)) {
                        $meal_ids = array_merge($meal_ids, $meals);
                    }
                }
            }
        }
    }
    
    $category_ids = array_unique(array_filter($category_ids));
    $meal_ids = array_unique(array_filter($meal_ids));
    
    // Fetch all category titles at once
    $category_titles = [];
    if (!empty($category_ids)) {
        $cat_table = $wpdb->prefix . 'catering_terms';
        $cat_placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $cat_data = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, title FROM $cat_table WHERE ID IN ($cat_placeholders)",
            $category_ids
        ), OBJECT_K);
        
        foreach ($cat_data as $cat) {
            $category_titles[$cat->ID] = $cat->title;
        }
    }
    
    // Fetch all meal titles at once
    $meal_titles = [];
    if (!empty($meal_ids)) {
        $meal_table = $wpdb->prefix . 'catering_meal';
        $meal_placeholders = implode(',', array_fill(0, count($meal_ids), '%d'));
        $meal_data = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, title FROM $meal_table WHERE ID IN ($meal_placeholders)",
            $meal_ids
        ), OBJECT_K);
        
        foreach ($meal_data as $meal) {
            $meal_titles[$meal->ID] = $meal->title;
        }
    }
    
    // Helper function to map choice data to frontend-ready structure
    $map_choice_data = function($choice_data) use ($category_titles, $meal_titles) {
        if (!$choice_data) {
            return null;
        }
        
        $unserialized = maybe_unserialize($choice_data);
        if (!is_array($unserialized)) {
            return null;
        }
        
        $mapped = [];
        foreach ($unserialized as $cat_id => $meal_ids_array) {
            if (!is_array($meal_ids_array)) {
                continue;
            }
            
            $meals = [];
            foreach ($meal_ids_array as $meal_id) {
                $meals[] = [
                    'id' => $meal_id,
                    'title' => isset($meal_titles[$meal_id]) ? $meal_titles[$meal_id] : "Meal #$meal_id (deleted)"
                ];
            }
            
            $mapped[] = [
                'cat_id' => $cat_id,
                'cat_title' => isset($category_titles[$cat_id]) ? $category_titles[$cat_id] : "Category #$cat_id (deleted)",
                'meals' => $meals
            ];
        }
        
        return $mapped;
    };
    
    // Process each history entry and map the choice data
    $formatted_history = [];
    foreach ($history as $entry) {
        $formatted_entry = [
            'id' => $entry['id'],
            'booking_id' => $entry['booking_id'],
            'choice_date' => $entry['choice_date'],
            'previous_choice' => $map_choice_data($entry['previous_choice']),
            'new_choice' => $map_choice_data($entry['new_choice']),
            'changed_by_user_id' => $entry['changed_by_user_id'],
            'changed_by_user_type' => $entry['changed_by_user_type'],
            'change_reason' => $entry['change_reason'],
            'action_type' => $entry['action_type'],
            'amended_time' => $entry['amended_time']
        ];
        
        $formatted_history[] = $formatted_entry;
    }
    
    wp_send_json_success($formatted_history);
}

// NEW: Endpoint to get user saved address data
add_action('wp_ajax_get_user_address', 'catering_ajax_get_user_address');
function catering_ajax_get_user_address(){
    // Check if user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(__('User not logged in', 'catering-booking-and-scheduling'));
        return;
    }
    
    $address_type = isset($_POST['address_type']) ? sanitize_text_field($_POST['address_type']) : '';
    
    if (!in_array($address_type, ['shipping', 'shipping_2'])) {
        wp_send_json_error(__('Invalid address type', 'catering-booking-and-scheduling'));
        return;
    }
    
    $user_id = get_current_user_id();
    
    // Get address data based on address type
    $address_data = array(
        'first_name'    => get_user_meta($user_id, $address_type . '_first_name', true),
        'last_name'     => get_user_meta($user_id, $address_type . '_last_name', true),
        'company'       => get_user_meta($user_id, $address_type . '_company', true),
        'address'       => get_user_meta($user_id, $address_type . '_address_1', true),
        'city'          => get_user_meta($user_id, $address_type . '_city', true),
        'phone'         => get_user_meta($user_id, $address_type . '_phone', true),
        'phone_country' => get_user_meta($user_id, $address_type . '_phone_country', true),
        'remarks'       => get_user_meta($user_id, $address_type . '_remarks', true)
    );
    
    // Set defaults for empty values
    if (empty($address_data['phone_country'])) {
        $address_data['phone_country'] = '+852';
    }
    
    // Check if address exists (at least first_name and address should be present)
    if (empty($address_data['first_name']) && empty($address_data['address'])) {
        wp_send_json_error(__('No saved address found', 'catering-booking-and-scheduling'));
        return;
    }
    
    wp_send_json_success($address_data);
}