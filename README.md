# Breakdance ↔ Rank Math Bridge

![Breakdance Rank Math Bridge](breakdance-rankmath-bridge.png)

Bridges Breakdance’s rendered frontend output into Rank Math’s content analysis so SEO scores reflect the actual page HTML (including headings and links from templates and dynamic data).

## Why This Exists
Rank Math analyzes the editor content, but Breakdance renders templates and dynamic fields on the frontend. That means the analysis can miss headings, links, and text that only appear once Breakdance renders the page. This plugin feeds the rendered HTML into Rank Math so the score matches what visitors (and search engines) see.

## What It Does
- Injects rendered HTML into Rank Math’s **Content Analysis** pipeline.
- Works with Breakdance templates, CPTs, and dynamic fields.
- Supports drafts by using **WordPress preview URLs** with the current user’s cookies.
- Avoids the “low score flash” on load by reusing the last fetched content in **sessionStorage** until a fresh fetch completes.

## How It Works
1. **Editor integration (JS)**
   - Hooks `rank_math_content` (Rank Math Content Analysis API).
   - Fetches rendered HTML via a REST endpoint.
   - Refreshes Rank Math once content is available.

2. **REST endpoint (PHP)**
   - Attempts server-side Breakdance rendering.
   - Falls back to frontend fetch if Breakdance is unavailable or content is empty.
   - For drafts, uses `get_preview_post_link()` and passes logged-in cookies.

3. **Bulk recalculation (PHP)**
   - Hooks `rank_math/recalculate_score/data` to ensure bulk scoring uses the same rendered HTML.

## Installation
1. Upload this plugin to `wp-content/plugins/breakdance-rankmath-bridge`.
2. Activate it in WordPress.

## Requirements
- WordPress 6.x+
- Rank Math SEO (Free or Pro)
- Breakdance

## Configuration
The plugin is intentionally minimal. Defaults are tuned for Breakdance:
- **Content mode**: `breakdance` (rendered HTML replaces editor content)
- **Drafts**: rendered through WordPress preview URLs

If you want combined editor + rendered content, change `content_mode` in:
- `breakdance-rankmath-bridge.php`

## Limitations
- **Basic Authentication**: Not bypassed. If basic auth blocks previews, Rank Math can only analyze live mode.
- **Maintenance Mode**: If maintenance mode changes HTML output, scores will reflect that state.

## Development Notes
- Editor integration follows Rank Math’s Content Analysis API and `rank_math_content` filter.
- Breakdance rendering uses the core Breakdance render pipeline when available.

## License
GPL-2.0-or-later

## Credits
Built for Breakdance and Rank Math integration.
