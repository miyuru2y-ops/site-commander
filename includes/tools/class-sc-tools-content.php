<?php
/**
 * Content tools: posts, pages, any custom post type, scheduling, terms, revisions, custom fields.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_Content {

	public static function register() {
		$t = 'SC_Tools';

		$post_fields = array(
			'title'          => $t::p( 'string', 'Title.' ),
			'content'        => $t::p( 'string', 'Body. HTML or block markup (<!-- wp:paragraph -->…). Plain HTML is fine; block markup keeps it editable as blocks.' ),
			'excerpt'        => $t::p( 'string', 'Excerpt / summary.' ),
			'status'         => $t::p_enum( array( 'draft', 'publish', 'future', 'pending', 'private' ), 'Status. Use "future" with a date to schedule.' ),
			'date'           => $t::p( 'string', 'Publish date/time in the site timezone, e.g. "2026-11-02 09:30". A future date with status publish/future schedules the post.' ),
			'slug'           => $t::p( 'string', 'URL slug.' ),
			'author'         => $t::p( 'integer', 'Author user ID.' ),
			'parent'         => $t::p( 'integer', 'Parent ID (pages / hierarchical types).' ),
			'menu_order'     => $t::p( 'integer', 'Order.' ),
			'template'       => $t::p( 'string', 'Page template slug or file (e.g. "page-full-width.php" or block template slug).' ),
			'categories'     => array( 'type' => 'array', 'items' => array( 'type' => array( 'string', 'integer' ) ), 'description' => 'Category names or IDs. Missing names are created.' ),
			'tags'           => $t::p_list( 'Tag names. Missing tags are created.' ),
			'terms'          => array( 'type' => 'object', 'description' => 'Other taxonomies: {"taxonomy_name": ["term", ...]}.' ),
			'featured_image' => array( 'type' => array( 'integer', 'string' ), 'description' => 'Featured image: attachment ID, or an image URL to download into the media library. 0 removes it.' ),
			'seo'            => array(
				'type'        => 'object',
				'description' => 'SEO fields, written to the active SEO plugin (Yoast, Rank Math, SEOPress, AIOSEO) or Site Commander\'s own.',
				'properties'  => array(
					'title'         => array( 'type' => 'string' ),
					'description'   => array( 'type' => 'string' ),
					'focus_keyword' => array( 'type' => 'string' ),
					'canonical'     => array( 'type' => 'string' ),
					'noindex'       => array( 'type' => 'boolean' ),
				),
			),
			'meta'           => array( 'type' => 'object', 'description' => 'Custom fields to set: {"key": value}. null deletes a key.' ),
			'comment_status' => $t::p_enum( array( 'open', 'closed' ), 'Comments open or closed.' ),
			'sticky'         => $t::p( 'boolean', 'Stick to the front page (posts only).' ),
			'password'       => $t::p( 'string', 'Password-protect the post. Empty string removes it.' ),
			'format'         => $t::p( 'string', 'Post format (standard, aside, gallery, link, image, quote, status, video, audio, chat).' ),
		);

		$t::add(
			'list_post_types',
			array(
				'group'       => 'content',
				'description' => 'List post types (post, page, products, custom types) with their taxonomies and counts.',
				'callback'    => array( __CLASS__, 'list_post_types' ),
			)
		);

		$t::add(
			'list_content',
			array(
				'group'       => 'content',
				'description' => 'List or search posts, pages or any post type. Filter by status (incl. "future" for scheduled), category, tag, author, date range.',
				'properties'  => array(
					'post_type' => $t::p( 'string', 'post, page, product, any registered type, or "any". Default post.' ),
					'status'    => $t::p( 'string', 'publish, draft, future, pending, private, trash, or "any" (default, excludes trash).' ),
					'search'    => $t::p( 'string', 'Search text.' ),
					'category'  => $t::p( 'string', 'Category slug or ID.' ),
					'tag'       => $t::p( 'string', 'Tag slug.' ),
					'author'    => $t::p( 'integer', 'Author ID.' ),
					'after'     => $t::p( 'string', 'Only items dated after this date.' ),
					'before'    => $t::p( 'string', 'Only items dated before this date.' ),
					'orderby'   => $t::p_enum( array( 'date', 'modified', 'title', 'menu_order', 'ID', 'comment_count', 'rand' ), 'Sort field. Default date.' ),
					'order'     => $t::p_enum( array( 'DESC', 'ASC' ), 'Default DESC.' ),
					'per_page'  => $t::p( 'integer', '1-100, default 20.' ),
					'page'      => $t::p( 'integer', 'Page number, default 1.' ),
				),
				'callback'    => array( __CLASS__, 'list_content' ),
			)
		);

		$t::add(
			'get_content',
			array(
				'group'       => 'content',
				'description' => 'Get one post/page by ID, slug or URL, with full content, terms, featured image, SEO fields and custom fields.',
				'properties'  => array(
					'id'           => $t::p( 'integer', 'Post ID.' ),
					'slug'         => $t::p( 'string', 'Slug (with post_type).' ),
					'url'          => $t::p( 'string', 'Full URL of the post/page on this site.' ),
					'post_type'    => $t::p( 'string', 'Used with slug. Default "any".' ),
					'include_meta' => $t::p( 'boolean', 'Include all custom fields. Default false.' ),
				),
				'callback'    => array( __CLASS__, 'get_content' ),
			)
		);

		$t::add(
			'create_content',
			array(
				'group'       => 'content',
				'description' => 'Create a post, page or any post type. Supports scheduling (status future + date), categories/tags, featured image (ID or URL), SEO fields and custom fields. Defaults to draft.',
				'properties'  => array_merge( array( 'post_type' => $t::p( 'string', 'Default "post". Use "page" for pages.' ) ), $post_fields ),
				'required'    => array( 'title' ),
				'write'       => true,
				'callback'    => array( __CLASS__, 'create_content' ),
			)
		);

		$t::add(
			'update_content',
			array(
				'group'       => 'content',
				'description' => 'Update any field of an existing post/page. Only the fields you pass change. Pass content to replace the body.',
				'properties'  => array_merge( array( 'id' => $t::p( 'integer', 'Post ID.' ) ), $post_fields ),
				'required'    => array( 'id' ),
				'write'       => true,
				'callback'    => array( __CLASS__, 'update_content' ),
			)
		);

		$t::add(
			'schedule_content',
			array(
				'group'       => 'content',
				'description' => 'Schedule an existing draft/post to publish at a date/time (site timezone), or reschedule a scheduled post.',
				'properties'  => array(
					'id'   => $t::p( 'integer', 'Post ID.' ),
					'date' => $t::p( 'string', 'e.g. "2026-11-02 09:30".' ),
				),
				'required'    => array( 'id', 'date' ),
				'write'       => true,
				'callback'    => array( __CLASS__, 'schedule_content' ),
			)
		);

		$t::add(
			'bulk_update_status',
			array(
				'group'       => 'content',
				'description' => 'Change the status of many posts at once (e.g. publish drafts, unpublish to draft, make private).',
				'properties'  => array(
					'ids'    => $t::p_list( 'Post IDs.', 'integer' ),
					'status' => $t::p_enum( array( 'draft', 'publish', 'pending', 'private' ), 'New status.' ),
				),
				'required'    => array( 'ids', 'status' ),
				'write'       => true,
				'callback'    => array( __CLASS__, 'bulk_update_status' ),
			)
		);

		$t::add(
			'duplicate_content',
			array(
				'group'       => 'content',
				'description' => 'Duplicate a post/page (content, terms, featured image, custom fields) as a new draft.',
				'properties'  => array(
					'id'    => $t::p( 'integer', 'Post ID to copy.' ),
					'title' => $t::p( 'string', 'Title for the copy. Default "<title> (copy)".' ),
				),
				'required'    => array( 'id' ),
				'write'       => true,
				'callback'    => array( __CLASS__, 'duplicate_content' ),
			)
		);

		$t::add(
			'delete_content',
			array(
				'group'       => 'content',
				'description' => 'Move a post/page to the trash (restorable). force=true deletes permanently, only if the site owner allows it.',
				'properties'  => array(
					'id'    => $t::p( 'integer', 'Post ID.' ),
					'force' => $t::p( 'boolean', 'Skip the trash.' ),
				),
				'required'    => array( 'id' ),
				'write'       => true,
				'destructive' => true,
				'callback'    => array( __CLASS__, 'delete_content' ),
			)
		);

		$t::add(
			'restore_content',
			array(
				'group'       => 'content',
				'description' => 'Restore a post/page from the trash (back to draft).',
				'properties'  => array( 'id' => $t::p( 'integer', 'Post ID.' ) ),
				'required'    => array( 'id' ),
				'write'       => true,
				'callback'    => array( __CLASS__, 'restore_content' ),
			)
		);

		$t::add(
			'search_replace_content',
			array(
				'group'       => 'content',
				'description' => 'Find and replace text across post/page content and titles (e.g. fix old links, rename a brand). Does not reach Elementor page data: use elementor_find_replace for that. Runs as a dry run unless dry_run=false.',
				'properties'  => array(
					'search'     => $t::p( 'string', 'Text to find (case-sensitive).' ),
					'replace'    => $t::p( 'string', 'Replacement.' ),
					'post_types' => $t::p_list( 'Post types. Default post and page.' ),
					'in_titles'  => $t::p( 'boolean', 'Also replace in titles. Default false.' ),
					'dry_run'    => $t::p( 'boolean', 'Default true: only report matches.' ),
				),
				'required'    => array( 'search', 'replace' ),
				'allow_empty' => array( 'replace' ),
				'write'       => true,
				'callback'    => array( __CLASS__, 'search_replace' ),
			)
		);

		$t::add(
			'list_revisions',
			array(
				'group'       => 'content',
				'description' => 'List saved revisions of a post/page.',
				'properties'  => array( 'id' => $t::p( 'integer', 'Post ID.' ) ),
				'required'    => array( 'id' ),
				'callback'    => array( __CLASS__, 'list_revisions' ),
			)
		);

		$t::add(
			'restore_revision',
			array(
				'group'       => 'content',
				'description' => 'Restore a post/page to an earlier revision.',
				'properties'  => array( 'revision_id' => $t::p( 'integer', 'Revision ID from list_revisions.' ) ),
				'required'    => array( 'revision_id' ),
				'write'       => true,
				'callback'    => array( __CLASS__, 'restore_revision' ),
			)
		);

		$t::add(
			'list_terms',
			array(
				'group'       => 'content',
				'description' => 'List categories, tags or terms of any taxonomy.',
				'properties'  => array(
					'taxonomy' => $t::p( 'string', 'category (default), post_tag, product_cat, etc.' ),
					'search'   => $t::p( 'string', 'Search text.' ),
					'per_page' => $t::p( 'integer', 'Default 100.' ),
				),
				'callback'    => array( __CLASS__, 'list_terms' ),
			)
		);

		$t::add(
			'save_term',
			array(
				'group'       => 'content',
				'description' => 'Create a term (no id) or update one (with id): name, slug, description, parent. Term descriptions show on archive pages and help SEO.',
				'properties'  => array(
					'taxonomy'    => $t::p( 'string', 'Default category.' ),
					'id'          => $t::p( 'integer', 'Term ID to update.' ),
					'name'        => $t::p( 'string', 'Name.' ),
					'slug'        => $t::p( 'string', 'Slug.' ),
					'description' => $t::p( 'string', 'Description.' ),
					'parent'      => $t::p( 'integer', 'Parent term ID.' ),
				),
				'write'       => true,
				'callback'    => array( __CLASS__, 'save_term' ),
			)
		);

		$t::add(
			'delete_term',
			array(
				'group'       => 'content',
				'description' => 'Delete a category/tag/term. Posts are not deleted.',
				'properties'  => array(
					'taxonomy' => $t::p( 'string', 'Default category.' ),
					'id'       => $t::p( 'integer', 'Term ID.' ),
				),
				'required'    => array( 'id' ),
				'write'       => true,
				'destructive' => true,
				'callback'    => array( __CLASS__, 'delete_term' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	public static function list_post_types() {
		$out = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			$counts = wp_count_posts( $pt->name );
			$out[]  = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'hierarchical' => $pt->hierarchical,
				'public'       => $pt->public,
				'taxonomies'   => get_object_taxonomies( $pt->name ),
				'counts'       => array_filter( (array) $counts ),
			);
		}
		return $out;
	}

	public static function summary( WP_Post $p ) {
		$thumb = get_post_thumbnail_id( $p );
		return array(
			'id'       => $p->ID,
			'type'     => $p->post_type,
			'status'   => $p->post_status,
			'title'    => get_the_title( $p ),
			'slug'     => $p->post_name,
			'date'     => $p->post_date,
			'modified' => $p->post_modified,
			'link'     => get_permalink( $p ),
			'author'   => (int) $p->post_author,
			'excerpt'  => SC_Tools::plain_excerpt( $p->post_excerpt ? $p->post_excerpt : $p->post_content, 25 ),
			'featured' => $thumb ? (int) $thumb : null,
			'builder'  => SC_Tools_Builders::detect( $p ),
		);
	}

	public static function list_content( $a ) {
		$status = SC_Tools::str( $a, 'status', 'any' );
		$q      = array(
			'post_type'           => SC_Tools::str( $a, 'post_type', 'post' ),
			'post_status'         => 'any' === $status ? array( 'publish', 'future', 'draft', 'pending', 'private' ) : array_map( 'trim', explode( ',', $status ) ),
			'posts_per_page'      => min( 100, max( 1, SC_Tools::int( $a, 'per_page', 20 ) ) ),
			'paged'               => max( 1, SC_Tools::int( $a, 'page', 1 ) ),
			'orderby'             => SC_Tools::str( $a, 'orderby', 'date' ),
			'order'               => 'ASC' === strtoupper( (string) SC_Tools::str( $a, 'order', 'DESC' ) ) ? 'ASC' : 'DESC',
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
		);
		if ( 'any' === $q['post_type'] ) {
			$q['post_type'] = array_values( get_post_types( array( 'show_ui' => true ) ) );
		}
		if ( $s = SC_Tools::str( $a, 'search' ) ) {
			$q['s'] = $s;
		}
		if ( $c = SC_Tools::str( $a, 'category' ) ) {
			if ( is_numeric( $c ) ) {
				$q['cat'] = (int) $c;
			} else {
				$q['category_name'] = $c;
			}
		}
		if ( $tag = SC_Tools::str( $a, 'tag' ) ) {
			$q['tag'] = $tag;
		}
		if ( $au = SC_Tools::int( $a, 'author' ) ) {
			$q['author'] = $au;
		}
		$dq = array();
		if ( $af = SC_Tools::str( $a, 'after' ) ) {
			$dq['after'] = $af;
		}
		if ( $bf = SC_Tools::str( $a, 'before' ) ) {
			$dq['before'] = $bf;
		}
		if ( $dq ) {
			$dq['inclusive']  = true;
			$q['date_query'] = array( $dq );
		}
		$query = new WP_Query( $q );
		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $q['paged'],
			'items'       => array_map( array( __CLASS__, 'summary' ), $query->posts ),
		);
	}

	public static function find_post( $a ) {
		$id = SC_Tools::int( $a, 'id' );
		if ( ! $id && ( $url = SC_Tools::str( $a, 'url' ) ) ) {
			$id = url_to_postid( $url );
			if ( ! $id && untrailingslashit( $url ) === untrailingslashit( home_url() ) ) {
				$id = (int) get_option( 'page_on_front' );
			}
		}
		if ( ! $id && ( $slug = SC_Tools::str( $a, 'slug' ) ) ) {
			$found = get_posts(
				array(
					'name'        => $slug,
					'post_type'   => SC_Tools::str( $a, 'post_type', 'any' ),
					'post_status' => 'any',
					'numberposts' => 1,
				)
			);
			$id = $found ? $found[0]->ID : 0;
		}
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			SC_Tools::fail( 'Post not found.' );
		}
		return $post;
	}

	public static function get_content( $a ) {
		$p    = self::find_post( $a );
		$data = self::summary( $p );
		unset( $data['excerpt'] );
		$data['content']        = $p->post_content;
		$data['excerpt']        = $p->post_excerpt;
		$data['parent']         = (int) $p->post_parent;
		$data['menu_order']     = (int) $p->menu_order;
		$data['template']       = get_page_template_slug( $p );
		$data['comment_status'] = $p->comment_status;
		$data['word_count']     = str_word_count( wp_strip_all_tags( $p->post_content ) );
		$data['uses_blocks']    = has_blocks( $p->post_content );
		$data['date_gmt']       = $p->post_date_gmt;
		$terms                  = array();
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			$names = wp_get_post_terms( $p->ID, $tax, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $names ) && $names ) {
				$terms[ $tax ] = $names;
			}
		}
		$data['terms'] = $terms;
		$thumb         = get_post_thumbnail_id( $p );
		if ( $thumb ) {
			$data['featured_image'] = array(
				'id'  => (int) $thumb,
				'url' => wp_get_attachment_url( $thumb ),
				'alt' => get_post_meta( $thumb, '_wp_attachment_image_alt', true ),
			);
		}
		$data['seo'] = SC_Tools_SEO::get_seo( $p->ID );
		if ( SC_Tools::bool( $a, 'include_meta' ) ) {
			$meta = array();
			foreach ( get_post_meta( $p->ID ) as $k => $v ) {
				$meta[ $k ] = count( $v ) === 1 ? maybe_unserialize( $v[0] ) : array_map( 'maybe_unserialize', $v );
			}
			$data['meta'] = $meta;
		}
		return $data;
	}

	/**
	 * Build wp_insert_post data from tool args.
	 */
	private static function post_data( $a, $existing = null ) {
		$d = array();
		$map = array(
			'title'          => 'post_title',
			'content'        => 'post_content',
			'excerpt'        => 'post_excerpt',
			'slug'           => 'post_name',
			'comment_status' => 'comment_status',
			'password'       => 'post_password',
		);
		foreach ( $map as $in => $out ) {
			if ( isset( $a[ $in ] ) && is_scalar( $a[ $in ] ) ) {
				$d[ $out ] = (string) $a[ $in ];
			}
		}
		foreach ( array( 'author' => 'post_author', 'parent' => 'post_parent', 'menu_order' => 'menu_order' ) as $in => $out ) {
			if ( isset( $a[ $in ] ) ) {
				$d[ $out ] = (int) $a[ $in ];
			}
		}
		if ( isset( $a['template'] ) ) {
			$d['page_template'] = (string) $a['template'];
		}
		$status = SC_Tools::str( $a, 'status' );
		if ( $status ) {
			if ( ! in_array( $status, array( 'draft', 'publish', 'future', 'pending', 'private' ), true ) ) {
				SC_Tools::fail( "Invalid status '$status'." );
			}
			$d['post_status'] = $status;
		}
		if ( ! empty( $a['date'] ) ) {
			list( $local, $gmt, $ts ) = SC_Tools::parse_date( $a['date'] );
			$d['post_date']     = $local;
			$d['post_date_gmt'] = $gmt;
			$d['edit_date']     = true;
			$future             = $ts > time();
			$current            = $existing ? $existing->post_status : null;
			if ( ! $status && in_array( $current, array( 'publish', 'future' ), true ) ) {
				$status = 'publish';
			}
			if ( $future && in_array( $status, array( 'publish', 'future' ), true ) ) {
				$d['post_status'] = 'future';
			} elseif ( ! $future && 'future' === $status ) {
				$d['post_status'] = 'publish';
			}
		} elseif ( 'future' === $status && ! $existing ) {
			SC_Tools::fail( 'Scheduling needs a date.' );
		}
		return $d;
	}

	/**
	 * Terms, featured image, SEO, custom fields, sticky, format.
	 */
	private static function apply_extras( $post_id, $a ) {
		$post_type = get_post_type( $post_id );

		if ( isset( $a['categories'] ) && is_object_in_taxonomy( $post_type, 'category' ) ) {
			$ids = array();
			foreach ( (array) SC_Tools::arr( $a, 'categories' ) as $c ) {
				$ids[] = self::term_id( $c, 'category' );
			}
			wp_set_post_categories( $post_id, array_filter( $ids ) );
		}
		if ( isset( $a['tags'] ) && is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
			wp_set_post_tags( $post_id, (array) SC_Tools::arr( $a, 'tags' ), false );
		}
		if ( ! empty( $a['terms'] ) && is_array( $a['terms'] ) ) {
			foreach ( $a['terms'] as $tax => $list ) {
				if ( ! taxonomy_exists( $tax ) ) {
					SC_Tools::fail( "Unknown taxonomy '$tax'." );
				}
				$ids = array();
				foreach ( (array) $list as $term ) {
					$ids[] = self::term_id( $term, $tax );
				}
				wp_set_object_terms( $post_id, array_filter( $ids ), $tax, false );
			}
		}
		if ( array_key_exists( 'featured_image', $a ) ) {
			$fi = $a['featured_image'];
			if ( empty( $fi ) ) {
				delete_post_thumbnail( $post_id );
			} elseif ( is_numeric( $fi ) ) {
				if ( ! wp_attachment_is_image( (int) $fi ) ) {
					SC_Tools::fail( "Attachment $fi is not an image." );
				}
				set_post_thumbnail( $post_id, (int) $fi );
			} else {
				$att = SC_Tools_Media::sideload_url( (string) $fi, $post_id, get_the_title( $post_id ), get_the_title( $post_id ) );
				set_post_thumbnail( $post_id, $att );
			}
		}
		if ( ! empty( $a['seo'] ) && is_array( $a['seo'] ) ) {
			SC_Tools_SEO::apply_seo( $post_id, $a['seo'] );
		}
		if ( ! empty( $a['meta'] ) && is_array( $a['meta'] ) ) {
			foreach ( $a['meta'] as $k => $v ) {
				if ( null === $v ) {
					delete_post_meta( $post_id, $k );
				} else {
					update_post_meta( $post_id, $k, wp_slash( $v ) );
				}
			}
		}
		if ( isset( $a['sticky'] ) && 'post' === $post_type ) {
			if ( SC_Tools::bool( $a, 'sticky' ) ) {
				stick_post( $post_id );
			} else {
				unstick_post( $post_id );
			}
		}
		if ( ! empty( $a['format'] ) ) {
			set_post_format( $post_id, 'standard' === $a['format'] ? false : $a['format'] );
		}
	}

	public static function term_id( $value, $taxonomy ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		$exists = term_exists( $value, $taxonomy );
		if ( ! $exists ) {
			$exists = term_exists( sanitize_title( $value ), $taxonomy );
		}
		if ( $exists ) {
			return (int) ( is_array( $exists ) ? $exists['term_id'] : $exists );
		}
		$new = wp_insert_term( $value, $taxonomy );
		if ( is_wp_error( $new ) ) {
			SC_Tools::fail( $new->get_error_message() );
		}
		return (int) $new['term_id'];
	}

	private static function result( $post_id, $message ) {
		$p = get_post( $post_id );
		$r = self::summary( $p );
		$r['message']   = $message;
		$r['edit_link'] = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		if ( 'future' === $p->post_status ) {
			$r['scheduled_for'] = $p->post_date . ' (' . wp_timezone_string() . ')';
		}
		return $r;
	}

	public static function create_content( $a ) {
		$type = SC_Tools::str( $a, 'post_type', 'post' );
		if ( ! post_type_exists( $type ) ) {
			SC_Tools::fail( "Unknown post type '$type'." );
		}
		SC_License::check_create( $type );
		$d              = self::post_data( $a );
		$d['post_type'] = $type;
		if ( empty( $d['post_status'] ) ) {
			$d['post_status'] = 'draft';
		}
		$id = wp_insert_post( wp_slash( $d ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		SC_License::record_create( $type );
		self::apply_extras( $id, $a );
		return self::result( $id, ucfirst( $type ) . " #$id created as " . get_post_status( $id ) . '.' );
	}

	public static function update_content( $a ) {
		$p       = self::find_post( $a );
		$d       = self::post_data( $a, $p );
		$d['ID'] = $p->ID;
		if ( count( $d ) > 1 ) {
			$res = wp_update_post( wp_slash( $d ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		self::apply_extras( $p->ID, $a );
		$msg     = "Updated #{$p->ID}.";
		$builder = SC_Tools_Builders::detect( $p );
		if ( 'elementor' === $builder && isset( $a['content'] ) ) {
			$msg .= ' Warning: this page is built with Elementor, which ignores post content. Use get_elementor_data and update_elementor_element to change what visitors see.';
		} elseif ( in_array( $builder, array( 'divi', 'wpbakery' ), true ) && isset( $a['content'] ) ) {
			$msg .= " Note: this page uses the $builder builder (shortcodes). Prefer get_shortcode_layout and update_shortcode_node, which change one module without touching the rest of the layout.";
		}
		return self::result( $p->ID, $msg );
	}

	public static function schedule_content( $a ) {
		$p = self::find_post( $a );
		list( $local, $gmt, $ts ) = SC_Tools::parse_date( $a['date'] );
		if ( $ts <= time() ) {
			SC_Tools::fail( "That time is in the past (site time is now " . current_time( 'Y-m-d H:i' ) . ').' );
		}
		$res = wp_update_post(
			array(
				'ID'            => $p->ID,
				'post_status'   => 'future',
				'post_date'     => $local,
				'post_date_gmt' => $gmt,
				'edit_date'     => true,
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::result( $p->ID, "Scheduled #{$p->ID} for $local." );
	}

	public static function bulk_update_status( $a ) {
		$status = SC_Tools::str( $a, 'status' );
		$done   = array();
		foreach ( (array) SC_Tools::arr( $a, 'ids' ) as $id ) {
			$res = wp_update_post( array( 'ID' => (int) $id, 'post_status' => $status ), true );
			$done[ (int) $id ] = is_wp_error( $res ) ? $res->get_error_message() : get_post_status( $id );
		}
		return array( 'message' => count( $done ) . " items set to $status.", 'results' => $done );
	}

	public static function duplicate_content( $a ) {
		$p  = self::find_post( $a );
		SC_License::check_create( $p->post_type );
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $p->post_type,
					'post_title'     => SC_Tools::str( $a, 'title', $p->post_title . ' (copy)' ),
					'post_content'   => $p->post_content,
					'post_excerpt'   => $p->post_excerpt,
					'post_status'    => 'draft',
					'post_parent'    => $p->post_parent,
					'menu_order'     => $p->menu_order,
					'comment_status' => $p->comment_status,
					'page_template'  => get_page_template_slug( $p ),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			$terms = wp_get_object_terms( $p->ID, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $id, $terms, $tax );
			}
		}
		foreach ( get_post_meta( $p->ID ) as $k => $vals ) {
			if ( in_array( $k, array( '_edit_lock', '_edit_last', '_wp_old_slug' ), true ) ) {
				continue;
			}
			foreach ( $vals as $v ) {
				add_post_meta( $id, $k, wp_slash( maybe_unserialize( $v ) ) );
			}
		}
		SC_License::record_create( $p->post_type );
		return self::result( $id, "Duplicated #{$p->ID} as draft #$id." );
	}

	public static function delete_content( $a ) {
		$p     = self::find_post( $a );
		$force = SC_Tools::bool( $a, 'force' );
		if ( $force && ! SC_Plugin::setting( 'allow_permanent_delete' ) ) {
			SC_Tools::fail( 'Permanent deletion is switched off by the site owner. Leave force off to move it to the trash.' );
		}
		$title = get_the_title( $p );
		$res   = $force ? wp_delete_post( $p->ID, true ) : wp_trash_post( $p->ID );
		if ( ! $res ) {
			SC_Tools::fail( 'Could not delete.' );
		}
		return array( 'message' => ( $force ? 'Permanently deleted' : 'Moved to trash' ) . ": \"$title\" (#{$p->ID})." );
	}

	public static function restore_content( $a ) {
		$p = self::find_post( $a );
		if ( ! wp_untrash_post( $p->ID ) ) {
			SC_Tools::fail( 'Could not restore (is it in the trash?).' );
		}
		return self::result( $p->ID, "Restored #{$p->ID}." );
	}

	public static function search_replace( $a ) {
		global $wpdb;
		$search  = (string) $a['search'];
		$replace = (string) $a['replace'];
		$types   = SC_Tools::arr( $a, 'post_types' );
		$types   = $types ? $types : array( 'post', 'page' );
		$titles  = SC_Tools::bool( $a, 'in_titles' );
		$dry     = SC_Tools::bool( $a, 'dry_run', true );
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$in      = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$sql  = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status NOT IN ('trash','auto-draft','inherit') AND (post_content LIKE %s" . ( $titles ? ' OR post_title LIKE %s' : '' ) . ')';
		$vals = array_merge( $types, array( $like ), $titles ? array( $like ) : array() );
		$ids  = $wpdb->get_col( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore

		$changed = array();
		foreach ( $ids as $id ) {
			$p     = get_post( $id );
			$count = substr_count( $p->post_content, $search ) + ( $titles ? substr_count( $p->post_title, $search ) : 0 );
			if ( ! $count ) {
				continue;
			}
			$changed[] = array( 'id' => (int) $id, 'title' => $p->post_title, 'matches' => $count );
			if ( ! $dry ) {
				$upd = array( 'ID' => $p->ID, 'post_content' => str_replace( $search, $replace, $p->post_content ) );
				if ( $titles ) {
					$upd['post_title'] = str_replace( $search, $replace, $p->post_title );
				}
				wp_update_post( wp_slash( $upd ) );
			}
		}
		return array(
			'message' => ( $dry ? 'Dry run: would change ' : 'Changed ' ) . count( $changed ) . ' items.',
			'dry_run' => $dry,
			'items'   => $changed,
		);
	}

	public static function list_revisions( $a ) {
		$p   = self::find_post( $a );
		$out = array();
		foreach ( wp_get_post_revisions( $p->ID ) as $r ) {
			$out[] = array(
				'revision_id' => $r->ID,
				'date'        => $r->post_modified,
				'author'      => (int) $r->post_author,
				'title'       => $r->post_title,
				'words'       => str_word_count( wp_strip_all_tags( $r->post_content ) ),
			);
		}
		return $out;
	}

	public static function restore_revision( $a ) {
		$rev = wp_get_post_revision( (int) $a['revision_id'] );
		if ( ! $rev ) {
			SC_Tools::fail( 'Revision not found.' );
		}
		$res = wp_restore_post_revision( $rev->ID );
		if ( ! $res ) {
			SC_Tools::fail( 'Could not restore revision.' );
		}
		return array( 'message' => "Post #{$rev->post_parent} restored to revision from {$rev->post_modified}." );
	}

	public static function list_terms( $a ) {
		$tax = SC_Tools::str( $a, 'taxonomy', 'category' );
		if ( ! taxonomy_exists( $tax ) ) {
			SC_Tools::fail( "Unknown taxonomy '$tax'." );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'search'     => SC_Tools::str( $a, 'search', '' ),
				'number'     => min( 500, SC_Tools::int( $a, 'per_page', 100 ) ),
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		return array_map(
			function ( $t ) {
				return array(
					'id'          => $t->term_id,
					'name'        => $t->name,
					'slug'        => $t->slug,
					'parent'      => $t->parent,
					'count'       => $t->count,
					'description' => $t->description,
					'link'        => get_term_link( $t ),
				);
			},
			$terms
		);
	}

	public static function save_term( $a ) {
		$tax = SC_Tools::str( $a, 'taxonomy', 'category' );
		if ( ! taxonomy_exists( $tax ) ) {
			SC_Tools::fail( "Unknown taxonomy '$tax'." );
		}
		$args = array();
		foreach ( array( 'name', 'slug', 'description' ) as $k ) {
			if ( isset( $a[ $k ] ) ) {
				$args[ $k ] = (string) $a[ $k ];
			}
		}
		if ( isset( $a['parent'] ) ) {
			$args['parent'] = (int) $a['parent'];
		}
		$id = SC_Tools::int( $a, 'id' );
		if ( $id ) {
			$res = wp_update_term( $id, $tax, $args );
		} else {
			if ( empty( $args['name'] ) ) {
				SC_Tools::fail( 'name is required to create a term.' );
			}
			$name = $args['name'];
			unset( $args['name'] );
			$res = wp_insert_term( $name, $tax, $args );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$term = get_term( $res['term_id'], $tax );
		return array( 'message' => ( $id ? 'Updated' : 'Created' ) . " $tax \"{$term->name}\".", 'id' => $term->term_id, 'slug' => $term->slug );
	}

	public static function delete_term( $a ) {
		$tax = SC_Tools::str( $a, 'taxonomy', 'category' );
		$res = wp_delete_term( (int) $a['id'], $tax );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			SC_Tools::fail( 'Term not found or cannot be deleted (the default category cannot be deleted).' );
		}
		return array( 'message' => "Deleted $tax #{$a['id']}." );
	}
}
