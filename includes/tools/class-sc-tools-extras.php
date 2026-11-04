<?php
/**
 * Form entries, image optimisation and a page speed check.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_Extras {

	public static function init() {
		if ( SC_Plugin::setting( 'webp_uploads' ) ) {
			add_filter( 'image_editor_output_format', array( __CLASS__, 'webp_output' ) );
		}
	}

	public static function webp_output( $formats ) {
		$formats['image/jpeg'] = 'image/webp';
		$formats['image/png']  = 'image/webp';
		return $formats;
	}

	public static function register() {
		$t = 'SC_Tools';

		$t::add( 'list_forms', array(
			'group'       => 'people',
			'description' => 'List forms from Gravity Forms, WPForms, Contact Form 7 (with Flamingo) and Fluent Forms.',
			'callback'    => array( __CLASS__, 'list_forms' ),
		) );
		$t::add( 'list_form_entries', array(
			'group'       => 'people',
			'description' => 'Read form submissions (enquiries, sign-ups) so you can summarise or answer them. Supports Gravity Forms, WPForms Pro, Contact Form 7 + Flamingo and Fluent Forms.',
			'properties'  => array(
				'plugin'  => $t::p_enum( array( 'gravityforms', 'wpforms', 'cf7', 'fluentforms' ), 'Form plugin (from list_forms).' ),
				'form_id' => $t::p( 'integer', 'Form ID. Default: all forms.' ),
				'limit'   => $t::p( 'integer', 'Default 20, max 100.' ),
				'since'   => $t::p( 'string', 'Only entries after this date, e.g. "2026-11-01".' ),
			),
			'required'    => array( 'plugin' ),
			'callback'    => array( __CLASS__, 'list_form_entries' ),
		) );

		$t::add( 'image_audit', array(
			'group'       => 'media',
			'description' => 'Find heavy images: largest files, images wider than 2560px or bigger than 500 KB, and images without alt text.',
			'properties'  => array( 'limit' => $t::p( 'integer', 'How many to return. Default 30.' ) ),
			'callback'    => array( __CLASS__, 'image_audit' ),
		) );
		$t::add( 'resize_large_images', array(
			'group'       => 'media',
			'description' => 'Shrink oversized uploaded images in place (originals are backed up first). Default is a dry run that only reports.',
			'properties'  => array(
				'max_width' => $t::p( 'integer', 'Default 1920.' ),
				'ids'       => $t::p_list( 'Attachment IDs. Default: the largest images found.', 'integer' ),
				'limit'     => $t::p( 'integer', 'Max images per call. Default 10.' ),
				'quality'   => $t::p( 'integer', 'JPEG/WebP quality 40-95. Default 82.' ),
				'dry_run'   => $t::p( 'boolean', 'Default true.' ),
			),
			'write'       => true,
			'callback'    => array( __CLASS__, 'resize_large_images' ),
		) );
		$t::add( 'set_webp_uploads', array(
			'group'       => 'media',
			'description' => 'Turn on/off WebP for new uploads: WordPress then creates the resized copies of JPEG/PNG uploads as WebP (smaller files).',
			'properties'  => array( 'enabled' => $t::p( 'boolean', 'On or off.' ) ),
			'required'    => array( 'enabled' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'set_webp_uploads' ),
		) );
		$t::add( 'page_speed_check', array(
			'group'       => 'seo',
			'description' => 'Quick speed check of a page: server response time, HTML size, compression, caching, render-blocking scripts and styles, images without lazy loading or size. Lists what to fix.',
			'properties'  => array( 'url' => $t::p( 'string', 'Page URL on this site. Default: homepage.' ) ),
			'callback'    => array( __CLASS__, 'page_speed_check' ),
		) );
	}

	/* -------------------------------------------------------------------
	 * Forms
	 * ----------------------------------------------------------------- */

	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function list_forms() {
		global $wpdb;
		$out = array();
		if ( class_exists( 'GFAPI' ) ) {
			foreach ( GFAPI::get_forms() as $f ) {
				$out[] = array( 'plugin' => 'gravityforms', 'form_id' => (int) $f['id'], 'title' => $f['title'], 'entries' => (int) GFAPI::count_entries( $f['id'] ) );
			}
		}
		if ( function_exists( 'wpforms' ) ) {
			$forms = wpforms()->form->get( '', array( 'posts_per_page' => 100 ) );
			foreach ( (array) $forms as $f ) {
				$out[] = array( 'plugin' => 'wpforms', 'form_id' => (int) $f->ID, 'title' => $f->post_title, 'entries' => ( wpforms()->get( 'entry' ) ? 'stored' : 'not stored (WPForms Lite keeps no entries)' ) );
			}
		}
		if ( defined( 'WPCF7_VERSION' ) ) {
			foreach ( get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => 100 ) ) as $f ) {
				$out[] = array( 'plugin' => 'cf7', 'form_id' => (int) $f->ID, 'title' => $f->post_title, 'entries' => defined( 'FLAMINGO_VERSION' ) ? 'stored by Flamingo' : 'not stored (install Flamingo to keep entries)' );
			}
		}
		$ff = $wpdb->prefix . 'fluentform_forms';
		if ( defined( 'FLUENTFORM' ) && self::table_exists( $ff ) ) {
			foreach ( $wpdb->get_results( "SELECT id, title FROM {$ff} ORDER BY id DESC LIMIT 100" ) as $f ) { // phpcs:ignore
				$out[] = array( 'plugin' => 'fluentforms', 'form_id' => (int) $f->id, 'title' => $f->title );
			}
		}
		return $out ? $out : array( 'note' => 'No supported form plugin found (Gravity Forms, WPForms, Contact Form 7 + Flamingo, Fluent Forms).' );
	}

	public static function list_form_entries( $a ) {
		global $wpdb;
		$plugin = SC_Tools::str( $a, 'plugin' );
		$form   = SC_Tools::int( $a, 'form_id', 0 );
		$limit  = min( 100, max( 1, SC_Tools::int( $a, 'limit', 20 ) ) );
		$since  = SC_Tools::str( $a, 'since', '' );
		$since  = $since ? gmdate( 'Y-m-d H:i:s', strtotime( $since ) ) : '';
		$out    = array();

		if ( 'gravityforms' === $plugin ) {
			if ( ! class_exists( 'GFAPI' ) ) {
				SC_Tools::fail( 'Gravity Forms is not active.' );
			}
			$search = array( 'status' => 'active' );
			if ( $since ) {
				$search['start_date'] = $since;
			}
			$labels = array();
			$entries = GFAPI::get_entries( $form, $search, array( 'key' => 'date_created', 'direction' => 'DESC' ), array( 'offset' => 0, 'page_size' => $limit ) );
			if ( is_wp_error( $entries ) ) {
				SC_Tools::fail( $entries->get_error_message() );
			}
			foreach ( $entries as $e ) {
				if ( ! isset( $labels[ $e['form_id'] ] ) ) {
					$labels[ $e['form_id'] ] = array();
					$f = GFAPI::get_form( $e['form_id'] );
					foreach ( (array) ( $f ? $f['fields'] : array() ) as $field ) {
						$labels[ $e['form_id'] ][ (string) $field->id ] = $field->label;
					}
				}
				$fields = array();
				foreach ( $e as $k => $v ) {
					$base = (string) strtok( (string) $k, '.' );
					if ( is_numeric( $base ) && '' !== $v && isset( $labels[ $e['form_id'] ][ $base ] ) ) {
						$fields[ $labels[ $e['form_id'] ][ $base ] . ( false !== strpos( (string) $k, '.' ) ? ' (' . $k . ')' : '' ) ] = $v;
					}
				}
				$out[] = array( 'entry_id' => (int) $e['id'], 'form_id' => (int) $e['form_id'], 'date' => $e['date_created'], 'fields' => $fields );
			}
		} elseif ( 'wpforms' === $plugin ) {
			$h = function_exists( 'wpforms' ) ? wpforms()->get( 'entry' ) : null;
			if ( ! $h ) {
				SC_Tools::fail( 'WPForms entries are not available (WPForms Lite does not store entries).' );
			}
			$args = array( 'number' => $limit, 'orderby' => 'entry_id', 'order' => 'DESC' );
			if ( $form ) {
				$args['form_id'] = $form;
			}
			foreach ( (array) $h->get_entries( $args ) as $e ) {
				if ( $since && $e->date < $since ) {
					continue;
				}
				$fields = array();
				foreach ( (array) json_decode( $e->fields, true ) as $f ) {
					if ( isset( $f['name'] ) && '' !== (string) ( isset( $f['value'] ) ? $f['value'] : '' ) ) {
						$fields[ $f['name'] ] = $f['value'];
					}
				}
				$out[] = array( 'entry_id' => (int) $e->entry_id, 'form_id' => (int) $e->form_id, 'date' => $e->date, 'fields' => $fields );
			}
		} elseif ( 'cf7' === $plugin ) {
			if ( ! post_type_exists( 'flamingo_inbound' ) ) {
				SC_Tools::fail( 'Contact Form 7 does not keep entries. Install and activate the Flamingo plugin to store them.' );
			}
			$q = array( 'post_type' => 'flamingo_inbound', 'numberposts' => $limit, 'post_status' => 'publish' );
			if ( $since ) {
				$q['date_query'] = array( array( 'after' => $since ) );
			}
			if ( $form ) {
				$cf = get_post( $form );
				if ( $cf ) {
					$q['tax_query'] = array( array( 'taxonomy' => 'flamingo_inbound_channel', 'field' => 'slug', 'terms' => $cf->post_name ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				}
			}
			foreach ( get_posts( $q ) as $p ) {
				$fields = array();
				foreach ( (array) get_post_meta( $p->ID ) as $k => $v ) {
					if ( 0 === strpos( $k, '_field_' ) ) {
						$val = maybe_unserialize( $v[0] );
						$fields[ substr( $k, 7 ) ] = is_array( $val ) ? implode( ', ', $val ) : $val;
					}
				}
				$out[] = array( 'entry_id' => $p->ID, 'date' => $p->post_date, 'subject' => $p->post_title, 'from' => get_post_meta( $p->ID, '_from', true ), 'fields' => $fields );
			}
		} elseif ( 'fluentforms' === $plugin ) {
			$tb = $wpdb->prefix . 'fluentform_submissions';
			if ( ! self::table_exists( $tb ) ) {
				SC_Tools::fail( 'Fluent Forms is not active.' );
			}
			$where = '1=1';
			$vals  = array();
			if ( $form ) {
				$where .= ' AND form_id = %d';
				$vals[] = $form;
			}
			if ( $since ) {
				$where .= ' AND created_at >= %s';
				$vals[] = $since;
			}
			$vals[] = $limit;
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, form_id, response, created_at FROM {$tb} WHERE {$where} ORDER BY id DESC LIMIT %d", $vals ) ); // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				$out[] = array( 'entry_id' => (int) $r->id, 'form_id' => (int) $r->form_id, 'date' => $r->created_at, 'fields' => json_decode( $r->response, true ) );
			}
		} else {
			SC_Tools::fail( 'Unknown form plugin. Use list_forms.' );
		}
		return array( 'count' => count( $out ), 'entries' => $out );
	}

	/* -------------------------------------------------------------------
	 * Images
	 * ----------------------------------------------------------------- */

	private static function images( $max = 500 ) {
		return get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ), 'post_status' => 'inherit', 'numberposts' => $max, 'orderby' => 'date', 'order' => 'DESC' ) );
	}

	public static function image_audit( $a ) {
		$limit = max( 1, SC_Tools::int( $a, 'limit', 30 ) );
		$rows  = array();
		$big   = 0;
		$noalt = 0;
		$total = 0;
		foreach ( self::images() as $img ) {
			$file = get_attached_file( $img->ID );
			$size = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
			$meta = wp_get_attachment_metadata( $img->ID );
			$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
			$alt  = trim( (string) get_post_meta( $img->ID, '_wp_attachment_image_alt', true ) );
			$flag = array();
			if ( $w > 2560 ) {
				$flag[] = 'wider than 2560px';
			}
			if ( $size > 500 * 1024 ) {
				$flag[] = 'over 500 KB';
			}
			if ( '' === $alt ) {
				$flag[] = 'no alt text';
				$noalt++;
			}
			if ( $w > 2560 || $size > 500 * 1024 ) {
				$big++;
			}
			$total += $size;
			$rows[] = array( 'id' => $img->ID, 'file' => basename( (string) $file ), 'width' => $w, 'height' => $h, 'kb' => round( $size / 1024 ), 'issues' => $flag );
		}
		usort( $rows, function ( $x, $y ) {
			return $y['kb'] - $x['kb'];
		} );
		return array(
			'checked'        => count( $rows ),
			'total_mb'       => round( $total / 1048576, 1 ),
			'heavy_images'   => $big,
			'missing_alt'    => $noalt,
			'webp_supported' => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
			'webp_uploads'   => (bool) SC_Plugin::setting( 'webp_uploads' ),
			'largest'        => array_slice( $rows, 0, $limit ),
		);
	}

	public static function resize_large_images( $a ) {
		$max     = max( 640, SC_Tools::int( $a, 'max_width', 1920 ) );
		$limit   = min( 50, max( 1, SC_Tools::int( $a, 'limit', 10 ) ) );
		$quality = min( 95, max( 40, SC_Tools::int( $a, 'quality', 82 ) ) );
		$dry     = SC_Tools::bool( $a, 'dry_run', true );
		$ids     = SC_Tools::arr( $a, 'ids' );
		$list    = $ids ? array_filter( array_map( 'get_post', array_map( 'intval', $ids ) ) ) : self::images();
		$done    = array();
		$saved   = 0;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}
		foreach ( $list as $img ) {
			if ( count( $done ) >= $limit ) {
				break;
			}
			if ( 'attachment' !== $img->post_type || 0 !== strpos( (string) $img->post_mime_type, 'image/' ) ) {
				continue;
			}
			$file = get_attached_file( $img->ID );
			$meta = wp_get_attachment_metadata( $img->ID );
			if ( ! $file || ! file_exists( $file ) || empty( $meta['width'] ) || (int) $meta['width'] <= $max ) {
				continue;
			}
			$before = (int) filesize( $file );
			if ( $dry ) {
				$done[] = array( 'id' => $img->ID, 'file' => basename( $file ), 'from' => $meta['width'] . 'x' . $meta['height'], 'kb' => round( $before / 1024 ) );
				continue;
			}
			$backup = SC_Plugin::ensure_backup_dir() . '/images/' . $img->ID . '-' . basename( $file );
			wp_mkdir_p( dirname( $backup ) );
			if ( ! file_exists( $backup ) && ! @copy( $file, $backup ) ) { // phpcs:ignore
				continue;
			}
			$ed = wp_get_image_editor( $file );
			if ( is_wp_error( $ed ) ) {
				continue;
			}
			$ed->set_quality( $quality );
			$ed->resize( $max, null, false );
			$res = $ed->save( $file );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			clearstatcache( true, $file );
			$after            = (int) filesize( $file );
			$meta['width']    = (int) $res['width'];
			$meta['height']   = (int) $res['height'];
			$meta['filesize'] = $after;
			wp_update_attachment_metadata( $img->ID, $meta );
			$saved += max( 0, $before - $after );
			$done[] = array( 'id' => $img->ID, 'file' => basename( $file ), 'now' => $res['width'] . 'x' . $res['height'], 'kb_before' => round( $before / 1024 ), 'kb_after' => round( $after / 1024 ) );
		}
		if ( $dry ) {
			return array( 'message' => 'Dry run: ' . count( $done ) . " image(s) are wider than {$max}px.", 'dry_run' => true, 'images' => $done );
		}
		return array( 'message' => 'Resized ' . count( $done ) . " image(s) to max {$max}px, saved " . round( $saved / 1048576, 2 ) . ' MB. Originals are in uploads/site-commander-backups/images.', 'images' => $done );
	}

	public static function set_webp_uploads( $a ) {
		$on = SC_Tools::bool( $a, 'enabled' );
		if ( $on && ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			SC_Tools::fail( 'This server\'s image library cannot write WebP. Ask your host to enable WebP in GD or Imagick.' );
		}
		SC_Plugin::update_settings( array( 'webp_uploads' => $on ? 1 : 0 ) );
		return array( 'message' => 'WebP for new uploads is ' . ( $on ? 'on: resized copies of new JPEG/PNG uploads are saved as WebP.' : 'off.' ) );
	}

	/* -------------------------------------------------------------------
	 * Page speed
	 * ----------------------------------------------------------------- */

	public static function page_speed_check( $a ) {
		$url = SC_Tools::str( $a, 'url', '' );
		$url = $url ? $url : home_url( '/' );
		if ( 0 !== strpos( $url, home_url() ) && 0 !== strpos( $url, site_url() ) ) {
			SC_Tools::fail( 'Only pages on this site can be checked.' );
		}
		$start = microtime( true );
		$res   = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 3, 'headers' => array( 'Accept-Encoding' => 'gzip, br' ), 'decompress' => true ) );
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $res ) ) {
			SC_Tools::fail( 'Could not load the page: ' . $res->get_error_message() );
		}
		$html  = wp_remote_retrieve_body( $res );
		$head  = stristr( $html, '</head>', true );
		$head  = false === $head ? '' : $head;
		$fix   = array();
		$enc   = (string) wp_remote_retrieve_header( $res, 'content-encoding' );
		$cache = (string) wp_remote_retrieve_header( $res, 'cache-control' );

		preg_match_all( '#<script\b[^>]*\bsrc=[^>]*>#i', $head, $hs );
		$blocking = 0;
		foreach ( $hs[0] as $tag ) {
			if ( ! preg_match( '#\b(async|defer)\b|type=["\']module#i', $tag ) ) {
				$blocking++;
			}
		}
		preg_match_all( '#<link\b[^>]*rel=["\']stylesheet["\'][^>]*>#i', $html, $css );
		preg_match_all( '#<script\b[^>]*\bsrc=#i', $html, $js );
		preg_match_all( '#<img\b[^>]*>#i', $html, $imgs );
		$no_lazy = 0;
		$no_size = 0;
		foreach ( $imgs[0] as $i => $tag ) {
			if ( $i > 1 && false === stripos( $tag, 'loading=' ) ) {
				$no_lazy++;
			}
			if ( ! preg_match( '#\bwidth=#i', $tag ) || ! preg_match( '#\bheight=#i', $tag ) ) {
				$no_size++;
			}
		}
		$kb = round( strlen( $html ) / 1024 );

		if ( $ms > 800 ) {
			$fix[] = "Server took {$ms} ms to send the page. Add page caching (a cache plugin or host cache).";
		}
		if ( '' === $enc ) {
			$fix[] = 'The page is not compressed (no gzip/brotli). Turn on compression at the host or in a cache plugin.';
		}
		if ( $kb > 150 ) {
			$fix[] = "HTML is {$kb} KB. Large inline CSS/JS or page builder markup slows the first paint.";
		}
		if ( $blocking > 2 ) {
			$fix[] = "$blocking scripts in <head> block rendering. Defer or delay them.";
		}
		if ( count( $css[0] ) > 8 ) {
			$fix[] = count( $css[0] ) . ' stylesheets. Combine or remove unused ones.';
		}
		if ( count( $js[0] ) > 15 ) {
			$fix[] = count( $js[0] ) . ' script files. Remove plugins you do not need on this page.';
		}
		if ( $no_lazy > 0 ) {
			$fix[] = "$no_lazy images below the top are not lazy-loaded.";
		}
		if ( $no_size > 0 ) {
			$fix[] = "$no_size images have no width/height, which causes layout shift.";
		}
		return array(
			'url'                => $url,
			'status'             => (int) wp_remote_retrieve_response_code( $res ),
			'response_ms'        => $ms,
			'html_kb'            => $kb,
			'compression'        => $enc ? $enc : 'none',
			'cache_control'      => $cache,
			'stylesheets'        => count( $css[0] ),
			'scripts'            => count( $js[0] ),
			'blocking_head_js'   => $blocking,
			'images'             => count( $imgs[0] ),
			'to_fix'             => $fix ? $fix : array( 'No obvious problems found. For a full lab test use PageSpeed Insights.' ),
		);
	}
}
