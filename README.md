Smart Image Optimizer — OnePack

Generates WebP (and AVIF only if your server supports it) for the original image and all WordPress sizes. Serves a <picture> with WebP while keeping your original <img> as fallback. Includes a per-image “Regenerate WebP”, a bulk converter, and a few safe-upload knobs.

Why

Smaller images → faster pages.

No theme edits. Works with the core Image block.

Keeps JPG/PNG as a fallback, so nothing breaks.

Requirements

WordPress 5.8+ (tested up to 6.6)

PHP 7.4+

WebP support (imagewebp() available on most hosts)

AVIF is optional (imageavif())

Install

Download the ZIP for smart-image-optimizer-onepack/.

WP Admin → Plugins → Add New → Upload Plugin → upload → Activate.

Check Settings → Smart Image Optimizer:

WebP encoder: Yes

AVIF encoder: Yes/No (OK either way)

Use

New uploads: WebP (and AVIF if available) are generated for the original and all sizes. Insert the image with the core Image block and view the page.

Existing images:

Per-image: Media → Library → open an image → Regenerate WebP (all sizes).

Bulk: Tools → SmartIO Bulk WebP (All Sizes) → Run Batch until it shows 0 converted.
