(function($){
    window.getDaySchedule = function(productId, bookingId, date, success, error){
        $.ajax({
            url: catering_frontend_ajax.ajaxurl,
            method: 'GET',
            dataType: 'json',
            data: {
                action:       'get_day_schedule',
                product_id:   productId,
                booking_id:   bookingId,
                date:         date
            },
            success: success,
            error:   error || function(){ alert(cateringi18n('Error fetching schedule.')); }
        });
    };
    window.getMealScheduleWeek = function(productId, startDate, endDate, bookingId, success, error){

        $.ajax({
            url: catering_frontend_ajax.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: {
                action:     'get_meal_schedule_week',
                product_id: productId,
                start_date: startDate,
                end_date:   endDate,
                booking_id: bookingId
            },
            success: success,
            error:   error || function(){ alert(cateringi18n('Error fetching week schedule.')); }
        });
    };
    window.getHolidayData = function(year, month, success, error){
        $.ajax({
            url:   catering_frontend_ajax.ajaxurl,
            method:'POST',
            dataType:'json',
            data: {
                action: 'get_holiday_data',
                year:   year,
                month:  month
            },
            success: success,
            error:   error || function(){ alert(cateringi18n('Error fetching holiday data.')); }
        });
    };

    window.getMinOrderDays = function(success, error){
        $.ajax({
            url:   catering_frontend_ajax.ajaxurl,
            method:'POST',
            dataType:'json',
            data: {
                action: 'get_min_day_before_order'
            },
            success: success,
            error:   error || function(){ alert(cateringi18n('Error fetching min‑order days.')); }
        });
    };

    window.validateBooking = function(bookingId, success, error){
        $.ajax({
            url: catering_frontend_ajax.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'validate_booking',
                booking_id: bookingId
            },
            success: success,
            error: error || function(){ alert(cateringi18n('Error validating booking.')); }
        });
    };

    window.saveUserChoice = function(bookingId, date, choice, address, preference, success, error){
        $.ajax({
            url: catering_frontend_ajax.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'save_user_choice',
                booking_id: bookingId,
                date: date,
                choice: choice,
                address: address,
                preference: preference
            },
            success: success,
            error: error || function(){ alert(cateringi18n('Error saving user choice.')); }
        });
    };

    window.getUserMealChoicesRange = function(bookingId, startDate, endDate, success, error){
        $.ajax({
            url: catering_frontend_ajax.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'get_user_meal_choices_range',
                booking_id: bookingId,
                start_date: startDate,
                end_date: endDate
            },
            success: success,
            error: error || function(){ alert(cateringi18n('Error fetching user meal choices.')); }
        });
    };

    window.deleteUserMealChoice = function(bookingId, date, success, error){
        $.ajax({
            url: catering_frontend_ajax.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'delete_user_meal_choice',
                booking_id: bookingId,
                date: date
            },
            success: success,
            error: error || function(){ alert(cateringi18n('Error deleting user meal choice.')); }
        });
    };

    window.getHealthStatus = function(bookingId, success, error){
        $.ajax({
            url: catering_frontend_ajax.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'get_health_status',
                booking_id: bookingId
            },
            success: success,
            error: error || function(){ alert(cateringi18n('Error fetching health status.')); }
        });
    };

    window.updateHealthStatus = function(bookingId, healthStatus, success, error, autoDeleteConfirmed){
        autoDeleteConfirmed = autoDeleteConfirmed || false;
        
        $.ajax({
            url: catering_frontend_ajax.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'update_health_status',
                booking_id: bookingId,
                health_status: healthStatus,
                auto_delete_confirmed: autoDeleteConfirmed
            },
            success: function(response) {
                // Check if server requires confirmation for meal deletion
                if (response && !response.success && response.requires_confirmation) {
                    // Show confirmation dialog
                    if (confirm(response.confirmation_message)) {
                        // User confirmed, retry the request with confirmation flag
                        window.updateHealthStatus(bookingId, healthStatus, success, error, true);
                    } else {
                        // User cancelled, treat as error
                        if (error) {
                            error({
                                responseJSON: {
                                    success: false,
                                    data: cateringi18n('Update cancelled by user.')
                                }
                            });
                        }
                    }
                } else {
                    // Normal response, call original success handler
                    if (success) success(response);
                }
            },
            error: error || function(){ alert(cateringi18n('Error updating health status.')); }
        });
    };

    window.searchMeals = function(query, success, error){
        $.ajax({
            url: catering_frontend_ajax.ajaxurl,
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'search_meals',
                q: query.term
            },
            success: function(resp){
                if(resp.success){
                    success(resp.data);
                } else {
                    success([]);
                }
            },
            error: error || function(){ alert(cateringi18n('Error searching meals.')); }
        });
    };

    // helper: two‐week schedule preview (bookingId=0)
    window.getSchedulePreview = function(productId, startDate, endDate, success, error){
        window.getMealScheduleWeek(productId, startDate, endDate, 0, success, error);
    };
})(jQuery);

jQuery(function($){
    window.CateringAjax = {
        getSchedulePreview: function(product_id, start, end){
            return $.post(catering_frontend_ajax.ajaxurl, {
                action:   'get_meal_schedule_week',
                product_id: product_id,
                start_date: start,
                end_date:   end,
                is_preview: true  // Add flag to identify schedule preview requests
            }, null, 'json');
        },
        getHolidayData: function(year, month){
            return $.post(catering_frontend_ajax.ajaxurl, {
                action: 'get_holiday_data',
                year:   year,
                month:  month
            }, null, 'json');
        }
    };
});