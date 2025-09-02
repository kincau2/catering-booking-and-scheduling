<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\AutoFilter\Column;
global $wpdb;
// 1) Permission check
if ( ! current_user_can( 'manage_catering' ) ) {
    wp_die( 'Unauthorized' );
}

// 2) Input validation
$start = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end   = isset($_GET['end_date'])   ? $_GET['end_date']   : '';

// Calculate number of days between start and end dates
$day_count = (strtotime($end) - strtotime($start)) / 86400 + 1;

// Create date-to-index mapping array
$date_map = [];
for ($i = 0; $i < $day_count; $i++) {
    $current_date = date('Y-m-d', strtotime($start . " +{$i} days"));
    $date_map[$current_date] = $i;
}

if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/',$start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/',$end) ) {
    wp_die( 'Invalid date format' );
}
if ( $start > $end ) {
    wp_die( 'Start date must be before or same as end date' );
}

// NEW: Fetch allergy mapping (ID to title)
$terms_table = $wpdb->prefix . 'catering_terms';
$allergy_terms = $wpdb->get_results(
    $wpdb->prepare("SELECT ID, title FROM {$terms_table} WHERE type=%s ORDER BY ordering ASC, ID ASC", 'allergy'),
    ARRAY_A
);
$allergy_map = [];
foreach ($allergy_terms as $term) {
    $allergy_map[$term['ID']] = $term['title'];
}

// 3) Fetch & tally serialized choices from catering_choice

$ct = $wpdb->prefix . 'catering_choice';
$ot = $wpdb->prefix . 'catering_options';
$cb = $wpdb->prefix . 'catering_booking';

// fetch dynamic "soup" category ID
$soup_cat_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT option_value FROM {$ot} WHERE option_name=%s",
        'category_id_soup'
    )
);

$records = $wpdb->get_results( $wpdb->prepare(
    "SELECT c.* FROM {$ct} c
     INNER JOIN {$cb} b ON c.booking_id = b.ID
     WHERE c.date BETWEEN %s AND %s AND b.status = 'active'",
    $start, $end
) );

if ( ! $records ) {
    wp_die( 'No records found for the specified date range.' );
} else {
    // wp_die( 'Records found: ' . count($records) ); // For debugging purposes
}




$tally = [];
foreach ( $records as $rec ) {
    $choice = maybe_unserialize( $rec->choice );
    if ( ! is_array( $choice ) ) {
        continue;
    }
    foreach ( $choice as $cat_id => $meal_arr ) {
        if ( is_array( $meal_arr ) ) {
            foreach ( $meal_arr as $meal_id ) {
                $tally[ $cat_id ][ $meal_id ] = ( $tally[ $cat_id ][ $meal_id ] ?? 0 ) + 1;
            }
        }
    }
}
$choice_rows = [];
foreach ( $tally as $cat_id => $meals ) {
    foreach ( $meals as $meal_id => $qty ) {
        $choice_rows[] = [
            'category_id' => $cat_id,
            'meal_id'     => $meal_id,
            'qty'         => $qty,
        ];
    }
}

// 4) Load prefix category IDs (plus soup‐cat‐id always)
$opt = $wpdb->get_var( $wpdb->prepare(
    "SELECT option_value FROM {$ot} WHERE option_name=%s",
    'catering_category_id_require_prefix'
) );
$prefix_ids = is_serialized($opt) ? maybe_unserialize($opt) : [];
$prefix_ids[] = $soup_cat_id;

// 5) Group rows by category
$by_cat = [];
foreach ( $choice_rows as $r ) {
    $by_cat[ $r['category_id'] ][] = $r;
}



// NEW: Load category titles from catering_terms
global $wpdb;
$term_ids = array_keys( $by_cat );
$term_map = [];
if ( $term_ids ) {
    $ph = implode( ',', array_fill(0, count($term_ids), '%d') );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, title FROM {$wpdb->prefix}catering_terms WHERE ID IN ($ph)",
        $term_ids
    ), ARRAY_A );
    foreach ( $rows as $row ) {
        $term_map[ $row['ID'] ] = $row['title'];
    }
}

