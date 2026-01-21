# Catering Booking and Scheduling Plugin - Developer Guide

## System Overview
This is a WooCommerce-integrated WordPress plugin for managing catering meal subscription plans. Customers purchase multi-day meal plans, then use an interactive calendar to select daily meals within their booking period. Built for Hong Kong market (繁體中文 primary language, HKT timezone).

## Architecture

### Core Data Model
The plugin extends WooCommerce with custom tables (prefix: `wp_catering_*`):
- **`catering_booking`**: Created from order items; tracks user subscriptions, plan days, expiry, category quotas, and health status
- **`catering_choice`**: User meal selections per date; stores serialized choice arrays, addresses, and delivery preferences
- **`catering_meal`**: Meal items with SKU, cost, photos, descriptions
- **`catering_schedule`**: Product-specific meal availability by date; links meals to products with tags and types (產前/產後)
- **`catering_terms`**: Categories and allergen tags with colors and ordering
- **`catering_holiday`**: Hong Kong public holidays; affects delivery scheduling
- **`catering_log`**: Audit trail for meal choice changes with user role tracking
- **`catering_daily_meal_count`**: Historical meal counts (generated via WP-Cron)

### Entry Points
1. **WooCommerce Product Type**: `WC_Product_Catering_Plan` extends `WC_Product_Variable` (`include/wc-product-catering-plan.php`)
2. **Order Completion Hook**: `woocommerce_order_status_completed` creates bookings via `create_booking()` (`include/order-function.php`)
3. **My Account Page**: Custom "Catering Bookings" tab renders `template/catering-booking.php`
4. **Admin Menu**: "Meal schedule" menu at position 2 with subpages (`include/interface.php`)

### Key Classes
- **`Booking`** (`include/class-booking.php`): ORM-style object for booking management
  - Properties: `id`, `user_id`, `order_item_id`, `plan_days`, `expiry`, `cat_qty`, `status`, `health_status`, `type`
  - Key methods: `is_date_expired($date)`, `checkHealthDate($date)`, `get_linked_product()`, `get_meal_choices()`
  - Always load via `new Booking($id)` or `get_booking_by_order_item_id()`

## Critical Patterns

### Health Status & Meal Types
Bookings support three types (`booking.type`):
- `prenatal` (產前): Before due date meals
- `postpartum` (產後): After due date meals  
- `hybird`: Switches meal types based on `health_status['due_date']` vs selection date

**Tag System**: Meal tags support type-specific variants (see `HYBRID_TAG_FEATURE.md`):
```php
// Storage format in catering_schedule.tag column:
'{"產前":"孕婦湯","產後":"坐月湯"}'  // JSON for type-specific
'今日主食'                           // Plain string for universal tags

// Functions in include/ajax.php:
process_hybrid_tag($tag, $types)      // CSV → JSON storage
get_display_tag($stored_tag, $booking, $date)  // Show correct tag to user
format_tag_for_admin($stored_tag)     // Display in admin UI
format_tag_for_export($stored_tag)    // JSON → CSV for export
```

### CSV Import Workflow
Admins bulk-upload schedules via product edit page (`template/wc-product-catering-options.php`):
1. Frontend validates CSV structure and meal SKUs via AJAX
2. Backend processes rows via `catering_ajax_process_catering_schedule_row` (single-row AJAX batching)
3. Schedule data inserted into `catering_schedule` with `process_hybrid_tag()` for tag conversion
4. Expected CSV columns: `ID,SKU,Meal_title,Category,Type,Date,Tag`

### AJAX Architecture
All customer interactions use AJAX (`include/ajax.php` - 2600+ lines):
- **Naming convention**: `catering_ajax_*` functions hooked to `wp_ajax_*` actions
- **Authentication**: Use `wp_ajax_nopriv_*` for logged-out users (rare in this plugin)
- **Critical endpoints**:
  - `save_user_choice`: Validates booking limits, date restrictions, holiday conflicts; logs changes via `log_meal_choice_change()`
  - `get_day_schedule`: Returns available meals filtered by product, date, and booking type
  - `get_meal_schedule_week`: Weekly calendar data for admin dashboard
  - `process_catering_schedule_row`: Single CSV row import (called repeatedly by frontend)

### WP-Cron Task
`save_daily_catering_meal_count` runs daily (registered in main plugin file):
- Aggregates previous day's meal selections from `catering_choice`
- Upserts counts into `catering_daily_meal_count` (avoids duplicates)
- Uses HKT timezone: `new DateTime('now', new DateTimeZone('Asia/Hong_Kong'))`

### Serialization Convention
Use WordPress helpers for all array storage:
```php
maybe_serialize($data)    // Before INSERT/UPDATE
maybe_unserialize($data)  // After SELECT
```
Applied to: `booking.cat_qty`, `booking.health_status`, `choice.choice`, `choice.address`

### Timezone Handling
**Always use Hong Kong Time** for date calculations:
```php
$dt = new DateTime('now', new DateTimeZone('Asia/Hong_Kong'));
$hkt_date = $dt->format('Y-m-d');
```
Frontend JS must also respect HKT when displaying dates to users.

## File Organization

