<?php
/* 
   =======================================================================
   Catering Booking Popup Template
   =======================================================================
   This file defines the HTML, CSS, and JavaScript for the catering meal
   selection popup interface. 

   Key Sections:
   1. Popup Structure and Layout:
      - Contains the popup container, left panel (for meal selection & form),
        and right panel (for product info, days remaining, and summary display).
   
   2. Calendar Rendering:
      - Functions: getMonday, fmt, ymd, renderWeek
      - Renders weekly dates with special handling for holidays and user selections.
   
   3. Popup Initialization and Data Loading:
      - Click handler for .catering-pick-meal-btn to open popup,
        validate booking, load minimum order days, and fetch the weekly schedule.
   
   4. Meal Selection Form:
      - Dynamically builds form steps for meal selection per category and delivery address.
      - Implements step navigation (Prev/Next/Submit) and preselection logic, using 
        the updated userChoices data structure (meal objects with id and title).
   
   5. Select2 Integration for Category "Others":
      - Adds additional select2 inputs for admin-defined extra meal selection.
      - Handles duplicate check and updates both UI display and selection state.
   
   6. Health Status Handling:
      - AJAX loading/editing of booking health status.
   
   7. General UI and AJAX Event Handlers:
      - Manages delete actions, tooltips (for delivery address), and overall state resets.
   
*/

?>
<div id="catering-pick-meal-popup" style="display:none;">
  <div class="catering-popup-container">
    <div id="catering-popup-left" class="catering-popup-panel">
        <div class="show-if-mobile" style="display:none;">
            <h3 class="catering-product-title" class="panel-title"></h3>
            <p><?php _e('Days Remaining:','catering-booking-and-scheduling');?> 
                <span class="catering-days-remaining"></span>
            </p>
        </div>
    </div>
    <div id="catering-popup-right" class="catering-popup-panel">
      <h3 class="catering-product-title" class="panel-title"></h3>
      <div class="panel-info">
        <p><?php _e('Days Remaining:','catering-booking-and-scheduling');?> <span class="catering-days-remaining"></span></p>
        <div id="catering-selected-date" class="selected-date"></div>
        <div id="catering-selection-display" class="selection-display"></div>
      </div>
    </div>
    <button type="button" id="catering-popup-close" class="cb-btn cb-btn--icon">&times;</button>
  </div>
</div>
<script>
<?php
global $wpdb;
$prefix       = $wpdb->prefix;
$soup_option  = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT option_value FROM {$prefix}catering_options WHERE option_name=%s",
        'category_id_soup'
    )
);
$soup_cat_id = intval( $soup_option );
$other_option = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT option_value FROM {$prefix}catering_options WHERE option_name=%s",
        'category_id_others'
    )
);
$others_cat_id = intval( $other_option );

