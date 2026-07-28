#!/bin/bash

echo "========================================"
echo "Category System Verification"
echo "========================================"
echo ""

echo "✓ Checking Laravel version..."
php artisan --version
echo ""

echo "✓ Checking migrations status..."
php artisan migrate:status | grep -E "migrate_category|categories"
echo ""

echo "✓ Checking for CategorySeeder..."
if [ -f "database/seeders/CategorySeeder.php" ]; then
    echo "✓ CategorySeeder found"
else
    echo "✗ CategorySeeder NOT found"
fi
echo ""

echo "✓ Checking for category migration..."
if [ -f "database/migrations/2026_05_14_create_categories_table.php" ]; then
    echo "✓ Categories table migration found"
else
    echo "✗ Categories table migration NOT found"
fi
echo ""

echo "✓ Checking for category string migration..."
if [ -f "database/migrations/2026_05_14_migrate_category_strings_to_ids.php" ]; then
    echo "✓ Category strings migration found"
else
    echo "✗ Category strings migration NOT found"
fi
echo ""

echo "========================================"
echo "Setup complete!"
echo "========================================"
echo ""
echo "Next steps:"
echo "1. Visit /admin/projects to create a new project"
echo "2. You should see dynamic categories in the dropdown"
echo "3. Click the + button to add new categories (admin only)"
echo ""
