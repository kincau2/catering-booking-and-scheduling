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
            error:   error || function(){ alert('Error fetching schedule.'); }
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
            error:   error || function(){ alert('Error fetching week schedule.'); }
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
            error:   error || function(){ alert('Error fetching holiday data.'); }
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
            error:   error || function(){ alert('Error fetching min‑order days.'); }
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
            error: error || function(){ alert('Error validating booking.'); }
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
            error: error || function(){ alert('Error saving user choice.'); }
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
            error: error || function(){ alert('Error fetching user meal choices.'); }
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
            error: error || function(){ alert('Error deleting user meal choice.'); }
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
            error: error || function(){ alert('Error fetching health status.'); }
        });
    };

    window.updateHealthStatus = function(bookingId, healthStatus, success, error){
        $.ajax({
            url: catering_frontend_ajax.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'update_health_status',
                booking_id: bookingId,
                health_status: healthStatus
            },
            success: success,
            error: error || function(){ alert('Error updating health status.'); }
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
            error: error || function(){ alert('Error searching meals.'); }
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
                end_date:   end
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