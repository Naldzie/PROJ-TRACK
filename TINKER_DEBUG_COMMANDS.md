# Category System - Tinker Debugging Commands

Use these commands in Laravel Tinker to debug the category system. Open Tinker with:

```bash
php artisan tinker
```

---

## 📊 Basic Queries

### Check Category Count
```php
>>> App\Models\Category::count();
# Expected output: 5 or more (4 original + any added)
```

### List All Categories
```php
>>> App\Models\Category::all();
# Expected: Array of categories with id, name, color, description
```

### List with Project Counts
```php
>>> App\Models\Category::withCount('projects')->get();
# Expected: Each category shows projects_count
```

### Check Specific Order (as used in modal)
```php
>>> App\Models\Category::withCount('projects')->orderBy('name')->get();
# Expected: Ordered by name (Maintenance, New Construction, Repair, Renovation)
```

---

## 🔍 Verification Queries

### Verify Each Original Category Exists
```php
>>> App\Models\Category::where('name', 'Maintenance')->first();
>>> App\Models\Category::where('name', 'New Construction')->first();
>>> App\Models\Category::where('name', 'Renovation')->first();
>>> App\Models\Category::where('name', 'Repair')->first();
# Each should return the category object with all fields
```

### Check Recently Added Categories
```php
>>> App\Models\Category::latest()->first();
# Shows most recently added category

>>> App\Models\Category::latest('created_at')->limit(3)->get();
# Shows 3 most recent categories
```

### Check for Duplicate Names
```php
>>> App\Models\Category::groupBy('name')->havingRaw('count(*) > 1')->get();
# If empty, no duplicates. If has results, duplicates exist.
```

---

## 📝 Detailed Field Inspection

### Check All Fields of One Category
```php
>>> $cat = App\Models\Category::first();
>>> $cat->toArray();
# Shows all fields including: id, name, description, color, is_active, created_at, updated_at, deleted_at
```

### Check JSON Response Format (as returned by API)
```php
>>> $categories = App\Models\Category::withCount('projects')->orderBy('name')->get();
>>> json_encode($categories);
# Shows exactly what the API returns (what the modal receives)
```

### Pretty Print Categories
```php
>>> App\Models\Category::all()->each(function ($cat) { echo "$cat->id: $cat->name ($cat->color) - $cat->projects_count projects\n"; });
# Output format: 1: New Construction (#667eea) - 3 projects
```

---

## 🔧 Fix Queries (If Needed)

### Activate All Categories
```php
>>> App\Models\Category::where('is_active', false)->update(['is_active' => true]);
# Activates all inactive categories (if that was the issue)
```

### Ensure All Have Valid Colors
```php
>>> App\Models\Category::whereNull('color')->update(['color' => '#667eea']);
# Sets default color for any with null color
```

### Reset Category Names to Original
```php
>>> App\Models\Category::where('name', 'New Construction')->first()->update(['name' => 'New Construction']);
>>> App\Models\Category::where('name', 'Renovation')->first()->update(['name' => 'Renovation']);
>>> App\Models\Category::where('name', 'Repair')->first()->update(['name' => 'Repair']);
>>> App\Models\Category::where('name', 'Maintenance')->first()->update(['name' => 'Maintenance']);
```

---

## 🐛 If Categories Missing

### Find All Non-Deleted Categories
```php
>>> App\Models\Category::withoutTrashed()->get();
# Shows only non-soft-deleted categories
```

### Find Soft-Deleted Categories
```php
>>> App\Models\Category::onlyTrashed()->get();
# Shows categories that were soft-deleted
```

### Restore Soft-Deleted Categories
```php
>>> App\Models\Category::onlyTrashed()->restore();
# Restores all soft-deleted categories
```

---

## 🔌 API Response Simulation

### Simulate What Modal Receives (with mapping)
```php
>>> App\Models\Category::withCount('projects')
    ->orderBy('name')
    ->get()
    ->map(function ($category) {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description ?? '',
            'color' => $category->color ?? '#667eea',
            'is_active' => $category->is_active,
            'projects_count' => $category->projects_count ?? 0,
        ];
    });
# Shows EXACTLY what the API returns to the modal
```

---

## 🚀 Quick Health Check Script

Run all these in order to verify everything is working:

```php
# 1. Check count
>>> App\Models\Category::count();

# 2. List all
>>> App\Models\Category::all()->pluck('name');

# 3. Check with counts
>>> App\Models\Category::withCount('projects')->orderBy('name')->pluck('name', 'projects_count');

# 4. Simulate API response
>>> echo json_encode(App\Models\Category::withCount('projects')->orderBy('name')->get()->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color, 'projects_count' => $c->projects_count]));

# 5. Check for soft deletes
>>> App\Models\Category::onlyTrashed()->count();
```

Expected output:
```
# 1. Should be >= 5
# 2. Should list: Maintenance, New Construction, Repair, Renovation, plus any added
# 3. Should show counts for each
# 4. Should show JSON array with all categories
# 5. Should be 0 (no soft-deleted categories)
```

---

## 💡 Interpreting Results

| Query | Good Result | Bad Result |
|-------|------------|-----------|
| `count()` | 5+ | Less than 4 |
| `all()->pluck('name')` | Contains all 4 originals | Missing any original |
| `withCount` | All have projects_count | Any null or missing |
| JSON response | Complete array | Only 1 item |
| `onlyTrashed()` | Empty or 0 | Has items |

---

## 🔗 Type Checking

```php
# Verify is_active is boolean
>>> App\Models\Category::first()->is_active;
# Should be: true or false (not 0 or 1)

# Verify projects_count is integer
>>> App\Models\Category::withCount('projects')->first()->projects_count;
# Should be: int (0, 1, 2, etc.)

# Verify color is valid hex
>>> App\Models\Category::first()->color;
# Should be: string like "#667eea"
```

---

## 📤 Export for Sharing

To export all categories as JSON for sharing:

```php
>>> file_put_contents('/tmp/categories.json', json_encode(App\Models\Category::withCount('projects')->get(), JSON_PRETTY_PRINT));
>>> echo "Exported to /tmp/categories.json";
```

Then share the `categories.json` file for debugging.

---

## Exit Tinker

```php
>>> exit
```

Or press Ctrl+C

