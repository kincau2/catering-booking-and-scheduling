<?php
global $post;
$product_id = intval($post->ID);
?>
<button id="preview-schedule-btn" class="button preview-button"><?php _e('Preview Schedule','catering-booking-and-scheduling'); ?></button>

<!-- Dark overlay for background -->
<div id="schedule-preview-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); z-index:9998;"></div>

<div id="schedule-preview-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:90%; max-width:1200px; max-height:90vh; background:#fff; z-index:9999; overflow:auto; padding:15px; border-radius:8px; box-shadow:0 5px 15px rgba(0,0,0,0.3);">
  <button id="schedule-preview-close" class="cb-btn cb-btn--icon" style="position:absolute; top:10px; right:10px; font-size:24px; background:none; border:none; cursor:pointer;">&times;</button>
  <h3><?php _e('Meal Schedule Preview','catering-booking-and-scheduling'); ?></h3>
  
  <!-- Navigation controls -->
  <div class="preview-navigation">
    <div class="week-navigation">
      <button id="prev-week-btn" class="nav-button">&laquo;</button>
      <span id="week-indicator">08/06 - 14/06</span>
      <button id="next-week-btn" class="nav-button">&raquo;</button>
    </div>
    <div class="day-navigation">
      <button id="prev-day-btn" class="nav-button">&laquo;</button>
      <span id="day-indicator"><?php _e('Sunday','catering-booking-and-scheduling'); ?> 08/06</span>
      <button id="next-day-btn" class="nav-button">&raquo;</button>
    </div>
  </div>
  
  <div id="schedule-preview-container">
    <!-- Week 1 -->
    <div id="week-1-container" class="week-container">
      <table class="schedule-table">
        <thead>
          <tr>
            <th><?php _e('Sunday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Monday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Tuesday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Wednesday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Thursday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Friday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Saturday','catering-booking-and-scheduling'); ?></th>
          </tr>
        </thead>
        <tbody id="schedule-week1">
          <!-- First week will be populated here -->
        </tbody>
      </table>
    </div>
    
    <!-- Week 2 -->
    <div id="week-2-container" class="week-container">
      <table class="schedule-table">
        <thead>
          <tr>
            <th><?php _e('Sunday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Monday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Tuesday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Wednesday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Thursday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Friday','catering-booking-and-scheduling'); ?></th>
            <th><?php _e('Saturday','catering-booking-and-scheduling'); ?></th>
          </tr>
        </thead>
        <tbody id="schedule-week2">
          <!-- Second week will be populated here -->
        </tbody>
      </table>
    </div>
    
    <!-- Mobile Day View -->
    <div id="day-container" class="day-container">
      <!-- Single day content will be populated here -->
    </div>
  </div>
</div>

<style>
#schedule-preview-container {
  margin-top: 15px;
}

.preview-navigation {
  display: flex;
  justify-content: center;
  align-items: center;
  margin-bottom: 20px;
}

.week-navigation, .day-navigation {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* Navigation buttons */
.nav-button {
  background-color: #f0f0f0;
  color: #333;
  border: 1px solid #ddd;
  border-radius: 4px;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 14px;
  font-weight: bold;
}

.nav-button:hover {
  background-color: #e0e0e0;
}

/* Disabled button styles */
.nav-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  background: #f7f7f7;
  color: #a0a5aa;
  border-color: #ddd;
}

#week-indicator, #day-indicator {
  font-weight: bold;
  min-width: 120px;
  text-align: center;
  font-size: 16px;
}

.day-navigation {
  display: none; /* Hidden by default, shown in mobile view */
}

/* Schedule table styling */
.schedule-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 10px;
  margin-top: 10px;
  table-layout: fixed;
}

table.schedule-table td, table.schedule-table th {
    border-radius: 5px;
}

.schedule-table th {
  background-color: #3498db;
  color: white;
  text-align: center;
  padding: 12px 8px;
  font-weight: normal;
  border: none;
}

.schedule-table th:first-child {
  border-top-left-radius: 8px;
}

.schedule-table th:last-child {
  border-top-right-radius: 8px;
}

.schedule-table td {
  background-color: #f8f8f8;
  vertical-align: top;
  padding: 10px;
  height: 120px;
  box-shadow: 0 3px 6px #0000001f;
  position: relative;
}

div#schedule-preview-modal{
  font-size: 14px!important;
}

/* Ensure consistent date display */
.date-display {
  text-align: center;
  background-color: #f0f0f0;
  padding: 5px;
  border-radius: 4px;
  margin-bottom: 8px;
  font-weight: bold;
}

