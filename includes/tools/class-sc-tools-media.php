<?php
/**
 * Media library tools.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_Media {

	public static function register() {
		$t = 'SC_Tools';

		$t::add(
			'list_media',
			array(
				'group'       => 'media',
				'description' => 'List or search the media library. missing_alt=true finds images without alt text.',
				'properties'  => array(
					'search'      => $t::p( 'string', 'Search title/filename.' ),
					'mime_type'   => $t::p( 'string', 'e.g. image, image/png, application/pdf, video.' ),
					'missing_alt' => $t::p( 'boolean', 'Only images with no alt text.' ),
					'attached_to' => $t::p( 'integer', 'Only media attached to this post ID.' ),
					'per_page'    => $t::p( 'integer', '1-100, default 30.' ),
					'page'        => $t::p( 'integer', 'Default 1.' ),
				),
				'callback'    => array( __CLASS__, 'list_media' ),
			)
		);

		$t::add(
			'upload_media',
			array(
				'group'       => 'media',
				'description' => 'Upload a file to the media library from a public URL or base64 data. Can set title, alt text, caption, attach to a post and make it the featured image.',
				'properties'  => array(
					'url'          => $t::p( 'string', 'Public URL of the file to download.' ),
					'base64'       => $t::p( 'string', 'File contents as base64 (alternative to url). Data URLs are accepted.' ),
					'filename'     => $t::p( 'string', 'File name with extension (required with base64, optional with url). Use a descriptive, SEO-friendly name.' ),
					'title'        => $t::p( 'string', 'Title.' ),
					'alt'          => $t::p( 'string', 'Alt text (describe the image).' ),
					'caption'      => $t::p( 'string', 'Caption.' ),
					'description'  => $t::p( 'string', 'Description.' ),
					'attach_to'    => $t::p( 'integer', 'Post ID to attach to.' ),
					'set_featured' => $t::p( 'boolean', 'Make it the featured image of attach_to.' ),
				),
				'write'       => true,
				'callback'    => array( __CLASS__, 'upload_media' ),
			)
		);

		$t::add(
			'update_media',
			array(
				'group'       => 'media',
				'description' => 'Edit a media item\'s title, alt text, caption or description.',
				'properties'  => array(
					'id'          => $t::p( 'integer', 'Attachment ID.' ),
					'title'       => $t::p( 'string', 'Title.' ),
					'alt'         => $t::p( 'string', 'Alt text.' ),
					'caption'     => $t::p( 'string', 'Caption.' ),
					'description' => $t::p( 'string', 'Description.' ),
				),
				'required'    => array( 'id' ),
				'write'       => true,
				'callback'    => array( __CLASS__, 'update_media' ),
			)
		);

		$t::add(
			'regenerate_thumbnails',
			array(
				'group'       => 'media',
				'description' => 'Rebuild the resized image sizes for one or more images (after a theme change or new image sizes).',
				'properties'  => array( 'ids' => $t::p_list( 'Attachment IDs.', 'integer' ) ),
				'required'    => array( 'ids' ),
				'write'       => true,
				'callback'    => array( __CLASS__, 'regenerate' ),
			)
		);

		$t::add(
			'delete_media',
			array(
				'group'       => 'media',
				'description' => 'Permanently delete a media file (needs "Allow permanent deletion").',
				'properties'  => array( 'id' => $t::p( 'integer', 'Attachment ID.' ) ),
				'required'    => array( 'id' ),
				'write'       => true,
				'destructive' => true,
				'requires'    => 'allow_permanent_delete',
				'callback'    => array( __CLASS__, 'delete_media' ),
			)
		);
	}

	public static function format( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$file = get_attached_file( $id );
		return array(
			'id'          => (int) $id,
			'title'       => get_the_title( $id ),
			'url'         => wp_get_attachment_url( $id ),
			'mime'        => get_post_mime_type( $id ),
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => wp_get_attachment_caption( $id ),
			'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'filesize_kb' => ( $file && file_exists( $file ) ) ? round( filesize( $file ) / 1024 ) : null,
			'attached_to' => (int) wp_get_post_parent_id( $id ),
			'date'        => get_post_field( 'post_date', $id ),
		);
	}

	public static function list_media( $a ) {
		$q = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => min( 100, max( 1, SC_Tools::int( $a, 'per_page', 30 ) ) ),
			'paged'          => max( 1, SC_Tools::int( $a, 'page', 1 ) ),
			'fields'         => 'ids',
		);
		if ( $s = SC_Tools::str( $a, 'search' ) ) {
			$q['s'] = $s;
		}
		if ( $m = SC_Tools::str( $a, 'mime_type' ) ) {
			$q['post_mime_type'] = $m;
		}
		if ( isset( $a['attached_to'] ) ) {
			$q['post_parent'] = (int) $a['attached_to'];
		}
		if ( SC_Tools::bool( $a, 'missing_alt' ) ) {
			$q['post_mime_type'] = 'image';
			$q['meta_query']     = array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
			);
		}
		$query = new WP_Query( $q );
		return array(
			'total' => (int) $query->found_posts,
			'items' => array_map( array( __CLASS__, 'format' ), $query->posts ),
		);
	}

	private static function load_admin_includes() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	private static function ext_for_mime( $mime ) {
		foreach ( wp_get_mime_types() as $exts => $m ) {
			if ( $m === $mime ) {
				$parts = explode( '|', $exts );
				return $parts[0];
			}
		}
		return '';
	}

	/**
	 * Put a temp file into the media library. Returns attachment ID.
	 */
	private static function sideload_tmp( $tmp, $filename, $post_id, $title ) {
		$filename = sanitize_file_name( $filename );
		if ( '' === pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : '';
			$ext  = $mime ? self::ext_for_mime( $mime ) : '';
			if ( ! $ext ) {
				@unlink( $tmp ); // phpcs:ignore
				SC_Tools::fail( 'Could not tell the file type. Give a filename with an extension.' );
			}
			$filename .= '.' . $ext;
		}
		$file = array( 'name' => $filename, 'tmp_name' => $tmp );
		$id   = media_handle_sideload( $file, (int) $post_id, $title );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore
			SC_Tools::fail( 'Upload failed: ' . $id->get_error_message() );
		}
		return (int) $id;
	}

	public static function sideload_url( $url, $post_id = 0, $title = null, $alt = '' ) {
		self::load_admin_includes();
		if ( ! wp_http_validate_url( $url ) ) {
			SC_Tools::fail( 'That URL is not a valid public URL.' );
		}
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			SC_Tools::fail( 'Download failed: ' . $tmp->get_error_message() );
		}
		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$name = $name ? rawurldecode( $name ) : 'download';
		$id   = self::sideload_tmp( $tmp, $name, $post_id, $title );
		if ( $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return $id;
	}

	public static function upload_media( $a ) {
		self::load_admin_includes();
		$post_id = SC_Tools::int( $a, 'attach_to' );
		$title   = SC_Tools::str( $a, 'title' );

		if ( ! empty( $a['url'] ) ) {
			$tmp = download_url( (string) $a['url'], 60 );
			if ( is_wp_error( $tmp ) ) {
				SC_Tools::fail( 'Download failed: ' . $tmp->get_error_message() );
			}
			$name = SC_Tools::str( $a, 'filename' );
			if ( ! $name ) {
				$name = basename( (string) wp_parse_url( $a['url'], PHP_URL_PATH ) );
				$name = $name ? rawurldecode( $name ) : 'download';
			}
		} elseif ( ! empty( $a['base64'] ) ) {
			$name = SC_Tools::str( $a, 'filename' );
			if ( ! $name ) {
				SC_Tools::fail( 'filename is required with base64.' );
			}
			$data = (string) $a['base64'];
			if ( preg_match( '/^data:[^;]+;base64,/', $data ) ) {
				$data = substr( $data, strpos( $data, ',' ) + 1 );
			}
			$bin = base64_decode( $data, true );
			if ( false === $bin ) {
				SC_Tools::fail( 'base64 data is not valid.' );
			}
			if ( strlen( $bin ) > wp_max_upload_size() ) {
				SC_Tools::fail( 'File is larger than the site upload limit (' . size_format( wp_max_upload_size() ) . ').' );
			}
			$tmp = wp_tempnam( $name );
			file_put_contents( $tmp, $bin ); // phpcs:ignore
		} else {
			SC_Tools::fail( 'Give either url or base64.' );
		}

		$id = self::sideload_tmp( $tmp, $name, $post_id, $title );
		self::apply_fields( $id, $a );
		if ( $post_id && SC_Tools::bool( $a, 'set_featured' ) && wp_attachment_is_image( $id ) ) {
			set_post_thumbnail( $post_id, $id );
		}
		$out            = self::format( $id );
		$out['message'] = "Uploaded media #$id.";
		return $out;
	}

	private static function apply_fields( $id, $a ) {
		$upd = array( 'ID' => $id );
		if ( isset( $a['title'] ) ) {
			$upd['post_title'] = (string) $a['title'];
		}
		if ( isset( $a['caption'] ) ) {
			$upd['post_excerpt'] = (string) $a['caption'];
		}
		if ( isset( $a['description'] ) ) {
			$upd['post_content'] = (string) $a['description'];
		}
		if ( count( $upd ) > 1 ) {
			wp_update_post( wp_slash( $upd ) );
		}
		if ( isset( $a['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $a['alt'] ) );
		}
	}

	public static function update_media( $a ) {
		$id = (int) $a['id'];
		if ( 'attachment' !== get_post_type( $id ) ) {
			SC_Tools::fail( 'Media item not found.' );
		}
		self::apply_fields( $id, $a );
		$out            = self::format( $id );
		$out['message'] = "Updated media #$id.";
		return $out;
	}

	public static function regenerate( $a ) {
		self::load_admin_includes();
		$done = array();
		foreach ( (array) SC_Tools::arr( $a, 'ids' ) as $id ) {
			$file = get_attached_file( (int) $id );
			if ( ! $file || ! file_exists( $file ) || ! wp_attachment_is_image( (int) $id ) ) {
				$done[ $id ] = 'skipped (not an image or file missing)';
				continue;
			}
			$meta = wp_generate_attachment_metadata( (int) $id, $file );
			wp_update_attachment_metadata( (int) $id, $meta );
			$done[ $id ] = 'ok';
		}
		return array( 'message' => 'Regenerated ' . count( array_filter( $done, function ( $v ) { return 'ok' === $v; } ) ) . ' images.', 'results' => $done );
	}

	public static function delete_media( $a ) {
		$id = (int) $a['id'];
		if ( 'attachment' !== get_post_type( $id ) ) {
			SC_Tools::fail( 'Media item not found.' );
		}
		$title = get_the_title( $id );
		if ( ! wp_delete_attachment( $id, true ) ) {
			SC_Tools::fail( 'Could not delete.' );
		}
		return array( 'message' => "Deleted media \"$title\" (#$id)." );
	}
}
