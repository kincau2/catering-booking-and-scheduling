<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

if ( ! current_user_can( 'manage_options' ) ) {
    return;
}

// New: Fetch initial holiday data for current week directly from DB
global $wpdb;
$date = new DateTime('now', new DateTimeZone('UTC'));
$monday = clone $date;
$monday->modify('monday this week');
$week_start = $monday->format('Y-m-d');
$monday->modify('+6 days');
$week_end = $monday->format('Y-m-d');
$table = $wpdb->prefix . 'catering_holiday';
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT date, holiday_name FROM $table WHERE date >= %s AND date <= %s",
    $week_start, $week_end
), ARRAY_A);
$initial_holidays = [];
foreach($rows as $row){
    $initial_holidays[$row['date']] = maybe_unserialize($row['holiday_name']);
}

// Enqueue the centralized CSS file
wp_enqueue_style('catering-styles', plugin_dir_url(dirname(__FILE__)) . 'public/catering.css', array(), '1.0.0', 'all');

?>
<div class="wrap">
    <div class="header-section">
        <div class="catering-header"><?php _e( 'Meal Delivery', 'catering-booking-and-scheduling' ); ?></div>
        <button class="btn btn-primary" id="export-btn"><?php _e( 'Export', 'catering-booking-and-scheduling' ); ?></button>
    </div>

    <div id="meal-schedule-calendar" style="margin-top:20px;">
    <div id="calendar-nav">
        <button type="button" id="schedule-prev-week">&lt;</button>
        <span id="schedule-week-range"></span>
        <button type="button" id="schedule-next-week">&gt;</button>
    </div>
    <div class="catering-week-days">
        <div><?php _e("Sun","catering-booking-and-scheduling");?></div>
        <div><?php _e("Mon","catering-booking-and-scheduling");?></div>
        <div><?php _e("Tue","catering-booking-and-scheduling");?></div>
        <div><?php _e("Wed","catering-booking-and-scheduling");?></div>
        <div><?php _e("Thu","catering-booking-and-scheduling");?></div>
        <div><?php _e("Fri","catering-booking-and-scheduling");?></div>
        <div><?php _e("Sat","catering-booking-and-scheduling");?></div>
    </div>
    <div id="schedule-week-grid" style="display:grid; grid-template-columns: repeat(7, 1fr); gap: 5px; margin-top:10px;">
        <!-- Day grid placeholders -->
        <div class="day-cell"></div>
        <div class="day-cell"></div>
        <div class="day-cell"></div>
        <div class="day-cell"></div>
        <div class="day-cell"></div>
        <div class="day-cell"></div>
        <div class="day-cell"></div>
    </div>
    </div>
</div>

<!-- Added Export Modal -->
<div id="export-modal">
    <div class="export-content">
        <button id="export-close">×</button>
        <h2><?php _e('Export Report', 'catering-booking-and-scheduling'); ?></h2>
        <div>
            <label><?php _e('Start Date',   'catering-booking-and-scheduling'); ?>: <input type="date" id="export-start" value="<?php echo date('Y-m-d'); ?>"></label>
        </div>
        <div>
            <label><?php _e('End Date',     'catering-booking-and-scheduling'); ?>: <input type="date" id="export-end"   value="<?php echo date('Y-m-d'); ?>"></label>
        </div>
        <div style="margin-top: 10px;">
            <button id="export-meal-report"><?php _e('Export',       'catering-booking-and-scheduling'); ?></button>
        </div>
    </div>
</div>

