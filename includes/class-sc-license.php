<?php
/**
 * Licensing (Freemius) and Free-plan limits.
 *
 * Free:  1 AI connection, up to 10 posts and 2 pages created through the plugin,
 *        core content/media/basic SEO tools.
 * Pro:   everything, unlimited. A 7-day Pro trial (no card) is available.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'sc_fs' ) ) {
	/**
	 * Freemius SDK instance.
	 */
	function sc_fs() {
		global $sc_fs;
		if ( ! isset( $sc_fs ) ) {
			require_once SC_DIR . 'includes/freemius/start.php';
			$sc_fs = fs_dynamic_init(
				array(
					'id'             => '42738',
					'slug'           => 'site-commander',
					'type'           => 'plugin',
					'public_key'     => 'pk_7ea9f4d3f1d06c83cd1b96b626804',
					'is_premium'     => true,
					'has_addons'     => false,
					'has_paid_plans' => true,
					'trial'          => array(
						'days'               => 7,
						'is_require_payment' => false,
					),
					'menu'           => array(
						'slug'    => 'site-commander',
						'support' => false,
					),
				)
			);
		}
		return $sc_fs;
	}

	/**
	 * Removes Site Commander's settings when the plugin is deleted.
	 * SEO titles/descriptions saved on posts are kept, because they are site content.
	 * The Free-plan creation counter (sc_created_counts) is kept so a reinstall does not reset it.
	 */
	function sc_fs_uninstall_cleanup() {
		foreach ( array( 'sc_settings', 'sc_keys', 'sc_key_hash', 'sc_key_hint', 'sc_activity_log', 'sc_404_log', 'sc_redirects', 'sc_robots_extra', 'sc_home_seo', 'sc_snippets', 'sc_maintenance_message' ) as $opt ) {
			delete_option( $opt );
		}
		delete_site_transient( 'sc_update_release' );
	}

	sc_fs()->add_action( 'after_uninstall', 'sc_fs_uninstall_cleanup' );
	do_action( 'sc_fs_loaded' );
}

class SC_License {

	const FREE_POSTS = 10;
	const FREE_PAGES = 2;
	const FREE_KEYS  = 1;
	const COUNTS     = 'sc_created_counts';

	/** Tools that need Pro. Everything else is available on Free. */
	public static function pro_tools() {
		return array(
			// Content.
			'bulk_update_status', 'search_replace_content', 'restore_revision',
			'get_shortcode_layout', 'update_shortcode_node', 'divi_clear_cache', 'get_elementor_data', 'update_elementor_element', 'elementor_find_replace', 'elementor_clear_cache',
			// Design.
			'install_theme', 'delete_theme', 'activate_theme', 'create_child_theme',
			'write_theme_file', 'restore_file_backup', 'update_template', 'reset_template',
			'update_global_styles', 'save_menu', 'save_menu_item', 'delete_menu_item',
			'add_block_widget', 'remove_widget',
			// Site.
			'install_plugin', 'toggle_plugin', 'delete_plugin', 'update_settings', 'get_activity_log',
			// SEO (basic get/set stays free).
			'seo_audit', 'bulk_set_seo', 'fix_content_image_alts', 'find_internal_link_opportunities',
			'find_broken_links', 'list_redirects', 'add_redirect', 'delete_redirect', 'list_404s', 'set_robots_txt',
			// People and commerce.
			'save_user', 'moderate_comments', 'reply_to_comment', 'list_products', 'save_product', 'list_orders', 'update_order_status',
			// Dev.
			'run_updates', 'maintenance_mode', 'save_code_snippet', 'delete_code_snippet', 'update_option',
			'database_search_replace', 'run_cron_event', 'read_debug_log', 'export_content', 'self_update',
		);
	}

	public static function is_pro() {
		if ( ! function_exists( 'sc_fs' ) || ! sc_fs() ) {
			return false;
		}
		return sc_fs()->is_paying() || sc_fs()->is_trial();
	}

	public static function is_pro_tool( $name ) {
		return in_array( $name, self::pro_tools(), true );
	}

	public static function upgrade_url() {
		return function_exists( 'sc_fs' ) && sc_fs() ? sc_fs()->get_upgrade_url() : '';
	}

	public static function upgrade_note() {
		$url = self::upgrade_url();
		return 'Upgrade to Site Commander Pro' . ( $url ? ': ' . $url : ' from the Site Commander menu in WordPress' ) . ' (7-day free trial, no card needed).';
	}

	/** Message when a Pro tool is called on Free, or null if allowed. */
	public static function tool_block( $name ) {
		if ( self::is_pro() || ! self::is_pro_tool( $name ) ) {
			return null;
		}
		return "'$name' is a Pro feature. " . self::upgrade_note();
	}

	public static function counts() {
		$c = get_option( self::COUNTS, array() );
		$c = is_array( $c ) ? $c : array();
		return array(
			'post' => isset( $c['post'] ) ? (int) $c['post'] : 0,
			'page' => isset( $c['page'] ) ? (int) $c['page'] : 0,
		);
	}

	/** Throw (via SC_Tools::fail) if creating this post type would pass the Free limit. */
	public static function check_create( $type ) {
		if ( self::is_pro() ) {
			return;
		}
		if ( 'post' !== $type && 'page' !== $type ) {
			SC_Tools::fail( "Creating '$type' content is a Pro feature on the Free plan (posts and pages only). " . self::upgrade_note() );
		}
		$counts = self::counts();
		$limit  = 'page' === $type ? self::FREE_PAGES : self::FREE_POSTS;
		if ( $counts[ $type ] >= $limit ) {
			SC_Tools::fail( sprintf( 'Free plan limit reached (%d/%d %ss created). %s', $counts[ $type ], $limit, $type, self::upgrade_note() ) );
		}
	}

	public static function record_create( $type ) {
		if ( 'post' !== $type && 'page' !== $type ) {
			return;
		}
		$c          = self::counts();
		$c[ $type ] = $c[ $type ] + 1;
		update_option( self::COUNTS, $c, false );
	}

	public static function can_add_key() {
		return self::is_pro() || count( SC_Auth::keys() ) < self::FREE_KEYS;
	}

	public static function trial_days_left() {
		if ( ! function_exists( 'sc_fs' ) || ! sc_fs() || ! sc_fs()->is_trial() ) {
			return 0;
		}
		$plan = sc_fs()->get_site();
		if ( ! $plan || empty( $plan->trial_ends ) ) {
			return 0;
		}
		return max( 0, (int) ceil( ( strtotime( $plan->trial_ends . ' UTC' ) - time() ) / DAY_IN_SECONDS ) );
	}
}