#week-2-container {
  display: none; /* Hidden by default */
}

.date-header {
  font-weight: bold;
  text-align: center;
  padding: 5px 0;
  margin: 0;
  background-color: #f0f0f0;
  border-radius: 4px;
}

.holiday-container {
  text-align: center;
  margin: 5px 0 8px;
  min-height: 20px;
  /* display: none; */ /* removed so populated containers show */
}

.holiday-container:empty {
  display: none; /* only hide when there is no content */
}

.holiday-label {
  color: #d00;
  font-weight: bold;
  display: inline-block;
  padding: 2px 5px;
  margin: 2px 0;
  background-color: #ffecec;
  border-radius: 3px;
}

.meal-category {
  margin-top: 10px;
  font-weight: bold;
  padding: 3px 0;
}

.meal-item {
  display: block;
  margin: 3px 0;
  padding: 5px;
  text-align: center;
  color: #333;
  border-radius: 3px;
  font-weight:  500;
}

#day-container {
  display: none; /* Hidden in desktop view */
  padding: 15px;
  background-color: #f8f8f8;
  border-radius: 8px;
}

/* Modern flat style for preview button */
.preview-button {
  background-color: #3498db !important;
  color: #fff !important;
  border: none !important;
  border-radius: 4px !important;
  padding: 8px 16px !important;
  font-size: 14px !important;
  font-weight: 500 !important;
  cursor: pointer !important;
  transition: background-color 0.3s ease !important;
  text-transform: uppercase !important;
  letter-spacing: 0.5px !important;
  box-shadow: 0 2px 5px rgba(0,0,0,0.1) !important;
  outline: none !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  height: auto !important;
  line-height: normal !important;
}

.preview-button:hover {
  background-color: #2980b9 !important;
  box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;
}

.preview-button:active {
  background-color: #2471a3 !important;
  box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
  transform: translateY(1px) !important;
}

/* Adding an icon for extra polish */
.preview-button:before {
  content: "\f177";
  font-family: dashicons;
  font-size: 16px;
  margin-right: 6px;
  vertical-align: middle;
}

.schedule-preview-container {
  margin-top: 15px;
}

.preview-navigation {
  display: flex;
  justify-content: center;
  align-items: center;
  margin-bottom: 20px;
}

.week-navigation, .day-navigation {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* Navigation buttons */
.nav-button {
  background-color: #f0f0f0;
  color: #333;
  border: 1px solid #ddd;
  border-radius: 4px;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 14px;
  font-weight: bold;
}

.nav-button:hover {
  background-color: #e0e0e0;
}

/* Disabled button styles */
.nav-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  background: #f7f7f7;
  color: #a0a5aa;
  border-color: #ddd;
}

#week-indicator, #day-indicator {
  font-weight: bold;
  min-width: 120px;
  text-align: center;
  font-size: 16px;
}

.day-navigation {
  display: none; /* Hidden by default, shown in mobile view */
}

/* Schedule table styling */
.schedule-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 10px;
  margin-top: 10px;
  table-layout: fixed;
}

table.schedule-table td, table.schedule-table th {
    border-radius: 5px;
}

.schedule-table th {
  background-color: #3498db;
  color: white;
  text-align: center;
  padding: 12px 8px;
  font-weight: normal;
  border: none;
}

.schedule-table th:first-child {
  border-top-left-radius: 8px;
}

.schedule-table th:last-child {
  border-top-right-radius: 8px;
}

.schedule-table td {
  background-color: #f8f8f8;
  vertical-align: top;
  padding: 10px;
  height: 120px;
  box-shadow: 0 3px 6px #0000001f;
  position: relative;
}

div#schedule-preview-modal{
  font-size: 14px!important;
}

/* Ensure consistent date display */
.date-display {
  text-align: center;
  background-color: #f0f0f0;
  padding: 5px;
  border-radius: 4px;
  margin-bottom: 8px;
  font-weight: bold;
}

#week-2-container {
  display: none; /* Hidden by default */
}

.date-header {
  font-weight: bold;
  text-align: center;
  padding: 5px 0;
  margin: 0;
  background-color: #f0f0f0;
  border-radius: 4px;
}

.holiday-container {
  text-align: center;
  margin: 5px 0 8px;
  min-height: 20px;
  /* display: none; */ /* removed so populated containers show */
}

.holiday-container:empty {
  display: none; /* only hide when there is no content */
}

