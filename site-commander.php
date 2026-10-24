<?php
/**
 * Plugin Name:       Site Commander – Universal AI Connector
 * Plugin URI:        https://github.com/miyuru2y-ops/site-commander
 * Description:       Let any AI (Claude, ChatGPT, Gemini, Cursor, Copilot, n8n, your own agent) manage the WordPress backend: posts, pages, scheduling, media uploads, themes, design (CSS, global styles, templates, theme files), menus, settings and SEO fixes. Exposes one tool set over MCP, a plain REST API and an OpenAPI spec, each AI with its own scoped key.
 * Version:           1.2.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Site Commander
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SC_VERSION', '1.2.1' );
define( 'SC_FILE', __FILE__ );
define( 'SC_DIR', plugin_dir_path( __FILE__ ) );
define( 'SC_URL', plugin_dir_url( __FILE__ ) );

require_once SC_DIR . 'includes/class-sc-plugin.php';
require_once SC_DIR . 'includes/class-sc-license.php';
require_once SC_DIR . 'includes/class-sc-auth.php';
require_once SC_DIR . 'includes/class-sc-log.php';
require_once SC_DIR . 'includes/class-sc-mcp-server.php';
require_once SC_DIR . 'includes/class-sc-tools.php';
require_once SC_DIR . 'includes/tools/class-sc-tools-content.php';
require_once SC_DIR . 'includes/tools/class-sc-tools-media.php';
require_once SC_DIR . 'includes/tools/class-sc-tools-design.php';
require_once SC_DIR . 'includes/tools/class-sc-tools-site.php';
require_once SC_DIR . 'includes/tools/class-sc-tools-seo.php';
require_once SC_DIR . 'includes/tools/class-sc-tools-people.php';
require_once SC_DIR . 'includes/tools/class-sc-tools-dev.php';
require_once SC_DIR . 'includes/tools/class-sc-tools-commerce.php';
require_once SC_DIR . 'includes/class-sc-seo-frontend.php';
require_once SC_DIR . 'includes/class-sc-admin.php';

register_activation_hook( __FILE__, array( 'SC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SC_Plugin', 'deactivate' ) );

SC_Plugin::init();
