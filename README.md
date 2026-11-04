# Site Commander – Universal AI Connector for WordPress

Let any AI manage a WordPress site: write and schedule posts, upload media, change the design, fix SEO, run updates — directly, with no middle server. Each AI gets its own key that works only on that one site.

The same set of ~95 tools is exposed three ways, so it works with every AI:

| Transport | Endpoint | Works with |
|---|---|---|
| **MCP** (Streamable HTTP, JSON-RPC 2.0) | `POST /wp-json/site-commander/v1/mcp` | Claude (web, desktop, Code), ChatGPT connectors, Cursor, VS Code / Copilot, Windsurf, Gemini CLI, any MCP client |
| **REST** | `GET /wp-json/site-commander/v1/tools` · `POST /wp-json/site-commander/v1/tools/{name}` | n8n, Make, Zapier, scripts, your own agents |
| **OpenAPI 3.1** | `GET /wp-json/site-commander/v1/openapi.json` (`?groups=content,seo` to slim it) | ChatGPT Custom GPT Actions, Gemini, Postman, any OpenAPI importer |

Auth: `Authorization: Bearer KEY`, `X-SC-Key: KEY`, or `?key=KEY` (for clients that only accept a URL).

**One key per AI.** Under *Site Commander → AI connections* create a key for each AI ("Claude", "ChatGPT", "n8n"…). Each key:

- works only on the site that issued it (stored there as a hash),
- can be limited to tool groups (e.g. only *content* + *seo*) and/or made read-only — the AI only sees the tools it may use,
- can be disconnected on its own without affecting other AIs,
- shows up by name in the activity log, with last-used time.

GitHub is used **only** to ship plugin updates; site data and commands never go through it.

## Install

1. Upload `site-commander.zip` in **Plugins → Add New → Upload**, activate.
2. Open **Site Commander** in the admin menu → **Add an AI connection**.
3. Copy the ready-made setup for your AI from the same screen.

Requires WordPress 6.2+ and PHP 7.4+.

## What the AI can do

**Content** — list/search/get any post type, create, update, schedule, bulk status, duplicate, trash/restore, find & replace, revisions, categories/tags/terms, custom fields.

**Media** — upload from URL or base64, set alt/title/caption, featured images, regenerate thumbnails.

**Design** — themes (list, activate, install), child theme creation, Additional CSS, Customizer theme mods, logo/favicon, block-theme global styles (colors, fonts, spacing), templates & template parts (header/footer/single…), block patterns, theme files (backed up, PHP syntax-checked, auto-rolled back if the site breaks), menus, widgets.

**SEO** — full site audit with score, per-page fixes, bulk meta titles/descriptions, focus keywords, canonical, noindex, custom JSON-LD schema, homepage SEO, image alt text (library + inside content), internal-link opportunities, broken-link finder, 301/302/410 redirects, 404 log, robots.txt rules. Writes into **Yoast, Rank Math, SEOPress, AIOSEO or The SEO Framework** when present; otherwise prints meta, Open Graph and schema itself.

**Site** — site overview, settings (title, tagline, homepage, permalinks, timezone, comments, media sizes…), plugins (list, install, activate/deactivate with auto-rollback), render any page and analyse it, activity log.

**People** — comments (moderate, reply), users (create, edit roles/bios — never administrators).

**Dev** — clear caches (WP Rocket, LiteSpeed, W3TC, WP Super Cache, SiteGround, Cloudflare, Kinsta, WP Engine…), check/run core-plugin-theme-translation updates, maintenance mode, header/footer code snippets (analytics, pixels), Site Health, debug.log, cron, missed schedules, read/update options, serialization-safe database search & replace, JSON export, self-update.

**WooCommerce** (when active) — list/create/update products (prices, sale, stock, SKU, images, categories), list orders, change order status.

## Safety

