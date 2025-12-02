# Quick Setup Guide

Use this guide for a fresh install on a live site.

## 1) Install & Activate
1. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**.
2. Upload the plugin ZIP, install, and click **Activate**.

## 2) Configure WebP Conversion
1. Go to **Settings → Easy WebP Converter**.
2. Choose your conversion method:
   - Leave **Use External API** unchecked to use PHP (GD/ImageMagick).
   - Enable **Use External API** if you have your own conversion service.
3. Decide whether to keep the original JPG/PNG files.
4. Save changes.

## 3) (Optional) Enable CDN/R2 Offload
1. Enable **R2 Offload** if you want CDN delivery.
2. Enter your R2 details:
   - **Account ID**
   - **Access Key ID**
   - **Secret Access Key**
   - **Bucket Name**
   - **Public Domain** (e.g., `https://cdn.example.com`)
3. Save changes.

## 4) Verify
1. Upload a JPG/PNG via **Media → Add New**.
2. Confirm `.webp` files appear in `wp-content/uploads/...`.
3. If R2 is enabled, confirm images are reachable via your CDN domain.

For more options, tools, and troubleshooting, see `README.md`.
