<?php
/**
 * Self-updates from GitHub releases.
 *
 * Push a tag like v1.2.0 with a release that has site-commander.zip attached
 * (the included GitHub Action does this), and every site running the plugin
 * sees the update — and installs it automatically if auto-update is on.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Updater {

	const CACHE = 'sc_update_release';
	const SLUG  = 'site-commander';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_download' ), 10, 2 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action( 'sc_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	/**
	 * The repository updates come from: the built-in official repo, unless a site
	 * sets its own (e.g. a fork) in settings. Define SC_DISABLE_SELF_UPDATE in
	 * wp-config.php to switch self-updates off entirely.
	 */
	public static function repo() {
		if ( defined( 'SC_DISABLE_SELF_UPDATE' ) && SC_DISABLE_SELF_UPDATE ) {
			return '';
		}
		$repo = trim( (string) SC_Plugin::setting( 'update_repo' ) );
		if ( '' === $repo ) {
			$repo = SC_UPDATE_REPO;
		}
		$repo = preg_replace( '#^https?://github\.com/#i', '', $repo );
		$repo = trim( preg_replace( '#\.git$#', '', $repo ), '/' );
		return preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ? $repo : '';
	}

	/**
	 * Latest release info, cached 6 hours.
	 */
	public static function latest( $force = false ) {
		$repo = self::repo();
		if ( ! $repo ) {
			return null;
		}
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) && isset( $cached['repo'] ) && $cached['repo'] === $repo ) {
			return $cached;
		}
		$headers = array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Site-Commander/' . SC_VERSION );
		$token   = SC_Plugin::setting( 'update_token' );
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$res = wp_remote_get( "https://api.github.com/repos/$repo/releases/latest", array( 'timeout' => 15, 'headers' => $headers ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			$info = array( 'repo' => $repo, 'error' => is_wp_error( $res ) ? $res->get_error_message() : 'GitHub returned HTTP ' . wp_remote_retrieve_response_code( $res ) );
			set_site_transient( self::CACHE, $info, HOUR_IN_SECONDS );
			return $info;
		}
		$rel     = json_decode( wp_remote_retrieve_body( $res ), true );
		$package = '';
		foreach ( (array) ( isset( $rel['assets'] ) ? $rel['assets'] : array() ) as $asset ) {
			if ( preg_match( '/\.zip$/i', $asset['name'] ) ) {
				// Private repos need the API asset URL (downloaded with the token); public ones the browser URL.
				$package = $token ? $asset['url'] : $asset['browser_download_url'];
				break;
			}
		}
		if ( ! $package && ! empty( $rel['zipball_url'] ) ) {
			$package = $rel['zipball_url'];
		}
		$info = array(
			'repo'      => $repo,
			'version'   => ltrim( (string) $rel['tag_name'], 'vV' ),
			'package'   => $package,
			'notes'     => isset( $rel['body'] ) ? (string) $rel['body'] : '',
			'url'       => isset( $rel['html_url'] ) ? $rel['html_url'] : "https://github.com/$repo",
			'published' => isset( $rel['published_at'] ) ? $rel['published_at'] : '',
		);
		set_site_transient( self::CACHE, $info, 6 * HOUR_IN_SECONDS );
		return $info;
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$rel = self::latest();
		if ( ! $rel || empty( $rel['version'] ) || empty( $rel['package'] ) ) {
			return $transient;
		}
		$file = plugin_basename( SC_FILE );
		$item = (object) array(
			'id'           => $file,
			'slug'         => self::SLUG,
			'plugin'       => $file,
			'new_version'  => $rel['version'],
			'url'          => $rel['url'],
			'package'      => $rel['package'],
			'requires'     => '6.2',
			'requires_php' => '7.4',
			'icons'        => array(),
		);
		if ( version_compare( $rel['version'], SC_VERSION, '>' ) ) {
			$transient->response[ $file ] = $item;
		} else {
			$transient->no_update[ $file ] = $item;
		}
		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$rel = self::latest();
		if ( ! $rel || empty( $rel['version'] ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Site Commander – Universal AI Connector',
			'slug'          => self::SLUG,
			'version'       => $rel['version'],
			'author'        => 'Site Commander',
			'homepage'      => $rel['url'],
			'download_link' => $rel['package'],
			'requires'      => '6.2',
			'requires_php'  => '7.4',
			'last_updated'  => $rel['published'],
			'sections'      => array(
				'description' => 'Let any AI manage this WordPress site over MCP, REST or OpenAPI.',
				'changelog'   => wpautop( esc_html( $rel['notes'] ? $rel['notes'] : 'See the release on GitHub.' ) ),
			),
		);
	}

	/**
	 * GitHub zipballs unpack to "owner-repo-hash/"; rename to "site-commander/" so the plugin stays active.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( SC_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		// A zip whose root holds the plugin file directly or one folder of any name.
		if ( $wp_filesystem->exists( trailingslashit( $source ) . 'site-commander.php' ) && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return $source;
	}

	public static function auth_download( $args, $url ) {
		$token = SC_Plugin::setting( 'update_token' );
		$repo  = self::repo();
		if ( $token && $repo && ( 0 === strpos( $url, "https://api.github.com/repos/$repo/" ) ) ) {
			$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
			$args['headers']['Authorization'] = 'Bearer ' . $token;
			if ( false !== strpos( $url, '/releases/assets/' ) ) {
				$args['headers']['Accept'] = 'application/octet-stream';
			}
		}
		return $args;
	}

	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && plugin_basename( SC_FILE ) === $item->plugin && self::repo() ) {
			return (bool) SC_Plugin::setting( 'auto_update_self' );
		}
		return $update;
	}

	/**
	 * Update right now (used by the admin button and the self_update tool).
	 */
	public static function update_now() {
		delete_site_transient( self::CACHE );
		$rel = self::latest( true );
		if ( ! $rel ) {
			return array( 'ok' => false, 'message' => 'Self-updates are switched off on this site (SC_DISABLE_SELF_UPDATE).' );
		}
		if ( ! empty( $rel['error'] ) ) {
			return array( 'ok' => false, 'message' => 'Could not check GitHub: ' . $rel['error'] );
		}
		if ( ! version_compare( $rel['version'], SC_VERSION, '>' ) ) {
			return array( 'ok' => true, 'message' => 'Already on the latest version (' . SC_VERSION . ').' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$up  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$res = $up->upgrade( plugin_basename( SC_FILE ) );
		if ( is_wp_error( $res ) || ! $res ) {
			return array( 'ok' => false, 'message' => 'Update failed' . ( is_wp_error( $res ) ? ': ' . $res->get_error_message() : '.' ) );
		}
		if ( ! is_plugin_active( plugin_basename( SC_FILE ) ) ) {
			activate_plugin( plugin_basename( SC_FILE ) );
		}
		return array( 'ok' => true, 'message' => 'Updated Site Commander from ' . SC_VERSION . ' to ' . $rel['version'] . '.' );
	}

	public static function register_tools() {
		SC_Tools::add(
			'self_update',
			array(
				'group'       => 'dev',
				'description' => 'Check GitHub for a newer Site Commander release and install it.',
				'properties'  => array( 'check_only' => SC_Tools::p( 'boolean', 'Only report versions. Default false.' ) ),
				'write'       => true,
				'callback'    => function ( $a ) {
					if ( SC_Tools::bool( $a, 'check_only' ) ) {
						$rel = SC_Updater::latest( true );
						return array( 'installed' => SC_VERSION, 'latest' => $rel ? $rel : 'self-updates switched off' );
					}
					$r = SC_Updater::update_now();
					if ( ! $r['ok'] ) {
						SC_Tools::fail( $r['message'] );
					}
					return $r;
				},
			)
		);
	}
}