// NEW: Load meal titles from catering_meal
$meal_ids = array_unique( array_column( $choice_rows, 'meal_id' ) );
$meal_map = [];
if ( $meal_ids ) {
    $ph2 = implode( ',', array_fill(0, count($meal_ids), '%d') );
    $mrows = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, title FROM {$wpdb->prefix}catering_meal WHERE ID IN ($ph2)",
        $meal_ids
    ), ARRAY_A );
    foreach ( $mrows as $mr ) {
        $meal_map[ $mr['ID'] ] = $mr['title'];
    }
}

// 6) Build spreadsheet – Kitchen Report sheet
$spreadsheet = new Spreadsheet();
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle( 'Kitchen Report' );
// Header rows
$sheet1->setCellValue('A1','Report Time: '. current_time('Y/m/d H:i') );
$sheet1->setCellValue('A2','Date Range: '. $start .' – '. $end );
// Merge A1:C1 and A2:C2
$sheet1->mergeCells('A1:C1');
$sheet1->mergeCells('A2:C2');
// Column titles
$sheet1->setCellValue('A4',__('Category','catering-booking-and-scheduling') );
$sheet1->setCellValue('B4',__('Meal','catering-booking-and-scheduling') );
$sheet1->setCellValue('C4',__('Count', 'catering-booking-and-scheduling') );

$rowNum = 5;

// Initialize prefix map for use later
$prefix_map = [];

// Display each category separately instead of grouping them  
// First, get all categories except soup and sort them (prefix categories first)
$prefix_cids = array_filter($prefix_ids, fn($id)=> $id !== $soup_cat_id);
$other_cids = [];
foreach ( $by_cat as $cid => $items ) {
    if ( $cid !== $soup_cat_id && !in_array($cid, $prefix_cids, true) ) {
        $other_cids[] = $cid;
    }
}

// Combine prefix categories first, then other categories
$ordered_categories = array_merge($prefix_cids, $other_cids);

// For prefix categories, we need to maintain the letter mapping
$global_letter_index = 0;

// Process each category individually
foreach ( $ordered_categories as $cat_id ) {
    if ( empty( $by_cat[$cat_id] ) ) {
        continue;
    }
    
    $cat_title = $term_map[$cat_id] ?? "Cat {$cat_id}";
    $category_items = $by_cat[$cat_id];
    
    // Merge category column for this category's items
    $item_count = count($category_items);
    if ( $item_count > 1 ) {
        $sheet1->mergeCells("A{$rowNum}:A".($rowNum + $item_count - 1));
    }
    $sheet1->setCellValue("A{$rowNum}", $cat_title);
    
    // List meals for this category
    foreach ( $category_items as $item ) {
        $mid = $item['meal_id'];
        $title = $meal_map[$mid] ?? "Meal {$mid}";
        
        // Apply prefix letter only for prefix categories
        if ( in_array($cat_id, $prefix_cids, true) ) {
            $letter = chr(65 + $global_letter_index++);
            $prefix_map[$mid] = $letter;
            $display_title = "{$letter}. {$title}";
        } else {
            $display_title = $title;
            // Highlight gift soup items in other categories
            if( str_contains($title, '[贈送]') && str_contains($title, '湯') ) {
                $sheet1->getStyle("B{$rowNum}:B{$rowNum}")
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFA500');
            }
        }
        
        $sheet1->setCellValue("B{$rowNum}", $display_title);
        $sheet1->setCellValue("C{$rowNum}", $item['qty']);
        $rowNum++;
    }
}

// Handle soup category separately with container breakdown
$group3 = $by_cat[ $soup_cat_id ] ?? [];
// Initialize variables to prevent fatal errors when no soup items exist
$total_pot = 0;
$total_cup = 0;
$prefix_map_soup = [];
$letters = [];

