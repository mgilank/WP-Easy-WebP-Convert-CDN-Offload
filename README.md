# WP Easy WebP - WordPress WebP Converter & R2 CDN Offload

Convert your WordPress images to WebP format automatically and optionally offload them to Cloudflare R2 for blazing-fast global delivery.

## Features

### 🎨 Automatic WebP Conversion
- **Automatic conversion** on upload - new images are converted to WebP automatically
- **Multiple conversion methods:**
  - Local PHP (GD Library)
  - Local PHP (ImageMagick)
  - External API (for advanced processing)
- **All image sizes** - converts thumbnails, medium, large, and full-size images
- **Preserves originals** - option to keep or delete original files

### ☁️ Cloudflare R2 CDN Offload
- **Automatic R2 upload** - WebP files uploaded to R2 on creation
- **Global CDN delivery** - serve images from Cloudflare's global network
- **Bandwidth savings** - reduce load on your origin server
- **Custom domain support** - use your own CDN domain
- **Size variant support** - all image sizes uploaded to R2

### 🔧 Bulk Processing Tools
- **Bulk Convert** - convert all existing images to WebP
- **Convert Post URLs** - update post content to use WebP URLs
- **Sync to R2** - upload missing WebP files to R2
- **Batch processing** - handles large sites without timeouts

### 🎯 Smart URL Replacement
- **Dynamic URL replacement** - automatically serves WebP from R2 when enabled
- **Fallback support** - serves local WebP if R2 is disabled
- **Size variant handling** - correctly handles all WordPress image sizes
- **Post content filtering** - updates image URLs in post content

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- One of the following for local conversion:
  - GD Library with WebP support
  - ImageMagick extension
- (Optional) Cloudflare R2 account for CDN offload

## Installation

1. Download the plugin
2. Upload to `/wp-content/plugins/WP-Easy-WebP/`
3. Activate through the WordPress 'Plugins' menu
4. Go to Settings → Easy WebP Converter to configure

## Configuration

### Basic Setup

1. **Navigate to Settings → Easy WebP Converter**
2. **Choose conversion method:**
   - Leave "Use External API" unchecked for local conversion (GD/ImageMagick)
   - Check "Use External API" if you have an external conversion service
3. **Set "Keep Original Images":**
   - Checked: Keep original JPG/PNG files
   - Unchecked: Delete originals after conversion (saves disk space)

### R2 CDN Offload Setup

1. **Enable R2 Offload** - Check the "Enable R2 Offload" checkbox
2. **Enter R2 Credentials:**
   - **Account ID**: Your Cloudflare account ID
   - **Access Key ID**: R2 API access key
   - **Secret Access Key**: R2 API secret key
   - **Bucket Name**: Your R2 bucket name
   - **Public Domain**: Your R2 public domain (e.g., `https://cdn.example.com`)

#### Getting R2 Credentials

