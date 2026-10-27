<?php
/**
 * Tool registry. Every ability is defined once here and served to every transport
 * (MCP, REST, OpenAPI), so all AIs see exactly the same tools and permissions.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tool_Error extends Exception {}

class SC_Tools {

	/** @var array<string,array> */
	private static $tools = null;

	/**
	 * Register a tool.
	 *
	 * @param string   $name     snake_case name.
	 * @param array    $def      group, description, properties, required, write, requires, callback.
	 */
	public static function add( $name, array $def ) {
		if ( class_exists( 'SC_License' ) && SC_License::is_pro_tool( $name ) && isset( $def['description'] ) ) {
			$def['description'] = '[Pro] ' . $def['description'];
		}
		self::$tools[ $name ] = wp_parse_args(
			$def,
			array(
				'name'        => $name,
				'title'       => ucwords( str_replace( '_', ' ', $name ) ),
				'group'       => 'site',
				'description' => '',
				'properties'  => array(),
				'required'    => array(),
				'write'       => false,
				'destructive' => false,
				'requires'    => null, // Setting key that must be on.
				'callback'    => null,
			)
		);
	}

	public static function all() {
		if ( null === self::$tools ) {
			self::$tools = array();
			SC_Tools_Content::register();
			SC_Tools_Media::register();
			SC_Guard::register_tools();
			SC_Tools_Design::register();
			SC_Tools_Site::register();
			SC_Tools_SEO::register();
			SC_Tools_People::register();
			SC_Tools_Dev::register();
			SC_Tools_Commerce::register();
			SC_Tools_Builders::register();
			SC_Tools_Shortcode_Builders::register();
			/**
			 * Let developers add their own tools:
			 * add_action( 'sc_register_tools', function () { SC_Tools::add( 'my_tool', [...] ); } );
			 */
			do_action( 'sc_register_tools' );
		}
		return self::$tools;
	}

	public static function get( $name ) {
		$all = self::all();
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	public static function groups() {
		return array(
			'content' => 'Posts, pages, custom post types, scheduling, categories and tags',
			'media'   => 'Media library and file uploads',
			'design'  => 'Themes, custom CSS, global styles, templates, theme files, menus',
			'site'    => 'Site settings, plugins, cache, activity log',
			'seo'     => 'SEO audit, meta titles/descriptions, schema, image alt text, redirects, robots.txt',
			'people'  => 'Comments and users',
			'dev'     => 'Code snippets, maintenance mode, site health, cron, debug log, database tools, updates',
			'commerce'=> 'WooCommerce products and orders',
		);
	}

	/**
	 * JSON Schema for a tool's input.
	 */
	public static function input_schema( array $tool ) {
		$schema = array(
			'type'       => 'object',
			'properties' => empty( $tool['properties'] ) ? new stdClass() : $tool['properties'],
		);
		if ( ! empty( $tool['required'] ) ) {
			$schema['required'] = array_values( $tool['required'] );
		}
		return $schema;
	}

	/**
	 * Why a tool is unavailable, or null if it can run.
	 */
	public static function blocked_reason( array $tool ) {
		if ( $tool['requires'] && ! SC_Plugin::setting( $tool['requires'] ) ) {
			$labels = array(
				'allow_theme_files'      => 'Allow editing theme files',
				'allow_php_edits'        => 'Allow editing PHP theme files',
				'allow_extensions'       => 'Allow installing/activating plugins and themes',
				'allow_permanent_delete' => 'Allow permanent deletion',
				'allow_users'            => 'Allow managing users',
				'allow_code'             => 'Allow header/footer code snippets',
				'allow_database'         => 'Allow database search & replace',
				'allow_updates'          => 'Allow updating core, plugins and themes',
			);
			$label = isset( $labels[ $tool['requires'] ] ) ? $labels[ $tool['requires'] ] : $tool['requires'];
			return sprintf( 'This action is switched off. The site owner can enable "%s" in WordPress under Site Commander settings.', $label );
		}
		return null;
	}

	/**
	 * Run a tool. Always returns array( ok, data | error ).
	 */
	public static function call( $name, $args ) {
		$tool = self::get( $name );
		if ( ! $tool ) {
			return array( 'ok' => false, 'error' => "Unknown tool: $name" );
		}
		$args = is_array( $args ) ? $args : array();

		$blocked = SC_Auth::scope_blocks( $tool );
		if ( ! $blocked ) {
			$blocked = self::blocked_reason( $tool );
		}
		if ( $blocked ) {
			if ( $tool['write'] ) {
				SC_Log::add( $name, $args, false, 'Refused: ' . $blocked );
			}
			return array( 'ok' => false, 'error' => $blocked );
		}
		$blocked = SC_License::tool_block( $name );
		if ( $blocked ) {
			return array( 'ok' => false, 'error' => $blocked );
		}
		foreach ( $tool['required'] as $req ) {
			$empty_ok = in_array( $req, isset( $tool['allow_empty'] ) ? (array) $tool['allow_empty'] : array(), true );
			if ( ! array_key_exists( $req, $args ) || ( '' === $args[ $req ] && ! $empty_ok ) || null === $args[ $req ] ) {
				return array( 'ok' => false, 'error' => "Missing required argument: $req" );
			}
		}

		try {
			$result = call_user_func( $tool['callback'], $args );
			if ( is_wp_error( $result ) ) {
				throw new SC_Tool_Error( $result->get_error_message() );
			}
			if ( $tool['write'] ) {
				$summary = is_array( $result ) && isset( $result['message'] ) ? $result['message'] : 'done';
				SC_Log::add( $name, $args, true, $summary );
			}
			return array( 'ok' => true, 'data' => $result );
		} catch ( Throwable $e ) {
			if ( $tool['write'] ) {
				SC_Log::add( $name, $args, false, $e->getMessage() );
			}
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}
	}

	/* ---------------------------------------------------------------------
	 * Small helpers shared by tool classes.
	 * ------------------------------------------------------------------- */

	public static function str( $args, $key, $default = null ) {
		return isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ? (string) $args[ $key ] : $default;
	}

	public static function int( $args, $key, $default = 0 ) {
		return isset( $args[ $key ] ) && is_numeric( $args[ $key ] ) ? (int) $args[ $key ] : $default;
	}

	public static function bool( $args, $key, $default = false ) {
		if ( ! isset( $args[ $key ] ) ) {
			return $default;
		}
		$v = $args[ $key ];
		if ( is_string( $v ) ) {
			return in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $v;
	}

	public static function arr( $args, $key ) {
		if ( ! isset( $args[ $key ] ) ) {
			return null;
		}
		$v = $args[ $key ];
		if ( is_string( $v ) ) {
			$decoded = json_decode( $v, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			return array_filter( array_map( 'trim', explode( ',', $v ) ), 'strlen' );
		}
		return is_array( $v ) ? $v : array( $v );
	}

	public static function fail( $message ) {
		throw new SC_Tool_Error( $message );
	}

	/** Schema shorthands. */
	public static function p( $type, $description, array $extra = array() ) {
		return array_merge( array( 'type' => $type, 'description' => $description ), $extra );
	}

	public static function p_enum( array $values, $description, array $extra = array() ) {
		return array_merge( array( 'type' => 'string', 'enum' => $values, 'description' => $description ), $extra );
	}

	public static function p_list( $description, $item_type = 'string' ) {
		return array( 'type' => 'array', 'items' => array( 'type' => $item_type ), 'description' => $description );
	}

	/**
	 * Parse a date given in the site's timezone (or with an explicit offset).
	 * Returns array( local 'Y-m-d H:i:s', gmt 'Y-m-d H:i:s', timestamp ).
	 */
	public static function parse_date( $value ) {
		try {
			$dt = new DateTimeImmutable( (string) $value, wp_timezone() );
		} catch ( Exception $e ) {
			self::fail( "Could not understand the date '$value'. Use YYYY-MM-DD HH:MM in the site's timezone (" . wp_timezone_string() . ').' );
		}
		$local = $dt->setTimezone( wp_timezone() );
		$gmt   = $dt->setTimezone( new DateTimeZone( 'UTC' ) );
		return array( $local->format( 'Y-m-d H:i:s' ), $gmt->format( 'Y-m-d H:i:s' ), $dt->getTimestamp() );
	}

	public static function plain_excerpt( $html, $words = 30 ) {
		return wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $html ) ), $words, '…' );
	}
}