if ( $group3 ) {
    $container_counts = [];

    foreach ( $records as $rec ) {
        $cont      = maybe_unserialize($rec->preference)['soup_container'] ?? '';
        $choiceArr = maybe_unserialize($rec->choice);
        if ( ! empty($choiceArr[ $soup_cat_id ]) && is_array($choiceArr[ $soup_cat_id ]) ) {
            foreach ( $choiceArr[ $soup_cat_id ] as $mid ) {
                $container_counts[$mid][$cont] = ( $container_counts[$mid][$cont] ?? 0 ) + 1;
            }
        }
    }

    if ( $container_counts ) {
        // merge A over all container‐lines
        $lines = 0;
        foreach ( $container_counts as $arr ) {
            $lines += count($arr);
        }
        $sheet1->mergeCells("A{$rowNum}:A".($rowNum + $lines - 1));
        $sheet1->setCellValue("A{$rowNum}", $term_map[$soup_cat_id] ?? "湯水" );
        $letterIndex = 0;
        foreach ( $container_counts as $mid => $arr ) {
            $letter = chr(65 + $letterIndex++);
            $prefix_map_soup[ $mid ] = $letter;
            foreach ( $arr as $cont => $qty ) {
                $title = $meal_map[$mid] ?? "Meal {$mid}";
                if ( $cont === 'pot' ) {
                    $label = "{$letter}. {$title} ({$letter}壺)";
                    $total_pot += $qty;
                } elseif ( $cont === 'cup' ) {
                    $label = "{$letter}. {$title} ({$letter}杯)";
                    $qty *= 2; // cups are counted as double
                    $total_cup += $qty;
                } else {
                    $label = "{$letter}. {$title}";
                }
                $sheet1->setCellValue("B{$rowNum}", $label );
                $sheet1->setCellValue("C{$rowNum}", $qty );
                $rowNum++;
            }
        }
        // Update letters array based on prefix_map_soup
        $letters = array_values( array_unique( $prefix_map_soup ) );
    }
}
$rowNum++;
// Set summary row for total quantities

$start_row = $rowNum;
$sheet1->setCellValue("A{$rowNum}", '總計');
$sheet1->mergeCells("A{$rowNum}:A".($rowNum + 1));
$sheet1->setCellValue("B{$rowNum}", '總壺數');
$sheet1->setCellValue("C{$rowNum}", $total_pot );
$rowNum++;
$sheet1->setCellValue("B{$rowNum}", '總杯數(不包含贈送湯水)');
$sheet1->setCellValue("C{$rowNum}", $total_cup );
$end_row = $rowNum;
$sheet1->getStyle("A{$start_row}:C{$end_row}")
        ->getBorders()
        ->getOutline()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

$rowNum++;


// Determine last populated row
$lastRow = $rowNum - 1;

// 8) Font styling:
// Header rows (A1:C2): size 16, bold
$sheet1->getStyle('A1:C2')
        ->getFont()
        ->setSize(16)
        ->setBold(true);

// All other cells (from row 3 onward): size 14
$sheet1->getStyle("A3:C{$lastRow}")
        ->getFont()
        ->setSize(14);

// Auto-size columns A, B, C to fit content
foreach ( range('A','C') as $col ) {
    $sheet1->getColumnDimension($col)->setAutoSize(true);
}

// Center‐align category titles (merged cells in column A)
// Rows 5 through last populated row ($rowNum-1)
$lastRow = $rowNum - 1;
$sheet1
    ->getStyle("A5:A{$lastRow}")
    ->getAlignment()
    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

// 8) Build "Delivery Report" sheet
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('Delivery Report');
// Header rows
$sheet2->setCellValue('A1','Report Time: '. current_time('Y/m/d H:i') );
$sheet2->setCellValue('A2','Date Range: '. $start .' – '. $end );
$sheet2->mergeCells('A1:F1');
$sheet2->mergeCells('A2:F2');

// Summary box for soup containers
$soup_count_start_row = 5;
$sheet2->setCellValue("E{$soup_count_start_row}", '湯');
// collect all soup prefixes (letters) from category soup
$r = $soup_count_start_row;
if ( !empty($letters) ) {
    foreach ( $letters as $letter ) {
        // per‐prefix pot
        $sheet2->setCellValue("F{$r}", "湯款({$letter}) - 暖壺");
        $r++;
        // per‐prefix cup
        $sheet2->setCellValue("F{$r}", "湯款({$letter}) - 湯杯");
        $r++;
    }
}
// totals
$sheet2->setCellValue("F{$r}", '總壺數');
// background fill orange
$sheet2->getStyle("F{$r}:F{$r}")
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFA500');
$sheet2->getStyle("H{$r}:H{$r}")
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFA500');
$r++;
$sheet2->setCellValue("F{$r}", '總杯數');
// background fill orange
$sheet2->getStyle("F{$r}:F{$r}")
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFA500');
$sheet2->getStyle("H{$r}:H{$r}")
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFA500');
$soup_count_end_row = $r;
$sheet2->mergeCells("E{$soup_count_start_row}:E{$soup_count_end_row}");
$r += 2;

