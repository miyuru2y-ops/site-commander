=== Site Commander – Universal AI Connector ===
Contributors: miyuru2y
Tags: ai, mcp, claude, chatgpt, seo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let any AI (Claude, ChatGPT, Gemini, Cursor, Copilot, n8n) manage your WordPress site in plain language: posts, media, design, SEO and security checks.

== Description ==

Site Commander connects an AI assistant to your WordPress site. You create a separate, scoped key for each AI, paste it into the assistant, and then ask for things in plain language: "publish this post", "fix the missing alt text", "update my plugins".

It exposes the site-management tools over MCP (Model Context Protocol), a plain REST API and an OpenAPI spec.

**What an AI can do**

* Write, edit and schedule posts and pages; manage categories, tags, revisions and custom fields
* Upload media from a URL or file data; set alt text and featured images
* Change the design: Additional CSS, theme settings, block-theme styles and templates, menus, widgets
* Fix SEO: titles, meta descriptions, schema, alt text, redirects, robots.txt (works with Yoast, Rank Math, SEOPress and AIOSEO)
* Maintenance: updates, caches, Site Health, debug log, cron
* Edit pages built with Elementor (and, in beta, Divi and WPBakery)

**You stay in control**

* A separate key per AI, each limited to chosen tool groups or read-only
* Keys only work on the site that issued them and can be revoked at any time
* Risky abilities are off until you enable them
* Every change is written to an activity log
* Built-in security check: compares WordPress core files with the official checksums, looks for unknown PHP files and new administrator accounts, and can email you and reinstall damaged core files

= Free and Pro =

The free plugin includes the core content tools, one AI key, the activity log and the security check. A Pro plan unlocks the full toolbox (SEO tools, page-builder tools, more keys). Pro is sold through Freemius.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Site Commander in the admin menu and add an AI connection.
3. Copy the setup text for your AI tool from the same screen and paste it into the assistant.

== Frequently Asked Questions ==

= Is my content sent anywhere? =

Your AI connects directly to your own site using your key. Site Commander does not route your content through a copy of your site.

= Can an AI break my site? =

Risky tools are disabled until you turn them on, theme file edits keep backups, and every action is logged so you can see what changed.

= Which AIs work? =

Any assistant that supports MCP or HTTP tools, including Claude, ChatGPT, Cursor and n8n.

== Third-party services ==

This plugin uses the Freemius SDK for licensing, optional opt-in usage data and updates of paid plans. Data is sent to Freemius only after you choose to opt in or start a trial. Service: https://freemius.com – Terms: https://freemius.com/terms/ – Privacy: https://freemius.com/privacy/

The security check and link checker make HTTP requests to your own site's URLs only.

== Changelog ==

= 1.6.0 =
* Security check: core checksum comparison, unknown PHP file detection, new administrator alerts, daily email alert, one-click core repair.

= 1.5.1 =
* Flushing permalinks now rewrites the rewrite rules fully.

= 1.5.0 =
* Tool to reinstall damaged or missing WordPress core files.

= 1.1.0 =
* Separate key per AI, each limited to chosen tool groups or read-only.

= 1.0.0 =
* First release.
