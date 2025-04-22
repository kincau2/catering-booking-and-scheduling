<?php

global $post;

    // Retrieve any existing meta so we can reselect them
    $saved_cat_ids = maybe_unserialize( get_post_meta( $post->ID, 'catering_cat_id', true ) );
    if(!is_array($saved_cat_ids)){ $saved_cat_ids = []; }

    // $saved_plan_days = maybe_unserialize( get_post_meta( $post->ID, 'catering_plan_length', true ) );
    // if(!is_array($saved_plan_days)){ $saved_plan_days = []; }

    ?>
    <div id="catering_option_product_data" class="panel woocommerce_options_panel hidden" style="padding: 15px 30px 30px 30px;">


        <!-- CSV Import/Export Widget -->
        <div id="csv-import-export">
            <h3><?php _e('CSV Import/Export', 'catering-booking-and-scheduling'); ?></h3>
            <button type="button" id="csv-import-btn" class="button"><?php _e('Import CSV', 'catering-booking-and-scheduling'); ?></button>
            <button type="button" id="csv-export-btn" class="button"><?php _e('Export CSV', 'catering-booking-and-scheduling'); ?></button>
        </div>

        <!-- Hidden modal for CSV import -->
        <div id="csv-import-modal" style="display:none; position:fixed; top:20%; left:35%; width:30%; background:#fff; border:1px solid #ccc; padding:20px; z-index:9999;">
            <h3><?php _e('Import CSV', 'catering-booking-and-scheduling'); ?></h3>
            <form id="csv-import-form">
                <input type="file" name="csv_file" id="csv_file" accept=".csv">
                <br/><br/>
                <input type="button" id="csv-submit-btn" class="button button-primary" value="<?php _e('Upload CSV', 'catering-booking-and-scheduling'); ?>">
                <button type="button" id="csv-cancel-btn" class="button"><?php _e('Cancel', 'catering-booking-and-scheduling'); ?></button>
            </form>
            <div id="csv-import-message"></div>
        </div>

        <!-- Hidden modal for CSV export -->
        <div id="csv-export-modal" style="display:none; position:fixed; top:25%; left:40%; width:20%; background:#fff; border:1px solid #ccc; padding:15px; z-index:9999;">
            <h4><?php _e('Export Schedule CSV','catering-booking-and-scheduling'); ?></h4>
            <div><?php _e('Select the date range for the export.','catering-booking-and-scheduling'); ?></div>
            <input type="date" id="export-start" /> - 
            <input type="date" id="export-end" /><br/><br/>
            <button type="button" id="export-submit" class="button button-primary"><?php _e('Download','catering-booking-and-scheduling'); ?></button>
            <button type="button" id="export-cancel" class="button"><?php _e('Cancel','catering-booking-and-scheduling'); ?></button>
        </div>

        <!-- Weekly Meal Schedule Widget -->
        <div id="catering-schedule-widget" style="margin-top:30px;">
            <h3><?php _e('Weekly Meal Schedule','catering-booking-and-scheduling'); ?></h3>
            <div id="week-controls" style="margin-bottom:10px;">
                <button type="button" id="prev-week" class="button"><?php _e('Previous','catering-booking-and-scheduling'); ?></button>
                <span id="week-range" style="margin:0 10px;"></span>
                <button type="button" id="next-week" class="button"><?php _e('Next','catering-booking-and-scheduling'); ?></button>
                <select id="month-select"></select>
                <select id="year-select"></select>
            </div>
            <style>
                /* add borders and padding on each grid cell */
                #schedule-calendar { 
                    border-collapse: collapse; 
                }
                #schedule-calendar td { 
                    border:1px solid #ccc; padding:5px; vertical-align:top;height: 180px; 
                }
                .date-label { 
                    font-weight:bold; 
                    margin:5px 0; 
                    display: flex;
                    flex-direction: row;
                    justify-content: space-between;
                }
                .holiday-label { 
                    color:red; font-weight:bold; margin-top:5px; display:block; 
                }
                div.holiday-label { 
                    margin-bottom:10px; 
                }
                .meal-badge{ 
                    display: inline-block;
                    padding: 5px 0px;
                    border-radius: 5px;
                    width: 99%;
                    text-align: center;
                }
                button.button.view-day {
                    padding: unset !important;
                    border: unset !important;
                    min-height: unset !important;
                    line-height: 18px;
                    background: #FFF;
                    margin-left: 10px;
                }
            </style>
            <table id="schedule-calendar" class="widefat fixed">
                <thead><tr>
                    <th><?php _e('Sunday','catering-booking-and-scheduling'); ?></th>
                    <th><?php _e('Monday','catering-booking-and-scheduling'); ?></th>
                    <th><?php _e('Tuesday','catering-booking-and-scheduling'); ?></th>
                    <th><?php _e('Wednesday','catering-booking-and-scheduling'); ?></th>
                    <th><?php _e('Thursday','catering-booking-and-scheduling'); ?></th>
                    <th><?php _e('Friday','catering-booking-and-scheduling'); ?></th>
                    <th><?php _e('Saturday','catering-booking-and-scheduling'); ?></th>
                </tr></thead>
                <tbody id="schedule-body"><tr>
                    <td data-day="0"></td><td data-day="1"></td><td data-day="2"></td>
                    <td data-day="3"></td><td data-day="4"></td><td data-day="5"></td>
                    <td data-day="6"></td>
                </tr></tbody>
            </table>
            <!-- modal for view details -->
            <div id="schedule-modal" style="display:none; position:fixed; top:20%; left:30%; width:40%; background:#fff; border:1px solid #ccc; padding:20px; z-index:9999;">
                <h4><?php _e('Meals for','catering-booking-and-scheduling'); ?> <span id="modal-date"></span></h4>
                <ul id="modal-list"></ul>
                <button type="button" id="modal-close" class="button"><?php _e('Close','catering-booking-and-scheduling'); ?></button>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var validCats = [];
            // Fetch all category titles for validation
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: { action: 'get_all_terms', termType: 'category' },
                success: function(res){
                    if(res.success){
                        validCats = res.data.map(function(item){ return item.title; });
                    }
                }
            });

            // Show the import modal when Import CSV button is clicked.
            $('#csv-import-btn').click(function(){
                 $('#csv-import-modal').show();
            });

            // Hide modal on Cancel
            $('#csv-cancel-btn').click(function(){
                 $('#csv-import-modal').hide();
            });

            // Stub action for Export CSV button (to be implemented as needed)
            $('#csv-export-btn').click(function(){
                 $('#csv-export-modal').show();
            });

            $('#export-cancel').click(function(){
                $('#csv-export-modal').hide();
            });

            $('#export-submit').click(function(){
                var s = $('#export-start').val(),
                    e = $('#export-end').val();
                if(!s||!e){ alert('<?php _e("Please select both dates.","catering-booking-and-scheduling"); ?>');return; }
                // ensure start not after end
                if(new Date(s) > new Date(e)){
                    alert('<?php _e("Start date cannot be later than end date.","catering-booking-and-scheduling"); ?>');
                    return;
                }
                var url = ajaxurl
                    + '?action=export_catering_schedule'
                    + '&product_id=' + productId
                    + '&start_date=' + s
                    + '&end_date=' + e;
                window.location = url;
            });

            // Handle CSV import form submission.
            $('#csv-submit-btn').on( 'click' , function(e){
                e.preventDefault();
                if (validCats.length === 0) {
                    alert('<?php _e("Category list not loaded. Please try again later.", "catering-booking-and-scheduling"); ?>');
                    return;
                }
                var fileInput = $('#csv_file')[0];
                if(fileInput.files.length === 0){
                    alert('<?php _e("Please select a CSV file.", "catering-booking-and-scheduling"); ?>');
                    return;
                }
                var file = fileInput.files[0];
                var reader = new FileReader();
                reader.onload = function(e) {
                    var csvData = e.target.result;
                    var lines = csvData.split(/\r\n|\n/);
                    
                    if (lines.length < 2) {
                        alert('<?php _e("CSV file is empty.", "catering-booking-and-scheduling"); ?>');
                        return;
                    }
                    // Updated CSV parsing function to correctly handle commas and quotes.
                    function parseCSVLine(line) {
                        var pattern = /("([^"]*(?:""[^"]*)*)"|[^",\r\n]*)(,|$)/g;
                        var result = [];
                        var match;
                        while ((match = pattern.exec(line)) !== null) {
                            var matchedValue = match[1];
                            if(matchedValue.charAt(0) === '"' && matchedValue.slice(-1) === '"'){
                                matchedValue = matchedValue.slice(1, -1).replace(/""/g, '"');
                            }
                            result.push(matchedValue);
                            if(match[3] === '') break;
                        }
                        return result;
                    }
                    var headers    = parseCSVLine(lines[0]);
                    var dateIndex  = headers.indexOf("Date");
                    var catIndex   = headers.indexOf("Category");
                    var idIndex    = headers.indexOf("ID");
                    var skuIndex   = headers.indexOf("SKU");
                    var titleIndex = headers.indexOf("Meal_title");
                    var tagIndex   = headers.indexOf("Tag");
                    if(tagIndex === -1){
                        alert('<?php _e("CSV must contain Tag column.", "catering-booking-and-scheduling"); ?>');
                        return;
                    }
                    if(idIndex===-1 || skuIndex===-1 || titleIndex===-1){
                        alert('<?php _e("CSV must contain ID, SKU, and Meal_title columns.", "catering-booking-and-scheduling"); ?>');
                        return;
                    }
                    if(catIndex === -1){
                        alert('<?php _e("CSV file does not contain Category column.", "catering-booking-and-scheduling"); ?>');
                        return;
                    }
                    // Added date validation function
                    function isValidDate(dateStr) {
                        var date = new Date(dateStr);
                        return !isNaN(date.getTime());
                    }
                    var missingRows     = [];
                    var missingCatRows  = [];
                    var invalidCatRows  = [];
                    var uniqueDates     = [];

                    // Check for duplicate IDs on the same date
                    var idCountsByDate = {}, dupRows = [];
                    lines.slice(1).forEach(function(line, idx) {
                        if (!line.trim()) return;
                        var cols    = parseCSVLine(line),
                            dateVal = cols[dateIndex].trim(),
                            idVal   = cols[idIndex].trim();
                        if (!dateVal || !idVal) return;
                        idCountsByDate[dateVal] = idCountsByDate[dateVal] || {};
                        if (idCountsByDate[dateVal][idVal]) {
                            dupRows.push(idx + 2);
                        } else {
                            idCountsByDate[dateVal][idVal] = 1;
                        }
                    });
                    if (dupRows.length) {
                        alert('<?php _e("Duplicate meal IDs found on the same date at rows:", "catering-booking-and-scheduling"); ?> ' + dupRows.join(', '));
                        return;
                    }

                    for(var i = 1; i < lines.length; i++){
                        if(!lines[i].trim()) continue;
                        var cols = parseCSVLine(lines[i]);
                        var dateVal = cols[dateIndex].trim();
                        // Format default CSV date (yyyy/MM/dd) to SQL format yyyy-MM-dd
                        var formattedDateVal = (function(v){
                            var parts = v.split(/[\/\-]/);
                            if(parts.length === 3){
                                var y = parts[0];
                                var m = parts[1].padStart(2,'0');
                                var d = parts[2].padStart(2,'0');
                                return y + '-' + m + '-' + d;
                            }
                            return v;
                        })(dateVal);

                        if(!formattedDateVal || !isValidDate(formattedDateVal)){
                            missingRows.push(i+1);
                            continue;
                        }
                        var catVal = cols[catIndex].trim();
                        if(!catVal){
                            missingCatRows.push(i+1);
                            continue;
                        }
                        // Validate each category against system list
                        var catVals = catVal.split('|').map(function(v){ return v.trim(); });
                        var invalids = catVals.filter(function(v){
                            return $.inArray(v, validCats) === -1;
                        });
                        if (invalids.length) {
                            invalidCatRows.push({ row: i+1, vals: invalids });
                            continue;
                        }
                        if($.inArray(formattedDateVal, uniqueDates) === -1){
                            uniqueDates.push(formattedDateVal);
                        }
                    }
                    if(missingRows.length > 0){
                        alert('<?php _e("CSV file contains rows with empty or invalid Date column at row numbers: ", "catering-booking-and-scheduling"); ?>' + missingRows.join(', '));
                        return;
                    }
                    if(missingCatRows.length > 0){
                        alert('<?php _e("CSV file contains rows with empty Category value at row numbers: ", "catering-booking-and-scheduling"); ?>' + missingCatRows.join(', '));
                        return;
                    }
                    if (invalidCatRows.length > 0) {
                        var msgs = [];
                        invalidCatRows.forEach(function(obj){
                            msgs.push('Row ' + obj.row + ': ' + obj.vals.join(', '));
                        });
                        alert('<?php _e("CSV file contains invalid Category values:", "catering-booking-and-scheduling"); ?>\n' + msgs.join('\n'));
                        return;
                    }

                    // collect all meal entries with row number
                    var mealsToValidate = [];
                    for(var i=1; i<lines.length; i++){
                        if(!lines[i].trim()) continue;
                        var cols = parseCSVLine(lines[i]);
                        mealsToValidate.push({
                            row:   i+1,
                            id:    cols[idIndex].trim(),
                            sku:   cols[skuIndex].trim(),
                            title: cols[titleIndex].trim()
                        });
                    }
                    // backend validation of meals
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'validate_catering_meals',
                            meals:  mealsToValidate
                        },
                        success: function(res){
                            if(res.success){
                                if(res.data.invalid.length){
                                    var msgs = [];
                                    res.data.invalid.forEach(function(v){
                                        msgs.push('Row '+v.row+': ID '+v.id+', SKU '+v.sku+', Title '+v.title);
                                    });
                                    alert('<?php _e("CSV contains invalid meal entries:", "catering-booking-and-scheduling"); ?>\n'+msgs.join('\n'));
                                    return;
                                }
                                // AJAX call to check existing schedule data by product_id and unique dates.
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    dataType: 'json',
                                    data: {
                                        action: 'check_catering_schedule',
                                        product_id: <?php echo intval($post->ID); ?>,
                                        unique_dates: uniqueDates
                                    },
                                    success: function(response) {
                                        if(response.success) {
                                            if(response.data.existing && response.data.existing.length > 0) {
                                                var existingDates = response.data.existing.join(', ');
                                                if(confirm('<?php _e("The following dates already have schedule data: ", "catering-booking-and-scheduling"); ?>' + existingDates + '<?php _e(". Do you want to clear the existing data for these dates?", "catering-booking-and-scheduling"); ?>')) {
                                                    // AJAX call to clear schedule data.
                                                    $.ajax({
                                                        url: ajaxurl,
                                                        type: 'POST',
                                                        dataType: 'json',
                                                        data: {
                                                            action: 'clear_catering_schedule',
                                                            product_id: <?php echo intval($post->ID); ?>,
                                                            dates: response.data.existing
                                                        },
                                                        success: function(resp) {
                                                            if(resp.success) {
                                                                $('#csv-import-message').html('<p><?php _e("Existing schedule data cleared. Proceeding with CSV processing...", "catering-booking-and-scheduling"); ?></p>');
                                                                
                                                                // prepare progress UI
                                                                $('#csv-import-message').html(
                                                                  '<div id="csv-errors" style="color:red;margin-bottom:10px;"></div>' +
                                                                  '<div id="csv-progress">' +
                                                                    '<span id="csv-progress-text">Processing 0/'+(lines.length-1)+'</span><br>' +
                                                                    '<progress id="csv-progress-bar" max="'+(lines.length-1)+'" value="0" style="width:100%;"></progress>' +
                                                                  '</div>'
                                                                );

                                                                var total = lines.length-1, processed = 0, errors = [];

                                                                // process each row sequentially
                                                                function processNextRow(i){
                                                                  if(i > total){
                                                                    if(errors.length){
                                                                      $('#csv-errors').html(errors.join('<br>'));
                                                                    } else {
                                                                      $('#csv-progress-text').text('All rows processed successfully.');
                                                                    }
                                                                    return;
                                                                  }
                                                                  var cols = parseCSVLine(lines[i]);
                                                                  var meal_id = cols[idIndex].trim(),
                                                                      dateVal = cols[dateIndex].trim(),
                                                                      formattedDate = (function(v){
                                                                        var p=v.split(/[\/\-]/); return (p.length===3)
                                                                          ? p[0]+'-'+p[1].padStart(2,'0')+'-'+p[2].padStart(2,'0')
                                                                          : v;
                                                                      })(dateVal),
                                                                      catTitles = cols[catIndex].trim().split('|').map(function(v){return v.trim();}),
                                                                      tagVal = cols[tagIndex].trim();

                                                                  $.ajax({
                                                                    url: ajaxurl,
                                                                    type: 'POST',
                                                                    dataType: 'json',
                                                                    data: {
                                                                      row:       i,
                                                                      action:    'process_catering_schedule_row',
                                                                      post_id:   <?php echo intval($post->ID); ?>,
                                                                      meal_id:   meal_id,
                                                                      date:      formattedDate,
                                                                      cats:      catTitles,
                                                                      tag:       tagVal
                                                                    }
                                                                  }).done(function(res){
                                                                    if(!res.success){
                                                                      errors.push('Row '+i+': '+res.data);
                                                                    }
                                                                  }).fail(function(){
                                                                    errors.push('Row '+i+': request failed');
                                                                  }).always(function(){
                                                                    processed++;
                                                                    $('#csv-progress-bar').val(processed);
                                                                    $('#csv-progress-text').text('Processing '+processed+'/'+total);
                                                                    processNextRow(i+1);
                                                                  });
                                                                }

                                                                // kick off
                                                                processNextRow(1);
                                                            } else {
                                                                alert(resp.data);
                                                            }
                                                        }
                                                    });
                                                }
                                            } else {
                                                $('#csv-import-message').html('<p><?php _e("No existing schedule data conflicts. Proceeding with CSV processing...", "catering-booking-and-scheduling"); ?></p>');
                                                
                                                // prepare progress UI
                                                $('#csv-import-message').html(
                                                  '<div id="csv-errors" style="color:red;margin-bottom:10px;"></div>' +
                                                  '<div id="csv-progress">' +
                                                    '<span id="csv-progress-text">Processing 0/'+(lines.length-1)+'</span><br>' +
                                                    '<progress id="csv-progress-bar" max="'+(lines.length-1)+'" value="0" style="width:100%;"></progress>' +
                                                  '</div>'
                                                );

                                                var total = lines.length-1, processed = 0, errors = [];

                                                // process each row sequentially
                                                function processNextRow(i){
                                                  if(i > total){
                                                    if(errors.length){
                                                      $('#csv-errors').html(errors.join('<br>'));
                                                    } else {
                                                      $('#csv-progress-text').text('All rows processed successfully.');
                                                    }
                                                    return;
                                                  }
                                                  var cols = parseCSVLine(lines[i]);
                                                  var meal_id = cols[idIndex].trim(),
                                                      dateVal = cols[dateIndex].trim(),
                                                      formattedDate = (function(v){
                                                        var p=v.split(/[\/\-]/); return (p.length===3)
                                                          ? p[0]+'-'+p[1].padStart(2,'0')+'-'+p[2].padStart(2,'0')
                                                          : v;
                                                      })(dateVal),
                                                      catTitles = cols[catIndex].trim().split('|').map(function(v){return v.trim();}),
                                                      tagVal = cols[tagIndex].trim();

                                                  $.ajax({
                                                    url: ajaxurl,
                                                    type: 'POST',
                                                    dataType: 'json',
                                                    data: {
                                                      row:       i,
                                                      action:    'process_catering_schedule_row',
                                                      post_id:   <?php echo intval($post->ID); ?>,
                                                      meal_id:   meal_id,
                                                      date:      formattedDate,
                                                      cats:      catTitles,
                                                      tag:       tagVal
                                                    }
                                                  }).done(function(res){
                                                    if(!res.success){
                                                      errors.push('Row '+i+': '+res.data);
                                                    }
                                                  }).fail(function(){
                                                    errors.push('Row '+i+': request failed');
                                                  }).always(function(){
                                                    processed++;
                                                    $('#csv-progress-bar').val(processed);
                                                    $('#csv-progress-text').text('Processing '+processed+'/'+total);
                                                    processNextRow(i+1);
                                                  });
                                                }

                                                // kick off
                                                processNextRow(1);
                                            }
                                        } else {
                                            alert(response.data);
                                        }
                                    }
                                });
                            } else {
                                alert(res.data);
                            }
                        }
                    });
                };
                reader.readAsText(file);
            });

            var productId = <?php echo intval($post->ID); ?>,
                today = new Date(),
                weekStart, weekEnd;

            // build month/year selects
            var monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            for (var m = 1; m <= 12; m++) {
                $('#month-select').append(
                    '<option value="' + m + '">' + monthNames[m - 1] + '</option>'
                );
            }
            var curYear = today.getFullYear();
            for(var y=curYear-5;y<=curYear+5;y++){
                $('#year-select').append('<option value="'+y+'">'+y+'</option>');
            }

            function formatDate(dt){
                var mm=('0'+(dt.getMonth()+1)).slice(-2),
                    dd=('0'+dt.getDate()).slice(-2);
                return dt.getFullYear()+'-'+mm+'-'+dd;
            }

            function updateWeekControls(){
                $('#month-select').val(weekStart.getMonth()+1);
                $('#year-select').val(weekStart.getFullYear());
                $('#week-range').text(
                    ('0'+weekStart.getDate()).slice(-2)+'/'+('0'+(weekStart.getMonth()+1)).slice(-2)+'/'+weekStart.getFullYear()
                    +' – '+
                    ('0'+weekEnd.getDate()).slice(-2)+'/'+('0'+(weekEnd.getMonth()+1)).slice(-2)+'/'+weekEnd.getFullYear()
                );
            }

            var weekSched = {}, weekTerms = {};

            function loadSchedule(){
                // 1) label each cell with its date
                $('#schedule-calendar td').each(function(){
                    var wd = $(this).data('day');
                    if(wd!==undefined){
                        var d = new Date(weekStart);
                        d.setDate( weekStart.getDate() + parseInt(wd,10) );
                        // display as DD/MM
                        var dd = ('0'+d.getDate()).slice(-2),
                            mm = ('0'+(d.getMonth()+1)).slice(-2);
                        $(this).html('<div class="date-label">'+ dd + '/' + mm +'<div class="view-button"></div></div><div class="holiday-label"></div>');
                    }
                });

                // 2) fetch and group by category
                $.post(ajaxurl,{
                    action:     'get_meal_schedule_week',
                    product_id: productId,
                    start_date: formatDate(weekStart),
                    end_date:   formatDate(weekEnd)
                }, function(res){
                    if(!res.success) return;
                    weekSched = res.data.schedule;
                    weekTerms = res.data.terms;
                    var sched = res.data.schedule;
                    var terms = res.data.terms;
                    Object.keys(sched).forEach(function(d){
                        var wd   = new Date(d).getDay();
                        var cell = $('#schedule-body td[data-day="'+wd+'"]');
                        // sort categories by ordering
                        var cids = Object.keys(sched[d]).sort(function(a,b){
                            return terms[a].ordering - terms[b].ordering;
                        });
                        cids.forEach(function(cid){
                            var cat = terms[cid];
                            cell.append('<div class="date-label" style="font-weight:bold;">'+cat.title+'</div>');
                            // sort meals by ID ascending
                            var mealsSorted = sched[d][cid].slice().sort(function(a,b){ return a.id - b.id; });
                            mealsSorted.forEach(function(m){
                                var bg = cat.color || '#FFF';
                                cell.append(
                                  '<span class="meal-badge" style="background:'+bg+'; margin:2px;">'+
                                  m.title+
                                  '</span><br>'
                                );
                            });
                        });
                        cell.find('.view-button').append('<button type="button" class="button view-day" data-date="'+d+'"><?php _e('Detail','catering-booking-and-scheduling'); ?></button>');
                    });
                    // fetch holidays for current week (may span two months)
                    // determine months covered by this week
                    var months = [];
                    var y1 = weekStart.getFullYear(), m1 = weekStart.getMonth()+1;
                    var y2 = weekEnd.getFullYear(),   m2 = weekEnd.getMonth()+1;
                    months.push({year:y1, month:m1});
                    if(m2 !== m1 || y2 !== y1) {
                        months.push({year:y2, month:m2});
                    }
                    var ws = weekStart.getTime(), we = weekEnd.getTime();
                    months.forEach(function(mo){
                        $.post(ajaxurl, {
                            action: 'get_holiday_data',
                            year:   mo.year,
                            month:  mo.month
                        }, function(hr){
                            if(!hr.success) return;
                            hr.data.forEach(function(h){
                                var ht = new Date(h.date).getTime();
                                if(ht < ws || ht > we) return;
                                var wd2 = new Date(h.date).getDay(),
                                    cell2 = $('#schedule-body td[data-day="'+wd2+'"] div.holiday-label');
                                cell2.append('<span class="holiday-label">'+ h.holidayNames.join(', ') +'</span>');
                            });
                        }, 'json');
                    });
                },'json');
            }

            function setWeek(dt){
                weekStart=new Date(dt);
                weekStart.setDate( weekStart.getDate() - weekStart.getDay() );
                weekEnd = new Date(weekStart);
                weekEnd.setDate( weekEnd.getDate() + 6 );
                updateWeekControls();
                loadSchedule();
            }

            // init to current week
            setWeek(today);

            $('#prev-week').click(function(){ setWeek(new Date(weekStart).setDate(weekStart.getDate()-7)); });
            $('#next-week').click(function(){ setWeek(new Date(weekStart).setDate(weekStart.getDate()+7)); });
            $('#month-select,#year-select').change(function(){
                var m=parseInt($('#month-select').val(),10)-1,
                    y=parseInt($('#year-select').val(),10);
                setWeek(new Date(y,m,1));
            });

            // view modal
            $(document).off('click','.view-day').on('click','.view-day',function(){
                var d = $(this).data('date');
                $('#modal-date').text(d);
                $('#modal-list').empty();
                var dayData = weekSched[d]||{}, terms = weekTerms;
                // sort categories by ordering
                Object.keys(dayData).sort(function(a,b){
                    return terms[a].ordering - terms[b].ordering;
                }).forEach(function(cid){
                    $('#modal-list').append('<li><strong>'+terms[cid].title+'</strong></li>');
                    // list meals with tag
                    dayData[cid].forEach(function(m){
                        $('#modal-list').append(
                          '<li>'+m.title+' <em>('+m.tag+')</em></li>'
                        );
                    });
                });
                $('#schedule-modal').show();
            });
            $('#modal-close').click(function(){ $('#schedule-modal').hide(); });
        });
        </script>
    </div>