$setmeal_count_start_row = $r;
$sheet2->setCellValue("E{$setmeal_count_start_row}", '餐');
$sheet2->setCellValue("F{$r}", "餐總數");
$sheet2->getStyle("F{$r}:F{$r}")
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFA500');
$sheet2->getStyle("I{$r}:I{$r}")
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFA500');
$r++;
// per‐prefix cup
$sheet2->setCellValue("F{$r}", "其他總數");
$sheet2->getStyle("F{$r}:F{$r}")
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFA500');
$sheet2->getStyle("I{$r}:I{$r}")
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFA500');
$setmeal_count_end_row = $r;
$sheet2->mergeCells("E{$setmeal_count_start_row}:E{$setmeal_count_end_row}");
$r++;

// start actual grouped tables one row below the summary
$row = $r + 2;
$delivery_table_start_row = $row +1;
for ($i = 0; $i < $day_count; $i++) {
        
    $r = $soup_count_start_row;
    $header_row = $r -1;
    $current_date = date('Y-m-d', strtotime($start . " +{$i} days"));
    $pot_row = [];
    $cup_row = [];
    $col_soup = chr(72 + $i*2); // Start from column H (72 is ASCII for 'H')
    $col_meal = chr(73 + $i*2); // Start from column I (73 is ASCII for 'I')

    // Set Date Header
    $sheet2->setCellValue( $col_soup.$header_row, $current_date );
    $sheet2->mergeCells("{$col_soup}{$header_row}:{$col_meal}{$header_row}");
    $sheet2->getColumnDimension($col_soup)->setWidth(8);
    $sheet2->getColumnDimension($col_meal)->setWidth(8);

    foreach ( $letters as $letter ) {
        // per‐prefix pot
        $sheet2->setCellValue("{$col_soup}{$r}", "=COUNTIF({$col_soup}{$delivery_table_start_row}:{$col_soup}1000,\"*{$letter}壺*\")");
        $r++;
        // per‐prefix cup
        $sheet2->setCellValue("{$col_soup}{$r}", "=COUNTIF({$col_soup}{$delivery_table_start_row}:{$col_soup}1000,\"*2{$letter}杯*\")*2");
        $r++;
    }
    $soup_count_end_row = $r;
    $total_rows = count($letters);
    for( $j = 0; $j < $total_rows; $j++ ) {
        $pot_row[] = $col_soup.($soup_count_start_row + $j * 2); 
        $cup_row[] = $col_soup.($soup_count_start_row + $j * 2 + 1);
    }
    // totals
    if ( !empty($pot_row) ) {
        $sheet2->setCellValue("{$col_soup}{$r}",  "=SUM(".implode(',', $pot_row).")");
    } else {
        $sheet2->setCellValue("{$col_soup}{$r}", 0);
    }
    $sheet2->getStyle("{$col_soup}{$r}")
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFA500');
    $r++;
    if ( !empty($cup_row) ) {
        $sheet2->setCellValue("{$col_soup}{$r}",  "=SUM(".implode(',', $cup_row).")");
    } else {
        $sheet2->setCellValue("{$col_soup}{$r}", 0);
    }

    $total_soup_end_row = $r;

    $sheet2->getStyle("{$col_soup}{$soup_count_start_row}:{$col_meal}{$total_soup_end_row}")
        ->getBorders()
        ->getOutline()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $r += 2;

        //餐總數
        $sheet2->setCellValue("{$col_meal}{$r}", "=COUNTIF({$col_meal}{$delivery_table_start_row}:{$col_meal}1000,\"*Y\")");
        $sheet2->getStyle("{$col_meal}{$r}")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFA500');
        $r++;
        //其他總數
        $sheet2->setCellValue("{$col_meal}{$r}", "=COUNTIF({$col_meal}{$delivery_table_start_row}:{$col_meal}1000,\"*N\")");
        $sheet2->getStyle("{$col_meal}{$r}")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFA500');

        $sheet2->getStyle("{$col_soup}{$setmeal_count_start_row}:{$col_meal}{$setmeal_count_end_row}")
            ->getBorders()
            ->getOutline()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

}

