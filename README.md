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

## Automatic updates (push a release → all sites update)

The update source is built in (`SC_UPDATE_REPO` = `miyuru2y-ops/site-commander`), so every install updates itself with no setup. Only plugin code comes from GitHub; site data and keys never go there.

To ship a new version: bump `Version:` and `SC_VERSION` in `site-commander.php`, commit, then

```bash
git tag v1.2.0 && git push origin main --tags
```

`.github/workflows/release.yml` lints, builds `site-commander.zip` and publishes the release. Sites pick it up within ~6 hours, or immediately via **Check for updates now** or the `self_update` tool. Auto-install is on by default and can be switched off per site.

Optional: a site can point at a fork under *Plugin updates → Advanced*, or switch self-updates off with `define( 'SC_DISABLE_SELF_UPDATE', true );` in wp-config.php.

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
