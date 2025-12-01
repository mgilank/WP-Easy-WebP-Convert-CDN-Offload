# Quick Setup Guide

## Step 1: Enable WordPress Debug Mode

Edit `wp-config.php` and add these lines BEFORE `/* That's all, stop editing! */`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

## Step 2: Activate the Plugin

1. Go to WordPress Admin: `http://your-site.local/wp-admin`
2. Navigate to **Plugins > Installed Plugins**
3. Find "Cloudflare WebP Converter & R2 Offload"
4. Click **Activate**

## Step 3: Configure Settings

1. Go to **Settings > Cloudflare WebP**
2. Enter Worker URL: `https://cf-webp-converter.blogthisite.workers.dev`
3. Leave "Keep Original Images" **checked** for testing
4. Click **Save Changes**

## Step 4: Test Upload

1. Go to **Media > Add New**
2. Upload a JPG or PNG image
3. Check the debug log: `wp-content/debug.log`
4. Look for lines starting with "CF WebP:"

## Step 5: Check Results

### Check the database:
```sql
SELECT * FROM wp_cf_webp_status ORDER BY id DESC LIMIT 10;
```

### Check the filesystem:
Look in `wp-content/uploads/YYYY/MM/` for `.webp` files

### Common Issues:

**Plugin not activating:**
- Check file permissions
- Check PHP error log

**No conversion happening:**
- Check if the hook is firing (look for "CF WebP: Processing" in debug.log)
- Verify Worker URL is correct
- Test Worker directly with curl

**Worker errors:**
- Check Worker logs in Cloudflare dashboard
- Test Worker with: `curl -X POST -H "Content-Type: image/jpeg" --data-binary "@test.jpg" https://cf-webp-converter.blogthisite.workers.dev -o test.webp`
