=== Site Commander – Universal AI Connector ===
Tags: ai, mcp, claude, chatgpt, seo
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let any AI (Claude, ChatGPT, Gemini, Cursor, Copilot, n8n…) manage your WordPress site: posts, scheduling, media, design and SEO.

== Description ==

Site Commander exposes about 95 site-management tools over MCP, a plain REST API and an OpenAPI spec, with a separate, scoped key for each AI.

* Write, edit and schedule posts and pages; manage categories, tags, revisions and custom fields
* Upload media from a URL or file data; set alt text and featured images
* Change the design: Additional CSS, theme settings, block-theme styles and templates, theme files (with backups and automatic rollback), menus, widgets, child themes
* Fix SEO: site audit, meta titles and descriptions (Yoast, Rank Math, SEOPress, AIOSEO supported), schema, alt text, redirects, 404 log, broken links, robots.txt
* Maintenance: updates, caches, maintenance mode, code snippets, Site Health, debug log, cron, database search & replace
* Comments, users and WooCommerce products/orders
* Self-updates from your GitHub releases

Risky abilities are off until you enable them, and every change is logged.

== Installation ==

1. Upload and activate the plugin.
2. Go to Site Commander and add an AI connection.
3. Copy the setup for your AI tool from the same screen.

== Changelog ==

= 1.1.0 =
* Separate key per AI, each limited to chosen tool groups or read-only.
* Keys only work on the site that issued them.

= 1.0.0 =
* First release.
