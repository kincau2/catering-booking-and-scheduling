// Phone validation functions
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
            return cateringi18n('Please enter a valid Hong Kong mobile number (8 digits starting with 4, 5, 6, or 9)');
        case '+853':
            return cateringi18n('Please enter a valid Macau mobile number (8 digits starting with 6)');
        case '+86':
            return cateringi18n('Please enter a valid China mobile number (11 digits starting with 1)');
        default:
            return cateringi18n('Please enter a valid phone number');
    }
}

// Real-time phone validation
jQuery(document).ready(function($) {
    // Function to validate phone field
    function validatePhoneField(phoneInput, countrySelect) {
        const phone = phoneInput.val();
        const countryCode = countrySelect.val();
        const fieldName = phoneInput.attr('name');
        
        if (phone && countryCode) {
            const isValid = validatePhoneWithCountry(phone, countryCode);
            const errorClass = 'phone-validation-error';
            const errorId = fieldName + '-error';
            
            // Remove existing error
            phoneInput.removeClass('error');
            $('#' + errorId).remove();
            
            if (!isValid) {
                // Add error styling and message
                phoneInput.addClass('error');
                const errorMsg = getPhoneErrorMessage(countryCode);
                phoneInput.after('<div id="' + errorId + '" class="' + errorClass + '" style="color: #932331; font-size: 12px; margin-top: 5px;">' + errorMsg + '</div>');
                return false;
            }
        }
        return true;
    }
    
    // Bind validation to phone inputs
    function bindPhoneValidation() {
        // Shipping phone
        const shippingPhone = $('input[name="shipping_phone"]');
        const shippingCountry = $('select[name="shipping_phone_country"]');
        
        if (shippingPhone.length && shippingCountry.length) {
            shippingPhone.on('blur keyup', function() {
                validatePhoneField(shippingPhone, shippingCountry);
            });
            
            shippingCountry.on('change', function() {
                if (shippingPhone.val()) {
                    validatePhoneField(shippingPhone, shippingCountry);
                }
            });
        }
        
        // Shipping 2 phone
        const shipping2Phone = $('input[name="shipping_2_phone"]');
        const shipping2Country = $('select[name="shipping_2_phone_country"]');
        
        if (shipping2Phone.length && shipping2Country.length) {
            shipping2Phone.on('blur keyup', function() {
                validatePhoneField(shipping2Phone, shipping2Country);
            });
            
            shipping2Country.on('change', function() {
                if (shipping2Phone.val()) {
                    validatePhoneField(shipping2Phone, shipping2Country);
                }
            });
        }
        
        // Billing phone
        const billingPhone = $('input[name="billing_phone"]');
        const billingCountry = $('select[name="billing_phone_country"]');
        
        if (billingPhone.length && billingCountry.length) {
            billingPhone.on('blur keyup', function() {
                validatePhoneField(billingPhone, billingCountry);
            });
            
            billingCountry.on('change', function() {
                if (billingPhone.val()) {
                    validatePhoneField(billingPhone, billingCountry);
                }
            });
        }
    }
    
    // Initial binding
    bindPhoneValidation();
    
    // Re-bind after checkout update (for dynamic content)
    $(document.body).on('checkout_updated', function() {
        bindPhoneValidation();
    });
    
    // Checkout form validation
    $('form.checkout').on('checkout_place_order', function() {
        let isValid = true;
        
        // Validate shipping phone
        const shippingPhone = $('input[name="shipping_phone"]');
        const shippingCountry = $('select[name="shipping_phone_country"]');
        if (shippingPhone.length && shippingCountry.length) {
            if (!validatePhoneField(shippingPhone, shippingCountry)) {
                isValid = false;
            }
        }
        
        // Validate billing phone
        const billingPhone = $('input[name="billing_phone"]');
        const billingCountry = $('select[name="billing_phone_country"]');
        if (billingPhone.length && billingCountry.length) {
            if (!validatePhoneField(billingPhone, billingCountry)) {
                isValid = false;
            }
        }
        
        return isValid;
    });
    
    // Real-time validation for catering popup phone fields
    $(document).on('blur keyup', 'input[name="delivery[phone]"]', function() {
        const phoneInput = $(this);
        const countrySelect = $('select[name="delivery[phone_country]"]');
        
        if (countrySelect.length) {
            validateCateringPhoneField(phoneInput, countrySelect);
        }
    });
    
    $(document).on('change', 'select[name="delivery[phone_country]"]', function() {
        const countrySelect = $(this);
        const phoneInput = $('input[name="delivery[phone]"]');
        
        if (phoneInput.val() && phoneInput.length) {
            validateCateringPhoneField(phoneInput, countrySelect);
        }
    });
    
    function validateCateringPhoneField(phoneInput, countrySelect) {
        const phone = phoneInput.val();
        const countryCode = countrySelect.val();
        
        if (phone && countryCode) {
            const isValid = validatePhoneWithCountry(phone, countryCode);
            const errorClass = 'phone-validation-error';
            const errorId = 'popup-phone-error';
            
            // Remove existing error
            phoneInput.removeClass('error');
            $('#' + errorId).remove();
            
            if (!isValid) {
                // Add error styling and message
                phoneInput.addClass('error');
                const errorMsg = getPhoneErrorMessage(countryCode);
                phoneInput.after('<div id="' + errorId + '" class="' + errorClass + '">' + errorMsg + '</div>');
                return false;
            }
        }
        return true;
    }
});
