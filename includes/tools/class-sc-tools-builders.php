<?php
/**
 * Page-builder awareness. Detects Elementor, Divi, WPBakery and blocks, and gives
 * AI tools to read and edit Elementor pages (whose content lives in post meta, not
 * in post_content).
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_Builders {

	/** Text-bearing widget settings, in the order they are shown. */
	const TEXT_KEYS = array( 'title', 'title_text', 'heading', 'sub_title', 'subtitle', 'description', 'description_text', 'editor', 'text', 'button_text', 'html', 'content', 'tab_title', 'tab_content', 'alert_title', 'alert_description', 'caption', 'item_description' );

	public static function detect( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return 'classic';
		}
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) || get_post_meta( $post->ID, '_elementor_data', true ) ) {
			return 'elementor';
		}
		if ( 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) || false !== strpos( $post->post_content, '[et_pb_section' ) ) {
			return 'divi';
		}
		if ( 'true' === get_post_meta( $post->ID, '_wpb_vc_js_status', true ) || false !== strpos( $post->post_content, '[vc_row' ) ) {
			return 'wpbakery';
		}
		return has_blocks( $post->post_content ) ? 'blocks' : 'classic';
	}

	public static function register() {
		$t = 'SC_Tools';

		$t::add( 'get_elementor_data', array(
			'group'       => 'content',
			'description' => 'Elementor pages: list the layout tree (sections/containers, columns, widgets) with each element id and its text, link and image settings. Pass element_id with full=true to see every setting of one element. Elementor stores content in post meta, so post_content does not show it.',
			'properties'  => array(
				'id'         => $t::p( 'integer', 'Page or post ID.' ),
				'element_id' => $t::p( 'string', 'Only this element (and its children).' ),
				'full'       => $t::p( 'boolean', 'Return all settings of element_id. Default false.' ),
			),
			'required'    => array( 'id' ),
			'callback'    => array( __CLASS__, 'get_elementor_data' ),
		) );

		$t::add( 'update_elementor_element', array(
			'group'       => 'content',
			'description' => 'Elementor pages: change settings of one element by its id (from get_elementor_data), for example {"title":"New heading"} or {"editor":"<p>New text</p>"} or {"link":{"url":"https://example.com"}}. Settings are merged into the existing ones. Regenerates the page CSS.',
			'properties'  => array(
				'id'         => $t::p( 'integer', 'Page or post ID.' ),
				'element_id' => $t::p( 'string', 'Element id.' ),
				'settings'   => array( 'type' => 'object', 'description' => 'Settings to merge.' ),
			),
			'required'    => array( 'id', 'element_id', 'settings' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'update_elementor_element' ),
		) );

		$t::add( 'elementor_find_replace', array(
			'group'       => 'content',
			'description' => 'Find and replace text inside Elementor page data (headings, text, buttons, links, image URLs). Dry run unless dry_run=false. Use this instead of search_replace_content for Elementor pages.',
			'properties'  => array(
				'search'    => $t::p( 'string', 'Text to find (case-sensitive).' ),
				'replace'   => $t::p( 'string', 'Replacement.' ),
				'post_ids'  => $t::p_list( 'Limit to these page IDs. Default: every Elementor page.' ),
				'dry_run'   => $t::p( 'boolean', 'Default true: only report matches.' ),
			),
			'required'    => array( 'search', 'replace' ),
			'allow_empty' => array( 'replace' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'elementor_find_replace' ),
		) );

		$t::add( 'elementor_clear_cache', array(
			'group'       => 'content',
			'description' => 'Elementor: regenerate all CSS and clear its cache. Run after editing Elementor pages if the front end looks stale.',
			'write'       => true,
			'callback'    => array( __CLASS__, 'elementor_clear_cache' ),
		) );
	}

	/* ------------------------------------------------------------------ */

	private static function load( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			SC_Tools::fail( 'Post not found.' );
		}
		$raw  = get_post_meta( $post->ID, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			SC_Tools::fail( "#{$post->ID} is not an Elementor page (builder: " . self::detect( $post ) . ').' );
		}
		return array( $post, $data );
	}

	private static function save( $post_id, array $data ) {
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		delete_post_meta( $post_id, '_elementor_css' );
		wp_update_post( array( 'ID' => $post_id ) );
		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( Throwable $e ) {
				// Cache clear is best effort.
			}
		}
	}

	private static function find_element( array $nodes, $id ) {
		foreach ( $nodes as $node ) {
			if ( isset( $node['id'] ) && (string) $node['id'] === (string) $id ) {
				return $node;
			}
			if ( ! empty( $node['elements'] ) ) {
				$hit = self::find_element( $node['elements'], $id );
				if ( $hit ) {
					return $hit;
				}
			}
		}
		return null;
	}

	private static function brief( array $nodes ) {
		$out = array();
		foreach ( $nodes as $n ) {
			$row = array( 'id' => isset( $n['id'] ) ? $n['id'] : null, 'type' => isset( $n['elType'] ) ? $n['elType'] : '' );
			if ( ! empty( $n['widgetType'] ) ) {
				$row['widget'] = $n['widgetType'];
			}
			$s = isset( $n['settings'] ) && is_array( $n['settings'] ) ? $n['settings'] : array();
			$text = array();
			foreach ( self::TEXT_KEYS as $k ) {
				if ( isset( $s[ $k ] ) && is_string( $s[ $k ] ) && '' !== $s[ $k ] ) {
					$text[ $k ] = mb_substr( $s[ $k ], 0, 300 );
				}
			}
			if ( $text ) {
				$row['text'] = $text;
			}
			if ( ! empty( $s['link']['url'] ) ) {
				$row['link'] = $s['link']['url'];
			}
			if ( ! empty( $s['image']['url'] ) ) {
				$row['image'] = $s['image']['url'];
			}
			if ( ! empty( $n['elements'] ) ) {
				$row['children'] = self::brief( $n['elements'] );
			}
			$out[] = $row;
		}
		return $out;
	}

	public static function get_elementor_data( $a ) {
		list( $post, $data ) = self::load( $a['id'] );
		$eid = SC_Tools::str( $a, 'element_id' );
		if ( $eid ) {
			$node = self::find_element( $data, $eid );
			if ( ! $node ) {
				SC_Tools::fail( "Element '$eid' not found on #{$post->ID}." );
			}
			return SC_Tools::bool( $a, 'full' ) ? $node : array( 'id' => $post->ID, 'tree' => self::brief( array( $node ) ) );
		}
		return array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'tree' => self::brief( $data ) );
	}

	private static function patch( array &$nodes, $id, array $settings ) {
		foreach ( $nodes as &$n ) {
			if ( isset( $n['id'] ) && (string) $n['id'] === (string) $id ) {
				$cur               = isset( $n['settings'] ) && is_array( $n['settings'] ) ? $n['settings'] : array();
				$n['settings']     = array_replace_recursive( $cur, $settings );
				return true;
			}
			if ( ! empty( $n['elements'] ) && self::patch( $n['elements'], $id, $settings ) ) {
				return true;
			}
		}
		return false;
	}

	public static function update_elementor_element( $a ) {
		list( $post, $data ) = self::load( $a['id'] );
		$settings = $a['settings'];
		if ( is_string( $settings ) ) {
			$settings = json_decode( $settings, true );
		}
		if ( ! is_array( $settings ) || ! $settings ) {
			SC_Tools::fail( 'settings must be a non-empty object.' );
		}
		if ( ! self::patch( $data, $a['element_id'], $settings ) ) {
			SC_Tools::fail( "Element '{$a['element_id']}' not found on #{$post->ID}." );
		}
		self::save( $post->ID, $data );
		return array( 'message' => "Updated element {$a['element_id']} on #{$post->ID}.", 'link' => get_permalink( $post ) );
	}

	private static function walk_replace( &$value, $search, $replace, &$count ) {
		if ( is_string( $value ) ) {
			$n = substr_count( $value, $search );
			if ( $n ) {
				$count += $n;
				$value  = str_replace( $search, $replace, $value );
			}
		} elseif ( is_array( $value ) ) {
			foreach ( $value as &$v ) {
				self::walk_replace( $v, $search, $replace, $count );
			}
		}
	}

	public static function elementor_find_replace( $a ) {
		$search  = (string) $a['search'];
		$replace = (string) $a['replace'];
		$dry     = SC_Tools::bool( $a, 'dry_run', true );
		$ids     = SC_Tools::arr( $a, 'post_ids' );
		if ( $ids ) {
			$ids = array_map( 'intval', $ids );
		} else {
			$ids = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_elementor_data' ) );
		}
		$items = array();
		$total = 0;
		foreach ( $ids as $id ) {
			$raw  = get_post_meta( $id, '_elementor_data', true );
			$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			if ( ! is_array( $data ) ) {
				continue;
			}
			$count = 0;
			self::walk_replace( $data, $search, $replace, $count );
			if ( $count ) {
				$items[] = array( 'id' => (int) $id, 'title' => get_the_title( $id ), 'matches' => $count );
				$total  += $count;
				if ( ! $dry ) {
					self::save( $id, $data );
				}
			}
		}
		return array( 'message' => ( $dry ? 'Dry run: ' : 'Changed ' ) . "$total matches on " . count( $items ) . ' Elementor pages.', 'dry_run' => $dry, 'items' => $items );
	}

	public static function elementor_clear_cache( $a ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			SC_Tools::fail( 'Elementor is not active on this site.' );
		}
		\Elementor\Plugin::$instance->files_manager->clear_cache();
		return array( 'message' => 'Elementor cache cleared. CSS regenerates on the next page view.' );
	}
}
