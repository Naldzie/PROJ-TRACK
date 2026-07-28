# Dynamic Category System Implementation

## Overview
This implementation adds a dynamic category management system to the PMU System, replacing static category dropdowns with database-driven categories that only admins can create, edit, and delete.

---

## 📋 What Was Implemented

### 1. **Database Migration**
- **File**: `database/migrations/2026_05_14_create_categories_table.php`
- **Features**:
  - Creates `categories` table with columns: `id`, `name`, `description`, `color`, `is_active`, timestamps, soft deletes
  - Adds `category_id` foreign key to `projects` table
  - Maintains backward compatibility with existing `category` field

### 2. **Category Model**
- **File**: `app/Models/Category.php`
- **Features**:
  - Soft delete support
  - Relationship with projects (`hasMany`)
  - Scope for active categories
  - Fillable: `name`, `description`, `color`, `is_active`

### 3. **Project Model Updates**
- **File**: `app/Models/Project.php`
- **Changes**:
  - Added `category_id` to fillable array
  - Added `category()` relationship method
  - Maintains backward compatibility with old `category` field

### 4. **CategoryController**
- **File**: `app/Http/Controllers/Admin/CategoryController.php`
- **Methods**:
  - `getCategories()`: Returns all active categories (for dropdowns)
  - `getAllForManagement()`: Returns all categories with project counts (admin only)
  - `store()`: Create new category (admin only)
  - `update()`: Update existing category (admin only)
  - `destroy()`: Delete category (admin only, with validation)
- **Authorization**: All methods check for `admin` role

### 5. **Category Policy**
- **File**: `app/Policies/CategoryPolicy.php`
- **Capabilities**:
  - `viewAny()`: All users can view categories
  - `create()`: Admin only
  - `update()`: Admin only
  - `delete()`: Admin only

### 6. **AppServiceProvider Updates**
- **File**: `app/Providers/AppServiceProvider.php`
- **Changes**: Registered CategoryPolicy for authorization checks

### 7. **Routes**
- **File**: `routes/web.php`
- **New Routes** (in admin prefix):
  - `GET /admin/categories` → Get all active categories
  - `GET /admin/categories/all` → Get all categories with counts
  - `POST /admin/categories` → Create category
  - `PUT /admin/categories/{category}` → Update category
  - `DELETE /admin/categories/{category}` → Delete category

### 8. **Category Management Modal**
- **File**: `resources/views/admin/_manage_categories_modal.blade.php`
- **Features**:
  - Add new category form (name, color, description)
  - List all categories with project counts
  - Edit button for each category
  - Delete button with confirmation
  - Color preview
  - Error handling and success notifications

### 9. **Updated Create Project Modal**
- **File**: `resources/views/admin/_create_project_modal.blade.php`
- **Changes**:
  - Category field now uses `category_id` (foreign key) instead of `category`
  - Added "Add" button next to category dropdown (visible to admins only using `@can`)
  - Button opens category management modal
  - Dynamic category loading on page load
  - Added `loadProjectCategories()` JavaScript function

### 10. **Updated Views to Include Category Modal**
- **Files Updated**:
  - `resources/views/admin/projects.blade.php`
  - `resources/views/projects/index.blade.php`
  - `resources/views/admin/projects/project_detail.blade.php`
  - `resources/views/projects/project_detail/project_detail.blade.php`
- **Changes**: Added `@include('admin._manage_categories_modal')` with proper authorization checks

### 11. **ProjectController Updates**
- **File**: `app/Http/Controllers/ProjectController.php`
- **Changes**:
  - Updated `store()` method validation to accept `category_id`
  - Updated `update()` method validation to accept `category_id`
  - Maintains backward compatibility with old `category` field

---

## 🔐 Authorization & Security

### Permission Model
- **Staff/Contractor**: Can see category dropdown but NOT the "Add" button
- **Admin**: Can see "Add" button to create/edit/delete categories
- **Implementation**: Uses Laravel's `@can` directive and Policy-based authorization

### Protected Operations
- Category creation: Admin only
- Category editing: Admin only
- Category deletion: Admin only + validation (prevents deletion if category is in use)

