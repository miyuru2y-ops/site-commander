<?php
/**
 * Undo: a snapshot is taken before an AI change, so the owner (or the AI) can roll it back.
 *
 * Snapshots are kept per change in their own option (not autoloaded); the newest 100 are kept.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Undo {

	const INDEX = 'sc_undo_index';
	const MAX   = 100;

	/** Tools whose target is one post given as `id`. */
	private static function post_tools() {
		return array(
			'update_content', 'schedule_content', 'set_seo', 'update_elementor_element', 'update_shortcode_node',
			'save_product', 'update_media', 'restore_revision', 'update_block', 'insert_block', 'delete_block',
			'move_block', 'update_acf_fields', 'add_internal_link',
		);
	}

	/** Tools that create something; undo moves it to the trash. */
	private static function create_tools() {
		return array( 'create_content', 'duplicate_content', 'create_translation' );
	}

	/**
	 * Take a snapshot before a tool runs. Returns snapshot data or null when the change can't be undone.
	 */
	public static function capture( $tool, array $args ) {
		try {
			if ( in_array( $tool, self::post_tools(), true ) ) {
				$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
				if ( 'restore_revision' === $tool && ! empty( $args['revision_id'] ) ) {
					$id = (int) wp_get_post_parent_id( (int) $args['revision_id'] );
				}
				if ( 'save_product' === $tool && ! $id ) {
					return array( 'kind' => 'create' );
				}
				return $id && get_post( $id ) ? array( 'kind' => 'posts', 'posts' => array( self::post_state( $id ) ) ) : null;
			}
			if ( in_array( $tool, self::create_tools(), true ) ) {
				return array( 'kind' => 'create' );
			}
			if ( 'delete_content' === $tool ) {
				$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
				return ( $id && empty( $args['force'] ) ) ? array( 'kind' => 'trash', 'id' => $id ) : null;
			}
			if ( 'bulk_update_status' === $tool || 'fix_content_image_alts' === $tool || 'bulk_set_seo' === $tool ) {
				$ids = array();
				foreach ( array( 'ids', 'post_ids' ) as $k ) {
					if ( ! empty( $args[ $k ] ) ) {
						$ids = SC_Tools::arr( $args, $k );
					}
				}
				if ( 'bulk_set_seo' === $tool && ! empty( $args['items'] ) && is_array( $args['items'] ) ) {
					foreach ( $args['items'] as $it ) {
						if ( is_array( $it ) && ! empty( $it['id'] ) ) {
							$ids[] = (int) $it['id'];
						}
					}
				}
				$ids = array_slice( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ), 0, 200 );
				if ( ! $ids ) {
					return null;
				}
				$posts = array();
				foreach ( $ids as $id ) {
					if ( get_post( $id ) ) {
						$posts[] = self::post_state( $id );
					}
				}
				return $posts ? array( 'kind' => 'posts', 'posts' => $posts ) : null;
			}
			if ( 'update_template' === $tool && function_exists( 'get_block_template' ) ) {
				$type = isset( $args['type'] ) ? (string) $args['type'] : 'wp_template';
				$tpl  = get_block_template( (string) $args['id'], $type );
				if ( ! $tpl && 'wp_template' === $type ) {
					$tpl = get_block_template( (string) $args['id'], 'wp_template_part' );
				}
				return ( $tpl && ! empty( $tpl->wp_id ) ) ? array( 'kind' => 'posts', 'posts' => array( self::post_state( (int) $tpl->wp_id ) ) ) : null;
			}
			if ( 'update_global_styles' === $tool && class_exists( 'WP_Theme_JSON_Resolver' ) ) {
				$id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
				return $id ? array( 'kind' => 'posts', 'posts' => array( self::post_state( $id ) ) ) : null;
			}
			if ( 'set_custom_css' === $tool ) {
				return array( 'kind' => 'css', 'css' => wp_get_custom_css() );
			}
			$opts = self::option_names( $tool, $args );
			if ( $opts ) {
				$state = array();
				foreach ( $opts as $o ) {
					$state[ $o ] = array( 'exists' => false !== get_option( $o, false ), 'value' => get_option( $o ) );
				}
				return array( 'kind' => 'options', 'options' => $state );
			}
		} catch ( Throwable $e ) {
			return null;
		}
		return null;
	}

	private static function option_names( $tool, array $args ) {
		$mods = 'theme_mods_' . get_option( 'stylesheet' );
		switch ( $tool ) {
			case 'update_option':
				return isset( $args['name'] ) ? array( sanitize_text_field( (string) $args['name'] ) ) : array();
			case 'update_settings':
				return isset( $args['settings'] ) && is_array( $args['settings'] ) ? array_map( 'sanitize_text_field', array_keys( $args['settings'] ) ) : array();
			case 'set_site_identity':
				return array( 'blogname', 'blogdescription', 'site_icon', $mods );
			case 'set_theme_mods':
				return array( $mods );
			case 'set_home_seo':
				return array( 'sc_home_seo' );
			case 'set_robots_txt':
				return array( 'sc_robots_extra' );
			case 'add_redirect':
			case 'delete_redirect':
				return array( 'sc_redirects' );
			// @pro-start
			case 'save_code_snippet':
			case 'delete_code_snippet':
				return array( 'sc_snippets' );
			case 'maintenance_mode':
				return array( 'sc_settings', 'sc_maintenance_message' );
			// @pro-end
		}
		return array();
	}

	private static function post_state( $id ) {
		$p    = get_post( $id, ARRAY_A );
		$keep = array( 'ID', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_date', 'post_date_gmt', 'post_parent', 'menu_order', 'post_password' );
		$meta = array();
		foreach ( (array) get_post_meta( $id ) as $k => $vals ) {
			if ( in_array( $k, array( '_edit_lock', '_edit_last' ), true ) ) {
				continue;
			}
			$meta[ $k ] = $vals;
		}
		return array( 'post' => array_intersect_key( $p, array_flip( $keep ) ), 'meta' => $meta, 'terms' => self::terms( $id ) );
	}

	private static function terms( $id ) {
		$out = array();
		foreach ( get_object_taxonomies( get_post_type( $id ) ) as $tax ) {
			$ids = wp_get_object_terms( $id, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) ) {
				$out[ $tax ] = array_map( 'intval', $ids );
			}
		}
		return $out;
	}

	/**
	 * Store the snapshot after a successful change. Returns the undo id or ''.
	 */
	public static function store( $snap, $tool, $result, $summary ) {
		if ( ! $snap ) {
			return '';
		}
		if ( 'create' === $snap['kind'] ) {
			$id = is_array( $result ) && isset( $result['id'] ) ? (int) $result['id'] : 0;
			if ( ! $id || ! get_post( $id ) ) {
				return '';
			}
			$snap['id'] = $id;
		}
		$uid = 'u' . wp_generate_password( 10, false, false );
		$snap['tool']    = $tool;
		$snap['summary'] = $summary;
		$snap['time']    = current_time( 'mysql' );
		$snap['client']  = SC_Log::$client;
		update_option( 'sc_undo_' . $uid, $snap, false );
		$index = get_option( self::INDEX, array() );
		$index = is_array( $index ) ? $index : array();
		array_unshift( $index, $uid );
		foreach ( array_slice( $index, self::MAX ) as $old ) {
			delete_option( 'sc_undo_' . $old );
		}
		update_option( self::INDEX, array_slice( $index, 0, self::MAX ), false );
		return $uid;
	}

	public static function get( $uid ) {
		$uid = preg_replace( '/[^A-Za-z0-9]/', '', (string) $uid );
		$s   = $uid ? get_option( 'sc_undo_' . $uid, null ) : null;
		return is_array( $s ) ? $s : null;
	}

	/** Roll a change back. */
	public static function undo( $uid ) {
		$s = self::get( $uid );
		if ( ! $s ) {
			SC_Tools::fail( 'Nothing to undo: this change has no snapshot (it may be too old or already undone).' );
		}
		switch ( $s['kind'] ) {
			case 'posts':
				foreach ( $s['posts'] as $state ) {
					self::restore_post( $state );
				}
				$msg = count( $s['posts'] ) . ' item(s) restored';
				break;
			case 'create':
				wp_trash_post( (int) $s['id'] );
				$msg = 'Created item #' . (int) $s['id'] . ' moved to the trash';
				break;
			case 'trash':
				wp_untrash_post( (int) $s['id'] );
				$msg = 'Item #' . (int) $s['id'] . ' restored from the trash';
				break;
			case 'css':
				wp_update_custom_css_post( (string) $s['css'] );
				$msg = 'Additional CSS restored';
				break;
			case 'options':
				foreach ( $s['options'] as $name => $o ) {
					if ( $o['exists'] ) {
						update_option( $name, $o['value'] );
					} else {
						delete_option( $name );
					}
				}
				$msg = 'Settings restored (' . implode( ', ', array_keys( $s['options'] ) ) . ')';
				break;
			default:
				SC_Tools::fail( 'Unknown snapshot type.' );
		}
		delete_option( 'sc_undo_' . $uid );
		update_option( self::INDEX, array_values( array_diff( (array) get_option( self::INDEX, array() ), array( $uid ) ) ), false );
		SC_Log::mark_undone( $uid );
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		return array( 'message' => $msg . ' (undid: ' . $s['tool'] . ', ' . $s['time'] . ').' );
	}

	private static function restore_post( array $state ) {
		$p  = $state['post'];
		$id = (int) $p['ID'];
		if ( ! get_post( $id ) ) {
			return;
		}
		kses_remove_filters();
		wp_update_post( wp_slash( $p ) );
		kses_init_filters();
		$now = get_post_meta( $id );
		foreach ( array_keys( (array) $now ) as $k ) {
			if ( in_array( $k, array( '_edit_lock', '_edit_last' ), true ) ) {
				continue;
			}
			if ( ! array_key_exists( $k, $state['meta'] ) ) {
				delete_post_meta( $id, $k );
			}
		}
		foreach ( $state['meta'] as $k => $vals ) {
			delete_post_meta( $id, $k );
			foreach ( (array) $vals as $v ) {
				add_post_meta( $id, $k, wp_slash( maybe_unserialize( $v ) ) );
			}
		}
		foreach ( (array) $state['terms'] as $tax => $ids ) {
			wp_set_object_terms( $id, $ids, $tax );
		}
		clean_post_cache( $id );
	}

	public static function list_recent( $limit = 20 ) {
		$out = array();
		foreach ( array_slice( (array) get_option( self::INDEX, array() ), 0, max( 1, (int) $limit ) ) as $uid ) {
			$s = self::get( $uid );
			if ( $s ) {
				$out[] = array( 'undo_id' => $uid, 'time' => $s['time'], 'client' => $s['client'], 'tool' => $s['tool'], 'summary' => $s['summary'] );
			}
		}
		return $out;
	}

	public static function register_tools() {
		$t = 'SC_Tools';
		$t::add(
			'list_undoable_changes',
			array(
				'group'       => 'site',
				'description' => 'List recent changes that can be undone (newest first), with their undo_id.',
				'properties'  => array( 'limit' => $t::p( 'integer', 'Default 20.' ) ),
				'callback'    => function ( $a ) {
					return SC_Undo::list_recent( SC_Tools::int( $a, 'limit', 20 ) );
				},
			)
		);
		$t::add(
			'undo_change',
			array(
				'group'       => 'site',
				'description' => 'Roll back one earlier change by its undo_id (from list_undoable_changes or the activity log). Restores the post, settings or CSS to how they were before.',
				'properties'  => array( 'undo_id' => $t::p( 'string', 'Undo id.' ) ),
				'required'    => array( 'undo_id' ),
				'write'       => true,
				'callback'    => function ( $a ) {
					return SC_Undo::undo( (string) $a['undo_id'] );
				},
			)
		);
	}
}