.holiday-label {
  color: #d00;
  font-weight: bold;
  display: inline-block;
  padding: 2px 5px;
  margin: 2px 0;
  background-color: #ffecec;
  border-radius: 3px;
}

.meal-category {
  margin-top: 10px;
  font-weight: bold;
  padding: 3px 0;
}

.meal-item {
  display: block;
  margin: 3px 0;
  padding: 5px;
  text-align: center;
  color: #333;
  border-radius: 3px;
  font-weight:  500;
}

#day-container {
  display: none; /* Hidden in desktop view */
  padding: 15px;
  background-color: #f8f8f8;
  border-radius: 8px;
}

/* Mobile styles */
@media screen and (max-width: 768px) {
  #schedule-preview-modal {
    width: 95%;
    padding: 10px;
    top: 5%;
    left: 2.5%;
    transform: none;
    max-height: 90%;
  }
  
  .week-container {
    display: none !important; /* Hide week view on mobile */
  }
  
  #day-container {
    display: block; /* Show day view on mobile */
  }
  
  .week-navigation {
    display: none; /* Hide week navigation */
  }
  
  .day-navigation {
    display: flex; /* Show day navigation */
  }
}

/* Small mobile adjustments */
@media screen and (max-width: 480px) {
  #schedule-preview-modal h3 {
    font-size: 1.1em;
    margin-top: 15px;
    margin-bottom: 10px;
  }
  
  .day-navigation {
    flex-wrap: wrap;
  }
  
  .meal-category {
    font-size: 0.9em;
  }
  
  .meal-item {
    font-size: 0.8em;
  }
  
  .holiday-label {
    font-size: 0.8em;
  }
}
</style>

