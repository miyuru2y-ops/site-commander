=== Site Commander Lite ===
Contributors: miyuru2u
Tags: ai, mcp, claude, chatgpt, seo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: {version}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let any AI (Claude, ChatGPT, Cursor, n8n) work on your WordPress content, media and SEO, with a separate key per AI, undo and approval.

== Description ==

Site Commander Lite connects an AI assistant to your WordPress site so it can do content and SEO work for you in plain language: "write a draft about our opening hours", "fix the missing alt text", "add a redirect from the old page".

You create a key for each AI on the plugin screen and paste it into the assistant. The plugin offers its tools over MCP (Model Context Protocol), a plain REST API and an OpenAPI description.

**What an AI can do**

* Write, edit and schedule posts and pages; categories, tags, revisions and custom fields
* Edit one Gutenberg block at a time, ACF fields, block templates, menus and widgets
* Upload media and set alt text and featured images; find heavy images and resize them
* SEO: titles, meta descriptions, schema, redirects, robots.txt, broken links, internal links, a 404 log (works with Yoast, Rank Math, SEOPress and AIOSEO)
* Read form entries (Gravity Forms, WPForms, Contact Form 7 with Flamingo, Fluent Forms), moderate and answer comments
* Create Polylang or WPML translations
* Check page speed and site health, clear caches

**What it does not do**

Lite never installs, activates or updates plugins and themes, never edits theme or plugin files, never adds scripts or custom code to your pages and never creates or changes user accounts. Those stay with you.

**You stay in control**

* A separate key for each AI, limited to chosen tool groups or read-only
* Keys can expire, be limited to IP addresses and to a number of changes per day, and can be revoked at any time
* Approval mode: changes from a key can wait for your approval before they are applied
* Every change is recorded in an activity log, and most changes can be undone with one click
* A daily security check compares WordPress core and WordPress.org plugin files with the official checksums and e-mails you when something changes

The AI works through the key you give it, as the administrator you choose on the plugin screen. Only give keys to assistants you trust, and revoke a key you no longer use.

== Installation ==

1. Install and activate the plugin.
2. Go to Site Commander in the admin menu and add an AI connection.
3. Copy the setup text for your AI tool and paste it into the assistant.

== Frequently Asked Questions ==

= Where does my content go? =

Your AI assistant connects directly to your site with your key. The plugin itself does not send your content to any other service.

= Can an AI break my site? =

Lite only changes content, media, SEO and similar settings. Changes are logged and most can be undone. With approval mode on, nothing is applied until you approve it.

= Which AIs work? =

Any assistant that supports MCP or HTTP tools, including Claude, ChatGPT, Cursor and n8n.

== External services ==

The plugin calls outside services only in these cases:

* The security check downloads the official checksums of WordPress core (api.wordpress.org) and of active plugins (downloads.wordpress.org) to compare with your files. No site data is sent apart from the WordPress and plugin versions in the request. https://wordpress.org/about/privacy/
* Webhooks, if you add them, send the events you choose (for example a new form entry) to the URL you enter.
* The speed check, link checker and page fetch tools request pages of your own site.

== Changelog ==

= {version} =
* First release on WordPress.org.
