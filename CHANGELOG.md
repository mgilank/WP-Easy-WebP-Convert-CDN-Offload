# Changelog

All notable changes to this project will be documented in this file.

## [1.1.0] - 2025-12-01

### Added
- **R2 Sync Tool**: Added a new tool in the "Tools" tab to scan the filesystem for existing WebP files and upload them to R2 if they are missing. This is useful for syncing files that were converted before R2 was enabled.
- **Image Size Variants Support**: The plugin now correctly converts and uploads all registered image size variants (thumbnail, medium, large, etc.) to R2, ensuring a fully offloaded media library.
- **Robust Fallback Mechanism**: Implemented a 3-tier fallback system for R2 images. If an R2 image fails to load (CDN down or file missing), it automatically falls back to the local WebP version, and then to the original JPG/PNG. This includes `srcset` handling to prevent responsive images from breaking.
- **Direct WebP Upload Support**: Added logic to handle directly uploaded WebP files. They are now correctly identified, skipped for conversion (since they are already WebP), and uploaded to R2 if enabled.
- **Post URL Conversion Options**: Added a radio button in the "Convert Post URLs" tool to let users choose between using Local WebP URLs or R2 CDN URLs.
- **Preferred URL Type Setting**: Added a new setting in the "Settings" tab to save the user's preference (Local vs R2) for post URL conversions.

### Fixed
- **Responsive Images (`srcset`)**: Fixed an issue where `srcset` attributes in post content were still pointing to local images even when R2 was enabled. Now `srcset` URLs are also replaced with R2 versions.
- **Media Library Previews**: Fixed an issue where R2 URLs were being used in the WordPress Admin/Media Library, causing broken previews. Now local URLs are always used in the admin area.
- **Original File Deletion**: Fixed a critical issue where original files were being deleted when "Keep Original" was unchecked, breaking the Media Library. Original files are now always preserved to ensure WordPress compatibility.
- **Fallback URL Construction**: Fixed the fallback logic to correctly construct local URLs when an R2 URL fails, ensuring the fallback image actually loads.
- **WebP Detection**: Fixed logic to properly detect and handle existing WebP files during bulk processing and upload.

### Changed
- **Plugin Name**: Updated plugin name to "WP Easy WebP" (formerly "Easy WebP Converter & CDN Offload").
- **UI Improvements**: Renamed "Bulk Convert" tab to "Tools" to better reflect the expanded functionality.
- **Refactoring**: Improved code structure for URL replacement and image processing.

## [1.0.0] - 2025-11-20
### Initial Release
- Basic WebP conversion using GD or ImageMagick.
- External API support for better compression.
- Basic R2 Cloudflare offload support.
- Bulk conversion tool.