<script src="<?php echo plugins_url('../include/js/frontend-ajax.js', __FILE__); ?>"></script>
<script>
jQuery(function($){
  // Track current state
  var currentWeek = 1;
  var currentDay = 0; // 0 = Sunday, 1 = Monday, etc.
  var allDays = [];
  var weekData = {};
  
  function formatISO(d) {
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }
  
  function formatDisplayDate(d) {
    return ('0'+d.getDate()).slice(-2) + '/' + ('0'+(d.getMonth()+1)).slice(-2);
  }
  
  // Format date like in the image: DD/MM format
  function formatDisplayDateShort(d) {
    return ('0'+d.getDate()).slice(-2) + '/' + ('0'+(d.getMonth()+1)).slice(-2);
  }
  
  function getDayName(day) {
    var days = [
      '<?php _e('Sunday','catering-booking-and-scheduling'); ?>',
      '<?php _e('Monday','catering-booking-and-scheduling'); ?>',
      '<?php _e('Tuesday','catering-booking-and-scheduling'); ?>',
      '<?php _e('Wednesday','catering-booking-and-scheduling'); ?>',
      '<?php _e('Thursday','catering-booking-and-scheduling'); ?>',
      '<?php _e('Friday','catering-booking-and-scheduling'); ?>',
      '<?php _e('Saturday','catering-booking-and-scheduling'); ?>'
    ];
    return days[day];
  }
  
  // Format date range (DD/MM - DD/MM format as in image)
  function formatDateRange(startDate, endDate) {
    return formatDisplayDateShort(startDate) + ' - ' + formatDisplayDateShort(endDate);
  }
  
  function updateDayNavigation() {
    // Update with day name and date format
    $('#day-indicator').text(getDayName(currentDay % 7) + ' ' + formatDisplayDateShort(allDays[currentDay]));
    
    // Disable/enable previous button based on if we're on the first day
    $('#prev-day-btn').prop('disabled', currentDay === 0);
    
    // Disable/enable next button based on if we're on the last day
    $('#next-day-btn').prop('disabled', currentDay === 13);
    
    // Update day container
    var dayDate = formatISO(allDays[currentDay]);
    renderDayView(dayDate);
  }
  
  function renderDayView(date) {
    var $container = $('#day-container').empty();
    
    // Create day header
    var dayObj = new Date(date);
    var displayDate = formatDisplayDate(dayObj);
    var dayName = getDayName(dayObj.getDay());
    
    $container.append('<h4 style=" margin-top: unset; ">' + dayName + ' ' + displayDate + '</h4>');
    
    // Add holiday container but keep it hidden initially
    var $holidayContainer = $('<div class="holiday-container"></div>');
    $container.append($holidayContainer);
    
    var hasHolidays = false;
    
    // Check if this is a Sunday
    if (dayObj.getDay() === 0) {
      $holidayContainer.append('<div class="holiday-label">星期日</div>');
      hasHolidays = true;
    }
    
    // Add holiday data
    if (weekData.holidays && weekData.holidays[date]) {
      weekData.holidays[date].forEach(function(holiday) {
        $holidayContainer.append('<div class="holiday-label">' + holiday + '</div>');
        hasHolidays = true;
      });
    }
    
    // Show holiday container only if it contains holidays
    if (hasHolidays) {
      $holidayContainer.show();
    }
    
    // Add meal data from the schedule
    if (weekData.schedule && weekData.schedule[date]) {
      var sched = weekData.schedule[date];
      var terms = weekData.terms;
      
      // Get all category IDs and sort by ordering
      var catIds = Object.keys(sched).sort(function(a, b) {
        return terms[a].ordering - terms[b].ordering;
      });
      
      // Add each category and its meals
      catIds.forEach(function(catId) {
        var cat = terms[catId];
        $container.append('<div class="meal-category">' + cat.title + '</div>');
        
        // Add meals for this category
        sched[catId].forEach(function(meal) {
          var bgColor = cat.color || '#ccc';
          $container.append(
            '<div class="meal-item" style="background-color:' + bgColor + '">'+
            meal.title +
            '</div>'
          );
        });
      });
    } else {
      // No meals for this day
      $container.append('<p><?php _e('No meals scheduled for this day.', 'catering-booking-and-scheduling'); ?></p>');
    }
  }
  
  function switchWeek(weekNum) {
    // Update UI with date range in the format shown in the image
    var weekStart, weekEnd;
    
    if (weekNum === 1) {
      weekStart = allDays[0];
      weekEnd = allDays[6];
    } else {
      weekStart = allDays[7];
      weekEnd = allDays[13];
    }
    
    $('#week-indicator').text(formatDateRange(weekStart, weekEnd));
    
    // Disable previous button on first week
    $('#prev-week-btn').prop('disabled', weekNum === 1);
    
    // Disable next button on second week
    $('#next-week-btn').prop('disabled', weekNum === 2);
    
    // Hide all week containers
    $('.week-container').hide();
    
    // Show the selected week
    $('#week-' + weekNum + '-container').show();
    
    currentWeek = weekNum;
  }
  
  function navigateDay(direction) {
    var newDay = currentDay + direction;
    
    // Handle wrapping around the ends (but now we'll enforce limits)
    if (newDay < 0) {
      newDay = 0; // Limit to first day
    } else if (newDay > 13) {
      newDay = 13; // Limit to last day
    }
    
    currentDay = newDay;
    updateDayNavigation();
  }
  
  // Initialize schedule preview
  $('#preview-schedule-btn').on('click', function(){
    // Get date range
    var today = new Date();
    var mon = new Date(today);
    mon.setDate(mon.getDate() - today.getDay()); // Start from Sunday
    var start = mon, end = new Date(mon);
    end.setDate(end.getDate() + 13); // Two weeks
    
    // Initialize allDays array for day navigation
    allDays = [];
    for (var i = 0; i < 14; i++) {
      var d = new Date(start);
      d.setDate(d.getDate() + i);
      allDays.push(d);
    }
    
    // Reset to first week
    currentWeek = 1;
    
    // Find today's index in allDays for mobile view
    var todayStr = formatISO(today);
    currentDay = 0; // Default to first day
    for (var i = 0; i < allDays.length; i++) {
      if (formatISO(allDays[i]) === todayStr) {
        currentDay = i;
        break;
      }
    }
    
    // Show modal and overlay
    $('#schedule-preview-overlay, #schedule-preview-modal').show();
    
    // Set up initial view
    switchWeek(1);
    
    // Clear existing content
    $('#schedule-week1, #schedule-week2').empty();
    $('#day-container').empty();
    
    // Create week rows
    var week1Row = $('<tr>');
    var week2Row = $('<tr>');
    
    // Initialize weekData.holidays
    weekData.holidays = {};
    
    // Add cells for each day
    for (var i = 0; i < 14; i++) {
      var d = new Date(start);
      d.setDate(d.getDate() + i);
      var date = formatISO(d);
      var displayDate = formatDisplayDateShort(d);
      
      var cell = $('<td data-date="' + date + '">');
      // Add date header as in the image
      cell.append('<div class="date-header">' + displayDate + '</div>');
      
      // Add holiday container
      var $holidayContainer = $('<div class="holiday-container"></div>');
      cell.append($holidayContainer);
      
      // Initialize holiday array for this date
      if (!weekData.holidays[date]) {
        weekData.holidays[date] = [];
      }
      
      // Check if this is a Sunday and mark as holiday
      if (d.getDay() === 0) {
        $holidayContainer.append('<div class="holiday-label">星期日</div>');
        weekData.holidays[date].push('星期日');
        $holidayContainer.show(); // Show the container immediately
      }
      
      // Add to appropriate week
      if (i < 7) {
        week1Row.append(cell);
      } else {
        week2Row.append(cell);
      }
    }
    
    $('#schedule-week1').append(week1Row);
    $('#schedule-week2').append(week2Row);
    
    // Update day navigation
    updateDayNavigation();
    
    // Fetch schedule data
    CateringAjax.getSchedulePreview(<?php echo $product_id;?>, formatISO(start), formatISO(end))
      .done(function(resp){
        if (!resp.success) return;
        
        var sched = resp.data.schedule;
        var terms = resp.data.terms;
        weekData.schedule = sched;
        weekData.terms = terms;
        weekData.holidays = {};
        
        // Process each date in the schedule
        Object.keys(sched).forEach(function(date) {
          var $cell = $('td[data-date="'+date+'"]');
          if (!$cell.length) return;
          
          // Get all category IDs and sort by ordering
          var catIds = Object.keys(sched[date]).sort(function(a, b) {
            return terms[a].ordering - terms[b].ordering;
          });
          
          // Add each category and its meals
          catIds.forEach(function(catId) {
            var cat = terms[catId];
            $cell.append('<div class="meal-category">' + cat.title + '</div>');
            
            // Add meals for this category
            sched[date][catId].forEach(function(meal) {
              var bgColor = cat.color || '#ccc';
              $cell.append(
                '<div class="meal-item" style="background-color:'+bgColor+'">'+
                  meal.title +
                '</div>'
              );
            });
          });
        });
        
        // Fetch and display holidays
        var months = [{year: start.getFullYear(), month: start.getMonth()+1}];
        var endMonth = {year: end.getFullYear(), month: end.getMonth()+1};
        if (months[0].year !== endMonth.year || months[0].month !== endMonth.month) {
          months.push(endMonth);
        }
        
        months.forEach(function(mo) {
          CateringAjax.getHolidayData(mo.year, mo.month).done(function(hr) {
            if (!hr.success) return;
            
            hr.data.forEach(function(h) {
              var $cell = $('td[data-date="'+h.date+'"]');
              if ($cell.length) {
                // Get the holiday container to place badges properly
                var $holidayContainer = $cell.find('.holiday-container');
                var hasHolidays = false;
                
                // Initialize holiday array if needed (should already be initialized above)
                if (!weekData.holidays[h.date]) {
                  weekData.holidays[h.date] = [];
                }
                
                // We don't need to check for Sunday here since we did that above
                // Just add other holiday names
                if (h.holidayNames && h.holidayNames.length) {
                  $holidayContainer.append('<div class="holiday-label">' + h.holidayNames.join(', ') + '</div>');
                  weekData.holidays[h.date] = weekData.holidays[h.date].concat(h.holidayNames);
                  hasHolidays = true;
                }
                
                // Show the holiday container if it has content
                if (hasHolidays) {
                  $holidayContainer.show();
                }
                
                // Update mobile view if this is the current day
                if (formatISO(allDays[currentDay]) === h.date) {
                  updateDayNavigation();
                }
              }
            });
          });
        });
      });
  });
  
  // Navigation button handlers
  $('#prev-week-btn').on('click', function() {
    if ($(this).prop('disabled')) return;
    switchWeek(currentWeek === 1 ? 2 : 1);
  });
  
  $('#next-week-btn').on('click', function() {
    if ($(this).prop('disabled')) return;
    switchWeek(currentWeek === 1 ? 2 : 1);
  });
  
  $('#prev-day-btn').on('click', function() {
    if ($(this).prop('disabled')) return;
    navigateDay(-1);
  });
  
  $('#next-day-btn').on('click', function() {
    if ($(this).prop('disabled')) return;
    navigateDay(1);
  });
  
  // Close modal and overlay
  $('#schedule-preview-close, #schedule-preview-overlay').on('click', function(){
    $('#schedule-preview-modal, #schedule-preview-overlay').hide();
  });
  
  // Close on escape key
  $(document).keyup(function(e) {
    if (e.key === "Escape") {
      $('#schedule-preview-modal, #schedule-preview-overlay').hide();
    }
  });
});
</script>