1. Log in to Cloudflare Dashboard
2. Go to R2 → Overview
3. Create a bucket (if you haven't already)
4. Go to R2 → Manage R2 API Tokens
5. Create API token with read/write permissions
6. Set up a custom domain or use the R2.dev subdomain

### External API Setup (Optional)

If you want to use an external conversion service:

1. Check "Use External API"
2. Enter your API URL (e.g., `http://your-server:3000/convert`)
3. Enter API key if required

## Usage

### Automatic Conversion (New Uploads)

Simply upload images to WordPress Media Library - they'll be automatically converted to WebP!

**What happens:**
1. You upload a JPG or PNG
2. WordPress generates size variants (thumbnail, medium, large)
3. Plugin converts all sizes to WebP
4. If R2 is enabled, uploads all WebP files to R2
5. Done! Images are ready to use

### Bulk Convert Existing Images

For images uploaded before installing the plugin:

1. Go to **Settings → Easy WebP Converter → Bulk Convert** tab
2. Click **"Start Bulk Conversion"**
3. Wait for processing to complete
4. All existing images will be converted to WebP

### Update Post Content URLs

To update your post content to use WebP URLs:

1. Go to **Bulk Convert** tab
2. Scroll to **"Convert Post URLs to WebP"** section
3. **Create a database backup first!**
4. Click **"Convert Post URLs to WebP"**
5. Confirm the warning
6. Wait for processing to complete

**Note:** This permanently modifies post content in the database.

### Sync WebP Files to R2

If you enabled R2 after converting images, or if some uploads failed:

1. Go to **Bulk Convert** tab
2. Scroll to **"Sync WebP Files to R2"** section
3. Click **"Sync WebP to R2"**
4. Wait for sync to complete

This will scan your uploads directory and upload any WebP files not yet in R2.

## How It Works

### Conversion Process

```
Upload Image (JPG/PNG)
    ↓
WordPress generates sizes
    ↓
Plugin converts to WebP
    ├─ Main image → image.webp
    ├─ Thumbnail → image-150x150.webp
    ├─ Medium → image-300x200.webp
    └─ Large → image-768x1024.webp
    ↓
Upload to R2 (if enabled)
    ↓
Update database
```

### URL Replacement

When R2 is enabled:
- `https://yoursite.com/uploads/image.jpg` → `https://cdn.example.com/uploads/image.webp`

When R2 is disabled:
- `https://yoursite.com/uploads/image.jpg` → `https://yoursite.com/uploads/image.webp`

## File Structure

```
WP-Easy-WebP/
├── cf-webp-converter.php       # Main plugin file
├── admin/
│   └── class-cf-webp-admin.php # Admin interface
├── includes/
│   ├── class-cf-webp-activator.php      # Plugin activation
│   ├── class-cf-webp-processor.php      # Image processing
│   ├── class-cf-webp-worker-client.php  # Conversion methods
│   ├── class-cf-webp-r2-client.php      # R2 upload client
│   └── class-cf-webp-bulk-handler.php   # Bulk operations
└── README.md
```

## Database

The plugin creates one table: `wp_cf_webp_status`

**Columns:**
- `id` - Primary key
- `attachment_id` - WordPress attachment ID
- `status` - Conversion status (converted/uploaded/error)
- `r2_url` - R2 CDN URL (if uploaded)
- `local_webp_path` - Local WebP file path
- `error_message` - Error details (if failed)
- `updated_at` - Last update timestamp

## Troubleshooting

### Images not converting automatically

**Check:**
1. Is GD or ImageMagick installed? (See System Status in settings)
2. Check `/wp-content/debug.log` for errors
3. Enable WordPress debug mode in `wp-config.php`

**Solution:**
- Install GD or ImageMagick PHP extension
- Or enable External API with a conversion service

### R2 uploads failing

**Check:**
1. Are R2 credentials correct?
2. Does the bucket exist?
3. Does the API token have write permissions?
4. Check debug log for specific errors

**Solution:**
- Verify credentials in Cloudflare dashboard
- Ensure bucket is in the same account
- Regenerate API token with correct permissions

### Post content still shows old URLs

**Solution:**
1. Run "Convert Post URLs to WebP" tool
2. Clear any caching plugins
3. Clear browser cache

### Some images missing from R2

**Solution:**
- Run "Sync WebP to R2" tool to upload missing files

## Performance

### Conversion Speed
- **Local (GD)**: ~0.5-1 second per image
- **Local (ImageMagick)**: ~0.3-0.8 seconds per image
- **External API**: Depends on API server

### Bulk Processing
- **Batch size**: 5 images per request (configurable)
- **Timeout protection**: Processes in batches to avoid timeouts
- **Large sites**: Can handle thousands of images

### R2 Upload Speed
- **Single file**: ~0.2-0.5 seconds
- **With variants**: ~1-2 seconds per image (4-5 files)

## FAQ

**Q: Will this work with existing images?**  
A: Yes! Use the "Bulk Convert" tool to convert existing images.

**Q: Can I disable R2 and use local WebP only?**  
A: Yes! Just uncheck "Enable R2 Offload" in settings.

**Q: What happens to original images?**  
A: You can choose to keep or delete them via "Keep Original Images" setting.

**Q: Does this work with image optimization plugins?**  
A: Yes, but run this plugin after other optimization plugins.

**Q: Can I use my own CDN domain with R2?**  
A: Yes! Set up a custom domain in Cloudflare and enter it in "Public Domain" setting.

**Q: Will this break my existing posts?**  
A: No. The plugin uses filters to replace URLs on-the-fly. Original content is unchanged unless you run "Convert Post URLs to WebP".

## Changelog

### Version 1.0.0
- Initial release
- Automatic WebP conversion on upload
- Local conversion (GD/ImageMagick)
- External API support
- Cloudflare R2 CDN offload
- Bulk conversion tool
- Post URL converter
- R2 sync tool
- Image size variant support
- Dynamic URL replacement

## Support

For issues, questions, or feature requests, please check:
- WordPress debug log: `/wp-content/debug.log`
- Plugin settings: System Status section
- Enable debug mode in `wp-config.php` for detailed logging

## License

GPL v2 or later

## Credits

Developed with ❤️ for the WordPress community