---

## 🚀 How to Use

### For Admins:
1. **View Categories**: Open create project modal, see dynamic category dropdown
2. **Add Category**: Click the "+" button next to category dropdown
3. **Manage Categories**: In the modal that opens:
   - Add new category with name, color, and optional description
   - Edit existing categories (click pencil icon)
   - Delete unused categories (click trash icon)
   - See project count for each category

### For Staff/Contractors:
1. **View Categories**: See dynamic category dropdown
2. **Select Category**: Choose from available categories
3. **No Edit Access**: Cannot add, edit, or delete categories (no button visible)

---

## 📊 Database Changes

### New Table: `categories`
```sql
CREATE TABLE categories (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#667eea',
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP NULL
);
```

### Modified Table: `projects`
- Added column: `category_id` (BIGINT, nullable, foreign key to categories)
- Existing `category` field maintained for backward compatibility

---

## 🔄 API Endpoints

### Get All Active Categories
```
GET /admin/categories
Response: [
    {
        "id": 1,
        "name": "Construction",
        "color": "#667eea",
        "is_active": true
    },
    ...
]
```

### Get All Categories with Counts
```
GET /admin/categories/all
Response: [
    {
        "id": 1,
        "name": "Construction",
        "color": "#667eea",
        "is_active": true,
        "projects_count": 5
    },
    ...
]
```

### Create Category
```
POST /admin/categories
Body: {
    "name": "Renovation",
    "description": "Building renovation projects",
    "color": "#10b981"
}
Response: {
    "success": true,
    "message": "Category created successfully",
    "category": { ... }
}
```

### Update Category
```
PUT /admin/categories/{id}
Body: {
    "name": "Updated Name",
    "color": "#f59e0b"
}
```

### Delete Category
```
DELETE /admin/categories/{id}
Response: {
    "success": true,
    "message": "Category deleted successfully"
}
```

---

## ✅ Next Steps / Future Improvements

1. **Run Migration**:
   ```bash
   php artisan migrate
   ```

2. **Create Seeder** (optional):
   - Create `database/seeders/CategorySeeder.php` with initial categories

3. **Add Bootstrap Colors**:
   - Consider adding a color picker utility for better UX

4. **Add Category Filtering**:
   - Filter projects by category in the project list view

5. **Add Category Statistics**:
   - Dashboard widget showing category distribution

---

## 🐛 Troubleshooting

### Issue: Add button not visible
- **Solution**: Check that you're logged in as an admin (user role must be 'admin')
- **Check**: Verify in Users table that your role is 'admin'

### Issue: Category not saving
- **Solution**: Check browser console for errors
- **Check**: Verify CSRF token is present in page

### Issue: Can't delete category
- **Solution**: Category might be in use by existing projects
- **Action**: Update projects to use different category first

---

## 📝 File Summary

| File | Type | Purpose |
|------|------|---------|
| `database/migrations/2026_05_14_create_categories_table.php` | Migration | Create categories table |
| `app/Models/Category.php` | Model | Category model with relationships |
| `app/Http/Controllers/Admin/CategoryController.php` | Controller | CRUD operations |
| `app/Policies/CategoryPolicy.php` | Policy | Authorization rules |
| `app/Providers/AppServiceProvider.php` | Provider | Policy registration |
| `resources/views/admin/_manage_categories_modal.blade.php` | View | Category management UI |
| `resources/views/admin/_create_project_modal.blade.php` | View | Updated with dynamic categories |
| `routes/web.php` | Routes | API endpoints |

---

## 🎯 Key Features

✅ Dynamic category management (no hardcoded values)  
✅ Admin-only access control via policies  
✅ Category colors for visual distinction  
✅ Project count tracking per category  
✅ Soft delete support  
✅ Beautiful modal UI with Bootstrap 5  
✅ Real-time category loading  
✅ Prevent deletion of in-use categories  
✅ Backward compatible with existing category field  
✅ Error handling and user feedback

---

## 📞 Support

For issues or questions:
1. Check the troubleshooting section above
2. Review the code comments in controller and modal
3. Check browser console for JavaScript errors
4. Verify database migrations have run successfully