$sheet2_last_col = $col_meal;

$sheet2->getStyle("E{$soup_count_start_row}:F{$total_soup_end_row}")
        ->getBorders()
        ->getOutline()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

$sheet2->getStyle("E{$setmeal_count_start_row}:F{$setmeal_count_end_row}")
        ->getBorders()
        ->getOutline()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

// Define city order for sorting
$city_order = [
    '東區' => 1,
    '灣仔區' => 2,
    '中西區' => 3,
    '南區' => 4,
    '離島區' => 5,
    '葵青區' => 6,
    '荃灣區' => 7,
    '屯門區' => 8,
    '元朗區' => 9,
    '沙田區' => 10,
    '大埔區' => 11,
    '北區' => 12,
    '深水埗區' => 13,
    '油尖旺區' => 14,
    '九龍城區' => 15,
    '黃大仙區' => 16,
    '觀塘區' => 17,
    '西頁區' => 18
];

// Sort records by city order first, then by order number within each city
usort($records, function($a, $b) use ($city_order){
    $addrA = maybe_unserialize($a->address);
    $addrB = maybe_unserialize($b->address);
    $cityA = $addrA['city'] ?? '';
    $cityB = $addrB['city'] ?? '';
    
    // Get city order (default to 999 for unknown cities)
    $orderA = $city_order[$cityA] ?? 999;
    $orderB = $city_order[$cityB] ?? 999;
    
    // First sort by city order
    if ($orderA !== $orderB) {
        return $orderA - $orderB;
    }
    
    // If same city, sort by order number
    $bookingA = new Booking($a->booking_id);
    $bookingB = new Booking($b->booking_id);
    $orderIdA = $bookingA->get_order_id();
    $orderIdB = $bookingB->get_order_id();
    
    // Get sequential order numbers if available
    $orderA = wc_get_order($orderIdA);
    $orderB = wc_get_order($orderIdB);
    $orderNumA = $orderA ? $orderA->get_order_number() : $orderIdA;
    $orderNumB = $orderB ? $orderB->get_order_number() : $orderIdB;
    
    return strcmp($orderNumA, $orderNumB);
});
// title row: merge A:F, bold
$sheet2->mergeCells("A{$row}:F{$row}");
$sheet2->setCellValue("A{$row}", "送貨地址 [{$start}-{$end}]" );
$sheet2->getStyle("A{$row}:{$sheet2_last_col}{$row}")->getFont()->setBold(true);

//Set Date Header
for ($i = 0; $i < $day_count; $i++) {
    $header_row = $row;
    $current_date = date('Y-m-d', strtotime($start . " +{$i} days"));
    $col_soup = chr(72 + $i*2); // Start from column H (72 is ASCII for 'H')
    $col_meal = chr(73 + $i*2); // Start from column I (73 is ASCII for 'I')
    $sheet2->setCellValue( $col_soup.$header_row, $current_date );
    $sheet2->mergeCells("{$col_soup}{$header_row}:{$col_meal}{$header_row}");
}

$row++;
// column headers start at B (add '湯水' as last header)
$headers = ['訂購編號','稱呼','聯絡電話','送貨地點','備註','','湯壺','餐'];
// capture header row
$headerRow = $row;
foreach ( $headers as $i => $title ) {
    $col = chr(66 + $i);
    $sheet2->setCellValue("{$col}{$row}", $title );
}

for ($i = 1; $i < $day_count; $i++) {
    $col_soup = chr(72 + $i*2); // Start from column H (72 is ASCII for 'H')
    $col_meal = chr(73 + $i*2); // Start from column I (73 is ASCII for 'I')
    $sheet2->setCellValue( $col_soup.$row, '湯壺' );
    $sheet2->setCellValue( $col_meal.$row, '餐' );
}

// set row height to 25 (points ≈ pixels)
$sheet2->getRowDimension($headerRow)->setRowHeight(30);
// outline border around header
$sheet2->getStyle("B{$headerRow}:{$sheet2_last_col}{$headerRow}")
        ->getBorders()
        ->getOutline()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
$row++;