<script>
jQuery(function($){
  // Helpers
  function getMonday(d){
    var dt=new Date(d), day=dt.getDay();
    dt.setDate(dt.getDate()-day);
    dt.setHours(0,0,0,0);
    return dt;
  }
  function formatDate(d){
    var dd=('0'+d.getDate()).slice(-2),
        mm=('0'+(d.getMonth()+1)).slice(-2);
    return dd+'/'+mm;
  }

  // State
  var holidayMap={}, initialHolidayMap=<?php echo json_encode($initial_holidays); ?>, 
      isInitialLoad=true,
      scheduleStartDate=getMonday(new Date()),
      ajaxurl='<?php echo admin_url("admin-ajax.php"); ?>',
      admin_base='<?php echo admin_url(); ?>',
      adminPostUrl = '<?php echo admin_url("admin-post.php"); ?>';

  // Fetch holiday data for navigation weeks
  function fetchHolidayData(callback){
    holidayMap={};
    var start=scheduleStartDate, end=new Date(start);
    end.setDate(end.getDate()+6);
    var months=[{year:start.getFullYear(),month:start.getMonth()+1}];
    if(start.getFullYear()!=end.getFullYear()||start.getMonth()!=end.getMonth()){
      months.push({year:end.getFullYear(),month:end.getMonth()+1});
    }
    (function next(i){
      if(i>=months.length) return callback&&callback();
      var m=months[i];
      $.post(ajaxurl,{action:'get_holiday_data',year:m.year,month:m.month},function(resp){
        $.each(resp.data||[],function(_,d){
          holidayMap[d.date]= (holidayMap[d.date]||[]).concat(d.holidayNames);
        });
        next(i+1);
      },'json');
    })(0);
  }

  // Fetch meal badges per day
  function fetchDailyMealChoices(dateStr,$cell){
    $.post(ajaxurl,{action:'get_daily_meal_choices',date:dateStr},function(resp){
      if(resp.success){
        var $wrap=$cell.find('.meal-badge-wrap');
        $wrap.append(`<div class="meal-badge-title"><?php _e( "Meal" , "catering-booking-and-scheduling") ;?>:</div>`);
        $.each(resp.data,function(_,item){
            var span = `<span class="meal-badge"
                             data-meal-id="`+item.id+`"
                             data-booking-id="`+item.booking_id+`"
                             data-date="`+dateStr+`">
                        <span class="meal-title">`+item.title+`</span>
                        <span class="meal-count">`+ item.count +`</span>
                      </span>`;
            $wrap.append(span);
        });
      }
    },'json');
  }

  // New: fetch delivery-item badges per day
  function fetchDailyDeliveryItems(dateStr, $cell){
    $.post(ajaxurl, { action:'get_daily_delivery_items', date: dateStr }, function(resp){
      if(resp.success && resp.data.length){
        var $wrap = $cell.find('.item-badge-wrap');
        $wrap.append(`<div class="item-badge-title"><?php _e("item","catering-booking-and-scheduling");?>:</div>`);
        $.each(resp.data, function(_, item){
          var badge = `<span class="item-badge"
                          data-order-item-id="`+ item.order_item_id.join(',') +`"
                          data-date="`+dateStr+`">
                      <span class="item-title">`+item.title+`</span>
                      <span class="item-count">`+ item.count +`</span>
                    </span>`;
          $wrap.append(badge);
        });
      }
    }, 'json');
  }

  // Build calendar grid
  function buildCalendar(){
    var start=scheduleStartDate, end=new Date(start);
    end.setDate(end.getDate()+6);
    $('#schedule-week-range').text(formatDate(start)+' – '+formatDate(end));
    var $grid=$('#schedule-week-grid').empty();
    for(var i=0;i<7;i++){
      var day=new Date(start);
      day.setDate(day.getDate()+i);
      var dateStr=day.getFullYear()+'-'+('0'+(day.getMonth()+1)).slice(-2)+'-'+('0'+day.getDate()).slice(-2);
      // remove .css(...) and rely on .day-cell class
      var $cell=$('<div>').addClass('day-cell').html(
        '<div class="day-grid-top"><div class="calendar-date">'+formatDate(day)+'</div></div>'+
        (holidayMap[dateStr] ? '<div class="day-grid-second"><div class="holiday">'+holidayMap[dateStr]+'</div></div>' : '') +
        '<div class="meal-badge-wrap"></div>'+
        '<div class="item-badge-wrap"></div>'
      ).appendTo($grid);
      fetchDailyMealChoices(dateStr,$cell);
      fetchDailyDeliveryItems(dateStr,$cell);
    }
  }

  // Render with loading overlay
  function renderWeek(){
    var $cal=$('#meal-schedule-calendar'),
        $ov=$('<div>').addClass('loading-overlay').append('<div class="spinner"></div>').appendTo($cal);
    if(isInitialLoad){
      holidayMap=initialHolidayMap; isInitialLoad=false;
      buildCalendar(); $ov.remove();
    } else {
      fetchHolidayData(function(){ buildCalendar(); $ov.remove(); });
    }
  }

  // Nav handlers
  $('#schedule-prev-week').on('click',function(){
    scheduleStartDate.setDate(scheduleStartDate.getDate()-7);
    renderWeek();
  });
  $('#schedule-next-week').on('click',function(){
    scheduleStartDate.setDate(scheduleStartDate.getDate()+7);
    renderWeek();
  });

  // inject modal HTML once
  $('#meal-schedule-calendar').after(`
    <div id="badge-info-modal">
      <div class="badge-info-content">
        <button id="badge-info-close" class="badge-info-close">×</button>
        <h2 id="badge-info-title"></h2>
        <table id="badge-info-table">
          <thead>
            <tr>
              <th><?php _e('Order ID', 'catering-booking-and-scheduling'); ?></th>
              <th><?php _e('Customer (ID)', 'catering-booking-and-scheduling'); ?></th>
              <th><?php _e('Due Date', 'catering-booking-and-scheduling'); ?></th>
              <th><?php _e('Phone', 'catering-booking-and-scheduling'); ?></th>
              <th><?php _e('Product', 'catering-booking-and-scheduling'); ?></th>
              <th><?php _e('Count', 'catering-booking-and-scheduling'); ?></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        <div id="badge-info-spinner" class="spinner"></div>
      </div>
    </div>
  `);

  // click → fetch & show
  $(document).on('click','.meal-badge',function(){
    var $b = $(this),
        title = $b.find('.meal-title').text(),
        bids  = String($b.data('booking-id')).split(','),
        mid   = $b.data('meal-id'),
        dt    = $b.data('date');

    $('#badge-info-title').text(title + ' ' + dt);
    $('#badge-info-table tbody').empty();
    $('#badge-info-modal').css('display','flex');
    $('#badge-info-spinner').show();

    $.post(ajaxurl,{
      action:'get_badge_booking_info',
      booking_ids:bids,
      meal_id:mid,
      date:dt
    },function(resp){
      $('#badge-info-spinner').hide();
      if(resp.success){
        var html='';
        $.each(resp.data,function(_,r){
          html+=`<tr>
            <td><a href="`+admin_base+`post.php?post=`+r.order_id+`&action=edit" target="_blank">`+r.order_id+`</a></td>
            <td><a href="`+admin_base+`admin.php?page=wc-orders&_customer_user=`+r.user_id+`&status=all" target="_blank">`+r.customer+`</a> (`+r.user_id+`)</td>
            <td>`+r.due_date+`</td>
            <td>`+r.phone+`</td>
            <td>`+r.product+`</td>
            <td>`+r.count+`</td>
          </tr>`;
        });
        $('#badge-info-table tbody').html(html);
        $('#badge-info-modal').css('display','flex');
      }
    },'json');
  });
  
  // New: click → fetch & show for item badge
  $(document).on('click','.item-badge',function(){
    var $b = $(this),
        productName = $b.find('.item-title').text(),
        dt = $b.data('date');
    $('#badge-info-title').text(productName + ' ' + dt);
    $('#badge-info-table tbody').empty();
    $('#badge-info-modal').css('display','flex');
    $('#badge-info-spinner').show();
    $.post(ajaxurl, {
      action:'get_badge_delivery_info',
      order_item_id: $b.data('order-item-id'),
      product_id: $b.data('product-id')
    }, function(resp){
      $('#badge-info-spinner').hide();
      if(resp.success){
        var html = '';
        $.each(resp.data, function(_, r){
          html += `<tr>
            <td><a href="`+admin_base+`post.php?post=`+r.order_id+`&action=edit" target="_blank">`+r.order_id+`</a></td>
            <td><a href="`+admin_base+`admin.php?page=wc-orders&_customer_user=`+r.user_id+`&status=all" target="_blank">`+r.customer+`</a> (`+r.user_id+`)</td>
            <td></td>
            <td>`+r.phone+`</td>
            <td>`+r.product+`</td>
            <td>`+r.count+`</td>
          </tr>`;
        });
        $('#badge-info-table tbody').html(html);
        $('#badge-info-modal').css('display','flex');
      }
    }, 'json');
  });
  $('#badge-info-close').on('click',function(){
    $('#badge-info-modal').hide();
  });

  // New: Event handler for Export button and modal close
  $('#export-btn').on('click', function(){
    $('#export-modal').css('display', 'flex');
  });
  $('#export-close').on('click', function(){
    $('#export-modal').hide();
  });

  // New: Event handler for Export button in modal with date validations
  $('#export-meal-report').on('click', function(){
    var startDate = $('#export-start').val(),
        endDate   = $('#export-end').val();
    if(!startDate || !endDate){
      alert('<?php _e("Please enter valid start and end dates.","catering-booking-and-scheduling"); ?>');
      return;
    }
    if(startDate > endDate){
      alert('<?php _e("Start Date must be before or the same as End Date.","catering-booking-and-scheduling"); ?>');
      return;
    }
    var url = adminPostUrl + '?action=generate_delivery_report&start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate);
    window.open(url, '_blank');
  });

  // Initial render
  renderWeek();
});
</script>