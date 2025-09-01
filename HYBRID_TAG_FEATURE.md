# Hybrid Tag Feature for Catering Plans

## Overview

This feature enables type-specific tags for hybrid catering plans, allowing different meal tags to be displayed based on customer due dates (產前/產後 status).

## How It Works

### 1. CSV Import Format

**For Simple Tags (Backward Compatible):**
```csv
ID,SKU,Meal_title,Category,Type,Date,Tag
123,ABC001,牛肉飯,主食,產前|產後,2024-01-01,今日主食
```

**For Type-Specific Tags (New Feature):**
```csv
ID,SKU,Meal_title,Category,Type,Date,Tag
123,ABC001,牛肉飯,主食,產前|產後,2024-01-01,產前:產前主食|產後:產後主食
456,DEF002,雞湯,湯類,產前|產後,2024-01-01,產前:孕婦湯|產後:坐月湯
```

### 2. Tag Format Rules

- **Simple tags**: `今日主食` (works for all meal types)
- **Type-specific tags**: `產前:產前主食|產後:產後主食`
- **Partial type-specific**: `產前:孕婦專用` (only for 產前, 產後 uses fallback)

### 3. Display Logic

**For Customers:**
- **Hybrid Plans**: Shows appropriate tag based on due date
  - Before due date: Shows 產前 tag
  - After due date: Shows 產後 tag
- **Non-Hybrid Plans**: Shows first available tag or simple tag

**For Admins:**
- **Schedule View**: Shows formatted tags like "產前:產前主食, 產後:產後主食"
- **CSV Export**: Converts back to import format "產前:產前主食|產後:產後主食"

### 4. Database Storage

Tags are stored in the existing `catering_schedule.tag` column:
- **Simple tags**: Stored as plain text `"今日主食"`
- **Type-specific tags**: Stored as JSON `{"產前":"產前主食","產後":"產後主食"}`

### 5. Backward Compatibility

- All existing simple tags continue to work unchanged
- New type-specific tags only apply when using the new format
- Mixed environments (simple + type-specific) are fully supported

## Usage Examples

### Example 1: Mixed Tag Types
```csv
ID,SKU,Meal_title,Category,Type,Date,Tag
123,ABC001,白飯,主食,產前|產後,2024-01-01,今日主食
456,DEF002,特製湯,湯類,產前|產後,2024-01-01,產前:孕婦湯|產後:坐月湯
```

### Example 2: Customer Experience
- **Customer A** (Due date: 2024-02-01, viewing 2024-01-15):
  - Sees: "今日主食" for 白飯
  - Sees: "孕婦湯" for 特製湯

- **Customer B** (Due date: 2024-01-01, viewing 2024-01-15):
  - Sees: "今日主食" for 白飯  
  - Sees: "坐月湯" for 特製湯

## Technical Implementation

### Key Functions Added:
1. `process_hybrid_tag()` - Converts CSV format to JSON storage
2. `get_display_tag()` - Returns appropriate tag for customer display
3. `format_tag_for_admin()` - Formats tags for admin interface
4. `format_tag_for_export()` - Converts JSON back to CSV format

### Files Modified:
- `include/ajax.php` - Core tag processing logic
- `template/wc-product-catering-options.php` - CSV validation

### Validation Rules:
- Type-specific tags must match meal types
- Format: `type:tag|type:tag`
- Valid types: `產前`, `產後`
- Tags cannot be empty when using type-specific format

## Migration

No database migration required! The feature:
- ✅ Works with existing data immediately
- ✅ Maintains all current functionality
- ✅ Adds new capabilities without breaking changes
