<?php
/**
 * Plugin bootstrap and settings.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Plugin {

	const OPTION = 'sc_settings';

	/**
	 * Default settings. Risky abilities are off until the site owner turns them on.
	 */
	public static function defaults() {
		return array(
			'enabled'                => 1,
			'run_as_user'            => 0,
			'allow_theme_files'      => 1, // CSS / JS / HTML / JSON files in themes.
			'allow_php_edits'        => 0, // PHP theme files (syntax-checked, backed up).
			'allow_extensions'       => 0, // Install / activate / deactivate plugins and install themes.
			'allow_permanent_delete' => 0, // Skip trash, delete media files.
			'allow_users'            => 0, // Create users, change roles.
			'allow_code'             => 0, // Header/footer code snippets (analytics, pixels, custom JS).
			'allow_database'         => 0, // Database-wide search & replace.
			'allow_updates'          => 1, // Update WordPress core, plugins, themes, translations.
			'seo_output'             => 'auto', // auto | on | off.
			'log_404'                => 1,
			'maintenance_mode'       => 0,
			// Self-update from GitHub releases.
			'update_repo'            => '', // Optional override (owner/repo); empty = built-in SC_UPDATE_REPO.
			'update_token'           => '', // Only for private repos.
			'auto_update_self'       => 1,
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function setting( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	public static function update_settings( array $changes ) {
		$s = array_merge( self::settings(), $changes );
		update_option( self::OPTION, $s, false );
		return $s;
	}

	public static function init() {
		add_action( 'rest_api_init', array( 'SC_MCP_Server', 'register_routes' ) );
		SC_SEO_Frontend::init();
		SC_Updater::init();
		if ( is_admin() ) {
			SC_Admin::init();
		}
	}

	public static function activate() {
		$s = self::settings();
		if ( empty( $s['run_as_user'] ) ) {
			$s['run_as_user'] = get_current_user_id();
		}
		update_option( self::OPTION, $s, false );
		self::ensure_backup_dir();
	}

	public static function deactivate() {
		// Nothing destructive: keys and settings stay so reactivation just works.
	}

	/**
	 * Directory for theme-file backups, protected from direct web access.
	 */
	public static function backup_dir() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . 'site-commander-backups';
	}

	public static function ensure_backup_dir() {
		$dir = self::backup_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore
		}
		return $dir;
	}
}