- Risky abilities are **off by default** and switched on per site: PHP edits, plugin/theme installs, permanent deletes, user management, code snippets, database tools.
- Deletes go to the trash unless permanent deletion is allowed.
- Theme files are backed up before every write; PHP is syntax-checked and the homepage is tested after saving — on a fatal error the file is restored automatically. Plugin activation is rolled back the same way.
- Secrets in options (passwords, API keys, tokens) are never returned.
- Administrators can't be created or modified; core options like `siteurl` are protected.
- Every change is recorded in the activity log with the AI client's name.
- **Undo**: most changes (posts, pages, SEO fields, Elementor/blocks, templates, global styles, CSS, settings, redirects, snippets) are snapshotted first. Roll one back from the activity log ("Undo" button) or with `undo_change`. The newest 100 snapshots are kept.
- **Approval mode** per key: changes wait in an approval list (with an e-mail to the owner) and are applied only when approved.
- **Key limits**: expiry date, IP allow-list (IPs or CIDR) and a daily change limit per key.
- **Security check** (daily, plus `check_integrity` tool): compares WordPress core files and active WordPress.org plugins with the official checksums and flags missing/changed core files, changed or extra plugin PHP files, unknown PHP files in core folders or uploads, random-named plugin folders, new administrators and a broken homepage. It e-mails the owner once when the findings change. `reinstall_core` (or the "Repair core files" button) restores core files without touching content, plugins, themes or wp-config.php.

## Integrations (1.7.0)

- **Gutenberg blocks**: `list_blocks`, `get_block`, `update_block`, `insert_block`, `delete_block`, `move_block` edit one block by path without rewriting the page.
- **ACF**: `get_acf_fields`, `update_acf_fields` (posts and options pages).
- **Forms**: `list_forms`, `list_form_entries` for Gravity Forms, WPForms Pro, Contact Form 7 + Flamingo, Fluent Forms.
- **Translations**: `list_languages`, `get_translations`, `create_translation` for Polylang and WPML.
- **Speed and images**: `page_speed_check`, `image_audit`, `resize_large_images` (originals backed up), `set_webp_uploads`.
- **Internal links**: `add_internal_link` links the first plain-text occurrence of a phrase.
- **Webhooks**: signed JSON POSTs for post_published, comment_posted, form_submitted, order_created, user_registered, ai_change, pending_approval, security_alert.
- **Agency hub**: add client sites (MCP URL + key) in wp-admin; the AI uses `list_client_sites`, `client_site_tools`, `run_on_client_site`.
- **Activity report**: weekly/monthly e-mail summary, or `activity_report`.

## Automatic updates (push a release → all sites update)

Licensing and updates run through [Freemius](https://freemius.com) (product 42738). Free plan: 1 AI connection, 10 posts and 2 pages created through the plugin, core tools. Pro: everything, with a 7-day trial.

To ship a new version: bump `Version:` and `SC_VERSION` in `site-commander.php` and push to `main`. `.github/workflows/release.yml` lints, builds `site-commander.zip`, tags and publishes it. Upload that zip to Freemius (Deployment) so customers receive it.

## Extending

Add your own tools; they appear in MCP, REST and OpenAPI automatically:

```php
add_action( 'sc_register_tools', function () {
	SC_Tools::add( 'count_words', array(
		'group'       => 'content',
		'description' => 'Count words in a post.',
		'properties'  => array( 'id' => array( 'type' => 'integer', 'description' => 'Post ID' ) ),
		'required'    => array( 'id' ),
		'write'       => false,          // true = logged + POST-only over REST
		'requires'    => null,           // or a setting key, e.g. 'allow_code'
		'callback'    => function ( $args ) {
			return array( 'words' => str_word_count( wp_strip_all_tags( get_post_field( 'post_content', $args['id'] ) ) ) );
		},
	) );
} );
```

Throw `SC_Tool_Error` (or return `WP_Error`) to report a failure. Hook `sc_purge_caches` to clear a custom cache layer.

## File map

```
site-commander.php                  bootstrap
includes/class-sc-mcp-server.php    MCP + REST + OpenAPI transports
includes/class-sc-tools.php         tool registry, validation, helpers
includes/tools/*.php                content, media, design, site, seo, people, dev, commerce
includes/class-sc-auth.php          key handling
includes/class-sc-seo-frontend.php  meta tags, schema, redirects, 404 log, robots, snippets, maintenance
includes/class-sc-updater.php       GitHub self-updates
includes/class-sc-admin.php         settings screen
```