$previous_address = '';
$group_start_row = $row;
// data rows start at B
foreach ( $records as $rec ) {
    $delivery_note = [];
    $addr    = maybe_unserialize( $rec->address );
    $booking = new Booking( $rec->booking_id );
    $order_id = $booking->get_order_id();
    
    // Get the sequential order number if available, otherwise fallback to regular order ID
    $order = wc_get_order($order_id);
    $display_order_number = $order ? $order->get_order_number() : $order_id;

    $current_address = ($addr['city'] ?? '') . ' ' . ($addr['address'] ?? '');
    
    // If address changed, close previous group with border
    if ( $current_address !== $previous_address && $previous_address !== '' ) {
        $sheet2->getStyle("B{$group_start_row}:{$sheet2_last_col}" . ($row - 1))
            ->getBorders()
            ->getOutline()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM);
        $group_start_row = $row;
    }

    $sheet2->setCellValue("B{$row}", $display_order_number );
    $sheet2->setCellValue("C{$row}", ($addr['first_name'] ?? '') . " " . ($addr['last_name'] ?? '') );
    $sheet2->setCellValue("D{$row}", ( $addr['phone_country']?? '' ) . ' ' . ($addr['phone'] ?? '') );
    $sheet2->setCellValue("E{$row}", $current_address );

    $WC_item = $booking->get_order_item();
    
    if( isset($addr['delivery_note']) && !empty($addr['delivery_note']) ){
        $delivery_note[] = __('Delivery Note','catering-booking-and-scheduling' ) . ': '. $addr['delivery_note'];
    }
    
    if ( ! empty( $WC_item->get_meta('cs_note', true) ) ) {
        $delivery_note[] = __('CS Note','catering-booking-and-scheduling' ) . ': '. $WC_item->get_meta('cs_note', true);
    }
    
    $health_status = maybe_unserialize( $booking->health_status );
    
    if ( is_array( $health_status ) && is_array( $health_status['allergy'] ) && !empty( $health_status['allergy'] ) ) {
        set_transient( 'debug', 'fired_', 30 ); // for debugging
        // Remove 'no_allergy' flag if present
        $health_status['allergy'] = array_diff( $health_status['allergy'], [ 'no_allergy' ] );
        if ( ! empty( $health_status['allergy'] ) ) {
            $allergy_titles = [];
            // Convert allergy IDs to titles using allergy_map
            foreach ( $health_status['allergy'] as $allergy_id ) {
                if ( isset( $allergy_map[$allergy_id] ) ) {
                    $allergy_titles[] = $allergy_map[$allergy_id];
                }
            }
            $delivery_note[] = __('Food Allergy','catering-booking-and-scheduling' ) . ': ' . implode( ', ', $allergy_titles );
        }
    }
    
    $sheet2->setCellValue("F{$row}", implode("\n", $delivery_note) );
    $sheet2->getStyle("F{$row}")
        ->getAlignment()
        ->setWrapText(true);

    $C = $date_map[$rec->date]; // map date to row number
    // 湯水 column: check if choice has category soup and get container preference
    $choiceArr = maybe_unserialize( $rec->choice );
    $prefArr   = maybe_unserialize( $rec->preference );
    $soupCell  = '';
    if ( ! empty( $choiceArr[$soup_cat_id] ) && !empty( $prefix_map_soup ) ) {
        $labels    = [];
        $container = $prefArr['soup_container'] ?? '';
        foreach ( (array) $choiceArr[$soup_cat_id] as $mid ) {
            $pref = $prefix_map_soup[ $mid ] ?? '';
            if ( $container === 'pot' ) {
                $labels[] = $pref . '壺';
            } elseif ( $container === 'cup' ) {
                $labels[] = '2' . $pref . '杯';
            }
        }
        $soupCell = implode(' / ', $labels);
    }
    $date_index = $date_map[$rec->date] ?? 0;

    $col_soup = chr(72 + $date_index * 2); // H, J, L, etc.
    $col_meal = chr(73 + $date_index * 2); // I, K, M, etc.

    $sheet2->setCellValue("{$col_soup}{$row}", $soupCell );

    if( COUNT($choiceArr) === 1 && isset($choiceArr[$soup_cat_id]) ) {
        // only soup, no meal
        $sheet2->setCellValue("{$col_meal}{$row}", 'N' );
    } else {
        // has meal(s)
        $sheet2->setCellValue("{$col_meal}{$row}", 'Y' );
    }
    
    $previous_address = $current_address;
    $row++;
}

