<?php
/**
 * Site tools: overview, settings, plugins, page fetch, activity log.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_Site {

	/** Settings an AI may change, with sanitizers. */
	public static function editable_settings() {
		return array(
			'blogname'               => 'sanitize_text_field',
			'blogdescription'        => 'sanitize_text_field',
			'admin_email'            => 'sanitize_email',
			'timezone_string'        => 'sanitize_text_field',
			'date_format'            => 'sanitize_text_field',
			'time_format'            => 'sanitize_text_field',
			'start_of_week'          => 'absint',
			'WPLANG'                 => 'sanitize_text_field',
			'posts_per_page'         => 'absint',
			'posts_per_rss'          => 'absint',
			'rss_use_excerpt'        => 'absint',
			'show_on_front'          => 'sanitize_key',
			'page_on_front'          => 'absint',
			'page_for_posts'         => 'absint',
			'wp_page_for_privacy_policy' => 'absint',
			'blog_public'            => 'absint',
			'default_category'       => 'absint',
			'default_post_format'    => 'sanitize_key',
			'default_comment_status' => 'sanitize_key',
			'default_ping_status'    => 'sanitize_key',
			'comment_registration'   => 'absint',
			'comment_moderation'     => 'absint',
			'comments_notify'        => 'absint',
			'moderation_notify'      => 'absint',
			'close_comments_for_old_posts' => 'absint',
			'close_comments_days_old'=> 'absint',
			'thread_comments'        => 'absint',
			'page_comments'          => 'absint',
			'show_avatars'           => 'absint',
			'permalink_structure'    => 'sanitize_text_field',
			'category_base'          => 'sanitize_text_field',
			'tag_base'               => 'sanitize_text_field',
			'thumbnail_size_w'       => 'absint',
			'thumbnail_size_h'       => 'absint',
			'medium_size_w'          => 'absint',
			'medium_size_h'          => 'absint',
			'large_size_w'           => 'absint',
			'large_size_h'           => 'absint',
			'uploads_use_yearmonth_folders' => 'absint',
			'users_can_register'     => 'absint',
			'default_role'           => 'sanitize_key',
		);
	}

	public static function register() {
		$t = 'SC_Tools';

		$t::add( 'get_site_info', array(
			'group'       => 'site',
			'description' => 'Start here. Site name, URL, WordPress/PHP versions, timezone and current local time, active theme (block or classic), active plugins, SEO plugin, content counts, permalink and visibility settings, and which Site Commander permissions are on.',
			'callback'    => array( __CLASS__, 'site_info' ),
		) );

		$t::add( 'get_settings', array(
			'group'       => 'site',
			'description' => 'Read WordPress settings (General, Reading, Discussion, Media, Permalinks).',
			'callback'    => array( __CLASS__, 'get_settings' ),
		) );

		$t::add( 'update_settings', array(
			'group'       => 'site',
			'description' => 'Change WordPress settings: site title, tagline, timezone, homepage (show_on_front=page + page_on_front), posts page, search engine visibility (blog_public), permalinks, comments, image sizes, etc. Pass {"option": value}.',
			'properties'  => array( 'settings' => array( 'type' => 'object', 'description' => 'Options to set. Allowed names come from get_settings.' ) ),
			'required'    => array( 'settings' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'update_settings' ),
		) );

		$t::add( 'list_plugins', array(
			'group'       => 'site',
			'description' => 'List installed plugins with version, active state and available updates.',
			'callback'    => array( __CLASS__, 'list_plugins' ),
		) );

		$t::add( 'install_plugin', array(
			'group'       => 'site',
			'description' => 'Install a plugin from WordPress.org by slug (e.g. "contact-form-7") or from a .zip URL, optionally activating it.',
			'properties'  => array(
				'slug'     => $t::p( 'string', 'WordPress.org plugin slug.' ),
				'zip_url'  => $t::p( 'string', 'Or a .zip URL.' ),
				'activate' => $t::p( 'boolean', 'Activate after install. Default false.' ),
			),
			'write'       => true,
			'requires'    => 'allow_extensions',
			'callback'    => array( __CLASS__, 'install_plugin' ),
		) );

		$t::add( 'toggle_plugin', array(
			'group'       => 'site',
			'description' => 'Activate or deactivate an installed plugin.',
			'properties'  => array(
				'plugin' => $t::p( 'string', 'Plugin file from list_plugins, e.g. "akismet/akismet.php".' ),
				'active' => $t::p( 'boolean', 'true = activate, false = deactivate.' ),
			),
			'required'    => array( 'plugin', 'active' ),
			'write'       => true,
			'destructive' => true,
			'requires'    => 'allow_extensions',
			'callback'    => array( __CLASS__, 'toggle_plugin' ),
		) );

		$t::add( 'fetch_page', array(
			'group'       => 'site',
			'description' => 'Load a page of this site as visitors see it and report title, meta description, canonical, robots, headings, links, images, schema and load time. mode "html" returns the raw HTML (trimmed).',
			'properties'  => array(
				'url'  => $t::p( 'string', 'Full URL or path like "/about/". Default homepage.' ),
				'mode' => $t::p_enum( array( 'summary', 'text', 'html' ), 'Default summary.' ),
			),
			'callback'    => array( __CLASS__, 'fetch_page' ),
		) );

		$t::add( 'get_activity_log', array(
			'group'       => 'site',
			'description' => 'Recent changes made through Site Commander, by any AI.',
			'properties'  => array( 'limit' => $t::p( 'integer', 'Default 30.' ) ),
			'callback'    => function ( $a ) {
				return SC_Log::get( SC_Tools::int( $a, 'limit', 30 ) );
			},
		) );
	}

	public static function seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			return 'seo-framework';
		}
		return null;
	}

	public static function site_info() {
		global $wp_version;
		$theme  = wp_get_theme();
		$counts = array();
		foreach ( array( 'post', 'page' ) as $pt ) {
			$c             = wp_count_posts( $pt );
			$counts[ $pt ] = array( 'publish' => (int) $c->publish, 'draft' => (int) $c->draft, 'future' => (int) $c->future );
		}
		$counts['media'] = (int) array_sum( (array) wp_count_attachments() );
		$s       = SC_Plugin::settings();
		$perms   = array();
		foreach ( array( 'allow_theme_files', 'allow_php_edits', 'allow_extensions', 'allow_permanent_delete', 'allow_users', 'allow_code', 'allow_database', 'allow_updates' ) as $k ) {
			$perms[ $k ] = (bool) $s[ $k ];
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = array();
		$all    = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$active[] = isset( $all[ $file ] ) ? $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'] : $file;
		}
		return array(
			'name'              => get_bloginfo( 'name' ),
			'tagline'           => get_bloginfo( 'description' ),
			'url'               => home_url(),
			'admin_url'         => admin_url(),
			'wordpress'         => $wp_version,
			'php'               => PHP_VERSION,
			'language'          => get_locale(),
			'timezone'          => wp_timezone_string(),
			'local_time_now'    => current_time( 'Y-m-d H:i' ),
			'multisite'         => is_multisite(),
			'theme'             => array(
				'name'        => $theme->get( 'Name' ),
				'stylesheet'  => get_stylesheet(),
				'parent'      => $theme->parent() ? get_template() : null,
				'block_theme' => wp_is_block_theme(),
			),
			'active_plugins'    => $active,
			'seo_plugin'        => self::seo_plugin(),
			'woocommerce'       => class_exists( 'WooCommerce' ),
			'counts'            => $counts,
			'permalinks'        => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'plain (not SEO friendly)',
			'search_engines'    => get_option( 'blog_public' ) ? 'allowed' : 'DISCOURAGED (site hidden from Google)',
			'homepage'          => 'page' === get_option( 'show_on_front' ) ? array( 'static_page' => (int) get_option( 'page_on_front' ), 'posts_page' => (int) get_option( 'page_for_posts' ) ) : 'latest posts',
			'maintenance_mode'  => (bool) $s['maintenance_mode'],
			'permissions'       => $perms,
			'your_connection'   => SC_Auth::$current ? array( 'name' => SC_Auth::$current['name'], 'groups' => SC_Auth::$current['groups'], 'read_only' => (bool) SC_Auth::$current['read_only'] ) : null,
			'site_commander'    => SC_VERSION,
		);
	}

	public static function get_settings() {
		$out = array();
		foreach ( array_keys( self::editable_settings() ) as $k ) {
			$out[ $k ] = get_option( $k );
		}
		return $out;
	}

	public static function update_settings( $a ) {
		$set     = SC_Tools::arr( $a, 'settings' );
		$allowed = self::editable_settings();
		$done    = array();
		$flush   = false;
		foreach ( (array) $set as $k => $v ) {
			if ( ! isset( $allowed[ $k ] ) ) {
				SC_Tools::fail( "Setting '$k' cannot be changed here. Allowed: " . implode( ', ', array_keys( $allowed ) ) );
			}
			$v = call_user_func( $allowed[ $k ], $v );
			if ( 'timezone_string' === $k && $v && ! in_array( $v, timezone_identifiers_list(), true ) ) {
				SC_Tools::fail( "Unknown timezone '$v'. Use e.g. Europe/Zurich." );
			}
			if ( 'admin_email' === $k && ! is_email( $v ) ) {
				SC_Tools::fail( 'Invalid email.' );
			}
			if ( in_array( $k, array( 'permalink_structure', 'category_base', 'tag_base' ), true ) ) {
				$flush = true;
			}
			update_option( $k, $v );
			$done[ $k ] = get_option( $k );
		}
		if ( $flush ) {
			global $wp_rewrite;
			$wp_rewrite->init();
			flush_rewrite_rules( false );
		}
		return array( 'message' => 'Updated ' . implode( ', ', array_keys( $done ) ) . '.', 'settings' => $done );
	}

	public static function list_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$updates = get_site_transient( 'update_plugins' );
		$out     = array();
		foreach ( get_plugins() as $file => $p ) {
			$out[] = array(
				'plugin'  => $file,
				'name'    => $p['Name'],
				'version' => $p['Version'],
				'active'  => is_plugin_active( $file ),
				'update'  => isset( $updates->response[ $file ] ) ? $updates->response[ $file ]->new_version : null,
			);
		}
		return $out;
	}

	public static function install_plugin( $a ) {
		SC_Tools_Design::upgrader_includes();
		$src = SC_Tools::str( $a, 'zip_url' );
		if ( ! $src ) {
			$slug = SC_Tools::str( $a, 'slug' );
			if ( ! $slug ) {
				SC_Tools::fail( 'Give slug or zip_url.' );
			}
			$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				SC_Tools::fail( "Plugin '$slug' not found on WordPress.org." );
			}
			$src = $api->download_link;
		}
		$skin = new WP_Ajax_Upgrader_Skin();
		$up   = new Plugin_Upgrader( $skin );
		$res  = $up->install( $src );
		if ( is_wp_error( $res ) || ! $res ) {
			$err = is_wp_error( $res ) ? $res : $skin->get_errors();
			SC_Tools::fail( 'Install failed: ' . ( is_wp_error( $err ) && $err->has_errors() ? $err->get_error_message() : 'it may already be installed' ) );
		}
		$file = $up->plugin_info();
		$msg  = "Installed $file.";
		if ( SC_Tools::bool( $a, 'activate' ) && $file ) {
			$act = activate_plugin( $file );
			$msg .= is_wp_error( $act ) ? ' Activation failed: ' . $act->get_error_message() : ' Activated.';
		}
		return array( 'message' => $msg, 'plugin' => $file );
	}

	public static function toggle_plugin( $a ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$file = (string) $a['plugin'];
		if ( plugin_basename( SC_FILE ) === $file ) {
			SC_Tools::fail( 'Site Commander cannot switch itself off.' );
		}
		if ( ! array_key_exists( $file, get_plugins() ) ) {
			SC_Tools::fail( "Plugin '$file' is not installed." );
		}
		if ( SC_Tools::bool( $a, 'active' ) ) {
			$res = activate_plugin( $file );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$check = SC_Tools_Dev::site_responds();
			if ( ! $check['ok'] ) {
				deactivate_plugins( $file, true );
				SC_Tools::fail( "Activating $file broke the site ({$check['detail']}), so it was switched off again." );
			}
			return array( 'message' => "Activated $file." );
		}
		deactivate_plugins( $file );
		return array( 'message' => "Deactivated $file." );
	}

	public static function fetch_page( $a ) {
		$url = SC_Tools::str( $a, 'url', home_url( '/' ) );
		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $home ) {
			SC_Tools::fail( 'Only pages of this site can be fetched.' );
		}
		$start = microtime( true );
		$res   = wp_remote_get( add_query_arg( 'sc_nocache', time(), $url ), array( 'timeout' => 30, 'redirection' => 3, 'sslverify' => false, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		if ( is_wp_error( $res ) ) {
			SC_Tools::fail( 'Could not load the page: ' . $res->get_error_message() );
		}
		$ms   = round( ( microtime( true ) - $start ) * 1000 );
		$html = wp_remote_retrieve_body( $res );
		$code = wp_remote_retrieve_response_code( $res );
		$mode = SC_Tools::str( $a, 'mode', 'summary' );
		if ( 'html' === $mode ) {
			return array( 'status' => $code, 'ms' => $ms, 'html' => substr( $html, 0, 150000 ) );
		}
		if ( 'text' === $mode ) {
			$body = preg_replace( '#<(script|style|noscript)[^>]*>.*?</\1>#si', ' ', $html );
			return array( 'status' => $code, 'text' => substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) ), 0, 40000 ) );
		}
		return array_merge( array( 'url' => $url, 'status' => $code, 'load_ms' => $ms, 'html_kb' => round( strlen( $html ) / 1024 ) ), self::analyze_html( $html, $url ) );
	}

	/**
	 * Pull SEO-relevant facts out of a rendered page.
	 */
	public static function analyze_html( $html, $url = '' ) {
		$out = array();
		$m   = array();
		$out['title']            = preg_match( '#<title[^>]*>(.*?)</title>#si', $html, $m ) ? html_entity_decode( trim( $m[1] ) ) : null;
		$out['meta_description'] = preg_match( '#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)#i', $html, $m ) ? html_entity_decode( $m[1] ) : null;
		$out['canonical']        = preg_match( '#<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)#i', $html, $m ) ? $m[1] : null;
		$out['robots']           = preg_match( '#<meta[^>]+name=["\']robots["\'][^>]*content=["\']([^"\']*)#i', $html, $m ) ? $m[1] : null;
		$out['og_image']         = preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]*content=["\']([^"\']*)#i', $html, $m ) ? $m[1] : null;
		$out['lang']             = preg_match( '#<html[^>]+lang=["\']([^"\']+)#i', $html, $m ) ? $m[1] : null;
		$out['viewport']         = (bool) preg_match( '#<meta[^>]+name=["\']viewport#i', $html );
		$heads                   = array();
		if ( preg_match_all( '#<(h[1-3])[^>]*>(.*?)</\1>#si', $html, $hm, PREG_SET_ORDER ) ) {
			foreach ( array_slice( $hm, 0, 40 ) as $h ) {
				$heads[] = strtoupper( $h[1] ) . ': ' . trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $h[2] ) ) );
			}
		}
		$out['headings']      = $heads;
		$out['h1_count']      = preg_match_all( '#<h1[\s>]#i', $html );
		$img_total            = preg_match_all( '#<img\b[^>]*>#i', $html, $imgs );
		$no_alt               = 0;
		foreach ( $imgs[0] as $img ) {
			if ( ! preg_match( '#\balt=["\'][^"\']+["\']#i', $img ) ) {
				$no_alt++;
			}
		}
		$out['images']        = array( 'total' => $img_total, 'missing_alt' => $no_alt );
		$host                 = wp_parse_url( home_url(), PHP_URL_HOST );
		$int                  = 0;
		$ext                  = 0;
		if ( preg_match_all( '#<a\b[^>]*href=["\']([^"\'\#]+)#i', $html, $lm ) ) {
			foreach ( $lm[1] as $href ) {
				$h = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $h || $h === $host ) {
					$int++;
				} else {
					$ext++;
				}
			}
		}
		$out['links']         = array( 'internal' => $int, 'external' => $ext );
		$types                = array();
		if ( preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#si', $html, $sm ) ) {
			foreach ( $sm[1] as $json ) {
				if ( preg_match_all( '#"@type"\s*:\s*"([^"]+)"#', $json, $tm ) ) {
					$types = array_merge( $types, $tm[1] );
				}
			}
		}
		$out['schema_types']  = array_values( array_unique( $types ) );
		$text                 = wp_strip_all_tags( preg_replace( '#<(script|style)[^>]*>.*?</\1>#si', ' ', $html ) );
		$out['word_count']    = str_word_count( $text );
		return $out;
	}
}
