<?php
/**
 * Divi (classic shortcode format) and WPBakery support.
 *
 * Both builders keep their layout in post_content as shortcodes. These tools
 * list the layout as a tree and change one module at a time by editing the
 * exact text of that shortcode in place, so the rest of the layout is left
 * byte for byte as it was. The result is checked for balanced tags before saving.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_Shortcode_Builders {

	const PREFIXES = array( 'et_pb_', 'vc_' );

	/** Attributes worth showing in the layout tree. */
	const SHOW = array( 'admin_label', 'title', 'heading', 'subtitle', 'button_text', 'button_url', 'button_one_text', 'button_one_url', 'button_two_text', 'button_two_url', 'link_url', 'url', 'src', 'image', 'image_url', 'alt', 'text', 'link', 'content', 'name', 'job_title', 'el_class' );

	public static function register() {
		$t = 'SC_Tools';

		$t::add( 'get_shortcode_layout', array(
			'group'       => 'content',
			'description' => 'Divi (shortcode format) and WPBakery pages: list the layout as a tree of modules (sections, rows, columns, text, images, buttons) with a node number, key settings and text for each. Use the node numbers with update_shortcode_node. Node numbers change after structural edits, so read again before editing.',
			'properties'  => array(
				'id'   => $t::p( 'integer', 'Page or post ID.' ),
				'node' => $t::p( 'integer', 'Only this node and its children.' ),
				'full' => $t::p( 'boolean', 'With node: return its complete shortcode text. Default false.' ),
			),
			'required'    => array( 'id' ),
			'callback'    => array( __CLASS__, 'get_layout' ),
		) );

		$t::add( 'update_shortcode_node', array(
			'group'       => 'content',
			'description' => 'Divi (shortcode format) and WPBakery pages: change one module by node number (from get_shortcode_layout). attrs sets or changes settings, for example {"button_text":"Buy now","button_url":"https://example.com"}; use null to remove one. inner replaces the content between the opening and closing tags (the HTML of a text module). Everything else on the page is left untouched. Do not edit raw HTML/code modules that are encoded.',
			'properties'  => array(
				'id'    => $t::p( 'integer', 'Page or post ID.' ),
				'node'  => $t::p( 'integer', 'Node number.' ),
				'attrs' => array( 'type' => 'object', 'description' => 'Settings to set (null removes).' ),
				'inner' => $t::p( 'string', 'New inner content for modules that have a closing tag.' ),
			),
			'required'    => array( 'id', 'node' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'update_node' ),
		) );

		$t::add( 'divi_clear_cache', array(
			'group'       => 'content',
			'description' => 'Divi: clear its static CSS cache so edited pages are re-rendered. Run after editing Divi pages if the front end looks stale.',
			'write'       => true,
			'callback'    => array( __CLASS__, 'divi_clear_cache' ),
		) );
	}

	/* ------------------------------ parser ------------------------------ */

	private static function known( $tag ) {
		foreach ( self::PREFIXES as $p ) {
			if ( 0 === strpos( $tag, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse builder shortcodes. Returns a list of nodes in document order:
	 * id, tag, parent, open_start, open_end, close_start (or null), end, selfclose.
	 * Returns null if the tags are not balanced.
	 */
	public static function parse( $s ) {
		if ( ! preg_match_all( '/\[(\/?)([a-zA-Z0-9_\-]+)((?:\s+[^\]]*?)?)(\/?)\]/', $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$closes = array();
		foreach ( $m as $x ) {
			if ( '/' === $x[1][0] && self::known( $x[2][0] ) ) {
				$tag            = $x[2][0];
				$closes[ $tag ] = isset( $closes[ $tag ] ) ? $closes[ $tag ] + 1 : 1;
			}
		}
		$nodes = array();
		$stack = array();
		foreach ( $m as $x ) {
			$tag = $x[2][0];
			if ( ! self::known( $tag ) ) {
				continue;
			}
			$start = $x[0][1];
			$end   = $start + strlen( $x[0][0] );
			if ( '/' === $x[1][0] ) {
				$top = end( $stack );
				if ( false === $top || $nodes[ $top ]['tag'] !== $tag ) {
					return null;
				}
				array_pop( $stack );
				$nodes[ $top ]['close_start'] = $start;
				$nodes[ $top ]['end']         = $end;
				continue;
			}
			$id       = count( $nodes );
			$leaf     = '/' === $x[4][0] || empty( $closes[ $tag ] );
			$nodes[]  = array(
				'id'         => $id,
				'tag'        => $tag,
				'parent'     => $stack ? end( $stack ) : null,
				'open_start' => $start,
				'open_end'   => $end,
				'close_start' => null,
				'end'        => $end,
				'selfclose'  => '/' === $x[4][0],
			);
			if ( ! $leaf ) {
				$stack[] = $id;
			}
		}
		return $stack ? null : $nodes;
	}

	private static function attrs( $open ) {
		$out = array();
		if ( preg_match_all( '/([a-zA-Z0-9_\-]+)=(?:"([^"]*)"|\'([^\']*)\'|([^\s\]\/]+))/', $open, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $a ) {
				$v = isset( $a[4] ) ? $a[4] : ( isset( $a[3] ) && '' !== $a[3] ? $a[3] : $a[2] );
				$out[ $a[1] ] = $v;
			}
		}
		return $out;
	}

	private static function load( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			SC_Tools::fail( 'Post not found.' );
		}
		if ( false !== strpos( $post->post_content, 'wp:divi/' ) ) {
			SC_Tools::fail( 'This page uses the Divi 5 block format, which these tools do not edit yet. Divi 4 shortcode pages and WPBakery pages are supported.' );
		}
		$nodes = self::parse( $post->post_content );
		if ( null === $nodes ) {
			SC_Tools::fail( "The page layout shortcodes in #{$post->ID} are not balanced, so it is not safe to edit automatically." );
		}
		if ( ! $nodes ) {
			SC_Tools::fail( "#{$post->ID} has no Divi or WPBakery shortcodes (builder: " . SC_Tools_Builders::detect( $post ) . ').' );
		}
		return array( $post, $nodes );
	}

	private static function describe( $s, array $nodes, $i, $depth = 0 ) {
		$n    = $nodes[ $i ];
		$open = substr( $s, $n['open_start'], $n['open_end'] - $n['open_start'] );
		$row  = array( 'node' => $n['id'], 'tag' => $n['tag'] );
		$at   = array();
		foreach ( self::attrs( $open ) as $k => $v ) {
			if ( in_array( $k, self::SHOW, true ) && '' !== $v ) {
				$at[ $k ] = mb_substr( str_replace( array( '%22', '`' ), '"', $v ), 0, 200 );
			}
		}
		if ( $at ) {
			$row['attrs'] = $at;
		}
		$kids = array();
		foreach ( $nodes as $c ) {
			if ( $c['parent'] === $n['id'] ) {
				$kids[] = $c['id'];
			}
		}
		if ( null !== $n['close_start'] && ! $kids ) {
			$inner = substr( $s, $n['open_end'], $n['close_start'] - $n['open_end'] );
			$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $inner ) ) );
			if ( '' !== $plain ) {
				$row['text'] = mb_substr( $plain, 0, 300 );
			}
		}
		if ( $kids ) {
			$row['children'] = array();
			foreach ( $kids as $k ) {
				$row['children'][] = self::describe( $s, $nodes, $k, $depth + 1 );
			}
		}
		return $row;
	}

	public static function get_layout( $a ) {
		list( $post, $nodes ) = self::load( $a['id'] );
		$s = $post->post_content;
		if ( isset( $a['node'] ) && '' !== $a['node'] ) {
			$i = (int) $a['node'];
			if ( ! isset( $nodes[ $i ] ) ) {
				SC_Tools::fail( "Node $i not found." );
			}
			if ( SC_Tools::bool( $a, 'full' ) ) {
				$n = $nodes[ $i ];
				return array( 'node' => $i, 'shortcode' => substr( $s, $n['open_start'], $n['end'] - $n['open_start'] ) );
			}
			return array( 'id' => $post->ID, 'builder' => SC_Tools_Builders::detect( $post ), 'tree' => array( self::describe( $s, $nodes, $i ) ) );
		}
		$roots = array();
		foreach ( $nodes as $n ) {
			if ( null === $n['parent'] ) {
				$roots[] = self::describe( $s, $nodes, $n['id'] );
			}
		}
		return array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'builder' => SC_Tools_Builders::detect( $post ), 'modules' => count( $nodes ), 'tree' => $roots );
	}

	/* ------------------------------ editing ----------------------------- */

	public static function encode_value( $tag, $value ) {
		$value = (string) $value;
		if ( false !== strpos( $value, ']' ) ) {
			SC_Tools::fail( 'Setting values cannot contain the character ].' );
		}
		if ( false !== strpos( $value, '"' ) ) {
			$value = 0 === strpos( $tag, 'vc_' ) ? str_replace( '"', '`', $value ) : str_replace( '"', '%22', $value );
		}
		return $value;
	}

	public static function set_attr( $open, $tag, $name, $value ) {
		$name_q = preg_quote( $name, '/' );
		$re     = '/(\s)' . $name_q . '=(?:"[^"]*"|\'[^\']*\'|[^\s\]\/]+)/';
		if ( null === $value ) {
			return preg_replace( $re, '', $open, 1 );
		}
		$new = $name . '="' . self::encode_value( $tag, $value ) . '"';
		if ( preg_match( $re, $open ) ) {
			return preg_replace_callback( $re, function ( $mm ) use ( $new ) {
				return $mm[1] . $new;
			}, $open, 1 );
		}
		// Append before the closing bracket (and the self-closing slash if any).
		return preg_replace( '/\s*(\/?)\]$/', ' ' . $new . ' $1]', $open, 1 );
	}

	public static function update_node( $a ) {
		list( $post, $nodes ) = self::load( $a['id'] );
		$i = (int) $a['node'];
		if ( ! isset( $nodes[ $i ] ) ) {
			SC_Tools::fail( "Node $i not found. Run get_shortcode_layout again." );
		}
		$attrs = isset( $a['attrs'] ) ? $a['attrs'] : null;
		if ( is_string( $attrs ) ) {
			$attrs = json_decode( $attrs, true );
		}
		$has_inner = isset( $a['inner'] );
		if ( ( ! is_array( $attrs ) || ! $attrs ) && ! $has_inner ) {
			SC_Tools::fail( 'Give attrs and/or inner.' );
		}
		$n = $nodes[ $i ];
		$s = $post->post_content;
		if ( $has_inner ) {
			if ( null === $n['close_start'] ) {
				SC_Tools::fail( "'{$n['tag']}' has no closing tag, so it has no inner content. Use attrs instead." );
			}
			$s = substr( $s, 0, $n['open_end'] ) . (string) $a['inner'] . substr( $s, $n['close_start'] );
		}
		if ( is_array( $attrs ) && $attrs ) {
			$open = substr( $s, $n['open_start'], $n['open_end'] - $n['open_start'] );
			foreach ( $attrs as $k => $v ) {
				if ( ! preg_match( '/^[a-zA-Z0-9_\-]+$/', (string) $k ) ) {
					SC_Tools::fail( "Invalid setting name '$k'." );
				}
				$open = self::set_attr( $open, $n['tag'], (string) $k, null === $v ? null : ( is_bool( $v ) ? ( $v ? 'on' : 'off' ) : $v ) );
			}
			$s = substr( $s, 0, $n['open_start'] ) . $open . substr( $s, $n['open_end'] );
		}
		if ( null === self::parse( $s ) ) {
			SC_Tools::fail( 'That change would leave the page layout malformed, so nothing was saved.' );
		}
		$res = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $s ) ), true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::divi_clear_cache( array() , true );
		return array( 'message' => "Updated node $i ({$n['tag']}) on #{$post->ID}.", 'link' => get_permalink( $post ) );
	}

	public static function divi_clear_cache( $a, $quiet = false ) {
		if ( class_exists( 'ET_Core_PageResource' ) ) {
			ET_Core_PageResource::remove_static_resources( 'all', 'all' );
			return $quiet ? true : array( 'message' => 'Divi static CSS cache cleared.' );
		}
		if ( $quiet ) {
			return false;
		}
		SC_Tools::fail( 'Divi is not active on this site.' );
	}
}