### Core Logic (`include/`)
- **`init.php`**: Plugin bootstrap; table creation, capability setup, asset enqueuing
- **`ajax.php`**: All AJAX handlers (46+ endpoints); hybrid tag functions at top
- **`core-functions.php`**: `create_booking()`, `is_min_day_requirement_met()`, `log_meal_choice_change()`
- **`order-function.php`**: WooCommerce hooks (checkout allergy section, order meta, booking creation on order completion)
- **`my-account.php`**: Adds "Catering Bookings" tab to WC My Account
- **`email.php`**: Booking confirmation/update email templates

### Admin Templates (`template/`)
- **`meal-schedule.php`**: Main admin dashboard; weekly calendar view with meal badges
- **`meal-form.php`**: Add/edit meal items (SKU, categories, photos)
- **`meal-settings.php`**: Global settings (min order days, category colors)
- **`holiday-setting.php`**: HK public holiday calendar with iCal import
- **`wc-product-catering-options.php`**: Product edit page for CSV import, category selection, plan configuration

### Customer Templates (`template/`)
- **`catering-booking.php`**: My Account booking list with status badges
- **`catering-booking-popup.php`**: Interactive meal selection modal (weekly calendar, drag-drop)
- **`wc-checkout-allergy.php`**: Checkout page allergen selection checkboxes

## Development Workflows

### Adding New AJAX Endpoints
1. Add function in `include/ajax.php` with signature: `function catering_ajax_YOUR_NAME() { ... }`
2. Hook it: `add_action('wp_ajax_YOUR_NAME', 'catering_ajax_YOUR_NAME');`
3. For logged-out access: `add_action('wp_ajax_nopriv_YOUR_NAME', 'catering_ajax_YOUR_NAME');`
4. Always verify `current_user_can('manage_catering')` for admin endpoints
5. Use `wp_send_json_success()` / `wp_send_json_error()` for responses

### Modifying Booking Logic
- **Never** directly UPDATE `wp_catering_booking`; use `Booking->set($param, $value)` method
- Date validation: Call `Booking->is_date_expired($date)` and `Booking->checkHealthDate($date)` before allowing selections
- Category limits: Check `Booking->cat_qty` (serialized array) against selection counts
- Log all changes: Use `log_meal_choice_change()` with booking_id, date, old/new choices, user_id, action_type

### Testing Booking Flow
1. Create a "Catering plan" product with variations (plan duration)
2. Set category quotas in "Catering option" tab (e.g., 2 soups, 1 main per day)
3. Import meal schedule via CSV on product edit page
4. Purchase as customer → booking auto-created on order completion
5. My Account → Catering Bookings → Open calendar → Select meals (respects quotas, holidays, date restrictions)

### Debugging AJAX
Frontend AJAX URLs configured via `wp_localize_script`:
- Admin: `catering_backend_ajax.ajaxUrl`
- Frontend: `catering_frontend_ajax.ajaxurl`

Check browser console for request/response; backend uses `error_log()` for server-side debugging.

## Conventions

### Text Domain
Always use `'catering-booking-and-scheduling'` for `__()`, `_e()`, `esc_html__()` calls. Translation files in `languages/catering-booking-and-scheduling-zh_HK.{po,mo,json}`.

### Capability Checks
Use `manage_catering` capability (granted to admin/shop_manager via `add_manage_catering_capability()`):
```php
if (!current_user_can('manage_catering')) {
    wp_send_json_error(__('No permission', 'catering-booking-and-scheduling'));
}
```

### Database Queries
- Prefer `$wpdb->prepare()` for all queries with user input
- Use `%s`, `%d` placeholders; never concatenate variables directly
- Apply indexes: Booking queries by `user_id`, choice queries by `booking_id + date`

### Frontend Assets
- jQuery dependencies: Include jQuery UI (datepicker), Select2, jscolor in `lib/`
- Enqueue via `catering_enqueue_plugin_assets()` hooks in `init.php`
- Custom JS: `public/catering.js` (customer), `include/js/frontend-ajax.js`, `include/js/backend-ajax.js`

## Dependencies
- **PhpSpreadsheet**: Excel export for delivery reports (`vendor/phpoffice/phpspreadsheet`)
- **ICS Parser**: Holiday calendar import (`vendor/johngrogg/ics-parser`)
- **WooCommerce 3.0+**: Required for product types, orders, My Account integration
- **PHP 7.4+**: Uses `??` null coalescing, type declarations in newer code

## Common Tasks

### Add New Meal Category
1. Admin → Meal schedule → Meal category → Add new
2. Category stored in `catering_terms` with `type='category'`
3. Drag-drop to reorder (saves `ordering` column via `catering_ajax_update_category_ordering`)

### Handle Holiday Delivery
1. Import HK holiday iCal or manually add dates in "Holiday Setting"
2. Customer selection UI blocks holiday dates via `catering_ajax_get_holiday_data`
3. Delivery report exports skip holiday dates automatically

### Export Delivery Report
1. Admin → Meal schedule → Click date badge → "Export" button
2. Triggers `catering_ajax_get_badge_delivery_info` → generates Excel via PhpSpreadsheet
3. Groups by district (HK-specific: `get_hk_districts()` function)

### Modify Email Templates
Edit `include/email.php` functions:
- `send_catering_booking_email()`: Booking confirmation
- Uses `wp_mail()` with HTML templates; customize subject/body inline
