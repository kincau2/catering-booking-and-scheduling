<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

if ( ! current_user_can( 'manage_catering' ) ) {
    return;
}

// Enqueue the centralized CSS file
wp_enqueue_style('catering-styles', plugin_dir_url(dirname(__FILE__)) . 'public/catering.css', array(), '1.0.0', 'all');

// Prepare translatable day names for JavaScript
$translatable_days = array(
    __("Sun", "catering-booking-and-scheduling"),
    __("Mon", "catering-booking-and-scheduling"),
    __("Tue", "catering-booking-and-scheduling"),
    __("Wed", "catering-booking-and-scheduling"),
    __("Thu", "catering-booking-and-scheduling"),
    __("Fri", "catering-booking-and-scheduling"),
    __("Sat", "catering-booking-and-scheduling")
);

?>
<div class="wrap">
    <div class="header-section">
        <div class="catering-header"><?php _e( 'Holiday Setting', 'catering-booking-and-scheduling' ); ?></div>
    </div>

    <!-- iCal Import Section -->
    <div class="import-section">
        <h2><?php _e( 'Import iCal', 'catering-booking-and-scheduling' ); ?></h2>
        <form method="post" enctype="multipart/form-data" id="ical-import-form" class="import-form">
            <div class="file-input-wrapper">
                <input type="file" name="ical_file" accept=".ics" id="ical-file-input" />
                <label for="ical-file-input" class="file-input-label">
                    <span class="file-icon">📁</span>
                    <span class="file-text"><?php _e( 'Choose iCal file (.ics)', 'catering-booking-and-scheduling' ); ?></span>
                </label>
            </div>
            <button type="button" class="btn btn-primary" id="ical-import-btn">
                <?php _e( 'Import iCal', 'catering-booking-and-scheduling' ); ?>
            </button>
        </form>
    </div>

    <!-- Calendar Navigation Section -->
    <div class="calendar-section">
        <div class="calendar-header">
            <h2><?php _e( 'Holiday Calendar', 'catering-booking-and-scheduling' ); ?></h2>
            <div class="calendar-controls">
                <button id="prev-month-btn" class="btn btn-nav">‹</button>
                <select id="month-select" class="month-selector"></select>
                <button id="next-month-btn" class="btn btn-nav">›</button>
            </div>
        </div>

        <!-- Calendar Container -->
        <div id="holiday-calendar" class="holiday-calendar-wrapper">
            <!-- Filled by JS -->
        </div>

        <!-- Save button -->
        <div class="save-section">
            <button id="save-holiday-changes" class="btn btn-success">
                <?php _e( 'Save Changes', 'catering-booking-and-scheduling' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- Holiday popup -->
<div id="add-holiday-popup" class="holiday-popup">
    <div class="popup-content">
        <h3><?php _e('Add Holiday', 'catering-booking-and-scheduling'); ?></h3>
        <div class="form-group">
            <label for="holiday-name-input"><?php _e('Holiday Name:', 'catering-booking-and-scheduling'); ?></label>
            <input type="text" id="holiday-name-input" class="form-input" placeholder="<?php _e('Enter holiday name', 'catering-booking-and-scheduling'); ?>" />
        </div>
        <div class="popup-actions">
            <button id="confirm-add-holiday" class="btn btn-primary">
                <?php _e('Add', 'catering-booking-and-scheduling'); ?>
            </button>
            <button id="cancel-add-holiday" class="btn btn-secondary">
                <?php _e('Cancel', 'catering-booking-and-scheduling'); ?>
            </button>
        </div>
    </div>
</div>

<script>
(function(jQuery){
  let currentYear, currentMonth;
  // This object: { '2025-03-10': ['HolidayA','HolidayB'], ... }
  // We store all the loaded data + user changes in memory
  let holidayDataMap = {};
  // Translated day names from PHP
  let dayNames = <?php echo json_encode($translatable_days); ?>;

  jQuery(document).ready(function(){
     buildMonthSelect();

     // Default to current date
     let now = new Date();
     currentYear  = now.getFullYear();
     currentMonth = now.getMonth()+1;

     // set select
     jQuery('#month-select').val( currentYear + '-' + pad2(currentMonth) );

     loadCalendarData(currentYear, currentMonth);

     // Prev / Next
     jQuery('#prev-month-btn').on('click', function(){
        currentMonth--;
        if(currentMonth<1){ currentMonth=12; currentYear--; }
        holidayDataMap = {};
        jQuery('#month-select').val( currentYear + '-' + pad2(currentMonth) );
        loadCalendarData(currentYear, currentMonth);
     });
     jQuery('#next-month-btn').on('click', function(){
        currentMonth++;
        if(currentMonth>12){ currentMonth=1; currentYear++; }
        holidayDataMap = {};
        jQuery('#month-select').val( currentYear + '-' + pad2(currentMonth) );
        loadCalendarData(currentYear, currentMonth);
     });
     jQuery('#month-select').on('change', function(){
        let parts = jQuery(this).val().split('-');
        currentYear = parseInt(parts[0]);
        currentMonth= parseInt(parts[1]);
        holidayDataMap = {};
        loadCalendarData(currentYear, currentMonth);
     });

     // Save
     jQuery('#save-holiday-changes').on('click', function(){
        saveHolidayChanges();
     });

     jQuery('#ical-import-btn').on('click', function(){
         // We'll do an AJAX approach to parse the file from #ical-import-form
         let fileInput = jQuery('input[name="ical_file"]')[0];
         if(!fileInput.files.length){
           alert('<?php echo esc_js( __( "Please select an iCal file.", "catering-booking-and-scheduling" ) ); ?>');
           return;
         }
         let formData = new FormData( jQuery('#ical-import-form')[0] );

         // Ajax to parse iCal
         jQuery.ajax({
           url: ajaxurl + '?action=import_ical',
           method: 'POST',
           data: formData,
           processData: false,
           contentType: false,
           success: function(resp){
              if(resp.success){
                // resp.data => e.g. array of { date:'2025-01-05', name:'Birthday' }
                let allEvents = resp.data;
                if(!allEvents.length){
                  alert('<?php echo esc_js( __( "No events found in iCal.", "catering-booking-and-scheduling" ) ); ?>');
                  return;
                }

                // Build a preview message
                // e.g. "iCal has these events:\n\n2025-01-05: Birthday\n2025-01-10: Something else\n\nSet these days as holiday?"
                let lines = allEvents.map(ev => (ev.date + ': ' + ev.name)).join(' | ');
                let msg = '<?php echo esc_js( __( "iCal has these events:", "catering-booking-and-scheduling" ) ); ?>\n\n'
                          + lines
                          + '\n\n<?php echo esc_js( __( "Set these days as holiday?", "catering-booking-and-scheduling" ) ); ?>';
                if(confirm(msg)){
                  // pass the entire array to batch_holiday_set
                  jQuery.post(ajaxurl, {
                    action: 'batch_holiday_set',
                    events: allEvents
                  }, function(r2){
                    if(r2.success){
                      alert('<?php echo esc_js( __( "Holidays successfully import.", "catering-booking-and-scheduling" ) ); ?>');
                      location.reload();
                    } else {
                      alert('Error: ' + r2.data);
                    }
                  });
                }
              } else {
                alert('Error parsing iCal: ' + resp.data);
              }
           },
           error: function(xhr, status, error){
             alert('Ajax error: ' + error);
           }
         });
     });


     // The hidden popup for adding holiday
     jQuery('#confirm-add-holiday').on('click', function(){
        let name = jQuery('#holiday-name-input').val().trim();
        if(!name){
          alert('<?php echo esc_js( __("Please enter a holiday name.","catering-booking-and-scheduling") ); ?>');
          return;
        }
        // We stored which date we are adding in a data attribute
        let dateStr = jQuery('#add-holiday-popup').data('targetDate');
        if(!dateStr){
          // safety check
          jQuery('#add-holiday-popup').hide();
          return;
        }
        // Add holiday name to holidayDataMap
        if(!holidayDataMap[dateStr]){
          holidayDataMap[dateStr] = [];
        }
        holidayDataMap[dateStr].push(name);

        // Rebuild the day cell UI
        rebuildDayCell(dateStr);

        // close popup
        jQuery('#add-holiday-popup').hide();
     });
     jQuery('#cancel-add-holiday').on('click', function(){
        jQuery('#add-holiday-popup').hide();
     });

     // Close popup when clicking outside
     jQuery('#add-holiday-popup').on('click', function(e){
        if(e.target === this){
          jQuery(this).hide();
        }
     });
  });

  function buildMonthSelect(){
    let sel = jQuery('#month-select');
    sel.empty();
    for(let y=2023; y<=2030; y++){
      for(let m=1; m<=12; m++){
        let val = y + '-' + pad2(m);
        let txt = monthName(m) + ' ' + y;
        sel.append( jQuery('<option/>').val(val).text(txt) );
      }
    }
  }

  // load from DB
  function loadCalendarData(year,month){
    jQuery.post(ajaxurl, {
      action: 'get_holiday_data',
      year: year,
      month: month
    }, function(resp){
      if(resp.success){
        // resp.data => array of { date:'YYYY-MM-DD', holidayNames:['NameA','NameB'] }
        holidayDataMap = {};
        resp.data.forEach(function(obj){
          holidayDataMap[obj.date] = obj.holidayNames.slice(); // copy
        });
        buildCalendarGrid(year, month);
      } else {
        alert('Error: '+ resp.data);
      }
    });
  }

  function buildCalendarGrid(year, month){
    let container = jQuery('#holiday-calendar');
    container.empty();

    let firstDay = new Date(year, month-1, 1);
    let dayCount = new Date(year, month, 0).getDate();
    let startDow = firstDay.getDay(); // 0=Sun,...6=Sat

    let html = '<table class="holiday-calendar-table">';
    // day name header
    html += '<thead><tr>';
    dayNames.forEach(d=>{ html += '<th>'+d+'</th>';});
    html += '</tr></thead>';
    html += '<tbody>';

    let dayNum=1;
    for(let row=0; row<6; row++){
      let rowHtml = '<tr>';
      for(let dow=0; dow<7; dow++){
        let cellHtml = '';
        if( row===0 && dow<startDow || dayNum>dayCount ) {
          // blank cell
          cellHtml = '&nbsp;';
        } else {
          // valid day
          let dateStr = year + '-' + pad2(month) + '-' + pad2(dayNum);
          cellHtml = '<div class="day-number">'+ dayNum +'</div>';

          // Holiday badges container
          cellHtml += '<div class="holiday-badges" id="badges_'+ dateStr + '"></div>';

          // "Add holiday" button
          cellHtml += '<button class="add-holiday-btn" data-date="'+dateStr+'"><?php _e('Add Holiday','catering-booking-and-scheduling') ;?></button>';

          dayNum++;
        }
        rowHtml += '<td>'+ cellHtml +'</td>';
      }
      rowHtml += '</tr>';
      html += rowHtml;
      if(dayNum>dayCount) break;
    }

    html += '</tbody></table>';
    container.html(html);

    // Attach "Add holiday" click
    container.find('.add-holiday-btn').on('click', function(){
      let d = jQuery(this).data('date');
      // show the popup
      jQuery('#holiday-name-input').val('');
      jQuery('#add-holiday-popup').data('targetDate', d);
      jQuery('#add-holiday-popup').css('display', 'flex');
    });

    // Fill badges
    fillBadges(year, month);
  }

  function fillBadges(year, month){
    // We'll iterate over each day
    let dayCount = new Date(year, month, 0).getDate();
    for(let d=1; d<=dayCount; d++){
      let dateStr = year + '-' + pad2(month) + '-' + pad2(d);

      rebuildDayCell(dateStr);
    }
  }

  function rebuildDayCell(dateStr){
    let container = jQuery('#badges_'+dateStr);
    if(container.length===0) return;

    container.empty();

    // 1) Check if it's Sunday
    let parts = dateStr.split('-');
    let y = parseInt(parts[0]), m=parseInt(parts[1]), day=parseInt(parts[2]);
    let dt = new Date(y, m-1, day);
    if(dt.getDay()===0){
      // Sunday badge
      container.append('<span class="holiday-badge sunday"><?php _e("Sun","catering-booking-and-scheduling") ;?> </span>');
    }

    // 2) User-saved holidays
    let holidayArr = holidayDataMap[dateStr] || [];
    holidayArr.forEach(function(name){
      let badge = jQuery('<span class="holiday-badge"></span>');
      badge.text(name + ' ');
      
      // Add remove link
      let xLink = jQuery('<a href="#">×</a>');
      xLink.on('click', function(e){
        e.preventDefault();
        removeHolidayName(dateStr, name);
      });
      badge.append(xLink);

      container.append(badge);
    });
  }

  function removeHolidayName(dateStr, name){
    if(!holidayDataMap[dateStr]) return;

    let arr = holidayDataMap[dateStr];
    let idx = arr.indexOf(name);
    if(idx >= 0){
      arr.splice(idx,1);
    }
    // Now if arr is empty, we keep it as an empty array so the server knows to remove the row
    // Instead of: if(!arr.length) delete holidayDataMap[dateStr];
    // We do: if(!arr.length) holidayDataMap[dateStr] = [];

    rebuildDayCell(dateStr);


  }

  function saveHolidayChanges(){
    let dataAsJson = JSON.stringify(holidayDataMap);
    if(!dataAsJson || dataAsJson==='{}'){
      alert('No changes.');
      return;
    }
    jQuery.post(ajaxurl, {
      action: 'update_holiday_batch',
      changes_json: dataAsJson
    }, function(resp){
      if(resp.success){
        alert("<?php _e("Holidays updated!","catering-booking-and-scheduling") ;?>");
        location.reload();
      } else {
        alert('Error: ' + resp.data);
      }
    });
  }


  function pad2(num){
    return (num<10)?('0'+num):''+num;
  }
  function monthName(m){
    let arr=['January','February','March','April','May','June','July','August','September','October','November','December'];
    return arr[m-1];
  }
})(jQuery);
</script>