// Close the final address group with border
if ( $group_start_row < $row ) {
    $sheet2->getStyle("B{$group_start_row}:{$sheet2_last_col}" . ($row - 1))
        ->getBorders()
        ->getOutline()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM);
}

// Style Delivery Report sheet like Kitchen Report
$lastRow = $row;
// Auto-size columns A–H
foreach ( range('A',"F") as $col ) {
    $sheet2->getColumnDimension($col)->setAutoSize(true);
}
// set column G width to 10 points $col_meal
$sheet2->getColumnDimension('G')->setWidth(2);
// Set text of Row 4 be Bold
$sheet2->getStyle("A4:{$sheet2_last_col}4")->getFont()->setBold(true);
// Header rows font: size 16 & bold
$sheet2->getStyle('A1:F2')
    ->getFont()
    ->setSize(16)
    ->setBold(true);
// All other cells (from row 3 onward): size 13
$sheet2->getStyle("A3:{$col_meal}{$lastRow}")
        ->getFont()
        ->setSize(13);

// 1) Left-align all cells in Delivery Report
$sheet2->getStyle("A1:{$col_meal}{$lastRow}")
        ->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        
// setAutoFilter
$sheet2->setAutoFilter("E{$delivery_table_start_row}:E{$lastRow}");

// 9) Build "Delivery Label" sheet
$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle('Delivery Label');
// Header rows
$sheet3->setCellValue('A1','Report Time: '. current_time('Y/m/d H:i') );
$sheet3->setCellValue('A2','Date Range: '. $start .' – '. $end );
$sheet3->mergeCells('A1:H1');
$sheet3->mergeCells('A2:H2');

// Column titles with blank "送貨日期" at A
$headers = ['餐點日期','訂購編號','稱呼','聯絡電話','送貨地點','餐點','備註','湯壺','餐'];
foreach ( $headers as $i => $title ) {
    $col = chr(65 + $i); // A…H
    $sheet3->setCellValue("{$col}4", $title );
}

// Data rows
$row3 = 5;

