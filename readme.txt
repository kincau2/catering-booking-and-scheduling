# Catering Booking and Scheduling Plugin

A comprehensive WordPress plugin for managing catering meal bookings and scheduling, designed specifically for catering businesses offering meal plans to customers.

## Overview

This plugin extends WooCommerce to provide a complete catering meal management system. It allows businesses to create meal plans as products, customers to book and schedule their meals in advance, and administrators to manage the entire meal delivery operation.

## Key Features

### 1. Catering Plan Product Type
- **Custom Product Type**: Extends WooCommerce with a new "Catering Plan" product type (`wc-product-catering-plan.php`)
- **Variable Products**: Support for different meal plan variations (duration, quantity, etc.)
- **Plan Configuration**: Set plan duration in days, expiry dates, and category quantities

### 2. Meal Booking System
- **Booking Management**: Creates and manages customer bookings via `class-booking.php`
- **User Account Integration**: Dedicated "Catering Bookings" tab in WooCommerce My Account (`my-account.php`)
- **Booking Status**: Active/inactive booking management with health status tracking
- **Plan Expiry**: Automatic expiry date calculation based on first meal selection

### 3. Interactive Meal Selection
- **Calendar Interface**: Week-based calendar popup for meal selection (`catering-booking-popup.php`)
- **Real-time Availability**: Shows available meals per day based on schedule
- **Drag-and-Drop Scheduling**: Intuitive meal selection with immediate feedback
- **Mobile Responsive**: Optimized for both desktop and mobile interfaces

### 4. Meal Schedule Management
- **Admin Schedule Interface**: Comprehensive meal scheduling dashboard (`meal-schedule.php`)
- **Category-based Organization**: Meals organized by categories (soups, mains, etc.)
- **Color-coded Display**: Visual meal categories with customizable colors
- **Holiday Management**: Built-in holiday calendar with delivery adjustments

### 5. Health & Dietary Management
- **Allergy Tracking**: Customer allergy information management (`terms-list.php`)
- **Health Status**: Support for prenatal/postpartum meal requirements
- **Dietary Preferences**: Customizable meal preferences and restrictions
- **Due Date Tracking**: Automatic meal type switching based on pregnancy due dates

### 6. Delivery Management
- **Address Management**: Multiple shipping addresses per customer
- **Hong Kong Districts**: Pre-configured HK district selection
- **Delivery Reports**: Comprehensive delivery reporting and export functionality
- **Route Optimization**: Grouped delivery information for efficient routing

### 7. Advanced Features
- **Multilingual Support**: Built-in internationalization with Chinese language support
- **AJAX Interface**: Real-time updates without page refreshes (`ajax.php`)
- **Data Export**: Excel/CSV export capabilities for reporting
- **Email Notifications**: Automated booking confirmations and updates (`email.php`)
- **Holiday Handling**: Automatic meal delivery rescheduling for public holidays

## File Structure

### Core Files
- `catering-booking-and-scheduling.php` - Main plugin file with activation hooks
- `include/init.php` - Plugin initialization and asset loading
- `include/core-functions.php` - Core booking and validation functions

### Classes & Models
- `include/class-booking.php` - Booking management class
- `include/class-meal.php` - Meal data handling (currently empty)
- `include/wc-product-catering-plan.php` - Custom WooCommerce product type

### Admin Interface
- `include/interface.php` - Admin menu and page routing
- `template/meal-schedule.php` - Main admin scheduling interface
- `template/meal-form.php` - Meal creation and editing forms
- `template/meal-settings.php` - Global meal settings
- `template/holiday-setting.php` - Holiday calendar management
- `template/delivery-report.php` - Delivery reporting interface
- `template/wc-product-catering-options.php` - Admin product edit page meal settings, including meal schedule CSV import

### Customer Interface
- `template/catering-booking.php` - My Account booking list
- `template/catering-booking-popup.php` - Interactive meal selection calendar
- `template/schedule-preview.php` - Meal preview on product page, display via shortcode [schedule_preview_button]
- `template/wc-checkout-allergy.php` - Checkout allergy selection


### Utilities & Assets
- `include/ajax.php` - AJAX request handlers
- `include/order-function.php` - WooCommerce order integration
- `public/catering.js` - Frontend JavaScript functionality
- `public/catering.css` - Plugin styling
- `public/catering-i18n.js` - Internationalization support

### Libraries
- `vendor/` - Composer dependencies for Excel export and ICS parsing
- `lib/` - JavaScript libraries (jQuery, Select2, jscolor)

### Language Support
- `languages/` - Translation files for Traditional/Simplified Chinese

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin panel
3. Ensure WooCommerce is installed and activated
4. Configure catering settings under "Meal schedule" in the admin menu

## Configuration

### Initial Setup
1. **Meal Categories**: Create meal categories (soups, mains, sides, etc.)
2. **Meal Items**: Add individual meal items with scheduling
3. **Holiday Calendar**: Configure public holidays and delivery exceptions
4. **Allergy Terms**: Set up common allergens and dietary restrictions

### Product Setup
1. Create a new product and select "Catering plan" as the product type
2. Configure plan duration, category quantities, and pricing
3. Set up product variations if needed
4. import meal schedule via csv in edit product page

### Customer Workflow
1. Customers purchase catering plans through WooCommerce
2. After payment confirmation, bookings are automatically created
3. Customers access their bookings via My Account → Catering Bookings
4. Interactive calendar allows meal selection within plan parameters
5. Delivery addresses and preferences can be set per meal selection

## Admin Features

### Meal Schedule Dashboard
- **Weekly View**: Visual calendar showing all scheduled meals
- **Badge System**: Click meal badges to view customer details and quantities
- **Export Function**: Generate delivery reports for specific date ranges
- **Real-time Updates**: Immediate reflection of customer meal selections

### Holiday Management
- **Public Holiday Calendar**: Configure Hong Kong public holidays
- **Delivery Rescheduling**: Automatic delivery adjustment for holidays
- **Custom Holiday Names**: Support for company-specific holidays

### Reporting & Analytics
- **Delivery Reports**: Detailed customer and meal quantity reports
- **Excel Export**: Comprehensive data export for logistics planning
- **Customer Tracking**: Order history and booking status monitoring

## Technical Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- MySQL 5.6+

## Dependencies

- **PhpSpreadsheet**: Excel file generation and manipulation
- **ICS Parser**: Calendar file processing for holidays
- **ZipStream**: Large file export handling

## Support & Customization

This plugin was custom-developed for Ms. Lo Soups website requirements and includes specialized features for Hong Kong-based catering operations. The codebase is designed for extensibility and can be customized for different regional requirements or business models.

## Author

Developed by Louis Au for catering business operations management.

## License

GPL-2.0+ - See LICENSE file for details.