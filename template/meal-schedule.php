<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // 1) Fetch categories + meals from DB in PHP
    $catsData = getAllCategoriesAndMeals();
    set_transient('debug',$catsData,30);
    $allCategories = $catsData['categories'];
    $noCategory    = $catsData['noCategory'];
    $mealMap       = $catsData['mealMap'];
    $categoryColorMap = $catsData['categoryColorMap'];
    $categoryOrderMap = $catsData['categoryOrderMap'];
    // echo "<pre>";
    // echo print_r($catsData,1);
    // echo "</pre>";

    echo '<div class="wrap">';
    echo '<h1>' . __( 'Meal Schedule', 'catering-booking-and-scheduling' ) . '</h1>';

    // 2) Render the category tabs in HTML
    ?>
    <div style="margin-bottom:10px;" id="category-tabs">
        <!-- "No category" tab -->

        <?php
        // each cat
        foreach($catsData['categories'] as $catId => $catInfo) {
            echo '<span class="meal-cat-tab" data-cat="'.esc_attr($catId).'" style="cursor:pointer; margin-right:10px;">'
                . esc_html($catInfo['name'])
                . '</span>';
        }
        ?>
        <span class="meal-cat-tab" data-cat="noCategory" style="cursor:pointer; margin-right:10px; font-weight:bold;">
            <?php echo esc_html( __( "No category", "catering-booking-and-scheduling" ) ); ?>
        </span>
    </div>

    <!-- 3) For each tab, we have a hidden <div> that has the meal badges -->
    <div id="meal-list-container">
        <!-- No category div -->
        <div class="meal-list-tab" data-cat="noCategory" style="border:1px solid #ccc; padding:10px; display:none;">
            <?php foreach($noCategory as $m): ?>
              <div class="meal-badge" data-mealid="<?php echo (int)$m['id']; ?>" draggable="true"
                   style="display:inline-block; background:#eee; padding:5px 10px; margin:5px; cursor:move;">
                   <?php echo esc_html($m['name']); ?>
              </div>
            <?php endforeach; ?>
        </div>
        <?php foreach($allCategories as $catId => $catInfo): ?>
        <div class="meal-list-tab" data-cat="<?php echo esc_attr($catId); ?>" style="border:1px solid #ccc; padding:10px; display:none;">
            <?php foreach($catInfo['meals'] as $m): ?>
              <div class="meal-badge" data-mealid="<?php echo (int)$m['id']; ?>" draggable="true"
                   style="display:inline-block; background:<?php echo esc_attr($catInfo['color']); ?>; padding:5px 10px; margin:5px; cursor:move;">
                   <?php echo esc_html($m['name']); ?>
              </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 4) Month/Year nav -->
    <div style="margin-top:10px; margin-bottom:10px;">
        <button id="prev-month-btn" class="button"><?php _e('Previous','catering-booking-and-scheduling'); ?></button>

        <select id="month-select"></select>
        <select id="year-select"></select>

        <button id="next-month-btn" class="button"><?php _e('Next','catering-booking-and-scheduling'); ?></button>
    </div>

    <!-- 5) Calendar container -->
    <div id="meal-schedule-calendar" style="border:1px solid #ccc; padding:10px; min-height:300px;"></div>

    <!-- 6) Save button -->
    <div style="margin-top:10px;">
        <button id="save-meal-schedule" class="button button-primary">
            <?php _e('Save','catering-booking-and-scheduling'); ?>
        </button>
    </div>

    <?php
    echo '</div>'; // end .wrap

    // 7) Inline JS
    ?>
    <script>
    (function(jQuery){
      // scheduleDataMap is a plain Object: date (YYYY-MM-DD) → array of objects { meal_id: number, cat_id: string|number }
      let scheduleDataMap = {};
      // Global holiday map (unchanged)
      let currentHolidayMap = {};

      var mealMap = <?php echo wp_json_encode($mealMap); ?>;
      var categoryColorMap = <?php echo wp_json_encode($categoryColorMap); ?>;
      var categoryOrderMap = <?php echo wp_json_encode($categoryOrderMap); ?>;
      let currentMonth, currentYear;

      jQuery(document).ready(function(){
        initCategoryTabs();
        buildMonthSelect();
        buildYearSelect();

        let now = new Date();
        currentYear  = now.getFullYear();
        currentMonth = now.getMonth() + 1;

        jQuery('#month-select').val( pad2(currentMonth) );
        jQuery('#year-select').val( currentYear );

        loadScheduleDataMonth(currentYear, currentMonth);

        // Prev / Next for month
        jQuery('#prev-month-btn').on('click', function(){
          currentMonth--;
          if(currentMonth < 1){
            currentMonth = 12;
            currentYear--;
          }
          updateMonthYearSelect();
          scheduleDataMap = {};
          loadScheduleDataMonth(currentYear, currentMonth);
        });
        jQuery('#next-month-btn').on('click', function(){
          currentMonth++;
          if(currentMonth > 12){
            currentMonth = 1;
            currentYear++;
          }
          updateMonthYearSelect();
          scheduleDataMap = {};
          loadScheduleDataMonth(currentYear, currentMonth);
        });

        jQuery('#month-select').on('change', function(){
          currentMonth = parseInt(jQuery(this).val(), 10);
          scheduleDataMap = {};
          loadScheduleDataMonth(currentYear, currentMonth);
        });
        jQuery('#year-select').on('change', function(){
          currentYear = parseInt(jQuery(this).val(), 10);
          scheduleDataMap = {};
          loadScheduleDataMonth(currentYear, currentMonth);
        });

        jQuery('#save-meal-schedule').on('click', function(){
          saveScheduleChanges();
        });
      });

      function initCategoryTabs(){
        jQuery('.meal-cat-tab').on('click', function(){
          let catId = jQuery(this).data('cat')+'';
          jQuery('.meal-cat-tab').css('font-weight','normal');
          jQuery(this).css('font-weight','bold');
          jQuery('.meal-list-tab').hide();
          jQuery('.meal-list-tab[data-cat="'+catId+'"]').show();
        });
        jQuery('.meal-cat-tab:first-child').trigger('click');
      }

      function buildMonthSelect(){
        let sel = jQuery('#month-select');
        sel.empty();
        for(let m = 1; m <= 12; m++){
          let val = pad2(m);
          let txt = monthName(m);
          sel.append( jQuery('<option/>').val(val).text(txt));
        }
      }
      function buildYearSelect(){
        let sel = jQuery('#year-select');
        sel.empty();
        for(let y = 2023; y <= 2030; y++){
          sel.append( jQuery('<option/>').val(y).text(y));
        }
      }
      function updateMonthYearSelect(){
        jQuery('#month-select').val( pad2(currentMonth) );
        jQuery('#year-select').val( currentYear );
      }

      function loadScheduleDataMonth(year, month){
        jQuery.post(ajaxurl, {
          action: 'get_meal_schedule_month',
          year: year,
          month: month
        }, function(resp){
          if(resp.success){
            scheduleDataMap = Object.assign({}, resp.data.schedule);
            currentHolidayMap = Object.assign({}, resp.data.holiday);
            buildMonthGrid(year, month, scheduleDataMap, currentHolidayMap);
          } else {
            alert('Error: ' + resp.data);
          }
        });
      }

      function buildMonthGrid(year, month, scheduleMap, holidayMap){
        let container = jQuery('#meal-schedule-calendar');
        container.empty();

        let firstDay = new Date(year, month - 1, 1);
        let dayCount = new Date(year, month, 0).getDate();
        let startDow = firstDay.getDay();

        let html = '<table class="widefat schedule-calendar-table"><thead><tr>';
        let dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        dayNames.forEach(d => { html += '<th>' + d + '</th>'; });
        html += '</tr></thead><tbody>';

        let day = 1;
        for(let row = 0; row < 6; row++){
          let rowHtml = '<tr>';
          for(let dow = 0; dow < 7; dow++){
            if ((row === 0 && dow < startDow) || (day > dayCount)) {
              rowHtml += '<td style="background:#eee; color:#666; border:1px solid #ccc; width:100px; height:80px;">&nbsp;</td>';
            } else {
              let dateStr = year + '-' + pad2(month) + '-' + pad2(day);
              // If it's Sunday (dow === 0), include "Sunday" in holiday list.
              if (dow === 0) {
                  if (!holidayMap[dateStr]) {
                      holidayMap[dateStr] = [];
                  }
                  if (!holidayMap[dateStr].includes('Sunday')) {
                      holidayMap[dateStr].push('Sunday');
                  }
              }
              let mealArr = scheduleMap[dateStr] || [];
              let holArr = holidayMap[dateStr] || [];
              let cellHtml = buildDayCell(dateStr, mealArr, holArr);
              rowHtml += '<td style="vertical-align:top; border:1px solid #ccc; width:100px; height:80px;">' +
                         cellHtml +
                         '</td>';
              day++;
            }
          }
          rowHtml += '</tr>';
          html += rowHtml;
          if (day > dayCount) break;
        }

        html += '</tbody></table>';
        container.html(html);
        initDragDrop_onDayCells();
      }

      // Build the content of a day cell.
      // Each meal is now an object: { meal_id, cat_id }
      // We will use mealMap for the meal's name and categoryColorMap (global variable to be output from PHP)
      // for the background color.
      function buildDayCell(dateStr, mealInstances, holidayNames) {
        // Sort mealInstances first by category ordering, then by meal ID.
        mealInstances.sort(function(a, b) {
          // Retrieve ordering for category a and category b
          let orderA = parseInt(categoryOrderMap[a.cat_id]) || 999;
          let orderB = parseInt(categoryOrderMap[b.cat_id]) || 999;
          if (orderA === orderB) {
            return b.meal_id - a.meal_id;
          }
          return orderB - orderA;
        });

        let cell = '<div>' + dateStr + '</div>';

        // If there are holiday names, show them in red.
        if (holidayNames.length) {
          cell += '<div style="color:red; font-weight:bold;">' + holidayNames.join(', ') + '</div>';
        }

        // Render each meal instance.
        mealInstances.forEach(function(instance) {
          let mealID = instance.meal_id;
          let catID = instance.cat_id;
          let mealName = mealMap[mealID] || ('Meal #' + mealID);
          // Use categoryColorMap (global) to set badge background.
          let bgColor = (typeof categoryColorMap !== 'undefined' && categoryColorMap[catID]) ? categoryColorMap[catID] : 'lightblue';
          cell += '<div class="day-meal-badge" style="background:' + bgColor + '; padding:2px 5px; margin:2px;"'
                + ' data-mealid="' + mealID + '" data-catid="' + catID + '">'
                + mealName
                + ' <a href="#" class="day-meal-remove" data-mealid="' + mealID + '" data-catid="' + catID + '">X</a>'
                + '</div>';
        });
        return cell;
      }


      function initDragDrop_onDayCells(){
        // Handle removal: now we need to pass both meal_id and cat_id.
        jQuery('.day-meal-remove').on('click', function(e){
          e.preventDefault();
          let mealID = parseInt(jQuery(this).data('mealid'), 10);
          let catID = parseInt(jQuery(this).data('catid'), 10);
          let td = jQuery(this).closest('td');
          let dateStr = td.find('div:first').text().trim();
          removeMealFromDate(dateStr, mealID, catID);
        });

        // Set droppable only on day cells (for valid cells only)
        let tds = jQuery('#meal-schedule-calendar td');
        tds.each(function(){
          let dateStr = jQuery(this).find('div:first').text().trim();
          if(!dateStr || dateStr === '\xA0'){
            return;
          }
          jQuery(this).on('dragover', function(ev){
            ev.preventDefault();
          });
          jQuery(this).on('drop', function(ev){
            ev.preventDefault();
            let data = ev.originalEvent.dataTransfer.getData('text/plain');
            // Parse the JSON-encoded data (meal and category)
            try {
              let mealData = JSON.parse(data);
              addMealToDate(dateStr, mealData);
            } catch(e) {
              console.error("Error parsing drag data: ", e);
            }
          });
        });

        // Set dragstart on meal badges in the tab.
        // Use the container's data-cat attribute.
        jQuery('.meal-badge').on('dragstart', function(e){
          let mealID = parseInt(jQuery(this).data('mealid'), 10);
          // Retrieve category from the parent .meal-list-tab's data attribute.
          let catID = jQuery(this).closest('.meal-list-tab').data('cat');
          let dataObj = { meal_id: mealID, cat_id: catID };
          e.originalEvent.dataTransfer.setData('text/plain', JSON.stringify(dataObj));
        });
      }

      function addMealToDate(dateStr, mealData){
        if (!scheduleDataMap[dateStr]) {
          scheduleDataMap[dateStr] = [];
        }
        // Check for duplicates: if an instance with same meal_id and cat_id already exists, ignore.
        let exists = scheduleDataMap[dateStr].some(function(item){
          return (item.meal_id === mealData.meal_id && item.cat_id == mealData.cat_id);
        });
        if (!exists) {
          scheduleDataMap[dateStr].push(mealData);
        }
        rebuildDayCell(dateStr);
      }

      function removeMealFromDate(dateStr, mealID, catID){
        if (!scheduleDataMap[dateStr]) return;
        scheduleDataMap[dateStr] = scheduleDataMap[dateStr].filter(function(item){
          return !(item.meal_id === mealID && item.cat_id == catID);
        });
        rebuildDayCell(dateStr);
      }

      function rebuildDayCell(dateStr){
        let td = findTDbyDate(dateStr);
        if(!td) return;
        let arr = scheduleDataMap[dateStr] || [];
        let holidayArr = currentHolidayMap[dateStr] || [];
        let newHtml = buildDayCell(dateStr, arr, holidayArr);
        td.html(newHtml);
        initDragDrop_onDayCells();
      }

      function findTDbyDate(dateStr){
        let tds = jQuery('#meal-schedule-calendar td');
        for (let i = 0; i < tds.length; i++){
          let text = jQuery(tds[i]).find('div:first').text().trim();
          if (text === dateStr){
            return jQuery(tds[i]);
          }
        }
        return null;
      }

      function saveScheduleChanges(){
        jQuery.post(ajaxurl, { action:'check_can_update_schedule' }, function(resp){
          if (!resp.success) {
            alert('Cannot update: ' + resp.data);
            return;
          }
          performScheduleUpdate();
        });
      }

      function performScheduleUpdate(){
        let dataJson = JSON.stringify(scheduleDataMap);
        if (!dataJson || dataJson === '{}'){
          alert('No changes.');
          return;
        }
        console.log("Final scheduleDataMap:", scheduleDataMap);
        console.log("JSON data:", dataJson);
        jQuery.post(ajaxurl, {
          action: 'update_meal_schedule_batch',
          changes_json: dataJson
        }, function(resp){
          if (resp.success){
            alert('Schedule updated!');
            location.reload();
          } else {
            alert('Error: ' + resp.data);
          }
        });
      }

      function pad2(n){ return (n < 10) ? ('0' + n) : '' + n; }
      function monthName(m){
        let arr = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        return arr[m - 1];
      }
    })(jQuery);
    </script>





