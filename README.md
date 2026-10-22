# WP Image Watermark

Automatically stamps every uploaded image with a watermark — a text label
(site name by default) or your own PNG logo — using PHP GD only, no TTF
files or external services needed.

Watermarking happens right after WordPress generates the attachment sizes,
so it applies exactly once per upload.

## Features

- **Text or PNG watermark** — GD built-in fonts (1–5), or a transparent PNG
  logo auto-scaled to at most N% of the image width.
- **9-grid positioning** — corners + center, with a configurable px margin.
- **Opacity control** — 0–100, mapped to true GD alpha (not dithered).
- **Size threshold** — skip images narrower than X px (thumbnails stay clean).
- **Original or all sizes** — watermark just the full-size image, or every
  registered size too.
- **Optional text pill** — dark background behind the text for readability.
- **`wiw_skip_attachment` filter** — skip watermarks per attachment in code.
- Guarded when GD is missing; skips non-writable files and non-image MIME
  types; works with JPEG, PNG, and WebP.

## Settings (Settings → Watermark)

Type (text/image), text, font size, colors as `r,g,b`, PNG path, max PNG
width %, opacity, position, margin, min width, apply-to scope.

## Install

Upload the folder to `wp-content/plugins/`, activate, and set your text or
PNG path under **Settings → Watermark**. Upload a test image and check the
Media Library. Uninstalling removes the option.

MIT licensed.
