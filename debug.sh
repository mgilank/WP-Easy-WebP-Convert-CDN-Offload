#!/bin/bash

# Debug script for CF WebP Converter Plugin
# This script helps diagnose issues with the WordPress plugin

echo "=== CF WebP Converter Plugin Debug ==="
echo ""

# Check if plugin directory exists
PLUGIN_DIR="/Users/gilang/Local Sites/wp-webp/app/public/wp-content/plugins/cf-webp-converter"
if [ -d "$PLUGIN_DIR" ]; then
    echo "✓ Plugin directory exists"
else
    echo "✗ Plugin directory NOT found"
    exit 1
fi

# Check main plugin file
if [ -f "$PLUGIN_DIR/cf-webp-converter.php" ]; then
    echo "✓ Main plugin file exists"
else
    echo "✗ Main plugin file NOT found"
    exit 1
fi

# Check database table
echo ""
echo "Checking database table..."
echo "Run this SQL query in your database:"
echo "SHOW TABLES LIKE 'wp_cf_webp_status';"
echo ""

# Check PHP error log
echo "Checking for PHP errors..."
ERROR_LOG="/Users/gilang/Local Sites/wp-webp/app/public/wp-content/debug.log"
if [ -f "$ERROR_LOG" ]; then
    echo "✓ Debug log exists at: $ERROR_LOG"
    echo ""
    echo "Recent CF WebP errors:"
    grep "CF WebP" "$ERROR_LOG" | tail -20
else
    echo "✗ Debug log not found. Enable WP_DEBUG in wp-config.php"
    echo ""
    echo "Add these lines to wp-config.php:"
    echo "define( 'WP_DEBUG', true );"
    echo "define( 'WP_DEBUG_LOG', true );"
    echo "define( 'WP_DEBUG_DISPLAY', false );"
fi

echo ""
echo "=== Next Steps ==="
echo "1. Make sure the plugin is activated in WordPress Admin"
echo "2. Configure the Worker URL in Settings > Cloudflare WebP"
echo "3. Upload a test image and check the debug log"
echo "4. Check the wp_cf_webp_status table for conversion status"