// Line 710: Insert allergy mapping
$terms_table   = $wpdb->prefix . 'catering_terms';
$allergy_terms = $wpdb->get_results(
    $wpdb->prepare("SELECT ID, title FROM {$terms_table} WHERE type=%s ORDER BY ordering ASC, ID ASC", 'allergy'),
    ARRAY_A
);
$allergy_map = [];
foreach ($allergy_terms as $term) {
    $allergy_map[$term['ID']] = $term['title'];
}
?>
var soupCatId   = <?php echo $soup_cat_id; ?>;
var othersCatId = <?php echo $others_cat_id; ?>;
var allergyMap = <?php echo json_encode($allergy_map); ?>;
jQuery(function($){
    var startDate, currentProductId, currentBookingId,
        currentCats = [], selection = {},
        scheduledDates = [], holidayMap = {}, expiryDate = '';

    var minDayBefore = 0;
    var calendarRendered = false;
    var userChoices = {}; // global variable to store fetched user choices
    var dueDate = ''; // declare global dueDate
    var productTitle = ''; // declare global productTitle
    var daysLeft = 0; // declare global daysLeft



    // start on Sunday instead of Monday
    function getMonday(d){
        var dt = new Date(new Date(d).toLocaleString("en-US", { timeZone: "Asia/Hong_Kong" })),
            day = dt.getDay();               // 0=Sun…6=Sat
        dt.setDate(dt.getDate() - day);
        return dt;
    }
    function fmt(d){
        var dd = ('0'+d.getDate()).slice(-2),
            mm = ('0'+(d.getMonth()+1)).slice(-2);
        return dd+'/'+mm;
    }
    function ymd(d){
        var yy = d.getFullYear(),
            mm = ('0'+(d.getMonth()+1)).slice(-2),
            dd = ('0'+d.getDate()).slice(-2);
        return yy+'-'+mm+'-'+dd;
    }
    function renderWeek(){
        // Force current date in HKT regardless of user local timezone
        var today0 = new Date(new Date().toLocaleString("en-US", { timeZone: "Asia/Hong_Kong" }));
        
        today0.setHours(0,0,0,0);

        var $rng   = $('#catering-week-range'),
            $grid  = $('#catering-week-grid'),
            end    = new Date(startDate);
        end.setDate(end.getDate()+6);
        $rng.text( fmt(startDate) + ' – ' + fmt(end) );
        $grid.empty();
        for(var i=0;i<7;i++){
            var day     = new Date(startDate);
            day.setHours(0,0,0,0);
            day.setDate(day.getDate()+i);
            var dateStr  = ymd(day);
            var diff     = Math.floor((day - today0)/(1000*60*60*24));
            var disabled = ( diff <= minDayBefore )? true : false; // disable past minDayBefore     

            if( expiryDate && dateStr > expiryDate ){
                disabled = true; // disable past expiry date
            }
            <?php if(!current_user_can('manage_catering')): ?>
                if(!disabled && userChoices[dateStr] ){
                    disabled = ( userChoices[dateStr].locked === 'true' );
                }
            <?php endif; ?>
            
            var html = '<div class="popup-week-days">';

            switch(day.getDay()){
                case 0: html += '<?php _e("Sun","catering-booking-and-scheduling")?>'; break;
                case 1: html += '<?php _e("Mon","catering-booking-and-scheduling")?>'; break;
                case 2: html += '<?php _e("Tue","catering-booking-and-scheduling")?>'; break;
                case 3: html += '<?php _e("Wed","catering-booking-and-scheduling")?>'; break;
                case 4: html += '<?php _e("Thu","catering-booking-and-scheduling")?>'; break;
                case 5: html += '<?php _e("Fri","catering-booking-and-scheduling")?>'; break;
                case 6: html += '<?php _e("Sat","catering-booking-and-scheduling")?>'; break;
            }
            html += '</div>';
            html += '<div class="day-grid-item ' + (disabled ? 'disabled' : '') + '">';

            html += '<div class="day-grid-top">';
             
            html += '<div class="calendar-date">'+ fmt(day) +'</div>';
            if(dateStr === dueDate){
                html += '<div class="due-date"><?php _e("due date","catering-booking-and-scheduling") ?></div>';
            }
           
            if(userChoices[dateStr] && userChoices[dateStr].choices && userChoices[dateStr].choices.length){
                html += '<i data-date="'+ dateStr +'" class="fa-solid fa-truck catering-address"></i>';
            }
            html += '</div>';
            html += '<div class="day-grid-second"><div class="holiday-wrap">';
            if( holidayMap[dateStr] || i === 0 ){
                html += '<div class="holiday">';
                if( userChoices[dateStr] ){
                    html += `<i class="fa-solid fa-triangle-exclamation" 
                             message="<?php _e("Holiday Meal will be delivered on the previous working day, please refer to our catering delivery policy on public holiday.","catering-booking-and-scheduling")?>">
                             </i>`;
                }
                html += ( i === 0 ) ? '<?php _e("Sun","catering-booking-and-scheduling")?>' : '';
                html += holidayMap[dateStr] ? holidayMap[dateStr].join(', ') : '';
                html += '</div>';
            }
            html += '</div>';

            
            // If booking is hybird and a meal choice type exists, display it.
            if(userChoices[dateStr] && userChoices[dateStr].type){
                var noticeIcon = '';
                if(userChoices[dateStr].notice && userChoices[dateStr].notice.type === 'warning' && userChoices[dateStr].notice.Message){
                    noticeIcon += '<i class="fa-solid fa-triangle-exclamation" message="'+ userChoices[dateStr].notice.Message +'"></i> ';
                }
                if(userChoices[dateStr].locked && userChoices[dateStr].locked === 'true' ){
                    noticeIcon += '<i class="fa-solid fa-lock" message="<?php _e("This day is lock as was edited by our CS Team","catering-booking-and-scheduling")?>"></i> ';
                }
                var mealType = '';
                switch(userChoices[dateStr].type){
                    case 'prenatal':
                        mealType = '<?php _e("prenatal meal","catering-booking-and-scheduling") ?>';
                        break;
                    case 'postpartum':
                        mealType = '<?php _e("postpartum meal","catering-booking-and-scheduling") ?>';
                        break;
                }
                html += '<div class="meal-type ' + userChoices[dateStr].type + '">' + noticeIcon + mealType +'</div>';
            }
            html += '</div>';
            
            if ( userChoices[dateStr] && userChoices[dateStr].choices.length ) {
                if ( ! disabled ) {
                    html += '<button class="catering-edit-meal" data-date="'+dateStr+'"><?php _e("Edit Meal","catering-booking-and-scheduling") ?></button>';
                }
                html += '<div class="user-choice">';
                
                // userChoices[dateStr] is an array of category objects sorted by ordering.
                for(var j=0; j< userChoices[dateStr].choices.length; j++){
                    var cat = userChoices[dateStr].choices[j];
                    html += '<div class="choice-cat meal-badge-wrap" style="margin:4px 0;"><strong>'+cat.cat_title+':</strong>';
                    for(var k=0; k<cat.meals.length; k++){
                        // Updated: use meal title from the meal object.
                        html += '<span class="meal-badge" style="background:'+cat.color+';">'+ cat.meals[k].title +'</span> ';
                    }
                    html += '</div>';
                }
                html += '</div>';
                // Add delete button in red at bottom
                if ( !disabled ) {
                        html += '<button type="button" class="delete-meal-choice" data-date="'+dateStr+'"'
                            + ' style="color:red;display:block;margin-top:20px;border: unset; background-color: #FFF;">'
                            + '<?php _e("Delete","catering-booking-and-scheduling") ?></button>';
                }
            } else if(!disabled && scheduledDates.indexOf(dateStr)!==-1 && window.remainingDays > 0){
                    html += '<button class="catering-add-meal" data-date="'+dateStr+'"><?php _e("Make meal booking","catering-booking-and-scheduling") ?></button>';
            } else if(!disabled){
                <?php if(current_user_can('manage_catering')): ?>
                    html += '<button class="catering-add-meal" data-date="'+dateStr+'"><?php _e("Make meal booking","catering-booking-and-scheduling") ?></button>';
                <?php endif ;?>
            }
            
            // Add meal history button for all days (only show if user can manage catering)
            <?php if(current_user_can('manage_catering')): ?>
            html += '<button type="button" class="meal-history-btn" data-date="'+dateStr+'"><?php _e("Meal History","catering-booking-and-scheduling") ?></button>';
            <?php endif; ?>
            
            html += '</div>';
            $('<div class="popup-day-grid"></div>')
              .html(html)
              .appendTo($grid);

        }

    }
    // show calendar
    $(document).on('click','.catering-pick-meal-btn',function(){
        // reset header flag so loadWeekData will re-insert header 
        calendarRendered = false;

        selection = {}; currentCats = [];
        $('#catering-selection-display,#catering-selected-date').empty().hide();
        var $btn = $(this);
        currentProductId = $btn.data('product-id');
        currentBookingId = $btn.data('booking-id');
        expiryDate = $btn.data('expiry-date') || '';
        productTitle = $btn.data('product-title') || '';
        daysLeft = $btn.data('days-left') || 0;
        startDate = getMonday(new Date());
        $('.catering-product-title').text($btn.data('product-title'));
        $('.catering-days-remaining').text($btn.data('days-left'));
        // Validate booking first
        validateBooking(currentBookingId, function(resp){
            if(resp.success){
                var $left = $('#catering-popup-left');
                $left.html('<div class="loading-overlay"><div class="spinner"></div></div>');
                $('#catering-pick-meal-popup').show();
                // fetch min‑order days first
                getMinOrderDays(function(mResp){
                    minDayBefore = mResp.data.min_days||0;
                    var today0 = new Date(); today0.setHours(0,0,0,0);
                    var threshold = new Date(today0);
                    threshold.setDate(threshold.getDate() + minDayBefore);
                    var weekEnd = new Date(startDate);
                    weekEnd.setDate(weekEnd.getDate() + 6);
                    if(threshold > weekEnd){
                        startDate.setDate(startDate.getDate() + 7);
                    }
                    loadWeekData();
                });
            } else {
                alert(resp.data || 'Booking validation failed.');
            }
        });
    });

    function initializeCalendarHTML(){
        $('#catering-popup-left').html(
          '<div class="show-if-mobile" style="display:none;">' + 
          '<h3 class="catering-product-title" class="panel-title">' + productTitle + '</h3>' +
          '<p><?php _e('Days Remaining:','catering-booking-and-scheduling'); ?> <span class="catering-days-remaining">' + daysLeft + '</span></p> ' +
          '</div>' +
          '<div class="week-navigator"><button type="button" id="catering-prev-week">&lt;</button>' +
          '<span id="catering-week-range"></span>' +
          '<button type="button" id="catering-next-week">&gt;</button></div>' +
          '<div id="catering-week-grid"></div>' +
          // NEW: Health status section
          '<div id="health-status-section" style="margin-top:15px; padding:10px; border:1px solid #ccc;">' +
             '<strong><?php _e("Health Status","catering-booking-and-scheduling");?>:</strong> ' +
             '<div id="health-status-display"></div> ' +
             '<button type="button" id="edit-health-status" class="cb-btn cb-btn--secondary" style="margin-left:10px;"><?php _e("Edit Health Status","catering-booking-and-scheduling");?></button>' +
          '</div>'
        );
    }

    // new: fetch schedule+holidays, then renderWeek()
    function loadWeekData(){
        var $left = $('#catering-popup-left');
        var spinner = '<div class="loading-overlay"><div class="spinner"></div></div>';
        if(!calendarRendered){
            $left.html(spinner);
        } else {
            $left.append(spinner);
        }
        var startStr = ymd(startDate),
            endDate  = new Date(startDate);
        endDate.setDate(endDate.getDate()+6);
        var endStr = ymd(endDate);

        getMealScheduleWeek(currentProductId, startStr, endStr, currentBookingId, function(resp){
            scheduledDates = resp.success && resp.data.schedule
                              ? Object.keys(resp.data.schedule) : [];

            if(resp.data.day_remaining !== null){
                $('.catering-days-remaining').text(resp.data.day_remaining + ' ' + '<?php _e("days","catering-booking-and-scheduling"); ?>');
                window.remainingDays = resp.data.day_remaining;
            } else {
                window.remainingDays = 0;
            }
            var months = [{ year:startDate.getFullYear(), month:startDate.getMonth()+1 }],
                e = endDate;
            if(e.getFullYear()!== startDate.getFullYear() || e.getMonth()!== startDate.getMonth()){
                months.push({ year:e.getFullYear(), month:e.getMonth()+1 });
            }
            holidayMap = {};
            (function fetchH(i){
                if(i>=months.length){
                    // After scheduled data and holidays are ready, fetch user choices for the week.
                    getUserMealChoicesRange(currentBookingId, startStr, endStr, function(ucResp){
                        if(ucResp.success){
                            userChoices = ucResp.data;
                        } else {
                            userChoices = {};
                        }
                        $left.find('.loading-overlay').remove();
                        if(!calendarRendered){
                            initializeCalendarHTML();
                            calendarRendered = true;
                        }
                        // First fetch health status to set dueDate, then render the week.
                        loadHealthStatus(function(){
                            renderWeek();
                        });
                    });
                    return;
                }
                var mo = months[i];
                getHolidayData(mo.year, mo.month, function(hResp){
                    $.each(hResp.data||[], function(_,day){
                        holidayMap[day.date] = (holidayMap[day.date]||[]).concat(day.holidayNames);
                    });
                    fetchH(i+1);
                });
            })(0);
        });
        
    }

    // replace prev / next to use loadWeekData
    $(document).on('click','#catering-prev-week',function(){
        startDate.setDate(startDate.getDate()-7);
        loadWeekData();
    });
    $(document).on('click','#catering-next-week',function(){
        startDate.setDate(startDate.getDate()+7);
        loadWeekData();
    });

    // close popup & reset left panel
    $(document).on('click','#catering-popup-close',function(){
        location.reload();
    });
    // New helper to render the meal/address form for both Add and Edit
    function renderMealForm(date, respData, isEdit){
        console.log(respData);
        currentCats = respData.categories;
        <?php if(current_user_can('manage_catering')): ?>
        currentCats.push({ cat_id: othersCatId, cat_title: "其他", max_qty: 10, meals: [] });
        <?php endif; ?>
        selection = {};
        var addr = respData.address || { first_name:'', last_name:'', address:'', city:'', phone:'', phone_country:'+852' };

        var stepCount = currentCats.length + 1; 
        $('#catering-selected-date').text(date + " ( 第" + respData.proportion.current_day + "天 / " + respData.proportion.plan_day + "天餐飲計劃 )");

        // Build HTML (meals + additional‐meals + delivery address select+required fields)
        var html = '<h4>'+ date + ' ( 第' + respData.proportion.current_day + '天 / ' + respData.proportion.plan_day + '天餐飲計劃 )' +'</h4><div id="catering-meal-form">';
        // Loop through each category to build meal selection steps
        $.each(currentCats, function(i, cat){
            html += '<div class="step" data-step="'+i+'" style="margin-bottom:20px;">'
                 + '<h5 style="margin-bottom:8px;">'+cat.cat_title+' (<?php _e('max picks','catering-booking-and-scheduling')?> '+cat.max_qty+' <?php _e('pcs','catering-booking-and-scheduling')?> )</h5>';
            $.each(cat.meals, function(_, m){
                var mealTag = m.tag ? '(' + m.tag + ')' : '';
                html += '<label class="meal-choice">'
                     + '<input type="checkbox" name="meals['+cat.cat_id+'][]" value="'+m.id+'" style="margin-right:6px;" />'
                     + '<div class="meal-title"> ' + m.title + '</div><span class="meal-tag">'+ mealTag +'</span>'
                     + '</label>';
            });
            // For Category "Others", add additional select2 meal option area for admins
            if(<?php echo current_user_can('manage_catering') ? 'true' : 'false'; ?> && cat.cat_id===othersCatId){
                html += '<div class="additional-meals" data-catid="'+ othersCatId +'">';
                html += '<button type="button" style=" padding: 4px 10px; font-weight: 400; " class="add-select2 cb-btn cb-btn--primary" data-catid="'+ othersCatId +'"><?php _e('Add New','catering-booking-and-scheduling')?></button>';
                html += '</div>';
            }
            html += '</div>';
        });
        // NEW: add a soup‐container preference step if cat_id matches the dynamic soup ID
        var hasSoup = currentCats.some(function(cat){ return cat.cat_id === soupCatId; });
        if ( hasSoup && respData.meal_setting && respData.meal_setting.soup_container ) {
            var setting = respData.meal_setting.soup_container;
            html += '<div class="step" data-step="'+ currentCats.length +'" style="margin-bottom:20px;">'
                 + '<h5><?php _e("Which container would you like for the soup?","catering-booking-and-scheduling");?></h5>'
                 + '<p style="color:red;"><b><?php _e("Please note that during holidays, all soups will be served in soup cups.","catering-booking-and-scheduling");?></b></p>'
                 + '<label><select name="preference[soup_container]" required>';
            if ( setting === 'pot_only' ) {
                html += '<option value="pot" selected><?php _e("Pot","catering-booking-and-scheduling");?></option>';
            } else if ( setting === 'cup_only' ) {
                html += '<option value="cup" selected><?php _e("Cup","catering-booking-and-scheduling");?></option>';
            } else {
                html += '<option value="pot" selected><?php _e("Pot","catering-booking-and-scheduling");?></option>'
                     + '<option value="cup"><?php _e("Cup","catering-booking-and-scheduling");?></option>';
            }
            html += '</select></label>'
                 + '</div>';
        }
        // Append new step for delivery address
        html += '<div class="step" data-step="'+currentCats.length+'" style="margin-bottom:20px;">'
             +  '<div class="address-button-wrapper">'
             +  '<button type="button" class="cb-btn cb-btn--primary select-address-1" data-booking-id="'+currentBookingId+'" data-address-type="shipping"><?php _e("Select Address 1","catering-booking-and-scheduling");?></button>'
             +  '<button type="button" class="cb-btn cb-btn--primary select-address-2" data-booking-id="'+currentBookingId+'" data-address-type="shipping_2"><?php _e("Select Address 2","catering-booking-and-scheduling");?></button>'
             +  '</div>' 
             +  '<h5 style="margin-bottom:8px;"><?php _e("Please confirm you delivery address","catering-booking-and-scheduling");?></h5>'
             +  '<label><?php _e("First name","catering-booking-and-scheduling");?>:<input type="text" name="delivery[first_name]" value="'+addr.first_name+'" required /></label>'
             +  '<label><?php _e("Last name","catering-booking-and-scheduling");?>:<input type="text" name="delivery[last_name]"  value="'+addr.last_name+'"  required /></label>'
             +  '<label><?php _e("City","catering-booking-and-scheduling");?>:'
             +    '<select name="delivery[city]" required style="display:block;width:100%;">'
             +      '<option value="">請選擇地區</option>'
             +      '<option value="灣仔區"'+ (addr.city==="灣仔區"   ? " selected" : "") +'>灣仔區</option>'
             +      '<option value="東區"  '+ (addr.city==="東區"     ? " selected" : "") +'>東區</option>'
             +      '<option value="中西區"'+ (addr.city==="中西區"   ? " selected" : "") +'>中西區</option>'
             +      '<option value="南區"  '+ (addr.city==="南區"     ? " selected" : "") +'>南區</option>'
             +      '<option value="北區"  '+ (addr.city==="北區"     ? " selected" : "") +'>北區</option>'
             +      '<option value="觀塘區"'+ (addr.city==="觀塘區"   ? " selected" : "") +'>觀塘區</option>'
             +      '<option value="油尖旺區"'+(addr.city==="油尖旺區"? " selected" : "") +'>油尖旺區</option>'
             +      '<option value="黃大仙區"'+(addr.city==="黃大仙區"? " selected" : "") +'>黃大仙區</option>'
             +      '<option value="深水埗區"'+(addr.city==="深水埗區"? " selected" : "") +'>深水埗區</option>'
             +      '<option value="九龍城區"'+(addr.city==="九龍城區"? " selected" : "") +'>九龍城區</option>'
             +      '<option value="荃灣區"  '+ (addr.city==="荃灣區"   ? " selected" : "") +'>荃灣區</option>'
             +      '<option value="離島區"  '+ (addr.city==="離島區"   ? " selected" : "") +'>離島區</option>'
             +      '<option value="葵青區"  '+ (addr.city==="葵青區"   ? " selected" : "") +'>葵青區</option>'
             +      '<option value="西貢區"  '+ (addr.city==="西貢區"   ? " selected" : "") +'>西貢區</option>'
             +      '<option value="沙田區"  '+ (addr.city==="沙田區"   ? " selected" : "") +'>沙田區</option>'
             +      '<option value="元朗區"  '+ (addr.city==="元朗區"   ? " selected" : "") +'>元朗區</option>'
             +      '<option value="屯門區"  '+ (addr.city==="屯門區"   ? " selected" : "") +'>屯門區</option>'
             +      '<option value="大埔區"  '+ (addr.city==="大埔區"   ? " selected" : "") +'>大埔區</option>'
             +    '</select>'
             +  '</label>'
             +  '<label><?php _e("Address","catering-booking-and-scheduling");?>:<input type="text" name="delivery[address]"    value="'+addr.address+'"    required /></label>'
             +  '<label><?php _e("Phone Country Code","catering-booking-and-scheduling");?>:'
             +    '<select name="delivery[phone_country]" required style="display:block;width:100%;">'
             +      '<option value="+852"'+ (addr.phone_country==="+852" ? " selected" : "") +'>+852 (Hong Kong)</option>'
             +      '<option value="+853"'+ (addr.phone_country==="+853" ? " selected" : "") +'>+853 (Macau)</option>'
             +      '<option value="+86" '+ (addr.phone_country==="+86"  ? " selected" : "") +'>+86 (China)</option>'
             +    '</select>'
             +  '</label>'
             +  '<label><?php _e("Phone Number","catering-booking-and-scheduling");?>:<input type="text" name="delivery[phone]" value="'+addr.phone+'" required placeholder="<?php _e("Enter phone number","catering-booking-and-scheduling");?>" /></label>'
             +  '<label><?php _e("Delivery Note","catering-booking-and-scheduling");?>:<textarea type="text" name="delivery[delivery_note]" required >'+addr.delivery_note+'</textarea></label>'
             +  '</div>';
        html += '<div class="steps-nav"><div class="left-buttons">'
             + '<button type="button" id="step-prev" class="cb-btn cb-btn--secondary"><?php _e('Prev step','catering-booking-and-scheduling')?></button>'
             + '<button type="button" id="step-next" class="cb-btn cb-btn--primary"><?php _e('Next step','catering-booking-and-scheduling')?></button>'
             + '<button type="button" id="step-submit" class="cb-btn cb-btn--primary" data-date="'+ date +'"><?php _e('Submit choices','catering-booking-and-scheduling')?></button></div>'
             + '<button type="button" id="back-to-calendar" class="cb-btn cb-btn--secondary"><?php _e('Back to calendar','catering-booking-and-scheduling')?></button>'
             + '</div></div>';

        $('#catering-popup-left').html(html);

        // initialize step navigation
        var totalSteps = currentCats.length + 1 + (hasSoup ? 1 : 0); // include address step
        var idx = 0, total = totalSteps;
        $('#catering-popup-left').find('.step').hide().eq(0).show();
        $('#catering-popup-left').find('#step-prev,#step-submit').hide();
        function toggleNav(){
             $('#catering-popup-left').find('#step-prev').toggle(idx > 0);
             $('#catering-popup-left').find('#step-next').toggle(idx < total - 1);
             $('#catering-popup-left').find('#step-submit').toggle(idx === total - 1);
        }
        toggleNav();
        $('#catering-popup-left').off('click','#step-next').on('click','#step-next',function(){
            if(idx < currentCats.length) {
                var cat = currentCats[idx];
                var selectedCount = $('#catering-popup-left').find('.step').eq(idx).find('input:checked').length;
                <?php if(!current_user_can('manage_catering')): ?>
                    if(selectedCount !== cat.max_qty) {
                        return alert('<?php _e("Please select exactly", "catering-booking-and-scheduling"); ?> ' 
                                    + cat.max_qty +' <?php _e("option(s) for", "catering-booking-and-scheduling"); ?> ' 
                                    + cat.cat_title);
                    }
                <?php endif ;?>
            }
            if(idx < total-1){ idx++; $('#catering-popup-left').find('.step').hide().eq(idx).show(); }
            toggleNav();
        });
        $('#catering-popup-left').on('click','#step-prev',function(){ if(idx>0){idx--; $('#catering-popup-left').find('.step').hide().eq(idx).show();} toggleNav(); });
        // ensure single back-to-calendar handler and reset calendar on return
        $('#catering-popup-left').off('click','#back-to-calendar').on('click','#back-to-calendar',function(){
            // reset calendar rendering state
            calendarRendered = false;
            selection = {};
            currentCats = [];
            $('#catering-selected-date, #catering-selection-display').empty().hide();
            initializeCalendarHTML();
            loadWeekData();
        });

        if(isEdit){
            // pre-select checkboxes from userChoices
            var uc = (userChoices[date] && userChoices[date].choices) ? userChoices[date].choices : [];
            uc.forEach(function(c){
                var stepIdx = currentCats.findIndex(function(cc){ return cc.cat_id === c.cat_id; });
                if(stepIdx < 0) return;
                c.meals.forEach(function(meal){
                    var $checkbox = $('#catering-popup-left').find('.step').eq(stepIdx).find('input[value="' + meal.id + '"]');
                    if($checkbox.length){
                        $checkbox.prop('checked', true);
                    }
                });
            });
            // prepopulate select2 for Category "Others"
            var ucAdditional = (userChoices[date].choices || [])
                .filter(function(c){ return c.cat_id === othersCatId; });

            ucAdditional.forEach(function(c){
                var stepIdx = currentCats.findIndex(function(cc){ return cc.cat_id === othersCatId; });
                if(stepIdx < 0) return;
                var $step = $('#catering-popup-left').find('.step').eq(stepIdx);
                var $container = $step.find('.additional-meals');
                if($container.length){
                     c.meals.forEach(function(meal){
                         var $newField = $('<div class="select2-container-wrapper" style="margin-top:5px;"><select class="search-meal-select" style="width:80%;"></select> <button type="button" class="remove-select2"><i class="fa-solid fa-trash-can"></i></button></div>');
                         $container.append($newField);
                         $newField.find('select').select2({
                              placeholder: '<?php _e("Search for meal...", "catering-booking-and-scheduling"); ?>',
                              data: [{ id: meal.id, text: meal.title }],
                              language: {
                                noResults: function() {
                                    return '<?php _e("No meals found.", "catering-booking-and-scheduling"); ?>';
                                },
                                searching: function() {
                                    return '<?php _e("Searching for meals...", "catering-booking-and-scheduling"); ?>';
                                }
                              }
                         });
                     });
                     // NEW: Update selection object for Category "Others" so its preselected meals are displayed.
                     var additionalValues = [];
                     $container.find('.search-meal-select').each(function(){
                          var data = $(this).select2('data');
                          if(data && data.length){
                              additionalValues.push( data[0].text );
                          }
                     });
                     selection[stepIdx] = additionalValues;
                     var disp = '';
                     
                     $.each(currentCats, function(i, cat){
                          if(selection[i] && selection[i].length){
                            
                              var mealTextArr = selection[i].map(function(m){ return m; });
                              disp += '<p><strong>' + cat.cat_title + ':</strong><br>' + mealTextArr.join('<br>') + '</p>';
                          }
                     });
                     if( disp == '' ){
                            disp = '<p><?php _e('You meal choice will show here.','catering-booking-and-scheduling')?></p>';
                     }
                     $('#catering-selection-display').html(disp).show();
                }
            });
            
            // NEW: Pre-select soup container preference if available
            if(userChoices[date] && userChoices[date].preference && userChoices[date].preference.soup_container) {
                var savedPreference = userChoices[date].preference.soup_container;
                var $soupSelect = $('select[name="preference[soup_container]"]');
                if($soupSelect.length) {
                    $soupSelect.val(savedPreference);
                }
            }
            
            // AFTER the loop, trigger once to populate display
            $('#catering-popup-left').find('#catering-meal-form input:checked').trigger('change');
        } else{
            $('#catering-selection-display').html('<p><?php _e('You meal choice will show here.','catering-booking-and-scheduling')?></p>').show();
        }
    }

    // Refactored Add‐Meal handler
    $(document).on('click','.catering-add-meal',function(){
        var date = $(this).data('date'),
            $left = $('#catering-popup-left');
        $left.html('<div class="loading-overlay"><div class="spinner"></div></div>');
        getDaySchedule(currentProductId, currentBookingId, date, function(resp){
            $left.find('.loading-overlay').remove();
            if(!resp.success){
                alert(resp.data||'<?php _e("Error","catering-booking-and-scheduling");?>');
                calendarRendered = false;
                initializeCalendarHTML();
                loadWeekData();
                return;
            }
            renderMealForm(date, resp.data, false);
        });
    });

    // Refactored Edit‐Meal handler
    $(document).on('click','.catering-edit-meal',function(){
        var date = $(this).data('date'),
            $left = $('#catering-popup-left');
        $left.html('<div class="loading-overlay"><div class="spinner"></div></div>');
        getDaySchedule(currentProductId, currentBookingId, date, function(resp){
            $left.find('.loading-overlay').remove();
            if(!resp.success){
                var msg = resp.data || 'Error';
                if(confirm(msg + '\n\nDelete your existing meal choice for ' + date + '?')){
                    deleteUserMealChoice(currentBookingId, date, function(){
                        calendarRendered = false;
                        initializeCalendarHTML();
                        loadWeekData();
                    });
                } else {
                    calendarRendered = false;
                    initializeCalendarHTML();
                    loadWeekData();
                }
                return;
            }
            renderMealForm(date, resp.data, true);
        });
    });

    // selection handler: radio or checkbox
    $(document).on('change','#catering-meal-form input',function(){
        var $inp = $(this),
            step = $inp.closest('.step').data('step'),
            cat = currentCats[step],
            vals = [];
        var checked = $inp.closest('.step').find(':checkbox:checked');
        <?php if(!current_user_can('manage_catering')): ?>
            if(checked.length > cat.max_qty){
                $inp.prop('checked', false);
                return alert('<?php _e("Limit reached","catering-booking-and-scheduling"); ?> ' + cat.max_qty);
            }
        <?php endif ;?>
        checked.each(function(){ vals.push($(this).parent().text().trim()); });
        selection[step] = vals;
        // render display
        var disp='';
        $.each(currentCats,function(i,c){
            if(selection[i]&&selection[i].length){
                disp += '<p><strong>'+c.cat_title+':</strong><br>'+selection[i].join('<br>')+'</p>';
            }
        });
        if( disp == '' ){
            disp = '<p><?php _e('You meal choice will show here.','catering-booking-and-scheduling')?></p>';
        }
        $('#catering-selection-display').html(disp).show();
    });
    // clear selections back to calendar on form submit
    $(document).on('click','#step-submit',function(e){
        e.preventDefault();

        // --- new delivery‐address validation ---
        var first = $.trim($('input[name="delivery[first_name]"]').val()),
            last  = $.trim($('input[name="delivery[last_name]"]').val()),
            adr   = $.trim($('input[name="delivery[address]"]').val()),
            city  = $('select[name="delivery[city]"]').val(),
            phone = $.trim($('input[name="delivery[phone]"]').val()),
            phoneCountry = $('select[name="delivery[phone_country]"]').val(),
            cities = ['灣仔區','東區','中西區','南區','北區','觀塘區','油尖旺區','黃大仙區','深水埗區','九龍城區','荃灣區','離島區','葵青區','西貢區','沙田區','元朗區','屯門區','大埔區'],
            nameRe  = /^\D+$/;

        // Phone validation function with country code support
        function validatePhoneWithCountry(phone, countryCode) {
            // Remove any spaces, dashes, or brackets
            phone = phone.replace(/[\s\-\(\)]/g, '');
            
            switch (countryCode) {
                case '+852': // Hong Kong
                    return /^[4569]\d{7}$/.test(phone);
                case '+853': // Macau
                    return /^6\d{7}$/.test(phone);
                case '+86': // China
                    return /^1[3456789]\d{9}$/.test(phone);
                default:
                    return false;
            }
        }

        function getPhoneErrorMessage(countryCode) {
            switch (countryCode) {
                case '+852':
                    return '<?php _e("Please enter a valid Hong Kong mobile number (8 digits starting with 4, 5, 6, or 9).","catering-booking-and-scheduling");?>';
                case '+853':
                    return '<?php _e("Please enter a valid Macau mobile number (8 digits starting with 6).","catering-booking-and-scheduling");?>';
                case '+86':
                    return '<?php _e("Please enter a valid China mobile number (11 digits starting with 1).","catering-booking-and-scheduling");?>';
                default:
                    return '<?php _e("Please enter a valid mobile number.","catering-booking-and-scheduling");?>';
            }
        }

        if (!first)    { alert('<?php _e("First name is required.","catering-booking-and-scheduling");?>'); return; }
        if (!nameRe.test(first))  { alert('<?php _e("First name cannot contain numbers.","catering-booking-and-scheduling");?>'); return; }
        if (!last)     { alert('<?php _e("Last name is required.","catering-booking-and-scheduling");?>'); return; }
        if (!nameRe.test(last))   { alert('<?php _e("Last name cannot contain numbers.","catering-booking-and-scheduling");?>'); return; }
        if (!adr)      { alert('<?php _e("Address is required.","catering-booking-and-scheduling");?>'); return; }
        if (!city)     { alert('<?php _e("City is required.","catering-booking-and-scheduling");?>'); return; }
        if (cities.indexOf(city) < 0) {
            alert('<?php _e("Please select a valid city.","catering-booking-and-scheduling");?>');
            return;
        }
        if (!phone)    { alert('<?php _e("Phone is required.","catering-booking-and-scheduling");?>'); return; }
        if (!phoneCountry) { alert('<?php _e("Phone country code is required.","catering-booking-and-scheduling");?>'); return; }
        if (!validatePhoneWithCountry(phone, phoneCountry)) {
            alert(getPhoneErrorMessage(phoneCountry));
            return;
        }
        // --- end validation ---

        // NEW: confirm user choice
        
        // Build choice object: key: category ID, value: array of meal IDs chosen for that category.
        var choiceData = {};
        $.each(currentCats, function(index, cat){
              // get all inputs under the current step for current category (cat.cat_id)
              // The form inputs names are "meals[catID]" (or "meals[catID][]" for multi select)
              var selected = [];
              $('#catering-meal-form').find('input[name^="meals['+cat.cat_id+']"]').each(function(){
                    if($(this).is(':checked')){
                        selected.push(parseInt($(this).val(),10));
                    }
              });
              if(selected.length){
                  choiceData[cat.cat_id] = selected;
              }
        });
        // NEW: Gather select2 meal selections for Category "Others"
        $('.additional-meals').each(function(){
             var catId = $(this).data('catid'); // data-catid holds the category id
             var additional = [];
             $(this).find('.search-meal-select').each(function(){
                 var mealId = $(this).val();
                 if(mealId){
                     additional.push(parseInt(mealId,10));
                 }
             });
             if(additional.length){
                 if(choiceData[catId]){
                     choiceData[catId] = choiceData[catId].concat(additional);
                 } else {
                     choiceData[catId] = additional;
                 }
             }
        });

        // delivery address from the extra step (assumed to be the last step)
        var delivery = {
             first_name: $('input[name="delivery[first_name]"]').val(),
             last_name:  $('input[name="delivery[last_name]"]').val(),
             address:    $('input[name="delivery[address]"]').val(),
             city:       $('select[name="delivery[city]"]').val(),
             phone:      $('input[name="delivery[phone]"]').val(),
             phone_country: $('select[name="delivery[phone_country]"]').val(),
             delivery_note: $('textarea[name="delivery[delivery_note]"]').val() || ''
        };

        // NEW: collect soup-container preference
        var preference = {};
        var pref = $('select[name="preference[soup_container]"]').val();
        if (pref) {
            preference.soup_container = pref;
        }
        
        // We assume the selected date is in #catering-selected-date
        var selectedDate = $(this).data('date');

        // build multi-line summary from each <p>, keep internal <br> as newlines

        var summaryLines = $('#catering-selection-display p').map(function(){
            var html      = $(this).html();
            var withBreak = html.replace(/<br\s*\/?>/gi, '\n');
            var stripped  = withBreak.replace(/<\/?strong>/gi,'').trim();
            return stripped;
        }).get();
        var summary = summaryLines.join("\n\n");

        var confirmMsg = $("#catering-selected-date").text() + "\n\n" + summary + "\n\n" +
            "<?php _e('Are you sure you want to submit your selections?','catering-booking-and-scheduling'); ?>";

    if ( ! confirm(confirmMsg) ) {
        return;
    }
        
        // Pass preference into the AJAX call
        saveUserChoice(currentBookingId, selectedDate, choiceData, delivery, preference, function(resp){
              if(resp.success){
                  alert("<?php _e('Your meal selection has been saved.',"catering-booking-and-scheduling");?>");
                  // Clear selections and reset calendar header
                  $('#catering-selected-date,#catering-selection-display').empty().hide();
                  initializeCalendarHTML();
                  loadWeekData();
              } else {
                  alert(resp.data || '<?php _e("Saving failed.","catering-booking-and-scheduling");?>');
                  // Clear selections and reset calendar header
                  $('#catering-selected-date,#catering-selection-display').empty().hide();
                  initializeCalendarHTML();
                  loadWeekData();
              }
        });
        
    });

    // handle delete meal choice
    $(document).on('click', '.delete-meal-choice', function(){
        var date = $(this).data('date');
        if(confirm('<?php _e('Are you sure you want to delete the meal choice for','catering-booking-and-scheduling'); ?>'+ date +'?')){
            deleteUserMealChoice(currentBookingId, date, function(resp){
                if(resp.success){
                    loadWeekData();
                } else {
                    alert(resp.data || '<?php _e("Delete failed.","catering-booking-and-scheduling");?>');
                }
            });
        }
    });

    // NEW: Append a tooltip container to the body (only once)
    if($('#popup-tooltip').length === 0){
        $('body').append('<div id="popup-tooltip" style="display:none;position:absolute;background:#fff;border:1px solid #ccc;padding:10px;z-index:10000;width:250px;font-size:14px;"></div>');
    }

    // NEW: Add event handlers for truck icon hover and click to show the tooltip
    $(document).on('mouseenter click', 'i.catering-address', function(e){
        var addr = userChoices[$(this).data('date')].address || {};
        if($.isEmptyObject(addr)){
            return; // no address to show
        }
        var tooltipHtml = '<strong><?php _e('Delivery address','catering-booking-and-scheduling'); ?>:</strong><br>' +
                          addr.first_name + ' ' + addr.last_name + '<br>' +
                          addr.address + '<br>' +
                          addr.city + '<br>' +
                          '<?php _e('Phone','catering-booking-and-scheduling'); ?>: ' + addr.phone_country + ' ' + addr.phone + '<br>' +
                          '<?php _e('Delivery Note','catering-booking-and-scheduling'); ?>: ' + (addr.delivery_note || '<?php _e('Nil','catering-booking-and-scheduling'); ?>') + '<br>';

        $('#popup-tooltip').html(tooltipHtml).css({
             top: e.pageY + 10,
             left: e.pageX + 10
        }).fadeIn();
    }).on('mouseleave', 'i.catering-address', function(){
        $('#popup-tooltip').fadeOut();
    });



    // NEW: Function to load health status via AJAX
    function loadHealthStatus(callback){
        if(typeof currentBookingId !== 'undefined'){
            window.getHealthStatus(currentBookingId, function(resp){
                if(resp.success){
                    var hs = resp.data;
                    if(hs && hs.due_date){
                        dueDate = hs.due_date; // assign global dueDate
                    }
                    var display = '';
                    if(hs && typeof hs === 'object'){
                        for(var key in hs){
                            if(hs.hasOwnProperty(key)){
                                var content = hs[key];
                                if(Array.isArray(content)){
                                    if( key === 'allergy' ){
                                        //awdawd
                                        content.forEach(function(item, index) {
                                            content[index] = allergyMap[item];
                                        });
                                    } 
                                    content = content.join(', ');
                                }
                                var text       = key.replace(/_/g, ' ');
                                var transLabel = cateringi18n(text);
                                var transContent = cateringi18n(content);
                                display += '<span><strong>' + transLabel + ':</strong> ' + transContent + '</span>';
                            }
                        }
                        if(!display){
                            display = '<?php _e("Not set","catering-booking-and-scheduling");?>';
                        }
                    } else {
                        display = '<?php _e("Not set","catering-booking-and-scheduling");?>';
                    }
                    $('#health-status-display').html(display);
                } else {
                    $('#health-status-display').html('<?php _e("Error loading health status","catering-booking-and-scheduling");?>');
                }
                if(callback){ callback(); }
            });
        } else {
            if(callback){ callback(); }
        }
    }

    // NEW: Handler for editing health status (replace previous prompt handler)
    $(document).on('click', '#edit-health-status', function(){
        // Hide the edit button while editing
        $('#edit-health-status').hide();
        window.getHealthStatus(currentBookingId, function(resp){
            var hs = {};
            if(resp.success && typeof resp.data === 'object'){
                hs = resp.data;
            }
            var allergies = hs.allergy || [];
            var due_date = hs.due_date || '';
            // Use allergyMap js object to create the allergy checkbox options
            var options = Object.keys(allergyMap);
            // Changed from a <form> to a <div>
            var html = '<div id="health-status-form">'; 
                html += '<div>';
                html += '<label><strong><?php _e('allergy','catering-booking-and-scheduling') ;?>:</strong></label><br>';
                options.forEach(function(id){
                    var title = allergyMap[id];
                    var checked = (allergies.indexOf(id) !== -1 || allergies.indexOf(parseInt(id,10)) !== -1) ? 'checked' : '';
                    html += '<label style="margin-right:10px;"><input type="checkbox" name="health_status[allergy][]" value="'+id+'" '+checked+'> '+ cateringi18n(title) +'</label>';
                });
                html += '</div>';
                html += '<div style="margin-top:10px;">';
                html += '<label><strong><?php _e('due date','catering-booking-and-scheduling') ;?>:</strong></label> ';
                html += '<input type="date" name="health_status[due_date]" value="'+due_date+'">';
                html += '</div>';
                html += '<div style="margin-top:10px;">';
                html += '<button type="button" id="save-health-status" class="cb-btn cb-btn--primary"><?php _e('Save','catering-booking-and-scheduling') ;?></button>';
                html += '<button type="button" id="cancel-health-status" class="cb-btn cb-btn--secondary" style="margin-left:10px;"><?php _e('Cancel','catering-booking-and-scheduling') ;?></button>';
                html += '</div>';
                html += '</div>';
            $('#health-status-display').html(html);
        });
    });

    // Changed event binding from form submission to button click for saving health status
    $(document).on('click', '#save-health-status', function(){
        var allergyArr = [];
        $('#health-status-form input[name="health_status[allergy][]"]:checked').each(function(){
            allergyArr.push($(this).val());
        });
        var dueDate = $('#health-status-form input[name="health_status[due_date]"]').val();
        var newData = {
            allergy: allergyArr,
            due_date: dueDate
        };
        window.updateHealthStatus(currentBookingId, newData, function(resp){
            if(resp.success){
                alert(resp.data.message || '<?php _e("Health status updated successfully.","catering-booking-and-scheduling");?>');
                loadHealthStatus();
                loadWeekData(); // re-render the calendar
                $('#edit-health-status').show();
            } else {
                // Show the error message (which will be about contacting CS team)
                alert(resp.data || '<?php _e("Update failed.","catering-booking-and-scheduling");?>');
                // Don't reload the health status display, keep the form open so user can modify
            }
        });
    });

    // NEW: Cancel button to revert to display and re-show edit button
    $(document).on('click', '#cancel-health-status', function(){
        loadHealthStatus();
        $('#edit-health-status').show(); 
    });

    // New event handlers for select2 fields in Category "Others"
    $(document).on('click', '.add-select2', function(){
        var container = $(this).closest('.additional-meals');
        var newField = $('<div class="select2-container-wrapper" style="margin-top:5px;"><select class="search-meal-select" style="width:80%;"></select> <button type="button" class="remove-select2"><i class="fa-solid fa-trash-can"></i></button></div>');
        container.append(newField);
        newField.find('select').select2({
             placeholder: '<?php _e("Search for meal...", "catering-booking-and-scheduling"); ?>',
             ajax: {
                  transport: function (params, success, failure) {
                      window.searchMeals(params.data, success, failure);
                  },
                  delay: 250,
                  processResults: function(data) {
                      return { results: data };
                  }
             },
             language: {
                noResults: function() {
                    return '<?php _e("No meals found.","catering-booking-and-scheduling"); ?>';
                },
                searching: function() {
                    return '<?php _e("Searching for meals...", "catering-booking-and-scheduling"); ?>';
                }
             }
        });
    });
    // New event handler for updating selection on select2 removal
    $(document).on('click', '.remove-select2', function(){
        // ask user to confirm removal
        if ( ! confirm('<?php _e("Are you sure you want to remove this selection?","catering-booking-and-scheduling"); ?>') ) {
            return;
        }
        var $container = $(this).closest('.additional-meals');
        // Remove the select2 field
        $(this).closest('.select2-container-wrapper').remove();
        // Update selection for this step
        var $step = $container.closest('.step');
        var stepIndex = $step.data('step');
        var values = [];
        $container.find('.search-meal-select').each(function(){
            var data = $(this).select2('data');
            if(data && data.length){
                values.push(data[0].text);
            }
        });
        selection[stepIndex] = values;
        // Refresh display panel (mimic original format)
        var disp = '';
        $.each(currentCats, function(i, cat){
            if(selection[i] && selection[i].length){
                disp += '<p><strong>' + cat.cat_title + ':</strong><br>' + selection[i].join('<br>') + '</p>';
            }
        });
        if( disp == '' ){
            disp = '<p><?php _e('You meal choice will show here.','catering-booking-and-scheduling')?></p>';
        }
        $('#catering-selection-display').html(disp).show();
    });
    // New event handler: on leaving focus from select2 field in Category "Others" update selection
    $(document).on('select2:close', '.search-meal-select', function(){
        var $container = $(this).closest('.additional-meals');
        var $step = $(this).closest('.step');
        var stepIndex = $step.data('step');
        var values = [];
        $container.find('.search-meal-select').each(function(){
            var data = $(this).select2('data');
            if(data && data.length){
                values.push(data[0].text);
            }
        });
        // Update selection for this step
        selection[stepIndex] = values;
        // Refresh display panel (mimic original format)
        var disp = '';
        $.each(currentCats, function(i, cat){
            if(selection[i] && selection[i].length){
                disp += '<p><strong>' + cat.cat_title + ':</strong><br>' + selection[i].join('<br>') + '</p>';
            }
        });
        
        $('#catering-selection-display').html(disp).show();
    });

    // New event handler for duplicate meal selection in select2 inputs (Category "Others")
    $(document).on('select2:select', '.search-meal-select', function(e){
        var currentMealId = e.params.data.id;
        var $container = $(this).closest('.additional-meals');
        var duplicateFound = false;
        $container.find('.search-meal-select').not(this).each(function(){
             var data = $(this).select2('data');
             if(data && data.length && data[0].id == currentMealId){
                 duplicateFound = true;
             }
        });
        if(duplicateFound){
             alert('<?php _e("Duplicate meal selected. Please choose a different meal.","catering-booking-and-scheduling");?>');
             $(this).val(null).trigger('change');
             // Update selection object after clearing duplicate
             var $step = $container.closest('.step');
             var stepIndex = $step.data('step');
             var values = [];
             $container.find('.search-meal-select').each(function(){
                  var data = $(this).select2('data');
                  if(data && data.length){
                      values.push(data[0].text);
                  }
             });
             selection[stepIndex] = values;
             var disp = '';
             $.each(currentCats, function(i, cat){
                  if(selection[i] && selection[i].length){
                      disp += '<p><strong>' + cat.cat_title + ':</strong><br>' + selection[i].join('<br>') + '</p>';
                  }
             });
             $('#catering-selection-display').html(disp).show();

        }
    });

    // Replace previous warning icon tooltip handler with:

    $(document).on('mouseenter click', 'i.fa-triangle-exclamation, i.fa-lock', function(e){
        var msg = $(this).attr('message');
        if(!msg) return;
        $('#popup-tooltip').html("<strong><?php _e("Warning","catering-booking-and-scheduling");?>:</strong> " + msg).css({
             top: e.pageY + 10,
             left: e.pageX + 10
        }).show();
    }).on('mouseleave', 'i.fa-triangle-exclamation, i.fa-lock', function(){
        $('#popup-tooltip').hide();
    });

    // Function to equalize popup-day-grid widths
    function equalizeGridWidths() {
        var $container = $('#catering-week-grid');
        if (!$container.length) return;
        
        var containerWidth = $('#catering-popup-left').width();
        var minItemWidth = 200; // Minimum width from CSS
        var spacing = 8; // Gap between items from CSS
        var itemCount = 7; // Number of day grids
        
        // Calculate how many items can fit per row
        var itemsPerRow = Math.floor((containerWidth + spacing) / (minItemWidth + spacing));
        itemsPerRow = Math.min(itemsPerRow, itemCount);
        itemsPerRow = Math.max(1, itemsPerRow);
        
        // Calculate the optimal width for each item
        var itemWidth = (containerWidth - (spacing * (itemsPerRow - 1))) / itemsPerRow;
        
        // Set width for all grid items
        $('.popup-day-grid').css({
            'width': itemWidth + 'px',
            'flex': '0 0 auto'
        });
        
        // If not all items fit in one row, ensure rows are properly aligned
        if (itemsPerRow < itemCount) {
            // Calculate items in the last row
            var itemsInLastRow = itemCount % itemsPerRow;
            if (itemsInLastRow === 0) itemsInLastRow = itemsPerRow;
            
            // Adjust container to ensure proper alignment
            $container.css({
                               'justify-content': 'flex-start',
                'flex-wrap': 'wrap'
            });
        }
    }
    
    // Initialize and run on window resize
    $(window).on('resize', function() {
        equalizeGridWidths();
    });
    
    // Run after calendar is rendered and anytime the calendar data refreshes
    var originalRenderWeek = renderWeek;
    renderWeek = function() {
        originalRenderWeek.apply(this, arguments);
        setTimeout(equalizeGridWidths, 10); // Short delay to ensure DOM is updated
    };
    
    // Also run when popup is first shown
    $(document).on('click', '.catering-pick-meal-btn', function() {
        setTimeout(equalizeGridWidths, 300); // Delay to ensure popup is fully rendered
    });

    // NEW: Meal History functionality
    $(document).on('click', '.meal-history-btn', function(e) {
        e.preventDefault();
        var date = $(this).data('date');
        showMealHistory(date);
    });

    function showMealHistory(date) {
        // Create overlay layer
        var overlayHtml = '<div id="meal-history-overlay" style="position:absolute;top:0;left:0;right:0;bottom:0;z-index:1000;padding:40px;overflow-y:auto;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">' +
            '<h3><?php _e("Meal Choice History","catering-booking-and-scheduling") ?> - ' + date + '</h3>' +
            '<button type="button" id="close-meal-history"><?php _e("Close","catering-booking-and-scheduling") ?></button>' +
            '</div>' +
            '<div id="meal-history-content">' +
            '<div class="loading-overlay"><div class="spinner"></div></div>' +
            '</div>' +
            '</div>';
        
        $('#catering-popup-left').append(overlayHtml);
        
        // Load meal history data
        loadMealHistoryData(date);
    }

    function loadMealHistoryData(date) {
        // AJAX call to get meal choice history for the specific date
        $.post(catering_frontend_ajax.ajaxurl, {
            action: 'get_meal_choice_history',
            booking_id: currentBookingId,
            choice_date: date
        }, function(response) {
            if (response.success) {
                console.log(response.data);
                displayMealHistory(response.data);
            } else {
                $('#meal-history-content').html('<p><?php _e("No history found or error loading data.","catering-booking-and-scheduling") ?></p>');
            }
        }).fail(function() {
            $('#meal-history-content').html('<p><?php _e("Error loading meal history.","catering-booking-and-scheduling") ?></p>');
        });
    }

    function displayMealHistory(historyData) {
        if (!historyData || historyData.length === 0) {
            $('#meal-history-content').html('<p><?php _e("No meal choice history found for this date.","catering-booking-and-scheduling") ?></p>');
            return;
        }

        var html = '<div class="meal-history-timeline">';
        
        historyData.forEach(function(entry, index) {
            var actionIcon = '';
            var actionClass = '';
            
            switch(entry.action_type) {
                case 'initial_choice':
                    actionIcon = '<i class="fa-solid fa-plus" style="color:green;"></i>';
                    actionClass = 'history-initial';
                    break;
                case 'meal_choice_update':
                    actionIcon = '<i class="fa-solid fa-pen" style="color:blue;"></i>';
                    actionClass = 'history-update';
                    break;
                case 'meal_choice_deletion':
                    actionIcon = '<i class="fa-solid fa-trash" style="color:red;"></i>';
                    actionClass = 'history-delete';
                    break;
                default:
                    actionIcon = '<i class="fa-solid fa-circle" style="color:gray;"></i>';
                    actionClass = 'history-other';
            }

            html += '<div class="history-entry ' + actionClass + '">';
            html += '<div class="history-header">';
            html += '<span>' + actionIcon + ' ' + getActionTypeText(entry.action_type) + '</span>';
            
            // Convert time to Hong Kong time (GMT+8)
            var timeString = entry.amended_time;
            var localTime;
            
            // Check if the time string includes timezone info
            if (timeString.includes('UTC') || timeString.includes('+') || timeString.includes('-')) {
                localTime = new Date(timeString);
            } else {
                // Assume it's already in server timezone, convert to Hong Kong time
                localTime = new Date(timeString + ' UTC'); // Treat as UTC first
            }
            
            // Convert to Hong Kong time using toLocaleString
            var hkTimeString = localTime.toLocaleString("en-GB", {
                timeZone: "Asia/Hong_Kong",
                year: 'numeric',
                month: '2-digit', 
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            }).replace(/(\d{2})\/(\d{2})\/(\d{4}), (\d{2}:\d{2}:\d{2})/, '$3-$2-$1 $4');
            
            html += '<span style="font-size:12px;color:#666;">' + hkTimeString + '</span>';
            html += '</div>';
            
            html += '<div class="history-meta">';
            html += '<?php _e("Changed by","catering-booking-and-scheduling") ?>: ' + entry.changed_by_user_type + ' (ID: ' + entry.changed_by_user_id + ')';
            if (entry.change_reason) {
                html += ' | <?php _e("Reason","catering-booking-and-scheduling") ?>: ' + entry.change_reason;
            }
            html += '</div>';

            if (entry.previous_choice && entry.action_type !== 'initial_choice') {
                html += '<div class="previous-choice">';
                html += '<strong><?php _e("Previous Choice","catering-booking-and-scheduling") ?>:</strong>';
                html += formatMealChoice(entry.previous_choice);
                html += '</div>';
            }

            if (entry.new_choice && entry.action_type !== 'meal_choice_deletion') {
                html += '<div class="new-choice">';
                html += '<strong><?php _e("New Choice","catering-booking-and-scheduling") ?>:</strong>';
                html += formatMealChoice(entry.new_choice);
                html += '</div>';
            }

            html += '</div>';
        });
        
        html += '</div>';
        $('#meal-history-content').html(html);
    }

    function getActionTypeText(actionType) {
        switch(actionType) {
            case 'initial_choice':
                return '<?php _e("Initial Choice","catering-booking-and-scheduling") ?>';
            case 'meal_choice_update':
                return '<?php _e("Updated Choice","catering-booking-and-scheduling") ?>';
            case 'meal_choice_deletion':
                return '<?php _e("Deleted Choice","catering-booking-and-scheduling") ?>';
            default:
                return '<?php _e("Other Action","catering-booking-and-scheduling") ?>';
        }
    }

    function formatMealChoice(choiceData) {
        if (!choiceData || !Array.isArray(choiceData) || choiceData.length === 0) {
            return '<div style="color:#999;font-style:italic;"><?php _e("No data","catering-booking-and-scheduling") ?></div>';
        }
        
        var html = '<div style="margin-left:20px;margin-top:5px;">';
        
        choiceData.forEach(function(category) {
            if (category.meals && category.meals.length > 0) {
                html += '<div style="margin-bottom:8px;">';
                html += '<strong style="color:#333;">' + category.cat_title + ':</strong><br>';
                
                category.meals.forEach(function(meal) {
                    html += '<span style="background:#f0f0f0;padding:2px 6px;margin:2px;border-radius:3px;font-size:12px;">' + meal.title + '</span> ';
                });
                
                html += '</div>';
            }
        });
        
        html += '</div>';
        return html;
    }

    // Close meal history overlay
    $(document).on('click', '#close-meal-history', function() {
        $('#meal-history-overlay').remove();
    });

    // NEW: Event handlers for Select Address buttons
    $(document).on('click', '.select-address-1, .select-address-2', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var addressType = $btn.data('address-type');
        var originalText = $btn.text();
        
        // Show loading state
        $btn.text('<?php _e("Loading...", "catering-booking-and-scheduling"); ?>').prop('disabled', true);
        
        getUserAddress(addressType, function(response) {
            // Reset button state
            $btn.text(originalText).prop('disabled', false);
            
            if (response.success && response.data) {
                var addressData = response.data;
                
                // Fill in the form fields
                var $step = $btn.closest('.step');
                $step.find('input[name="delivery[first_name]"]').val(addressData.first_name || '');
                $step.find('input[name="delivery[last_name]"]').val(addressData.last_name || '');
                $step.find('input[name="delivery[address]"]').val(addressData.address || '');
                $step.find('select[name="delivery[city]"]').val(addressData.city || '');
                $step.find('select[name="delivery[phone_country]"]').val(addressData.phone_country || '+852');
                $step.find('input[name="delivery[phone]"]').val(addressData.phone || '');
                $step.find('textarea[name="delivery[delivery_note]"]').val(addressData.remarks || '');
                
                // Show success message
                if (addressType === 'shipping') {
                    alert('<?php _e("Address 1 loaded successfully!", "catering-booking-and-scheduling"); ?>');
                } else {
                    alert('<?php _e("Address 2 loaded successfully!", "catering-booking-and-scheduling"); ?>');
                }
            } else {
                // Show error message
                if (addressType === 'shipping') {
                    alert('<?php _e("No saved Address 1 found. Please enter your address manually.", "catering-booking-and-scheduling"); ?>');
                } else {
                    alert('<?php _e("No saved Address 2 found. Please enter your address manually.", "catering-booking-and-scheduling"); ?>');
                }
            }
        }, function(xhr) {
            // Reset button state
            $btn.text(originalText).prop('disabled', false);
            
            // Show error message
            alert('<?php _e("Failed to load address. Please try again.", "catering-booking-and-scheduling"); ?>');
        });
    });

});
</script>