foreach ( $records as $rec ) {
    $addr     = maybe_unserialize( $rec->address );
    $prefArr  = maybe_unserialize( $rec->preference );
    $booking  = new Booking( $rec->booking_id );
    $order_id = $booking->get_order_id();
    
    // Get the sequential order number if available, otherwise fallback to regular order ID
    $order = wc_get_order($order_id);
    $display_order_number = $order ? $order->get_order_number() : $order_id;

    // A: 送貨日期 (leave blank)
    $sheet3->setCellValue("A{$row3}", $rec->date);

    // B: order id
    $sheet3->setCellValue("B{$row3}", $display_order_number );
    // C: name
    $sheet3->setCellValue("C{$row3}", trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')) );
    // D: phone
    $sheet3->setCellValue("D{$row3}", ( $addr['phone_country']?? '' ) . ' ' . ($addr['phone'] ?? '') );
    // E: location
    $sheet3->setCellValue("E{$row3}", trim(($addr['city'] ?? '') . ' ' . ($addr['address'] ?? '')) );

    // F: meals by category, apply prefix only via $prefix_map
    $choiceArr = maybe_unserialize( $rec->choice );
    $parts     = [];
    // 1) Prefix categories (excluding category id of soup)
    foreach ( $prefix_ids as $cid ) {
        if ( $cid === $soup_cat_id || empty( $choiceArr[ $cid ] ) ) {
            continue;
        }
        $catTitle = $term_map[ $cid ] ?? "Cat{$cid}";
        $tarr = [];
        foreach ( (array) $choiceArr[ $cid ] as $mid ) {
            $label = isset( $prefix_map[ $mid ] ) ? $prefix_map[$mid] . '. ' : '';
            $tarr[] = $label . ( $meal_map[$mid] ?? "Meal{$mid}" );
        }
        $parts[] = $catTitle . ":\n" . implode("\n", $tarr);
    }
    // 2) Other categories (not in prefix_ids or category id of soup)
    foreach ( $choiceArr as $cid => $mids ) {
        if ( in_array($cid, $prefix_ids, true) || $cid === $soup_cat_id ) {
            continue;
        }
        $catTitle = $term_map[$cid] ?? "Cat{$cid}";
        $tarr = [];
        foreach ( (array) $mids as $mid ) {
            $tarr[] = $meal_map[$mid] ?? "Meal{$mid}";
        }
        $parts[] = $catTitle . ":\n" . implode("\n", $tarr);
    }
    // 3) Category id of soup last
    if ( ! empty( $choiceArr[$soup_cat_id] ) ) {
        $catTitle = $term_map[$soup_cat_id] ?? "湯水";
        $tarr = [];
        foreach ( (array) $choiceArr[$soup_cat_id] as $mid ) {
            $tarr[] = $meal_map[$mid] ?? "Meal{$mid}";
        }
        $parts[] = $catTitle . ":\n" . implode("\n", $tarr);
    }
    $sheet3->setCellValue("F{$row3}", implode("\n", $parts));
    $sheet3->getStyle("F{$row3}")
            ->getAlignment()
            ->setWrapText(true);

    // G: 備註 – combine delivery note, CS note, and allergy
    $delivery_note = [];
    if ( ! empty( $addr['delivery_note'] ) ) {
        $delivery_note[] = __('Delivery Note','catering-booking-and-scheduling') . ': ' . $addr['delivery_note'];
    }
    if ( ! empty( $WC_item->get_meta('cs_note', true) ) ) {
        $delivery_note[] = __('CS Note','catering-booking-and-scheduling') . ': ' . $WC_item->get_meta('cs_note', true);
    }
    $health_status = maybe_unserialize( $booking->health_status );
    if ( is_array( $health_status ) && is_array( $health_status['allergy'] ) && !empty( $health_status['allergy'] ) ) {
        $health_status['allergy'] = array_diff( $health_status['allergy'], ['no_allergy'] );
        if ( ! empty( $health_status['allergy'] ) ) {
            $allergy_titles = [];
            // Convert allergy IDs to titles using allergy_map
            foreach ( $health_status['allergy'] as $allergy_id ) {
                if ( isset( $allergy_map[$allergy_id] ) ) {
                    $allergy_titles[] = $allergy_map[$allergy_id];
                }
            }
            $delivery_note[] = __('Food Allergy','catering-booking-and-scheduling') . ': ' . implode(', ', $allergy_titles);
        }
    }

    $sheet3->setCellValue("G{$row3}", implode("\n", $delivery_note));
    $sheet3->getStyle("G{$row3}")
            ->getAlignment()
            ->setWrapText(true);

    // H: 湯壺 – list all category soup items with correct container suffix
    $soup_labels = [];
    $container   = $prefArr['soup_container'] ?? '';
    if ( !empty( $choiceArr[$soup_cat_id] ) && !empty( $prefix_map_soup ) ) {
        foreach ( (array) $choiceArr[$soup_cat_id] as $mid ) {
            $pref = $prefix_map_soup[ $mid ] ?? '';
            if ( $container === 'pot' ) {
                $soup_labels[] = $pref . '壺';
            } elseif ( $container === 'cup' ) {
                $soup_labels[] = '2' . $pref . '杯';
            }
        }
    }
    $sheet3->setCellValue("H{$row3}", implode(' / ', $soup_labels));

    // I: 餐 – mark 'N' when only soup, otherwise 'Y'
    if ( count($choiceArr) === 1 && isset($choiceArr[$soup_cat_id]) ) {
        $sheet3->setCellValue("I{$row3}", 'N');
    } else {
        $sheet3->setCellValue("I{$row3}", 'Y');
    }

    $row3++;
}

// Style Delivery Label sheet
$last3 = $row3 - 1;
// setAutoFilter
$sheet3->setAutoFilter("A4:I{$last3}");
// Auto-size columns A–G
foreach ( range('A','I') as $col ) {
    $sheet3->getColumnDimension($col)->setAutoSize(true);
}
// Header font
$sheet3->getStyle("A1:G2")->getFont()->setSize(16)->setBold(true);
$sheet3->getStyle("A4:G4")->getFont()->setBold(true);
// Body font & left-align
$sheet3->getStyle("A3:I{$last3}")
        ->getFont()->setSize(14);
$sheet3->getStyle("A3:I{$last3}")
        ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

while ( ob_get_level() ) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="kitchen-report-'.$start.'_'.$end.'.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;




?>
