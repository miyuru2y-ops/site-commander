<?php
/**
 * Developer / maintenance tools: updates, cache, maintenance mode, code snippets,
 * site health, cron, debug log, options, database search & replace, export.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_Dev {

	const SNIPPETS = 'sc_snippets';

	/** Options that are never returned (secrets). */
	const SECRET_OPTIONS = '/(pass|secret|token|api_?key|license|salt|auth|nonce|private|credential|sc_key_hash|sc_settings)/i';

	public static function register() {
		$t = 'SC_Tools';

		$t::add( 'clear_cache', array(
			'group'       => 'dev',
			'description' => 'Clear WordPress object cache and page caches of common cache plugins/hosts (WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround, Cloudflare plugin, Autoptimize, Breeze, Kinsta, WP Engine) so changes show up.',
			'write'       => true,
			'callback'    => function () {
				return array( 'message' => 'Caches cleared: ' . implode( ', ', SC_Tools_Dev::purge_caches() ) . '.' );
			},
		) );

		$t::add( 'check_updates', array(
			'group'       => 'dev',
			'description' => 'Check for available WordPress core, plugin, theme and translation updates.',
			'callback'    => array( __CLASS__, 'check_updates' ),
		) );

		$t::add( 'run_updates', array(
			'group'       => 'dev',
			'description' => 'Install updates. what: "plugins", "themes", "core", "translations" or "all". Optionally only specific plugin files / theme slugs. The site is checked afterwards.',
			'properties'  => array(
				'what'  => $t::p_enum( array( 'plugins', 'themes', 'core', 'translations', 'all' ), 'What to update.' ),
				'items' => $t::p_list( 'Only these plugin files (e.g. "akismet/akismet.php") or theme slugs.' ),
			),
			'required'    => array( 'what' ),
			'write'       => true,
			'requires'    => 'allow_updates',
			'callback'    => array( __CLASS__, 'run_updates' ),
		) );

		$t::add( 'maintenance_mode', array(
			'group'       => 'dev',
			'description' => 'Turn maintenance mode on/off. Visitors see a "back soon" page (HTTP 503, safe for SEO); logged-in admins still see the site.',
			'properties'  => array(
				'enabled' => $t::p( 'boolean', 'On or off.' ),
				'message' => $t::p( 'string', 'Message to show visitors.' ),
			),
			'required'    => array( 'enabled' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'maintenance' ),
		) );

		$t::add( 'list_code_snippets', array(
			'group'       => 'dev',
			'description' => 'List header/footer code snippets (analytics, tracking pixels, verification tags, custom scripts/styles).',
			'callback'    => function () {
				return array_values( get_option( SC_Tools_Dev::SNIPPETS, array() ) );
			},
		) );

		$t::add( 'save_code_snippet', array(
			'group'       => 'dev',
			'description' => 'Add or update a code snippet printed on every page: Google Analytics/Tag Manager, Search Console verification meta, Facebook pixel, chat widgets, custom <script>/<style>. location: head, body_open or footer.',
			'properties'  => array(
				'id'       => $t::p( 'string', 'Snippet ID to update. Omit to create.' ),
				'name'     => $t::p( 'string', 'Label, e.g. "Google Analytics".' ),
				'code'     => $t::p( 'string', 'HTML to output (script/style/meta tags).' ),
				'location' => $t::p_enum( array( 'head', 'body_open', 'footer' ), 'Default head.' ),
				'active'   => $t::p( 'boolean', 'Default true.' ),
			),
			'required'    => array( 'code' ),
			'write'       => true,
			'requires'    => 'allow_code',
			'callback'    => array( __CLASS__, 'save_snippet' ),
		) );

		$t::add( 'delete_code_snippet', array(
			'group'       => 'dev',
			'description' => 'Remove a code snippet.',
			'properties'  => array( 'id' => $t::p( 'string', 'Snippet ID.' ) ),
			'required'    => array( 'id' ),
			'write'       => true,
			'requires'    => 'allow_code',
			'callback'    => function ( $a ) {
				$all = get_option( SC_Tools_Dev::SNIPPETS, array() );
				if ( ! isset( $all[ $a['id'] ] ) ) {
					SC_Tools::fail( 'Snippet not found.' );
				}
				unset( $all[ $a['id'] ] );
				update_option( SC_Tools_Dev::SNIPPETS, $all );
				SC_Tools_Dev::purge_caches();
				return array( 'message' => "Snippet {$a['id']} removed." );
			},
		) );

		$t::add( 'site_health', array(
			'group'       => 'dev',
			'description' => 'Run WordPress Site Health checks (PHP version, HTTPS, updates, debug mode, plugin/theme status, REST API, etc.) and list problems.',
			'callback'    => array( __CLASS__, 'site_health' ),
		) );

		$t::add( 'read_debug_log', array(
			'group'       => 'dev',
			'description' => 'Read the last lines of wp-content/debug.log (PHP errors and warnings) to find what is broken.',
			'properties'  => array( 'lines' => $t::p( 'integer', 'Default 100, max 1000.' ) ),
			'callback'    => array( __CLASS__, 'debug_log' ),
		) );

		$t::add( 'list_cron', array(
			'group'       => 'dev',
			'description' => 'List scheduled background tasks (WP-Cron), including scheduled post publishing.',
			'callback'    => array( __CLASS__, 'list_cron' ),
		) );

		$t::add( 'run_cron_event', array(
			'group'       => 'dev',
			'description' => 'Run a scheduled WP-Cron hook now (e.g. a missed scheduled post: "publish_future_post").',
			'properties'  => array( 'hook' => $t::p( 'string', 'Hook name from list_cron.' ) ),
			'required'    => array( 'hook' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'run_cron' ),
		) );

		$t::add( 'publish_missed_schedules', array(
			'group'       => 'dev',
			'description' => 'Publish scheduled posts whose time has passed but did not go live ("Missed schedule").',
			'write'       => true,
			'callback'    => array( __CLASS__, 'missed_schedules' ),
		) );

		$t::add( 'get_option', array(
			'group'       => 'dev',
			'description' => 'Read any WordPress option by name (plugin settings etc.). Secrets such as passwords and API keys are never returned. Use search to find option names.',
			'properties'  => array(
				'name'   => $t::p( 'string', 'Option name.' ),
				'search' => $t::p( 'string', 'Instead of name: list option names containing this text.' ),
			),
			'callback'    => array( __CLASS__, 'get_option' ),
		) );

		$t::add( 'update_option', array(
			'group'       => 'dev',
			'description' => 'Change any WordPress option (e.g. a plugin\'s settings). Powerful: read it with get_option first and change only what is needed. Core security options and secrets cannot be changed.',
			'properties'  => array(
				'name'  => $t::p( 'string', 'Option name.' ),
				'value' => array( 'description' => 'New value (string, number, object or array).' ),
				'merge' => $t::p( 'boolean', 'For array options: merge into the existing value instead of replacing. Default true.' ),
			),
			'required'    => array( 'name', 'value' ),
			'write'       => true,
			'requires'    => 'allow_database',
			'callback'    => array( __CLASS__, 'update_option' ),
		) );

		$t::add( 'database_search_replace', array(
			'group'       => 'dev',
			'description' => 'Search & replace across the database (content, custom fields, options, comments) safely, including serialized data — e.g. move from http:// to https:// or change domain. Dry run by default.',
			'properties'  => array(
				'search'  => $t::p( 'string', 'Text to find.' ),
				'replace' => $t::p( 'string', 'Replacement.' ),
				'dry_run' => $t::p( 'boolean', 'Default true.' ),
			),
			'required'    => array( 'search', 'replace' ),
			'write'       => true,
			'destructive' => true,
			'requires'    => 'allow_database',
			'callback'    => array( __CLASS__, 'db_replace' ),
		) );

		$t::add( 'flush_permalinks', array(
			'group'       => 'dev',
			'description' => 'Rebuild rewrite rules (fixes 404s on posts/pages after changes to permalinks or custom post types).',
			'write'       => true,
			'callback'    => function () {
				flush_rewrite_rules( false );
				return array( 'message' => 'Permalinks rebuilt.' );
			},
		) );

		$t::add( 'export_content', array(
			'group'       => 'dev',
			'description' => 'Export posts/pages as JSON (title, content, excerpt, status, date, terms, SEO) for backup or migration.',
			'properties'  => array(
				'post_types' => $t::p_list( 'Default post and page.' ),
				'limit'      => $t::p( 'integer', 'Default 50, max 500.' ),
				'offset'     => $t::p( 'integer', 'Skip this many.' ),
			),
			'callback'    => array( __CLASS__, 'export' ),
		) );
	}

	/* --------------------------------------------------------------------- */

	public static function purge_caches() {
		$done = array();
		wp_cache_flush();
		$done[] = 'object cache';
		$try = array(
			'WP Rocket'        => function () { if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); return true; } },
			'LiteSpeed'        => function () { if ( defined( 'LSCWP_V' ) ) { do_action( 'litespeed_purge_all' ); return true; } },
			'W3 Total Cache'   => function () { if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); return true; } },
			'WP Super Cache'   => function () { if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); return true; } },
			'WP Fastest Cache' => function () { if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) { $GLOBALS['wp_fastest_cache']->deleteCache( true ); return true; } },
			'SiteGround'       => function () { if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache(); return true; } },
			'Autoptimize'      => function () { if ( class_exists( 'autoptimizeCache' ) ) { autoptimizeCache::clearall(); return true; } },
			'Breeze'           => function () { if ( has_action( 'breeze_clear_all_cache' ) ) { do_action( 'breeze_clear_all_cache' ); return true; } },
			'Cloudflare'       => function () { if ( has_action( 'cloudflare_purge_everything' ) ) { do_action( 'cloudflare_purge_everything' ); return true; } },
			'Kinsta'           => function () { if ( isset( $GLOBALS['kinsta_cache'] ) && ! empty( $GLOBALS['kinsta_cache']->kinsta_cache_purge ) ) { $GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_complete_caches(); return true; } },
			'WP Engine'        => function () { if ( class_exists( 'WpeCommon' ) ) { if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) { WpeCommon::purge_memcached(); } if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) { WpeCommon::purge_varnish_cache(); } return true; } },
			'Nginx Helper'     => function () { if ( has_action( 'rt_nginx_helper_purge_all' ) ) { do_action( 'rt_nginx_helper_purge_all' ); return true; } },
		);
		foreach ( $try as $name => $fn ) {
			try {
				if ( $fn() ) {
					$done[] = $name;
				}
			} catch ( Throwable $e ) {
				// Ignore a misbehaving cache plugin.
			}
		}
		do_action( 'sc_purge_caches' );
		return $done;
	}

	/**
	 * Quick loopback request to see if the front end still works.
	 */
	public static function site_responds() {
		$res = wp_remote_get( add_query_arg( 'sc_check', wp_generate_password( 6, false ), home_url( '/' ) ), array( 'timeout' => 20, 'sslverify' => false, 'redirection' => 3 ) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => true, 'detail' => 'could not verify (server cannot request itself: ' . $res->get_error_message() . ')' );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		if ( $code >= 500 && 503 !== $code ) {
			return array( 'ok' => false, 'detail' => "homepage returned HTTP $code" );
		}
		if ( false !== stripos( $body, 'critical error on this website' ) || preg_match( '/<b>(Fatal|Parse) error<\/b>/i', $body ) ) {
			return array( 'ok' => false, 'detail' => 'homepage shows a PHP fatal error' );
		}
		return array( 'ok' => true, 'detail' => "homepage OK (HTTP $code)" );
	}

	public static function check_updates() {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();
		$core    = get_preferred_from_update_core();
		$plugins = array();
		foreach ( (array) get_plugin_updates() as $file => $p ) {
			$plugins[] = array( 'plugin' => $file, 'name' => $p->Name, 'from' => $p->Version, 'to' => $p->update->new_version );
		}
		$themes = array();
		foreach ( (array) get_theme_updates() as $slug => $th ) {
			$themes[] = array( 'theme' => $slug, 'from' => $th->get( 'Version' ), 'to' => $th->update['new_version'] );
		}
		return array(
			'core'         => ( $core && 'upgrade' === $core->response ) ? $core->current : 'up to date (' . get_bloginfo( 'version' ) . ')',
			'plugins'      => $plugins,
			'themes'       => $themes,
			'translations' => count( (array) wp_get_translation_updates() ),
		);
	}

	public static function run_updates( $a ) {
		SC_Tools_Design::upgrader_includes();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		$what  = SC_Tools::str( $a, 'what' );
		$only  = (array) SC_Tools::arr( $a, 'items' );
		$skin  = new Automatic_Upgrader_Skin();
		$out   = array();

		if ( in_array( $what, array( 'plugins', 'all' ), true ) ) {
			wp_update_plugins();
			$files = array_keys( (array) get_plugin_updates() );
			if ( $only ) {
				$files = array_values( array_intersect( $files, $only ) );
			}
			if ( $files ) {
				$res = ( new Plugin_Upgrader( $skin ) )->bulk_upgrade( $files );
				foreach ( (array) $res as $f => $r ) {
					$out['plugins'][ $f ] = ( is_wp_error( $r ) || ! $r ) ? 'failed' : 'updated';
				}
				// Reactivate anything the upgrade left inactive that was active.
			} else {
				$out['plugins'] = 'nothing to update';
			}
		}
		if ( in_array( $what, array( 'themes', 'all' ), true ) ) {
			wp_update_themes();
			$slugs = array_keys( (array) get_theme_updates() );
			if ( $only ) {
				$slugs = array_values( array_intersect( $slugs, $only ) );
			}
			if ( $slugs ) {
				$res = ( new Theme_Upgrader( $skin ) )->bulk_upgrade( $slugs );
				foreach ( (array) $res as $s => $r ) {
					$out['themes'][ $s ] = ( is_wp_error( $r ) || ! $r ) ? 'failed' : 'updated';
				}
			} else {
				$out['themes'] = 'nothing to update';
			}
		}
		if ( in_array( $what, array( 'translations', 'all' ), true ) ) {
			$res                  = ( new Language_Pack_Upgrader( $skin ) )->bulk_upgrade();
			$out['translations'] = is_array( $res ) ? count( $res ) . ' updated' : 'nothing to update';
		}
		if ( in_array( $what, array( 'core', 'all' ), true ) ) {
			wp_version_check();
			$core = get_preferred_from_update_core();
			if ( $core && 'upgrade' === $core->response ) {
				$res         = ( new Core_Upgrader( $skin ) )->upgrade( $core );
				$out['core'] = is_wp_error( $res ) ? 'failed: ' . $res->get_error_message() : 'updated to ' . $res;
			} else {
				$out['core'] = 'up to date';
			}
		}
		$check            = self::site_responds();
		$out['site_check'] = $check['detail'];
		$out['message']    = 'Updates finished. ' . $check['detail'] . '.';
		return $out;
	}

	public static function maintenance( $a ) {
		$on      = SC_Tools::bool( $a, 'enabled' );
		$changes = array( 'maintenance_mode' => $on ? 1 : 0 );
		if ( isset( $a['message'] ) ) {
			update_option( 'sc_maintenance_message', sanitize_textarea_field( $a['message'] ) );
		}
		SC_Plugin::update_settings( $changes );
		self::purge_caches();
		return array( 'message' => 'Maintenance mode ' . ( $on ? 'ON — visitors see the maintenance page.' : 'OFF — site is live.' ) );
	}

	public static function save_snippet( $a ) {
		$all = get_option( self::SNIPPETS, array() );
		$id  = SC_Tools::str( $a, 'id' );
		if ( $id && ! isset( $all[ $id ] ) ) {
			SC_Tools::fail( 'Snippet not found.' );
		}
		if ( ! $id ) {
			$id = 'snip_' . wp_generate_password( 6, false, false );
		}
		$prev       = isset( $all[ $id ] ) ? $all[ $id ] : array();
		$all[ $id ] = array(
			'id'       => $id,
			'name'     => SC_Tools::str( $a, 'name', isset( $prev['name'] ) ? $prev['name'] : 'Snippet' ),
			'code'     => (string) $a['code'],
			'location' => SC_Tools::str( $a, 'location', isset( $prev['location'] ) ? $prev['location'] : 'head' ),
			'active'   => SC_Tools::bool( $a, 'active', true ),
			'updated'  => current_time( 'mysql' ),
		);
		update_option( self::SNIPPETS, $all );
		self::purge_caches();
		return array( 'message' => "Snippet \"{$all[$id]['name']}\" saved ({$all[$id]['location']}).", 'id' => $id );
	}

	public static function site_health() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		$health = WP_Site_Health::get_instance();
		$tests  = WP_Site_Health::get_tests();
		$out    = array( 'critical' => array(), 'recommended' => array(), 'good' => 0 );
		foreach ( $tests['direct'] as $test ) {
			try {
				if ( is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
					$r = call_user_func( array( $health, 'get_test_' . $test['test'] ) );
				} elseif ( is_callable( $test['test'] ) ) {
					$r = call_user_func( $test['test'] );
				} else {
					continue;
				}
			} catch ( Throwable $e ) {
				continue;
			}
			if ( ! is_array( $r ) || empty( $r['status'] ) ) {
				continue;
			}
			if ( 'good' === $r['status'] ) {
				$out['good']++;
			} else {
				$out[ 'critical' === $r['status'] ? 'critical' : 'recommended' ][] = wp_strip_all_tags( $r['label'] ) . ' — ' . trim( wp_strip_all_tags( isset( $r['description'] ) ? $r['description'] : '' ) );
			}
		}
		$out['debug_mode'] = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$out['memory_limit'] = WP_MEMORY_LIMIT;
		$out['php']          = PHP_VERSION;
		return $out;
	}

	public static function debug_log( $a ) {
		$file = WP_CONTENT_DIR . '/debug.log';
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
			$file = WP_DEBUG_LOG;
		}
		if ( ! file_exists( $file ) ) {
			return array( 'message' => 'No debug.log found. To record errors, set WP_DEBUG and WP_DEBUG_LOG to true in wp-config.php.' );
		}
		$n    = min( 1000, max( 1, SC_Tools::int( $a, 'lines', 100 ) ) );
		$size = filesize( $file );
		$fh   = fopen( $file, 'r' ); // phpcs:ignore
		fseek( $fh, max( 0, $size - 400000 ) );
		$data = fread( $fh, 400000 ); // phpcs:ignore
		fclose( $fh ); // phpcs:ignore
		$lines = array_slice( explode( "\n", trim( (string) $data ) ), -$n );
		return array( 'file_kb' => round( $size / 1024 ), 'lines' => $lines );
	}

	public static function list_cron() {
		$out = array();
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $e ) {
					$out[] = array(
						'hook'     => $hook,
						'next_run' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ),
						'overdue'  => $ts < time(),
						'schedule' => $e['schedule'] ? $e['schedule'] : 'once',
						'args'     => $e['args'],
					);
				}
			}
		}
		return array( 'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'events' => array_slice( $out, 0, 200 ) );
	}

	public static function run_cron( $a ) {
		$hook = (string) $a['hook'];
		$ran  = 0;
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			if ( isset( $hooks[ $hook ] ) ) {
				foreach ( $hooks[ $hook ] as $e ) {
					do_action_ref_array( $hook, $e['args'] );
					$ran++;
				}
			}
		}
		if ( ! $ran ) {
			SC_Tools::fail( "No scheduled event '$hook'." );
		}
		return array( 'message' => "Ran $hook ($ran events)." );
	}

	public static function missed_schedules() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'future' AND post_date_gmt <= %s", gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			wp_publish_post( (int) $id );
		}
		return array( 'message' => count( $ids ) . ' missed posts published.', 'ids' => array_map( 'intval', $ids ) );
	}

	public static function get_option( $a ) {
		global $wpdb;
		if ( $s = SC_Tools::str( $a, 'search' ) ) {
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT 200", '%' . $wpdb->esc_like( $s ) . '%', '%transient%' ) ); // phpcs:ignore
			return array_values( array_filter( $names, function ( $n ) { return ! preg_match( SC_Tools_Dev::SECRET_OPTIONS, $n ); } ) );
		}
		$name = SC_Tools::str( $a, 'name' );
		if ( ! $name ) {
			SC_Tools::fail( 'Give name or search.' );
		}
		if ( preg_match( self::SECRET_OPTIONS, $name ) ) {
			SC_Tools::fail( 'This option may hold secrets and is not readable through Site Commander.' );
		}
		$v = get_option( $name, null );
		return array( 'name' => $name, 'exists' => null !== $v, 'value' => self::redact( $v ) );
	}

	private static function redact( $v ) {
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = ( is_string( $k ) && preg_match( self::SECRET_OPTIONS, $k ) ) ? '[hidden]' : self::redact( $x );
			}
		} elseif ( is_object( $v ) ) {
			return self::redact( (array) $v );
		}
		return $v;
	}

	public static function update_option( $a ) {
		$name    = (string) $a['name'];
		$blocked = array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'users_can_register', 'default_role', 'admin_email', 'db_version', 'cron', 'rewrite_rules', 'recently_activated', 'auto_core_update_notified' );
		if ( in_array( $name, $blocked, true ) || preg_match( self::SECRET_OPTIONS, $name ) || preg_match( '/user_roles$/', $name ) ) {
			SC_Tools::fail( "Option '$name' is protected. Use the dedicated tool (update_settings, activate_theme, toggle_plugin) if one exists." );
		}
		$value = $a['value'];
		$cur   = get_option( $name, null );
		if ( is_array( $value ) && is_array( $cur ) && SC_Tools::bool( $a, 'merge', true ) ) {
			$value = array_replace_recursive( $cur, $value );
		}
		update_option( $name, $value );
		self::purge_caches();
		return array( 'message' => "Option '$name' updated." );
	}

	/**
	 * Serialization-safe replace.
	 */
	public static function replace_deep( $data, $s, $r, &$count ) {
		if ( is_string( $data ) ) {
			if ( is_serialized( $data ) ) {
				$un = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore
				if ( false !== $un || 'b:0;' === $data ) {
					return serialize( self::replace_deep( $un, $s, $r, $count ) ); // phpcs:ignore
				}
			}
			$n = 0;
			$data = str_replace( $s, $r, $data, $n );
			$count += $n;
			return $data;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::replace_deep( $v, $s, $r, $count );
			}
		}
		return $data;
	}

	public static function db_replace( $a ) {
		global $wpdb;
		$s   = (string) $a['search'];
		$r   = (string) $a['replace'];
		$dry = SC_Tools::bool( $a, 'dry_run', true );
		if ( strlen( $s ) < 3 ) {
			SC_Tools::fail( 'Search text must be at least 3 characters.' );
		}
		$targets = array(
			array( $wpdb->posts, 'ID', array( 'post_content', 'post_title', 'post_excerpt' ) ),
			array( $wpdb->postmeta, 'meta_id', array( 'meta_value' ) ),
			array( $wpdb->options, 'option_id', array( 'option_value' ) ),
			array( $wpdb->comments, 'comment_ID', array( 'comment_content', 'comment_author_url' ) ),
			array( $wpdb->termmeta, 'meta_id', array( 'meta_value' ) ),
			array( $wpdb->term_taxonomy, 'term_taxonomy_id', array( 'description' ) ),
		);
		$report = array();
		$like   = '%' . $wpdb->esc_like( $s ) . '%';
		foreach ( $targets as $t ) {
			list( $table, $pk, $cols ) = $t;
			foreach ( $cols as $col ) {
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT `$pk` AS id, `$col` AS val FROM `$table` WHERE `$col` LIKE %s", $like ) ); // phpcs:ignore
				$total = 0;
				foreach ( $rows as $row ) {
					if ( $table === $wpdb->options && in_array( $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM `$table` WHERE option_id = %d", $row->id ) ), array( 'sc_activity_log' ), true ) ) { // phpcs:ignore
						continue;
					}
					$c   = 0;
					$new = self::replace_deep( $row->val, $s, $r, $c );
					if ( $c ) {
						$total += $c;
						if ( ! $dry ) {
							$wpdb->update( $table, array( $col => $new ), array( $pk => $row->id ) ); // phpcs:ignore
						}
					}
				}
				if ( $total ) {
					$report[ "$table.$col" ] = $total;
				}
			}
		}
		if ( ! $dry ) {
			wp_cache_flush();
			self::purge_caches();
		}
		return array( 'message' => ( $dry ? 'Dry run: would replace ' : 'Replaced ' ) . array_sum( $report ) . ' occurrences.', 'dry_run' => $dry, 'by_column' => $report );
	}

	public static function export( $a ) {
		$types = SC_Tools::arr( $a, 'post_types' );
		$posts = get_posts(
			array(
				'post_type'      => $types ? $types : array( 'post', 'page' ),
				'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
				'posts_per_page' => min( 500, SC_Tools::int( $a, 'limit', 50 ) ),
				'offset'         => SC_Tools::int( $a, 'offset', 0 ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = SC_Tools_Content::get_content( array( 'id' => $p->ID ) );
		}
		return array( 'count' => count( $out ), 'items' => $out );
	}
}
