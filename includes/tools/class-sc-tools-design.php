<?php
/**
 * Design tools: themes, CSS, Customizer, global styles, block templates, theme files, menus, widgets.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_Design {

	const TEXT_EXT = array( 'css', 'scss', 'js', 'mjs', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'xml' );

	public static function register() {
		$t = 'SC_Tools';

		$t::add( 'list_themes', array(
			'group'       => 'design',
			'description' => 'List installed themes, which one is active, and whether each is a block (FSE) theme.',
			'callback'    => array( __CLASS__, 'list_themes' ),
		) );

		$t::add( 'activate_theme', array(
			'group'       => 'design',
			'description' => 'Switch the active theme. This changes the whole site design — confirm with the user first.',
			'properties'  => array( 'stylesheet' => $t::p( 'string', 'Theme folder name from list_themes.' ) ),
			'required'    => array( 'stylesheet' ),
			'write'       => true,
			'destructive' => true,
			'callback'    => array( __CLASS__, 'activate_theme' ),
		) );

		$t::add( 'install_theme', array(
			'group'       => 'design',
			'description' => 'Install a theme from WordPress.org by slug (e.g. "astra", "kadence", "twentytwentyfive") or from a .zip URL. Does not activate it.',
			'properties'  => array(
				'slug'    => $t::p( 'string', 'WordPress.org theme slug.' ),
				'zip_url' => $t::p( 'string', 'Or a URL to a theme .zip.' ),
			),
			'write'       => true,
			'requires'    => 'allow_extensions',
			'callback'    => array( __CLASS__, 'install_theme' ),
		) );

		$t::add( 'create_child_theme', array(
			'group'       => 'design',
			'description' => 'Create a child theme of the active (or given) theme so code edits survive theme updates. Optionally activate it (theme settings are copied).',
			'properties'  => array(
				'parent'   => $t::p( 'string', 'Parent theme folder. Default: active theme.' ),
				'name'     => $t::p( 'string', 'Child theme name.' ),
				'activate' => $t::p( 'boolean', 'Activate it now. Default false.' ),
			),
			'write'       => true,
			'requires'    => 'allow_theme_files',
			'callback'    => array( __CLASS__, 'create_child_theme' ),
		) );

		$t::add( 'get_custom_css', array(
			'group'       => 'design',
			'description' => 'Read the site\'s Additional CSS (Customizer / Site Editor custom CSS) for the active theme.',
			'callback'    => array( __CLASS__, 'get_custom_css' ),
		) );

		$t::add( 'set_custom_css', array(
			'group'       => 'design',
			'description' => 'Change the site design with CSS. Saved as Additional CSS (survives theme updates, keeps revisions). mode "append" adds to existing CSS, "replace" overwrites it.',
			'properties'  => array(
				'css'  => $t::p( 'string', 'CSS code.' ),
				'mode' => $t::p_enum( array( 'append', 'replace' ), 'Default append.' ),
			),
			'required'    => array( 'css' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'set_custom_css' ),
		) );

		$t::add( 'get_theme_mods', array(
			'group'       => 'design',
			'description' => 'Read Customizer settings (theme mods) of the active theme: colors, header, logo, layout options the theme provides.',
			'callback'    => array( __CLASS__, 'get_theme_mods' ),
		) );

		$t::add( 'set_theme_mods', array(
			'group'       => 'design',
			'description' => 'Change Customizer settings (theme mods) for classic themes like Astra, GeneratePress, Kadence, OceanWP. Pass {"mod_name": value}; null removes a mod. Use get_theme_mods to see names.',
			'properties'  => array( 'mods' => array( 'type' => 'object', 'description' => 'Mods to set.' ) ),
			'required'    => array( 'mods' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'set_theme_mods' ),
		) );

		$t::add( 'set_site_identity', array(
			'group'       => 'design',
			'description' => 'Set the site logo and/or site icon (favicon) from a media ID or image URL.',
			'properties'  => array(
				'logo' => array( 'type' => array( 'integer', 'string' ), 'description' => 'Attachment ID or image URL. 0 removes.' ),
				'icon' => array( 'type' => array( 'integer', 'string' ), 'description' => 'Square image (512px+): attachment ID or URL. 0 removes.' ),
			),
			'write'       => true,
			'callback'    => array( __CLASS__, 'set_site_identity' ),
		) );

		$t::add( 'get_global_styles', array(
			'group'       => 'design',
			'description' => 'Block themes: read theme.json design settings — color palette, font families/sizes, spacing, layout widths — plus the user\'s saved style overrides.',
			'properties'  => array( 'include_theme_defaults' => $t::p( 'boolean', 'Also return the full merged settings/styles. Default false (only palette, fonts and user overrides).' ) ),
			'callback'    => array( __CLASS__, 'get_global_styles' ),
		) );

		$t::add( 'update_global_styles', array(
			'group'       => 'design',
			'description' => 'Block themes: change site-wide design (same as Appearance > Editor > Styles). Pass theme.json-shaped "styles" and/or "settings"; deep-merged into the saved overrides. Example: {"styles":{"color":{"background":"#fff","text":"#111"},"elements":{"link":{"color":{"text":"#0a58ca"}}},"typography":{"fontFamily":"var(--wp--preset--font-family--inter)"}}}. reset=true clears all overrides.',
			'properties'  => array(
				'styles'   => array( 'type' => 'object', 'description' => 'theme.json "styles" overrides.' ),
				'settings' => array( 'type' => 'object', 'description' => 'theme.json "settings" overrides (e.g. a new color palette).' ),
				'reset'    => $t::p( 'boolean', 'Remove all user style overrides first.' ),
			),
			'write'       => true,
			'callback'    => array( __CLASS__, 'update_global_styles' ),
		) );

		$t::add( 'list_templates', array(
			'group'       => 'design',
			'description' => 'Block themes: list templates (home, single, page, archive, 404…) or template parts (header, footer).',
			'properties'  => array( 'type' => $t::p_enum( array( 'wp_template', 'wp_template_part' ), 'Default wp_template.' ) ),
			'callback'    => array( __CLASS__, 'list_templates' ),
		) );

		$t::add( 'get_template', array(
			'group'       => 'design',
			'description' => 'Block themes: get a template or template part\'s block markup, e.g. id "twentytwentyfive//header".',
			'properties'  => array(
				'id'   => $t::p( 'string', 'Template ID "theme//slug".' ),
				'type' => $t::p_enum( array( 'wp_template', 'wp_template_part' ), 'Default: detected.' ),
			),
			'required'    => array( 'id' ),
			'callback'    => array( __CLASS__, 'get_template' ),
		) );

		$t::add( 'update_template', array(
			'group'       => 'design',
			'description' => 'Block themes: change a template or template part (header, footer, single post layout…) with new block markup. Saved as a customization; reset_template restores the theme original.',
			'properties'  => array(
				'id'      => $t::p( 'string', 'Template ID "theme//slug". A new slug creates a new template.' ),
				'type'    => $t::p_enum( array( 'wp_template', 'wp_template_part' ), 'Default wp_template, or detected.' ),
				'content' => $t::p( 'string', 'Full block markup.' ),
				'title'   => $t::p( 'string', 'Title (for new templates).' ),
				'area'    => $t::p_enum( array( 'header', 'footer', 'sidebar', 'uncategorized' ), 'Template part area (for new parts).' ),
			),
			'required'    => array( 'id', 'content' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'update_template' ),
		) );

		$t::add( 'reset_template', array(
			'group'       => 'design',
			'description' => 'Block themes: remove customizations of a template/part so the theme\'s original is used again.',
			'properties'  => array(
				'id'   => $t::p( 'string', 'Template ID "theme//slug".' ),
				'type' => $t::p_enum( array( 'wp_template', 'wp_template_part' ), 'Default: detected.' ),
			),
			'required'    => array( 'id' ),
			'write'       => true,
			'destructive' => true,
			'callback'    => array( __CLASS__, 'reset_template' ),
		) );

		$t::add( 'list_block_patterns', array(
			'group'       => 'design',
			'description' => 'List registered block patterns (ready-made sections) and synced patterns. Use pattern content inside post/template markup.',
			'properties'  => array(
				'search'          => $t::p( 'string', 'Filter by title/category.' ),
				'include_content' => $t::p( 'boolean', 'Include markup. Default false.' ),
			),
			'callback'    => array( __CLASS__, 'list_block_patterns' ),
		) );

		$t::add( 'list_theme_files', array(
			'group'       => 'design',
			'description' => 'List files in a theme folder (default: active theme).',
			'properties'  => array(
				'theme' => $t::p( 'string', 'Theme folder. Default active theme.' ),
				'path'  => $t::p( 'string', 'Sub-folder, e.g. "templates" or "assets/css".' ),
			),
			'callback'    => array( __CLASS__, 'list_theme_files' ),
		) );

		$t::add( 'read_theme_file', array(
			'group'       => 'design',
			'description' => 'Read a theme file (style.css, functions.php, theme.json, templates/*.html, etc.).',
			'properties'  => array(
				'theme' => $t::p( 'string', 'Theme folder. Default active theme.' ),
				'path'  => $t::p( 'string', 'Path inside the theme, e.g. "style.css".' ),
			),
			'required'    => array( 'path' ),
			'callback'    => array( __CLASS__, 'read_theme_file' ),
		) );

		$t::add( 'write_theme_file', array(
			'group'       => 'design',
			'description' => 'Create or overwrite a theme file. A backup is saved first. PHP files are syntax-checked and need "Allow editing PHP theme files". Prefer a child theme: parent theme edits are lost on update.',
			'properties'  => array(
				'theme'   => $t::p( 'string', 'Theme folder. Default active theme.' ),
				'path'    => $t::p( 'string', 'Path inside the theme.' ),
				'content' => $t::p( 'string', 'Full new file contents.' ),
			),
			'required'    => array( 'path', 'content' ),
			'write'       => true,
			'requires'    => 'allow_theme_files',
			'callback'    => array( __CLASS__, 'write_theme_file' ),
		) );

		$t::add( 'list_file_backups', array(
			'group'       => 'design',
			'description' => 'List backups made before theme file edits.',
			'properties'  => array( 'theme' => $t::p( 'string', 'Theme folder. Default active theme.' ) ),
			'callback'    => array( __CLASS__, 'list_file_backups' ),
		) );

		$t::add( 'restore_file_backup', array(
			'group'       => 'design',
			'description' => 'Restore a theme file from a backup (undo an edit).',
			'properties'  => array( 'backup' => $t::p( 'string', 'Backup name from list_file_backups.' ), 'theme' => $t::p( 'string', 'Theme folder. Default active theme.' ) ),
			'required'    => array( 'backup' ),
			'write'       => true,
			'requires'    => 'allow_theme_files',
			'callback'    => array( __CLASS__, 'restore_file_backup' ),
		) );

		$t::add( 'list_menus', array(
			'group'       => 'design',
			'description' => 'List navigation menus with their items and theme locations. (Block themes use Navigation blocks: post type wp_navigation, editable with update_content.)',
			'callback'    => array( __CLASS__, 'list_menus' ),
		) );

		$t::add( 'save_menu', array(
			'group'       => 'design',
			'description' => 'Create a menu (no id) or rename one, and/or assign it to theme locations (e.g. "primary", "footer").',
			'properties'  => array(
				'id'        => $t::p( 'integer', 'Menu ID to update.' ),
				'name'      => $t::p( 'string', 'Menu name.' ),
				'locations' => $t::p_list( 'Theme location slugs to assign.' ),
			),
			'write'       => true,
			'callback'    => array( __CLASS__, 'save_menu' ),
		) );

		$t::add( 'save_menu_item', array(
			'group'       => 'design',
			'description' => 'Add (no item_id) or edit a menu item: link to a page/post/category by ID or to a custom URL.',
			'properties'  => array(
				'menu_id'   => $t::p( 'integer', 'Menu ID.' ),
				'item_id'   => $t::p( 'integer', 'Existing item ID to edit.' ),
				'title'     => $t::p( 'string', 'Label.' ),
				'url'       => $t::p( 'string', 'Custom link URL.' ),
				'object_id' => $t::p( 'integer', 'Page/post/term ID to link.' ),
				'object'    => $t::p( 'string', 'page, post, category, product, … (with object_id). Default page.' ),
				'parent'    => $t::p( 'integer', 'Parent menu item ID (for dropdowns).' ),
				'position'  => $t::p( 'integer', 'Order position.' ),
				'target'    => $t::p_enum( array( '', '_blank' ), 'Open in new tab: "_blank".' ),
			),
			'required'    => array( 'menu_id' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'save_menu_item' ),
		) );

		$t::add( 'delete_menu_item', array(
			'group'       => 'design',
			'description' => 'Remove an item from a menu.',
			'properties'  => array( 'item_id' => $t::p( 'integer', 'Menu item ID.' ) ),
			'required'    => array( 'item_id' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'delete_menu_item' ),
		) );

		$t::add( 'list_widgets', array(
			'group'       => 'design',
			'description' => 'Classic themes: list widget areas (sidebars, footers) and the widgets in them.',
			'callback'    => array( __CLASS__, 'list_widgets' ),
		) );

		$t::add( 'add_block_widget', array(
			'group'       => 'design',
			'description' => 'Classic themes: add a block widget (any block markup or HTML) to a widget area.',
			'properties'  => array(
				'sidebar'  => $t::p( 'string', 'Widget area ID from list_widgets.' ),
				'content'  => $t::p( 'string', 'Block markup or HTML.' ),
				'position' => $t::p( 'integer', 'Index to insert at. Default end.' ),
			),
			'required'    => array( 'sidebar', 'content' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'add_block_widget' ),
		) );

		$t::add( 'remove_widget', array(
			'group'       => 'design',
			'description' => 'Classic themes: remove a widget from its area (moved to inactive widgets).',
			'properties'  => array( 'widget_id' => $t::p( 'string', 'Widget ID from list_widgets, e.g. "block-3".' ) ),
			'required'    => array( 'widget_id' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'remove_widget' ),
		) );
	}

	/* -------------------------------- themes ------------------------------ */

	public static function list_themes() {
		$active = get_stylesheet();
		$out    = array();
		foreach ( wp_get_themes() as $slug => $th ) {
			$out[] = array(
				'stylesheet'  => $slug,
				'name'        => $th->get( 'Name' ),
				'version'     => $th->get( 'Version' ),
				'active'      => $slug === $active,
				'parent'      => $th->parent() ? $th->get_template() : null,
				'block_theme' => method_exists( $th, 'is_block_theme' ) ? $th->is_block_theme() : false,
			);
		}
		return $out;
	}

	public static function activate_theme( $a ) {
		$slug  = (string) $a['stylesheet'];
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			SC_Tools::fail( "Theme '$slug' is not installed." );
		}
		if ( $theme->errors() ) {
			SC_Tools::fail( 'Theme is broken: ' . $theme->errors()->get_error_message() );
		}
		switch_theme( $slug );
		return array( 'message' => 'Activated theme ' . $theme->get( 'Name' ) . '.' );
	}

	public static function upgrader_includes() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( ! WP_Filesystem() ) {
			SC_Tools::fail( 'WordPress cannot write files directly on this server (FTP credentials needed), so installs and updates must be done in wp-admin.' );
		}
	}

	public static function install_theme( $a ) {
		self::upgrader_includes();
		$src = SC_Tools::str( $a, 'zip_url' );
		if ( ! $src ) {
			$slug = SC_Tools::str( $a, 'slug' );
			if ( ! $slug ) {
				SC_Tools::fail( 'Give slug or zip_url.' );
			}
			$api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				SC_Tools::fail( "Theme '$slug' not found on WordPress.org." );
			}
			$src = $api->download_link;
		}
		$skin = new WP_Ajax_Upgrader_Skin();
		$up   = new Theme_Upgrader( $skin );
		$res  = $up->install( $src );
		if ( is_wp_error( $res ) || ! $res ) {
			$err = is_wp_error( $res ) ? $res : $skin->get_errors();
			SC_Tools::fail( 'Install failed: ' . ( is_wp_error( $err ) && $err->has_errors() ? $err->get_error_message() : 'unknown error' ) );
		}
		$theme = $up->theme_info();
		return array( 'message' => 'Installed theme ' . ( $theme ? $theme->get( 'Name' ) : '' ) . '. Use activate_theme to switch.', 'stylesheet' => $theme ? $theme->get_stylesheet() : null );
	}

	public static function create_child_theme( $a ) {
		$parent_slug = SC_Tools::str( $a, 'parent', get_template() );
		$parent      = wp_get_theme( $parent_slug );
		if ( ! $parent->exists() ) {
			SC_Tools::fail( "Theme '$parent_slug' not found." );
		}
		if ( $parent->parent() ) {
			SC_Tools::fail( "'$parent_slug' is already a child theme." );
		}
		$name = SC_Tools::str( $a, 'name', $parent->get( 'Name' ) . ' Child' );
		$slug = sanitize_title( $name );
		$dir  = trailingslashit( get_theme_root() ) . $slug;
		if ( file_exists( $dir ) ) {
			SC_Tools::fail( "A theme folder '$slug' already exists." );
		}
		wp_mkdir_p( $dir );
		$style = "/*\nTheme Name: $name\nTemplate: $parent_slug\nVersion: 1.0.0\nDescription: Child theme of " . $parent->get( 'Name' ) . " created by Site Commander.\n*/\n";
		file_put_contents( $dir . '/style.css', $style ); // phpcs:ignore
		$fn = "<?php\n// Child theme functions.\nadd_action( 'wp_enqueue_scripts', function () {\n\twp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );\n\twp_enqueue_style( 'child-style', get_stylesheet_uri(), array( 'parent-style' ), wp_get_theme()->get( 'Version' ) );\n} );\n";
		file_put_contents( $dir . '/functions.php', $fn ); // phpcs:ignore
		if ( $parent->is_block_theme() ) {
			file_put_contents( $dir . '/theme.json', wp_json_encode( array( 'version' => 3, '$schema' => 'https://schemas.wp.org/trunk/theme.json' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore
		}
		$msg = "Created child theme '$slug'.";
		if ( SC_Tools::bool( $a, 'activate' ) ) {
			$mods = get_option( 'theme_mods_' . get_stylesheet() );
			$css  = wp_get_custom_css( get_stylesheet() );
			switch_theme( $slug );
			if ( $mods && get_stylesheet() === $slug ) {
				update_option( 'theme_mods_' . $slug, $mods );
				if ( $css ) {
					wp_update_custom_css_post( $css, array( 'stylesheet' => $slug ) );
				}
			}
			$msg .= ' Activated, with theme settings and custom CSS copied.';
		}
		return array( 'message' => $msg, 'stylesheet' => $slug );
	}

	/* ------------------------------ CSS & mods ---------------------------- */

	public static function get_custom_css() {
		return array( 'theme' => get_stylesheet(), 'css' => wp_get_custom_css() );
	}

	public static function set_custom_css( $a ) {
		$css  = (string) $a['css'];
		$mode = SC_Tools::str( $a, 'mode', 'append' );
		if ( 'append' === $mode ) {
			$existing = wp_get_custom_css();
			$css      = trim( $existing ) ? rtrim( $existing ) . "\n\n" . $css : $css;
		}
		$res = wp_update_custom_css_post( $css );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		SC_Tools_Dev::purge_caches();
		return array( 'message' => 'Custom CSS ' . ( 'append' === $mode ? 'added' : 'replaced' ) . ' (' . strlen( $css ) . ' chars total).' );
	}

	public static function get_theme_mods() {
		$mods = get_theme_mods();
		unset( $mods['custom_css_post_id'] );
		return array( 'theme' => get_stylesheet(), 'mods' => $mods ? $mods : new stdClass() );
	}

	public static function set_theme_mods( $a ) {
		$mods = $a['mods'];
		if ( is_string( $mods ) ) {
			$mods = json_decode( $mods, true );
		}
		if ( ! is_array( $mods ) ) {
			SC_Tools::fail( 'mods must be an object.' );
		}
		foreach ( $mods as $k => $v ) {
			if ( null === $v ) {
				remove_theme_mod( $k );
			} else {
				set_theme_mod( $k, $v );
			}
		}
		SC_Tools_Dev::purge_caches();
		return array( 'message' => 'Updated ' . count( $mods ) . ' theme settings.', 'keys' => array_keys( $mods ) );
	}

	private static function image_id( $v ) {
		if ( is_numeric( $v ) ) {
			return (int) $v;
		}
		return SC_Tools_Media::sideload_url( (string) $v, 0, get_bloginfo( 'name' ), get_bloginfo( 'name' ) );
	}

	public static function set_site_identity( $a ) {
		$done = array();
		if ( array_key_exists( 'logo', $a ) ) {
			$id = empty( $a['logo'] ) ? 0 : self::image_id( $a['logo'] );
			set_theme_mod( 'custom_logo', $id );
			update_option( 'site_logo', $id );
			$done[] = $id ? "logo #$id" : 'logo removed';
		}
		if ( array_key_exists( 'icon', $a ) ) {
			$id = empty( $a['icon'] ) ? 0 : self::image_id( $a['icon'] );
			update_option( 'site_icon', $id );
			$done[] = $id ? "icon #$id" : 'icon removed';
		}
		return array( 'message' => 'Site identity: ' . implode( ', ', $done ) . '.' );
	}

	/* ---------------------------- global styles --------------------------- */

	private static function require_block_theme() {
		if ( ! wp_is_block_theme() ) {
			SC_Tools::fail( 'The active theme is a classic theme. Use set_custom_css or set_theme_mods instead.' );
		}
	}

	public static function get_global_styles( $a ) {
		self::require_block_theme();
		$settings = wp_get_global_settings();
		$out      = array(
			'palette'    => isset( $settings['color']['palette'] ) ? $settings['color']['palette'] : null,
			'fonts'      => isset( $settings['typography']['fontFamilies'] ) ? $settings['typography']['fontFamilies'] : null,
			'font_sizes' => isset( $settings['typography']['fontSizes'] ) ? $settings['typography']['fontSizes'] : null,
			'layout'     => isset( $settings['layout'] ) ? $settings['layout'] : null,
		);
		$post_id           = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$user              = $post_id ? json_decode( get_post_field( 'post_content', $post_id ), true ) : array();
		$out['user_overrides'] = $user ? $user : new stdClass();
		if ( SC_Tools::bool( $a, 'include_theme_defaults' ) ) {
			$out['merged_settings'] = $settings;
			$out['merged_styles']   = wp_get_global_styles();
		}
		return $out;
	}

	public static function update_global_styles( $a ) {
		self::require_block_theme();
		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( ! $post_id ) {
			SC_Tools::fail( 'Could not find or create the global styles record.' );
		}
		$data = SC_Tools::bool( $a, 'reset' ) ? array() : json_decode( get_post_field( 'post_content', $post_id ), true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		foreach ( array( 'styles', 'settings' ) as $k ) {
			$v = SC_Tools::arr( $a, $k );
			if ( $v ) {
				$data[ $k ] = array_replace_recursive( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ? $data[ $k ] : array(), $v );
			}
		}
		$data['version']                     = isset( $data['version'] ) ? $data['version'] : 3;
		$data['isGlobalStylesUserThemeJSON'] = true;
		$res = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( wp_json_encode( $data ) ),
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( method_exists( 'WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
		SC_Tools_Dev::purge_caches();
		return array( 'message' => 'Global styles updated.', 'user_overrides' => $data );
	}

	/* ------------------------------ templates ----------------------------- */

	private static function template_type( $a ) {
		$type = SC_Tools::str( $a, 'type' );
		if ( $type ) {
			return $type;
		}
		$id = SC_Tools::str( $a, 'id', '' );
		if ( get_block_template( $id, 'wp_template_part' ) && ! get_block_template( $id, 'wp_template' ) ) {
			return 'wp_template_part';
		}
		return 'wp_template';
	}

	public static function list_templates( $a ) {
		self::require_block_theme();
		$type = SC_Tools::str( $a, 'type', 'wp_template' );
		$out  = array();
		foreach ( get_block_templates( array(), $type ) as $tpl ) {
			$out[] = array(
				'id'          => $tpl->id,
				'slug'        => $tpl->slug,
				'title'       => $tpl->title,
				'description' => $tpl->description,
				'source'      => $tpl->source, // theme = original, custom = edited.
				'area'        => isset( $tpl->area ) ? $tpl->area : null,
			);
		}
		return $out;
	}

	public static function get_template( $a ) {
		self::require_block_theme();
		$type = self::template_type( $a );
		$tpl  = get_block_template( (string) $a['id'], $type );
		if ( ! $tpl ) {
			SC_Tools::fail( 'Template not found. Use list_templates for IDs.' );
		}
		return array(
			'id'      => $tpl->id,
			'type'    => $type,
			'title'   => $tpl->title,
			'source'  => $tpl->source,
			'content' => $tpl->content,
		);
	}

	public static function update_template( $a ) {
		self::require_block_theme();
		$id    = (string) $a['id'];
		$type  = self::template_type( $a );
		$parts = explode( '//', $id, 2 );
		if ( count( $parts ) !== 2 ) {
			SC_Tools::fail( 'Template ID must look like "theme//slug".' );
		}
		list( $theme, $slug ) = $parts;
		$tpl     = get_block_template( $id, $type );
		$content = (string) $a['content'];

		if ( $tpl && $tpl->wp_id ) {
			$res = wp_update_post( wp_slash( array( 'ID' => $tpl->wp_id, 'post_content' => $content ) ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$post_id = $tpl->wp_id;
		} else {
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => $type,
						'post_status'  => 'publish',
						'post_name'    => $slug,
						'post_title'   => SC_Tools::str( $a, 'title', $tpl ? $tpl->title : ucwords( str_replace( '-', ' ', $slug ) ) ),
						'post_content' => $content,
						'post_excerpt' => $tpl ? $tpl->description : '',
					)
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			wp_set_post_terms( $post_id, array( $theme ), 'wp_theme' );
			if ( 'wp_template_part' === $type ) {
				$area = SC_Tools::str( $a, 'area', ( $tpl && ! empty( $tpl->area ) ) ? $tpl->area : 'uncategorized' );
				wp_set_post_terms( $post_id, array( $area ), 'wp_template_part_area' );
			}
		}
		SC_Tools_Dev::purge_caches();
		return array( 'message' => "Saved $type $id.", 'post_id' => (int) $post_id );
	}

	public static function reset_template( $a ) {
		self::require_block_theme();
		$type = self::template_type( $a );
		$tpl  = get_block_template( (string) $a['id'], $type );
		if ( ! $tpl || ! $tpl->wp_id ) {
			SC_Tools::fail( 'This template has no customizations to remove.' );
		}
		wp_delete_post( $tpl->wp_id, true );
		SC_Tools_Dev::purge_caches();
		return array( 'message' => "Reset {$a['id']} to the theme original." );
	}

	public static function list_block_patterns( $a ) {
		$search = strtolower( (string) SC_Tools::str( $a, 'search', '' ) );
		$full   = SC_Tools::bool( $a, 'include_content' );
		$out    = array();
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
			$hay = strtolower( $p['title'] . ' ' . implode( ' ', isset( $p['categories'] ) ? $p['categories'] : array() ) );
			if ( $search && false === strpos( $hay, $search ) ) {
				continue;
			}
			$row = array( 'name' => $p['name'], 'title' => $p['title'], 'categories' => isset( $p['categories'] ) ? $p['categories'] : array() );
			if ( $full ) {
				$row['content'] = $p['content'];
			}
			$out[] = $row;
		}
		foreach ( get_posts( array( 'post_type' => 'wp_block', 'numberposts' => 100, 'post_status' => 'publish' ) ) as $b ) {
			if ( $search && false === strpos( strtolower( $b->post_title ), $search ) ) {
				continue;
			}
			$row = array( 'name' => 'core/block ref ' . $b->ID, 'title' => $b->post_title, 'synced_pattern_id' => $b->ID, 'usage' => '<!-- wp:block {"ref":' . $b->ID . '} /-->' );
			if ( $full ) {
				$row['content'] = $b->post_content;
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ------------------------------ theme files --------------------------- */

	private static function theme_root( $a ) {
		$slug  = SC_Tools::str( $a, 'theme', get_stylesheet() );
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			SC_Tools::fail( "Theme '$slug' not found." );
		}
		return array( $slug, realpath( $theme->get_stylesheet_directory() ) );
	}

	/**
	 * Resolve a path inside the theme, refusing anything that escapes it.
	 */
	private static function safe_path( $root, $rel, $must_exist ) {
		$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( '' === $rel || preg_match( '#(^|/)\.\.(/|$)#', $rel ) || false !== strpos( $rel, "\0" ) ) {
			SC_Tools::fail( 'Invalid path.' );
		}
		$full = $root . '/' . $rel;
		if ( $must_exist ) {
			$real = realpath( $full );
			if ( ! $real || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
				SC_Tools::fail( "File '$rel' not found in the theme." );
			}
			return $real;
		}
		$dir = realpath( dirname( $full ) );
		if ( $dir && ( $dir === $root || 0 === strpos( $dir, $root . DIRECTORY_SEPARATOR ) ) ) {
			return $full;
		}
		if ( ! $dir ) {
			// New sub-folder: check the nearest existing ancestor.
			$probe = dirname( $full );
			while ( ! file_exists( $probe ) && strlen( $probe ) > strlen( $root ) ) {
				$probe = dirname( $probe );
			}
			$real = realpath( $probe );
			if ( $real && ( $real === $root || 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) ) {
				return $full;
			}
		}
		SC_Tools::fail( 'Path is outside the theme folder.' );
	}

	public static function list_theme_files( $a ) {
		list( $slug, $root ) = self::theme_root( $a );
		$base                = $root;
		if ( $sub = SC_Tools::str( $a, 'path' ) ) {
			$base = self::safe_path( $root, $sub, true );
		}
		$files = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$rel = ltrim( str_replace( $root, '', $f->getPathname() ), '/\\' );
			if ( preg_match( '#(^|/)(node_modules|vendor|\.git)/#', str_replace( '\\', '/', $rel ) ) ) {
				continue;
			}
			$files[] = array( 'path' => str_replace( '\\', '/', $rel ), 'kb' => round( $f->getSize() / 1024, 1 ) );
			if ( count( $files ) >= 500 ) {
				break;
			}
		}
		usort( $files, function ( $x, $y ) { return strcmp( $x['path'], $y['path'] ); } );
		return array( 'theme' => $slug, 'files' => $files );
	}

	public static function read_theme_file( $a ) {
		list( $slug, $root ) = self::theme_root( $a );
		$file                = self::safe_path( $root, $a['path'], true );
		$ext                 = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array_merge( self::TEXT_EXT, array( 'php' ) ), true ) ) {
			SC_Tools::fail( "Cannot read .$ext files as text." );
		}
		if ( filesize( $file ) > 1024 * 1024 ) {
			SC_Tools::fail( 'File is over 1 MB.' );
		}
		return array( 'theme' => $slug, 'path' => $a['path'], 'content' => file_get_contents( $file ) ); // phpcs:ignore
	}

	private static function backup( $slug, $root, $file ) {
		if ( ! file_exists( $file ) ) {
			return null;
		}
		$dir = SC_Plugin::ensure_backup_dir() . '/' . $slug;
		wp_mkdir_p( $dir );
		$rel  = str_replace( array( '/', '\\' ), '__', ltrim( str_replace( $root, '', $file ), '/\\' ) );
		$name = $rel . '.' . gmdate( 'Ymd-His' ) . '-' . wp_rand( 1000, 9999 ) . '.bak';
		copy( $file, $dir . '/' . $name );
		return $name;
	}

	public static function write_theme_file( $a ) {
		list( $slug, $root ) = self::theme_root( $a );
		$file                = self::safe_path( $root, $a['path'], false );
		$ext                 = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$content             = (string) $a['content'];

		if ( 'php' === $ext ) {
			if ( ! SC_Plugin::setting( 'allow_php_edits' ) ) {
				SC_Tools::fail( 'PHP file editing is switched off. The site owner can enable "Allow editing PHP theme files" in Site Commander settings. For styling, use set_custom_css instead.' );
			}
			try {
				token_get_all( $content, TOKEN_PARSE );
			} catch ( ParseError $e ) {
				SC_Tools::fail( 'PHP syntax error on line ' . $e->getLine() . ': ' . $e->getMessage() . '. File not saved.' );
			}
		} elseif ( ! in_array( $ext, self::TEXT_EXT, true ) ) {
			SC_Tools::fail( "Writing .$ext files is not allowed." );
		}
		if ( 'json' === $ext && null === json_decode( $content ) ) {
			SC_Tools::fail( 'Invalid JSON. File not saved.' );
		}

		$backup = self::backup( $slug, $root, $file );
		wp_mkdir_p( dirname( $file ) );
		if ( false === file_put_contents( $file, $content ) ) { // phpcs:ignore
			SC_Tools::fail( 'Could not write the file (check folder permissions).' );
		}
		if ( function_exists( 'opcache_invalidate' ) && 'php' === $ext ) {
			@opcache_invalidate( $file, true ); // phpcs:ignore
		}
		if ( 'json' === $ext && function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
		SC_Tools_Dev::purge_caches();

		$msg = "Saved {$a['path']} in $slug." . ( $backup ? " Backup: $backup" : ' (new file)' );
		if ( 'php' === $ext ) {
			$check = SC_Tools_Dev::site_responds();
			if ( ! $check['ok'] && $backup ) {
				copy( SC_Plugin::backup_dir() . "/$slug/$backup", $file );
				SC_Tools::fail( 'The site returned an error after this PHP change (' . $check['detail'] . '), so the file was restored from the backup automatically.' );
			}
			$msg .= ' Site check after save: ' . $check['detail'];
		}
		return array( 'message' => $msg, 'backup' => $backup );
	}

	public static function list_file_backups( $a ) {
		list( $slug ) = self::theme_root( $a );
		$dir          = SC_Plugin::backup_dir() . '/' . $slug;
		$out          = array();
		foreach ( (array) glob( $dir . '/*.bak' ) as $f ) {
			$name = basename( $f );
			if ( preg_match( '/^(.*)\.(\d{8}-\d{6})(?:-\d{4})?\.bak$/', $name, $m ) ) {
				$out[] = array( 'backup' => $name, 'file' => str_replace( '__', '/', $m[1] ), 'saved_utc' => $m[2] );
			}
		}
		return array_reverse( $out );
	}

	public static function restore_file_backup( $a ) {
		list( $slug, $root ) = self::theme_root( $a );
		$name                = basename( (string) $a['backup'] );
		$src                 = SC_Plugin::backup_dir() . "/$slug/$name";
		if ( ! file_exists( $src ) || ! preg_match( '/^(.*)\.\d{8}-\d{6}(?:-\d{4})?\.bak$/', $name, $m ) ) {
			SC_Tools::fail( 'Backup not found.' );
		}
		$target = self::safe_path( $root, str_replace( '__', '/', $m[1] ), false );
		self::backup( $slug, $root, $target );
		copy( $src, $target );
		SC_Tools_Dev::purge_caches();
		return array( 'message' => 'Restored ' . str_replace( '__', '/', $m[1] ) . " from $name." );
	}

	/* -------------------------------- menus ------------------------------- */

	public static function list_menus() {
		$locations = get_nav_menu_locations();
		$out       = array( 'registered_locations' => get_registered_nav_menus(), 'menus' => array() );
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = array();
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $it ) {
				$items[] = array(
					'item_id'   => $it->ID,
					'title'     => $it->title,
					'url'       => $it->url,
					'object'    => $it->object,
					'object_id' => (int) $it->object_id,
					'parent'    => (int) $it->menu_item_parent,
					'position'  => (int) $it->menu_order,
				);
			}
			$out['menus'][] = array(
				'id'        => $menu->term_id,
				'name'      => $menu->name,
				'locations' => array_keys( $locations, $menu->term_id ),
				'items'     => $items,
			);
		}
		if ( wp_is_block_theme() ) {
			$out['navigation_blocks'] = array_map(
				function ( $p ) {
					return array( 'id' => $p->ID, 'title' => $p->post_title );
				},
				get_posts( array( 'post_type' => 'wp_navigation', 'numberposts' => 20, 'post_status' => 'publish' ) )
			);
		}
		return $out;
	}

	public static function save_menu( $a ) {
		$id   = SC_Tools::int( $a, 'id' );
		$name = SC_Tools::str( $a, 'name' );
		if ( ! $id && ! $name ) {
			SC_Tools::fail( 'Give a name to create a menu.' );
		}
		if ( $name ) {
			$res = wp_update_nav_menu_object( $id, array( 'menu-name' => $name ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$id = (int) $res;
		}
		if ( isset( $a['locations'] ) ) {
			$locs = get_nav_menu_locations();
			foreach ( (array) SC_Tools::arr( $a, 'locations' ) as $loc ) {
				$locs[ $loc ] = $id;
			}
			set_theme_mod( 'nav_menu_locations', $locs );
		}
		return array( 'message' => "Menu #$id saved.", 'id' => $id );
	}

	public static function save_menu_item( $a ) {
		$menu_id = (int) $a['menu_id'];
		$item_id = SC_Tools::int( $a, 'item_id' );
		$data    = array( 'menu-item-status' => 'publish' );
		if ( $item_id ) {
			$it = wp_setup_nav_menu_item( get_post( $item_id ) );
			if ( ! $it || empty( $it->ID ) ) {
				SC_Tools::fail( 'Menu item not found.' );
			}
			$data += array(
				'menu-item-title'     => $it->title,
				'menu-item-url'       => $it->url,
				'menu-item-object-id' => $it->object_id,
				'menu-item-object'    => $it->object,
				'menu-item-type'      => $it->type,
				'menu-item-parent-id' => $it->menu_item_parent,
				'menu-item-position'  => $it->menu_order,
				'menu-item-target'    => $it->target,
			);
		}
		if ( isset( $a['title'] ) ) {
			$data['menu-item-title'] = (string) $a['title'];
		}
		if ( ! empty( $a['object_id'] ) ) {
			$object                      = SC_Tools::str( $a, 'object', 'page' );
			$data['menu-item-object-id'] = (int) $a['object_id'];
			$data['menu-item-object']    = $object;
			$data['menu-item-type']      = taxonomy_exists( $object ) ? 'taxonomy' : 'post_type';
		} elseif ( ! empty( $a['url'] ) ) {
			$data['menu-item-url']  = esc_url_raw( $a['url'] );
			$data['menu-item-type'] = 'custom';
		} elseif ( ! $item_id ) {
			SC_Tools::fail( 'Give url or object_id.' );
		}
		if ( isset( $a['parent'] ) ) {
			$data['menu-item-parent-id'] = (int) $a['parent'];
		}
		if ( isset( $a['position'] ) ) {
			$data['menu-item-position'] = (int) $a['position'];
		}
		if ( isset( $a['target'] ) ) {
			$data['menu-item-target'] = (string) $a['target'];
		}
		$res = wp_update_nav_menu_item( $menu_id, $item_id, $data );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'message' => ( $item_id ? 'Updated' : 'Added' ) . " menu item #$res.", 'item_id' => (int) $res );
	}

	public static function delete_menu_item( $a ) {
		$id = (int) $a['item_id'];
		if ( 'nav_menu_item' !== get_post_type( $id ) ) {
			SC_Tools::fail( 'Menu item not found.' );
		}
		wp_delete_post( $id, true );
		return array( 'message' => "Removed menu item #$id." );
	}

	/* ------------------------------- widgets ------------------------------ */

	public static function list_widgets() {
		global $wp_registered_sidebars;
		$sidebars = wp_get_sidebars_widgets();
		$blocks   = get_option( 'widget_block', array() );
		$out      = array();
		foreach ( (array) $wp_registered_sidebars as $id => $sb ) {
			$widgets = array();
			foreach ( isset( $sidebars[ $id ] ) ? (array) $sidebars[ $id ] : array() as $wid ) {
				$row = array( 'widget_id' => $wid );
				if ( preg_match( '/^block-(\d+)$/', $wid, $m ) && isset( $blocks[ $m[1] ]['content'] ) ) {
					$row['content'] = $blocks[ $m[1] ]['content'];
				}
				$widgets[] = $row;
			}
			$out[] = array( 'sidebar' => $id, 'name' => $sb['name'], 'widgets' => $widgets );
		}
		return $out;
	}

	public static function add_block_widget( $a ) {
		global $wp_registered_sidebars;
		$sidebar = (string) $a['sidebar'];
		if ( ! isset( $wp_registered_sidebars[ $sidebar ] ) ) {
			SC_Tools::fail( "Unknown widget area '$sidebar'." );
		}
		$blocks = get_option( 'widget_block', array() );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}
		$num            = max( array_merge( array( 1 ), array_filter( array_keys( $blocks ), 'is_int' ) ) ) + 1;
		$blocks[ $num ] = array( 'content' => (string) $a['content'] );
		if ( ! isset( $blocks['_multiwidget'] ) ) {
			$blocks['_multiwidget'] = 1;
		}
		update_option( 'widget_block', $blocks );
		$sidebars = wp_get_sidebars_widgets();
		$list     = isset( $sidebars[ $sidebar ] ) ? (array) $sidebars[ $sidebar ] : array();
		$pos      = isset( $a['position'] ) ? max( 0, (int) $a['position'] ) : count( $list );
		array_splice( $list, $pos, 0, array( "block-$num" ) );
		$sidebars[ $sidebar ] = $list;
		wp_set_sidebars_widgets( $sidebars );
		return array( 'message' => "Added widget block-$num to $sidebar.", 'widget_id' => "block-$num" );
	}

	public static function remove_widget( $a ) {
		$wid      = (string) $a['widget_id'];
		$sidebars = wp_get_sidebars_widgets();
		foreach ( $sidebars as $sb => $list ) {
			if ( 'wp_inactive_widgets' === $sb || ! is_array( $list ) ) {
				continue;
			}
			$i = array_search( $wid, $list, true );
			if ( false !== $i ) {
				unset( $sidebars[ $sb ][ $i ] );
				$sidebars[ $sb ]                   = array_values( $sidebars[ $sb ] );
				$sidebars['wp_inactive_widgets'][] = $wid;
				wp_set_sidebars_widgets( $sidebars );
				return array( 'message' => "Removed $wid from $sb." );
			}
		}
		SC_Tools::fail( 'Widget not found in any active area.' );
	}
}