<?php

function getAllCategoriesAndMeals() {
    global $wpdb;
    $table_terms = $wpdb->prefix . 'catering_terms';
    $table_meal  = $wpdb->prefix . 'catering_meal';
    $table_rel   = $wpdb->prefix . 'catering_term_relationships';

    // 1) Fetch all categories (only those with type 'category')
    $catsRows = $wpdb->get_results("
        SELECT ID, title, color, ordering
        FROM $table_terms
        WHERE type='category'
        ORDER BY ordering ASC, ID ASC
    ", ARRAY_A);

    // Build an array: cat_id => { id, name, color, meals: [] }
    $allCategories = [];
    foreach ($catsRows as $c) {
        $catId = (int)$c['ID'];
        $allCategories[$catId] = [
            'id'    => $catId,
            'name'  => $c['title'],
            'color' => ($c['color'] && trim($c['color']) !== '') ? $c['color'] : '#FFFFFF',
            'meals' => []
        ];
    }

    // Build category map: so that we can output global categoryColorMap later.
    $categoryColorMap = [];
    foreach($allCategories as $catId => $cat) {
        $categoryColorMap[$catId] = $cat['color'];
    }

    $categoryOrderMap = []; // new mapping: cat_id => ordering
    foreach ($catsRows as $c) {
        $catId = (int)$c['ID'];
        $color = ($c['color'] && trim($c['color']) !== '') ? $c['color'] : '#FFFFFF';
        $allCategories[$catId] = [
            'id'    => $catId,
            'name'  => $c['title'],
            'color' => $color,
            'ordering' => $c['ordering'],
            'meals' => [] // will be filled later
        ];
        $categoryOrderMap[$catId] = $c['ordering'];
    }

    // 2) Fetch relationships (now a meal may have more than one category)
    $relRows = $wpdb->get_results("
        SELECT meal_id, term_id
        FROM $table_rel
        WHERE term_type='category'
    ", ARRAY_A);

    // Build meal->categories map: meal_id => array of category IDs
    $mealToCats = [];
    foreach($relRows as $r) {
        $mealId = (int)$r['meal_id'];
        $catId = (int)$r['term_id'];
        if (!isset($mealToCats[$mealId])) {
            $mealToCats[$mealId] = [];
        }
        $mealToCats[$mealId][] = $catId;
    }
    // Remove duplicate category IDs per meal
    foreach($mealToCats as $mealId => $cats) {
        $mealToCats[$mealId] = array_unique($cats);
    }

    // 3) Fetch all meals
    $mealRows = $wpdb->get_results("
        SELECT ID, title
        FROM $table_meal
        ORDER BY title
    ", ARRAY_A);

    // No category array
    $noCategoryMeals = [];

    // Build mealMap: meal_id => meal title
    $mealMap = [];

    // 4) Distribute each meal into every category it is linked to.
    foreach($mealRows as $m) {
        $mId = (int)$m['ID'];
        $mName = $m['title'];
        $mealMap[$mId] = $mName;

        if (isset($mealToCats[$mId])) {
            foreach ($mealToCats[$mId] as $catId) {
                if (isset($allCategories[$catId])) {
                    $allCategories[$catId]['meals'][] = [
                        'id'   => $mId,
                        'name' => $mName
                    ];
                }
            }
        } else {
            $noCategoryMeals[] = [
                'id'   => $mId,
                'name' => $mName
            ];
        }
    }

    return [
        'categories'      => $allCategories,      // catId => { id, name, color, meals: [...] }
        'noCategory'      => $noCategoryMeals,      // array of { id, name }
        'mealMap'         => $mealMap,            // mealID => mealTitle
        'categoryColorMap'=> $categoryColorMap,    // catId => HEX color
        'categoryOrderMap' => $categoryOrderMap
    ];
}



?>
