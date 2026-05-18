# WebP Image Optimizer
WordPress plugin that optimizes media-library images and can optionally convert supported formats to WebP to reduce disk usage.

## Features
- Supports optimization for `WebP`, `JPEG`, `PNG`, `GIF`, and `AVIF` attachments.
- Optional conversion from `JPEG`, `PNG`, and `AVIF` to `WebP`.
- Batch workflow from the WordPress admin panel (`Media > Image Optimizer`).
- Optional `.bak` backups before replacing files.
- Safe processing flow (temporary file -> size check -> replace).
- Built-in stats: total, optimized, pending, savings, and pending WebP conversions.
- MIME type repair tool for mismatched attachment MIME metadata.
- Automatic skip for animated GIFs to avoid frame loss.

## Requirements
- A WordPress installation with plugin upload/activation access.
- PHP image library support:
  - `GD` **or** `ImageMagick` for optimization.
  - AVIF operations require AVIF support in GD/ImageMagick.
- Write permissions for your uploads directory and attachment files.

## Installation
1. Copy this plugin folder to your WordPress plugins directory:
   - `wp-content/plugins/webp-optimizer`
2. Activate **WebP Image Optimizer** from **Plugins** in wp-admin.
3. Open **Media > Image Optimizer**.

## Usage
1. Go to **Media > Image Optimizer**.
2. Choose image scope:
   - **Pending**: process only images not optimized yet.
   - **All**: force process all supported attachments.
3. Set quality (`50-95`).
4. Choose whether to keep backup files (`.bak`).
5. Select formats to include.
6. Run one of the actions:
   - **Optimize**
   - **Optimize and convert to WebP**
7. Review progress and logs in the same screen.
8. Use **Repair MIME types** if attachments have extension/MIME mismatches.

## Behavior Notes
- Files are only replaced when the optimized output is smaller.
- For lossy formats, the plugin enforces a max output size target of `600 KB` where possible.
- Conversion updates WordPress attachment metadata and MIME type to `image/webp`.
- If backup is enabled, original files are stored as `.bak`.

## Project Structure
- `webp-optimizer.php` — plugin bootstrap and hooks.
- `includes/class-webp-optimizer.php` — optimization/conversion core logic.
- `includes/class-admin.php` — admin page and AJAX handlers.
- `assets/css/admin.css` — admin UI styles.
- `assets/js/admin.js` — admin UI behavior, batch flow, and logs.

## License
GPL v2 or later.
