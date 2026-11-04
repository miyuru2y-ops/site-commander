<?php
/**
 * Gutenberg block editing, ACF fields, translations (Polylang / WPML) and internal links.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_Blocks {

	public static function register() {
		$t    = 'SC_Tools';
		$path = $t::p( 'string', 'Block path from list_blocks, e.g. "3" or "2.0.1" (inner blocks).' );

		/* Blocks ---------------------------------------------------------- */
		$t::add( 'list_blocks', array(
			'group'       => 'content',
			'description' => 'List the Gutenberg blocks of a post/page/template part as a tree with paths, block names, key attributes and a text preview. Use the path with get_block, update_block, insert_block, delete_block and move_block.',
			'properties'  => array( 'id' => $t::p( 'integer', 'Post ID.' ) ),
			'required'    => array( 'id' ),
			'callback'    => array( __CLASS__, 'list_blocks' ),
		) );
		$t::add( 'get_block', array(
			'group'       => 'content',
			'description' => 'Get the full block markup of one block (including its inner blocks).',
			'properties'  => array( 'id' => $t::p( 'integer', 'Post ID.' ), 'path' => $path ),
			'required'    => array( 'id', 'path' ),
			'callback'    => array( __CLASS__, 'get_block' ),
		) );
		$t::add( 'update_block', array(
			'group'       => 'content',
			'description' => 'Change one block without touching the rest of the page: replace it with new block markup, merge new attributes, or find/replace text inside it.',
			'properties'  => array(
				'id'      => $t::p( 'integer', 'Post ID.' ),
				'path'    => $path,
				'markup'  => $t::p( 'string', 'New block markup that replaces the block (may contain several blocks).' ),
				'attrs'   => array( 'type' => 'object', 'description' => 'Attributes to merge into the block comment (e.g. {"level":3}).' ),
				'search'  => $t::p( 'string', 'Text to find inside the block.' ),
				'replace' => $t::p( 'string', 'Replacement for search.' ),
			),
			'required'    => array( 'id', 'path' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'update_block' ),
		) );
		$t::add( 'insert_block', array(
			'group'       => 'content',
			'description' => 'Insert new block markup before or after a block, inside a block (as its last child), or at the end of the page.',
			'properties'  => array(
				'id'       => $t::p( 'integer', 'Post ID.' ),
				'markup'   => $t::p( 'string', 'Block markup, e.g. <!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ),
				'path'     => $t::p( 'string', 'Reference block path. Omit to add at the end of the page.' ),
				'position' => $t::p_enum( array( 'after', 'before', 'inside' ), 'Default after.' ),
			),
			'required'    => array( 'id', 'markup' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'insert_block' ),
		) );
		$t::add( 'delete_block', array(
			'group'       => 'content',
			'description' => 'Remove one block (and its inner blocks) from a post/page.',
			'properties'  => array( 'id' => $t::p( 'integer', 'Post ID.' ), 'path' => $path ),
			'required'    => array( 'id', 'path' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'delete_block' ),
		) );
		$t::add( 'move_block', array(
			'group'       => 'content',
			'description' => 'Move a block one place up or down among its siblings.',
			'properties'  => array(
				'id'        => $t::p( 'integer', 'Post ID.' ),
				'path'      => $path,
				'direction' => $t::p_enum( array( 'up', 'down' ), 'Direction.' ),
				'steps'     => $t::p( 'integer', 'How many places. Default 1.' ),
			),
			'required'    => array( 'id', 'path', 'direction' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'move_block' ),
		) );

		/* ACF ------------------------------------------------------------- */
		$t::add( 'get_acf_fields', array(
			'group'       => 'content',
			'description' => 'Advanced Custom Fields: list the ACF fields of a post (or of the options page with id 0) with name, label, type and value.',
			'properties'  => array( 'id' => $t::p( 'integer', 'Post ID, or 0 for ACF options pages.' ) ),
			'required'    => array( 'id' ),
			'allow_empty' => array( 'id' ),
			'callback'    => array( __CLASS__, 'get_acf_fields' ),
		) );
		$t::add( 'update_acf_fields', array(
			'group'       => 'content',
			'description' => 'Advanced Custom Fields: set field values on a post (or options page with id 0). fields={"field_name": value, ...}.',
			'properties'  => array(
				'id'     => $t::p( 'integer', 'Post ID, or 0 for ACF options pages.' ),
				'fields' => array( 'type' => 'object', 'description' => 'Field name => value.' ),
			),
			'required'    => array( 'id', 'fields' ),
			'allow_empty' => array( 'id' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'update_acf_fields' ),
		) );

		/* Translations ---------------------------------------------------- */
		$t::add( 'list_languages', array(
			'group'       => 'content',
			'description' => 'Polylang or WPML: list the site languages.',
			'callback'    => array( __CLASS__, 'list_languages' ),
		) );
		$t::add( 'get_translations', array(
			'group'       => 'content',
			'description' => 'Polylang or WPML: show the language of a post and the IDs of its translations.',
			'properties'  => array( 'id' => $t::p( 'integer', 'Post ID.' ) ),
			'required'    => array( 'id' ),
			'callback'    => array( __CLASS__, 'get_translations' ),
		) );
		$t::add( 'create_translation', array(
			'group'       => 'content',
			'description' => 'Polylang or WPML: create the translation of a post/page in another language and link it to the original. Translate the title and content yourself and pass them in.',
			'properties'  => array(
				'id'      => $t::p( 'integer', 'Original post ID.' ),
				'lang'    => $t::p( 'string', 'Language code from list_languages, e.g. "de".' ),
				'title'   => $t::p( 'string', 'Translated title.' ),
				'content' => $t::p( 'string', 'Translated content (keep the block markup).' ),
				'excerpt' => $t::p( 'string', 'Translated excerpt.' ),
				'slug'    => $t::p( 'string', 'Translated slug.' ),
				'status'  => $t::p_enum( array( 'draft', 'publish', 'pending' ), 'Default draft.' ),
			),
			'required'    => array( 'id', 'lang', 'title', 'content' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'create_translation' ),
		) );

		/* Internal links -------------------------------------------------- */
		$t::add( 'add_internal_link', array(
			'group'       => 'seo',
			'description' => 'Turn the first plain-text occurrence of a phrase in a post into a link (skips headings and existing links). Use with find_internal_link_opportunities.',
			'properties'  => array(
				'id'        => $t::p( 'integer', 'Post that gets the link.' ),
				'anchor'    => $t::p( 'string', 'Exact phrase to link (case-insensitive).' ),
				'target_id' => $t::p( 'integer', 'Post/page to link to.' ),
				'url'       => $t::p( 'string', 'Or any URL to link to.' ),
			),
			'required'    => array( 'id', 'anchor' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'add_internal_link' ),
		) );
	}

	/* -------------------------------------------------------------------
	 * Block helpers
	 * ----------------------------------------------------------------- */

	private static function load( $id ) {
		$p = get_post( (int) $id );
		if ( ! $p ) {
			SC_Tools::fail( "Post #$id not found." );
		}
		return $p;
	}

	private static function visible( array $b ) {
		return null !== $b['blockName'] || '' !== trim( (string) $b['innerHTML'] );
	}

	/** Map visible positions to raw array keys. */
	private static function visible_keys( array $blocks ) {
		$keys = array();
		foreach ( $blocks as $k => $b ) {
			if ( self::visible( $b ) ) {
				$keys[] = $k;
			}
		}
		return $keys;
	}

	private static function parse_path( $path ) {
		$path = trim( (string) $path );
		if ( ! preg_match( '/^\d+(\.\d+)*$/', $path ) ) {
			SC_Tools::fail( "Invalid block path '$path'. Use list_blocks to get paths like \"3\" or \"2.0\"." );
		}
		return array_map( 'intval', explode( '.', $path ) );
	}

	/**
	 * Apply $fn( array $siblings, int $raw_key, bool $top ) at $path and return the new tree.
	 * $fn returns the new siblings array and an innerContent change: array( siblings, op ), op = null|'del'|array('ins', raw_index).
	 */
	private static function apply( array $blocks, array $path, callable $fn, $top = true ) {
		$pos  = array_shift( $path );
		$keys = self::visible_keys( $blocks );
		if ( ! isset( $keys[ $pos ] ) ) {
			SC_Tools::fail( 'No block at that path. Use list_blocks to see the current paths.' );
		}
		$k = $keys[ $pos ];
		if ( ! $path ) {
			return $fn( $blocks, $k, $top );
		}
		$b      = $blocks[ $k ];
		$before = count( $b['innerBlocks'] );
		$inner  = self::apply( $b['innerBlocks'], $path, $fn, false );
		$b      = self::sync_inner( $b, $inner, $before );
		$blocks[ $k ] = $b;
		return $blocks;
	}

	/** Replace a block's inner blocks and keep innerContent placeholders in step. */
	private static function sync_inner( array $b, array $inner, $before ) {
		$inner = array_values( $inner );
		$after = count( $inner );
		$ic    = $b['innerContent'];
		if ( $after < $before ) {
			// Remove trailing placeholders until counts match (positions of nulls are equivalent).
			for ( $n = $before - $after; $n > 0; $n-- ) {
				for ( $i = count( $ic ) - 1; $i >= 0; $i-- ) {
					if ( null === $ic[ $i ] ) {
						array_splice( $ic, $i, 1 );
						break;
					}
				}
			}
		} elseif ( $after > $before ) {
			for ( $n = $after - $before; $n > 0; $n-- ) {
				$last_null = -1;
				foreach ( $ic as $i => $c ) {
					if ( null === $c ) {
						$last_null = $i;
					}
				}
				if ( $last_null >= 0 ) {
					array_splice( $ic, $last_null + 1, 0, array( null ) );
				} elseif ( count( $ic ) >= 2 ) {
					array_splice( $ic, count( $ic ) - 1, 0, array( null ) );
				} else {
					$ic[] = null;
				}
			}
		}
		$b['innerBlocks']  = $inner;
		$b['innerContent'] = $ic;
		return $b;
	}

	private static function save( $p, array $blocks, $msg ) {
		$content = serialize_blocks( $blocks );
		kses_remove_filters();
		$r = wp_update_post( wp_slash( array( 'ID' => $p->ID, 'post_content' => $content ) ), true );
		kses_init_filters();
		if ( is_wp_error( $r ) ) {
			SC_Tools::fail( $r->get_error_message() );
		}
		return array( 'message' => $msg . " in \"{$p->post_title}\" (#{$p->ID}).", 'id' => $p->ID );
	}

	private static function new_blocks( $markup ) {
		$markup = (string) $markup;
		if ( '' === trim( $markup ) ) {
			SC_Tools::fail( 'markup is empty.' );
		}
		$out = array();
		foreach ( parse_blocks( $markup ) as $b ) {
			if ( self::visible( $b ) ) {
				$out[] = $b;
			}
		}
		return $out;
	}

	private static function text( $html ) {
		$t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $html ) ) );
		return mb_strlen( $t ) > 120 ? mb_substr( $t, 0, 117 ) . '…' : $t;
	}

	private static function tree( array $blocks, $prefix = '' ) {
		$out = array();
		$i   = 0;
		foreach ( $blocks as $b ) {
			if ( ! self::visible( $b ) ) {
				continue;
			}
			$path  = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$attrs = array();
			foreach ( (array) $b['attrs'] as $k => $v ) {
				$enc = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
				if ( strlen( (string) $enc ) <= 80 ) {
					$attrs[ $k ] = $v;
				}
			}
			$row = array( 'path' => $path, 'block' => $b['blockName'] ? $b['blockName'] : 'core/freeform (classic HTML)' );
			if ( $attrs ) {
				$row['attrs'] = $attrs;
			}
			$txt = self::text( $b['innerHTML'] );
			if ( '' !== $txt ) {
				$row['text'] = $txt;
			}
			if ( $b['innerBlocks'] ) {
				$row['inner'] = self::tree( $b['innerBlocks'], $path );
			}
			$out[] = $row;
			$i++;
		}
		return $out;
	}

	public static function list_blocks( $a ) {
		$p      = self::load( $a['id'] );
		$blocks = parse_blocks( $p->post_content );
		$tree   = self::tree( $blocks );
		$note   = ( 1 === count( $tree ) && 'core/freeform (classic HTML)' === $tree[0]['block'] ) ? 'This post has no blocks (classic content). Edit it with update_content instead.' : '';
		return array( 'id' => $p->ID, 'title' => $p->post_title, 'blocks' => $tree, 'note' => $note );
	}

	public static function get_block( $a ) {
		$p      = self::load( $a['id'] );
		$found  = null;
		self::apply( parse_blocks( $p->post_content ), self::parse_path( $a['path'] ), function ( $sib, $k ) use ( &$found ) {
			$found = $sib[ $k ];
			return $sib;
		} );
		return array( 'path' => (string) $a['path'], 'block' => $found['blockName'], 'attrs' => $found['attrs'], 'markup' => serialize_block( $found ) );
	}

	public static function update_block( $a ) {
		$p       = self::load( $a['id'] );
		$markup  = SC_Tools::str( $a, 'markup' );
		$attrs   = isset( $a['attrs'] ) && is_array( $a['attrs'] ) ? $a['attrs'] : null;
		$search  = SC_Tools::str( $a, 'search' );
		$replace = SC_Tools::str( $a, 'replace', '' );
		if ( null === $markup && null === $attrs && ( null === $search || '' === $search ) ) {
			SC_Tools::fail( 'Pass markup, attrs, or search + replace.' );
		}
		$count  = 0;
		$blocks = self::apply( parse_blocks( $p->post_content ), self::parse_path( $a['path'] ), function ( $sib, $k ) use ( $markup, $attrs, $search, $replace, &$count ) {
			if ( null !== $markup ) {
				array_splice( $sib, $k, 1, self::new_blocks( $markup ) );
				$count = 1;
				return $sib;
			}
			$b = $sib[ $k ];
			if ( $attrs ) {
				$b['attrs'] = array_merge( (array) $b['attrs'], $attrs );
				$count++;
			}
			if ( null !== $search && '' !== $search ) {
				$n               = 0;
				$b['innerHTML']  = str_replace( $search, $replace, $b['innerHTML'], $n );
				foreach ( $b['innerContent'] as $i => $c ) {
					if ( is_string( $c ) ) {
						$b['innerContent'][ $i ] = str_replace( $search, $replace, $c );
					}
				}
				if ( ! $n ) {
					SC_Tools::fail( 'The search text was not found in that block (inner blocks are separate paths).' );
				}
				$count += $n;
			}
			$sib[ $k ] = $b;
			return $sib;
		} );
		return self::save( $p, $blocks, 'Updated block ' . $a['path'] );
	}

	public static function insert_block( $a ) {
		$p      = self::load( $a['id'] );
		$new    = self::new_blocks( $a['markup'] );
		$blocks = parse_blocks( $p->post_content );
		$path   = SC_Tools::str( $a, 'path' );
		$where  = SC_Tools::str( $a, 'position', 'after' );
		if ( null === $path || '' === $path ) {
			foreach ( $new as $b ) {
				$blocks[] = array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => "\n\n", 'innerContent' => array( "\n\n" ) );
				$blocks[] = $b;
			}
			return self::save( $p, $blocks, 'Added ' . count( $new ) . ' block(s) at the end' );
		}
		$blocks = self::apply( $blocks, self::parse_path( $path ), function ( $sib, $k ) use ( $new, $where ) {
			if ( 'inside' === $where ) {
				$b      = $sib[ $k ];
				$before = count( $b['innerBlocks'] );
				$inner  = array_merge( $b['innerBlocks'], $new );
				$sib[ $k ] = self::sync_inner( $b, $inner, $before );
				return $sib;
			}
			array_splice( $sib, 'before' === $where ? $k : $k + 1, 0, $new );
			return $sib;
		} );
		return self::save( $p, $blocks, 'Inserted ' . count( $new ) . " block(s) $where block $path" );
	}

	public static function delete_block( $a ) {
		$p      = self::load( $a['id'] );
		$blocks = self::apply( parse_blocks( $p->post_content ), self::parse_path( $a['path'] ), function ( $sib, $k ) {
			array_splice( $sib, $k, 1 );
			return $sib;
		} );
		return self::save( $p, $blocks, 'Deleted block ' . $a['path'] );
	}

	public static function move_block( $a ) {
		$p      = self::load( $a['id'] );
		$steps  = max( 1, SC_Tools::int( $a, 'steps', 1 ) );
		$up     = 'up' === SC_Tools::str( $a, 'direction', 'down' );
		$blocks = self::apply( parse_blocks( $p->post_content ), self::parse_path( $a['path'] ), function ( $sib, $k ) use ( $steps, $up ) {
			$keys = self::visible_keys( $sib );
			$pos  = array_search( $k, $keys, true );
			$to   = $up ? max( 0, $pos - $steps ) : min( count( $keys ) - 1, $pos + $steps );
			if ( $to === $pos ) {
				SC_Tools::fail( 'The block is already at the ' . ( $up ? 'top' : 'bottom' ) . '.' );
			}
			$vis = array();
			foreach ( $keys as $kk ) {
				$vis[] = $sib[ $kk ];
			}
			$moving = $vis[ $pos ];
			array_splice( $vis, $pos, 1 );
			array_splice( $vis, $to, 0, array( $moving ) );
			foreach ( $keys as $i => $kk ) {
				$sib[ $kk ] = $vis[ $i ];
			}
			return $sib;
		} );
		return self::save( $p, $blocks, 'Moved block ' . $a['path'] . ( $up ? ' up' : ' down' ) );
	}

	/* -------------------------------------------------------------------
	 * ACF
	 * ----------------------------------------------------------------- */

	private static function need_acf() {
		if ( ! function_exists( 'get_field_objects' ) || ! function_exists( 'update_field' ) ) {
			SC_Tools::fail( 'Advanced Custom Fields is not active on this site.' );
		}
	}

	public static function get_acf_fields( $a ) {
		self::need_acf();
		$id     = (int) $a['id'];
		$target = $id ? $id : 'option';
		if ( $id ) {
			self::load( $id );
		}
		$out = array();
		foreach ( (array) get_field_objects( $target ) as $name => $f ) {
			$out[] = array( 'name' => $name, 'label' => $f['label'], 'type' => $f['type'], 'value' => $f['value'] );
		}
		return array( 'id' => $id, 'fields' => $out, 'note' => $out ? '' : 'No ACF values saved yet. Field names come from the field groups assigned to this post.' );
	}

	public static function update_acf_fields( $a ) {
		self::need_acf();
		$id     = (int) $a['id'];
		$target = $id ? $id : 'option';
		if ( $id ) {
			self::load( $id );
		}
		if ( ! is_array( $a['fields'] ) || ! $a['fields'] ) {
			SC_Tools::fail( 'fields must be an object of field_name => value.' );
		}
		$done = array();
		foreach ( $a['fields'] as $name => $value ) {
			$name = sanitize_text_field( (string) $name );
			if ( update_field( $name, $value, $target ) || get_field( $name, $target, false ) == $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons
				$done[] = $name;
			}
		}
		return array( 'message' => 'Updated ACF fields: ' . ( $done ? implode( ', ', $done ) : 'none' ) . ( $id ? " on #$id." : ' on the options page.' ), 'updated' => $done );
	}

	/* -------------------------------------------------------------------
	 * Translations
	 * ----------------------------------------------------------------- */

	private static function engine() {
		if ( function_exists( 'pll_languages_list' ) ) {
			return 'polylang';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'wpml';
		}
		SC_Tools::fail( 'No translation plugin found. Polylang or WPML must be active.' );
	}

	public static function list_languages() {
		if ( 'polylang' === self::engine() ) {
			$out = array();
			foreach ( pll_languages_list( array( 'fields' => '' ) ) as $l ) {
				$out[] = array( 'code' => $l->slug, 'name' => $l->name, 'locale' => $l->locale, 'default' => pll_default_language() === $l->slug );
			}
			return array( 'plugin' => 'Polylang', 'languages' => $out );
		}
		$out     = array();
		$default = apply_filters( 'wpml_default_language', null );
		foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $code => $l ) {
			$out[] = array( 'code' => $code, 'name' => isset( $l['translated_name'] ) ? $l['translated_name'] : $code, 'locale' => isset( $l['default_locale'] ) ? $l['default_locale'] : '', 'default' => $default === $code );
		}
		return array( 'plugin' => 'WPML', 'languages' => $out );
	}

	private static function translations_of( $id ) {
		$p = self::load( $id );
		if ( 'polylang' === self::engine() ) {
			return array( pll_get_post_language( $p->ID ), (array) pll_get_post_translations( $p->ID ) );
		}
		$type = 'post_' . $p->post_type;
		$lang = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $p->ID, 'element_type' => $p->post_type ) );
		$trid = apply_filters( 'wpml_element_trid', null, $p->ID, $type );
		$out  = array();
		foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) as $code => $tr ) {
			$out[ $code ] = (int) $tr->element_id;
		}
		return array( $lang, $out );
	}

	public static function get_translations( $a ) {
		list( $lang, $tr ) = self::translations_of( (int) $a['id'] );
		return array( 'id' => (int) $a['id'], 'language' => $lang, 'translations' => $tr );
	}

	public static function create_translation( $a ) {
		$engine = self::engine();
		$src    = self::load( $a['id'] );
		$lang   = sanitize_key( $a['lang'] );
		list( $src_lang, $tr ) = self::translations_of( $src->ID );
		if ( $lang === $src_lang ) {
			SC_Tools::fail( "The original is already in '$lang'." );
		}
		if ( ! empty( $tr[ $lang ] ) && get_post( $tr[ $lang ] ) ) {
			SC_Tools::fail( "A '$lang' translation already exists (#{$tr[ $lang ]}). Update it with update_content instead." );
		}
		$status = SC_Tools::str( $a, 'status', 'draft' );
		$status = in_array( $status, array( 'draft', 'publish', 'pending' ), true ) ? $status : 'draft';
		$data   = array(
			'post_type'    => $src->post_type,
			'post_title'   => (string) $a['title'],
			'post_content' => (string) $a['content'],
			'post_excerpt' => SC_Tools::str( $a, 'excerpt', '' ),
			'post_status'  => $status,
			'post_author'  => $src->post_author,
			'post_parent'  => $src->post_parent,
			'menu_order'   => $src->menu_order,
		);
		if ( ! empty( $a['slug'] ) ) {
			$data['post_name'] = sanitize_title( $a['slug'] );
		}
		kses_remove_filters();
		$new = wp_insert_post( wp_slash( $data ), true );
		kses_init_filters();
		if ( is_wp_error( $new ) ) {
			SC_Tools::fail( $new->get_error_message() );
		}
		$thumb = get_post_thumbnail_id( $src->ID );
		if ( $thumb ) {
			set_post_thumbnail( $new, $thumb );
		}
		$tpl = get_post_meta( $src->ID, '_wp_page_template', true );
		if ( $tpl ) {
			update_post_meta( $new, '_wp_page_template', $tpl );
		}
		if ( 'polylang' === $engine ) {
			pll_set_post_language( $new, $lang );
			if ( ! $src_lang ) {
				$src_lang = pll_default_language();
				pll_set_post_language( $src->ID, $src_lang );
			}
			$tr[ $src_lang ] = $src->ID;
			$tr[ $lang ]     = $new;
			pll_save_post_translations( $tr );
		} else {
			$type = 'post_' . $src->post_type;
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'           => $new,
					'element_type'         => $type,
					'trid'                 => apply_filters( 'wpml_element_trid', null, $src->ID, $type ),
					'language_code'        => $lang,
					'source_language_code' => $src_lang,
				)
			);
		}
		return array( 'message' => "Created the '$lang' translation of \"{$src->post_title}\" as #$new ($data[post_status]).", 'id' => (int) $new, 'link' => get_permalink( $new ) );
	}

	/* -------------------------------------------------------------------
	 * Internal links
	 * ----------------------------------------------------------------- */

	public static function add_internal_link( $a ) {
		$p      = self::load( $a['id'] );
		$anchor = trim( (string) $a['anchor'] );
		$url    = '';
		if ( ! empty( $a['target_id'] ) ) {
			$url = get_permalink( (int) $a['target_id'] );
		} elseif ( ! empty( $a['url'] ) ) {
			$url = esc_url_raw( (string) $a['url'] );
		}
		if ( ! $url ) {
			SC_Tools::fail( 'Pass target_id or url.' );
		}
		if ( mb_strlen( $anchor ) < 2 ) {
			SC_Tools::fail( 'anchor is too short.' );
		}
		$parts   = preg_split( '/(<!--.*?-->|<[^>]+>)/s', $p->post_content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$in_a    = 0;
		$in_skip = 0;
		$done    = false;
		$re      = '/(?<![\p{L}\p{N}])(' . preg_quote( $anchor, '/' ) . ')(?![\p{L}\p{N}])/iu';
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				if ( preg_match( '#^<a[\s>]#i', $part ) ) {
					$in_a++;
				} elseif ( preg_match( '#^</a>#i', $part ) ) {
					$in_a = max( 0, $in_a - 1 );
				} elseif ( preg_match( '#^<(h[1-6]|button|script|style|code|pre)[\s>]#i', $part ) ) {
					$in_skip++;
				} elseif ( preg_match( '#^</(h[1-6]|button|script|style|code|pre)>#i', $part ) ) {
					$in_skip = max( 0, $in_skip - 1 );
				}
				continue;
			}
			if ( $done || $in_a || $in_skip ) {
				continue;
			}
			$new = preg_replace( $re, '<a href="' . esc_url( $url ) . '">$1</a>', $part, 1, $n );
			if ( $n ) {
				$parts[ $i ] = $new;
				$done        = true;
			}
		}
		if ( ! $done ) {
			SC_Tools::fail( "\"$anchor\" was not found as plain text in \"{$p->post_title}\" (headings and existing links are skipped)." );
		}
		kses_remove_filters();
		wp_update_post( wp_slash( array( 'ID' => $p->ID, 'post_content' => implode( '', $parts ) ) ) );
		kses_init_filters();
		return array( 'message' => "Linked \"$anchor\" to $url in \"{$p->post_title}\" (#{$p->ID}).", 'id' => $p->ID );
	}
}
