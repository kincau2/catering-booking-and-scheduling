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
                "SELECT ID, title FROM $table WHERE type='category' AND title LIKE %s ORDER BY title ASC",
                $like
            ),
            ARRAY_A
        );
    } else {
        // No search, return all
        $results = $wpdb->get_results(
            "SELECT ID, title FROM $table WHERE type='category' ORDER BY title ASC",
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

add_action( 'wp_ajax_catering_search_tags', 'catering_search_tags' );
function catering_search_tags() {
    global $wpdb;

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'No permission' );
    }

    $search = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
    $table  = $wpdb->prefix . 'catering_terms';

    if ( ! empty( $search ) ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, title FROM $table WHERE type='tag' AND title LIKE %s ORDER BY title ASC",
                $like
            ), ARRAY_A
        );
    } else {
        $results = $wpdb->get_results(
            "SELECT ID, title FROM $table WHERE type='tag' ORDER BY title ASC",
            ARRAY_A
        );
    }

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

add_action('wp_ajax_get_meal_schedule_month','catering_ajax_get_meal_schedule_month');
function catering_ajax_get_meal_schedule_month(){
    $year  = isset($_POST['year']) ? absint($_POST['year']) : 0;
    $month = isset($_POST['month']) ? absint($_POST['month']) : 0;
    if($year < 2000 || $year > 2100 || $month < 1 || $month > 12){
        wp_send_json_error('Invalid year/month');
    }

    global $wpdb;
    $table_sch = $wpdb->prefix.'catering_schedule';
    $table_hol = $wpdb->prefix.'catering_holiday';

    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $lastDay   = date('t', strtotime($startDate));
    $endDate   = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);

    // schedule
    $rows = $wpdb->get_results($wpdb->prepare("
      SELECT date, meal
      FROM $table_sch
      WHERE date >= %s AND date <= %s
    ", $startDate, $endDate), ARRAY_A);

    $schedule = [];
    foreach ($rows as $r){
        $d = $r['date'];
        $data = maybe_unserialize($r['meal']);
        // Ensure we get an array (even if empty)
        $schedule[$d] = is_array($data) ? $data : ($data ? [$data] : []);
    }

    // holiday
    $rows2 = $wpdb->get_results($wpdb->prepare("
      SELECT date, holiday_name
      FROM $table_hol
      WHERE date >= %s AND date <= %s
    ", $startDate, $endDate), ARRAY_A);

    $holiday = [];
    foreach ($rows2 as $r){
        $d = $r['date'];
        $hn = maybe_unserialize($r['holiday_name']);
        $holiday[$d] = is_array($hn) ? $hn : ($hn ? [$hn] : []);
    }

    wp_send_json_success([
      'schedule' => $schedule,
      'holiday'  => $holiday
    ]);
}

add_action('wp_ajax_update_meal_schedule_batch','catering_ajax_update_meal_schedule_batch');
function catering_ajax_update_meal_schedule_batch(){
    if(!isset($_POST['changes_json'])){
        wp_send_json_error('No changes_json provided');
    }
    $jsonStr = stripslashes($_POST['changes_json']);
    $changes = json_decode($jsonStr, true);
    if(!is_array($changes)){
        wp_send_json_error('Invalid JSON');
    }

    global $wpdb;
    $table = $wpdb->prefix.'catering_schedule';

    foreach($changes as $dateStr => $mealEntries){
        $dateStr = sanitize_text_field($dateStr);
        if(!is_array($mealEntries)){
            $mealEntries = [];
        }
        // Optionally: remove duplicate objects (with same meal_id and cat_id)
        $unique = [];
        foreach($mealEntries as $entry){
            $meal_id = absint($entry['meal_id']);
            $cat_id  = absint($entry['cat_id']);
            $key = $meal_id . '-' . $cat_id;
            $unique[$key] = ['meal_id' => $meal_id, 'cat_id' => $cat_id];
        }
        $mealEntries = array_values($unique);

        // Check if a row exists for this date
        $row = $wpdb->get_row($wpdb->prepare("SELECT ID FROM $table WHERE date=%s", $dateStr));

        if(empty($mealEntries)){
            // If there are no meal entries, remove the row if exists.
            if($row){
                $wpdb->delete($table, ['ID' => $row->ID], ['%d']);
            }
        } else {
            // Serialize the array of meal entries.
            $serialized = maybe_serialize($mealEntries);
            if($row){
                $wpdb->update($table, ['meal' => $serialized], ['ID' => $row->ID], ['%s'], ['%d']);
            } else {
                $wpdb->insert($table, ['date' => $dateStr, 'meal' => $serialized], ['%s', '%s']);
            }
        }
    }

    wp_send_json_success();
}

add_action('wp_ajax_check_can_update_schedule','catering_ajax_check_can_update_schedule');
function catering_ajax_check_can_update_schedule(){
    // For demonstration, we just allow
    wp_send_json_success(true);
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








?>
