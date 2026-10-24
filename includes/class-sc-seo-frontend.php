<?php
/**
 * Front-end output: SEO meta tags, schema, redirects, 404 log, robots.txt rules,
 * code snippets and maintenance mode.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_SEO_Frontend {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maintenance' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'redirects' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'log_404' ), 99 );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 20, 2 );
		add_action( 'wp_head', array( __CLASS__, 'custom_schema' ), 30 );
		add_action( 'wp_head', function () { SC_SEO_Frontend::snippets( 'head' ); }, 99 );
		add_action( 'wp_body_open', function () { SC_SEO_Frontend::snippets( 'body_open' ); }, 1 );
		add_action( 'wp_footer', function () { SC_SEO_Frontend::snippets( 'footer' ); }, 99 );

		add_action( 'wp', array( __CLASS__, 'maybe_meta' ) );
	}

	/** Should Site Commander print SEO meta itself? */
	public static function outputs_meta() {
		$mode = SC_Plugin::setting( 'seo_output' );
		if ( 'off' === $mode ) {
			return false;
		}
		if ( 'on' === $mode ) {
			return true;
		}
		return null === SC_Tools_Site::seo_plugin();
	}

	public static function maybe_meta() {
		if ( is_admin() || ! self::outputs_meta() ) {
			return;
		}
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 20 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ), 20, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots_meta' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'meta_tags' ), 2 );
		add_action( 'wp_head', array( __CLASS__, 'auto_schema' ), 31 );
	}

	private static function current_id() {
		if ( is_singular() ) {
			return get_queried_object_id();
		}
		if ( is_home() && get_option( 'page_for_posts' ) ) {
			return (int) get_option( 'page_for_posts' );
		}
		return 0;
	}

	public static function title( $title ) {
		$id = self::current_id();
		if ( $id ) {
			$t = get_post_meta( $id, '_sc_seo_title', true );
			return $t ? $t : $title;
		}
		if ( is_front_page() ) {
			$h = get_option( SC_Tools_SEO::HOME_SEO, array() );
			return ! empty( $h['title'] ) ? $h['title'] : $title;
		}
		return $title;
	}

	public static function description() {
		$id = self::current_id();
		if ( $id ) {
			$d = get_post_meta( $id, '_sc_seo_description', true );
			if ( $d ) {
				return $d;
			}
			$p = get_post( $id );
			$src = $p->post_excerpt ? $p->post_excerpt : $p->post_content;
			$txt = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $src ) ) ) ) );
			return mb_strlen( $txt ) > 155 ? rtrim( mb_substr( $txt, 0, 152 ) ) . '…' : $txt;
		}
		if ( is_front_page() ) {
			$h = get_option( SC_Tools_SEO::HOME_SEO, array() );
			return ! empty( $h['description'] ) ? $h['description'] : get_bloginfo( 'description' );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return wp_strip_all_tags( term_description() );
		}
		return '';
	}

	public static function canonical( $url, $post ) {
		$c = get_post_meta( $post->ID, '_sc_seo_canonical', true );
		return $c ? $c : $url;
	}

	public static function robots_meta( $robots ) {
		$id = self::current_id();
		if ( $id && get_post_meta( $id, '_sc_seo_noindex', true ) ) {
			$robots['noindex']  = true;
			$robots['follow']   = true;
			unset( $robots['max-image-preview'] );
		}
		if ( is_search() || is_404() ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	private static function image() {
		$id = self::current_id();
		if ( $id && has_post_thumbnail( $id ) ) {
			return wp_get_attachment_image_url( get_post_thumbnail_id( $id ), 'large' );
		}
		$h = get_option( SC_Tools_SEO::HOME_SEO, array() );
		if ( ! empty( $h['og_image'] ) ) {
			return $h['og_image'];
		}
		$icon = get_site_icon_url( 512 );
		return $icon ? $icon : '';
	}

	public static function meta_tags() {
		$desc  = self::description();
		$title = wp_get_document_title();
		$url   = is_singular() ? wp_get_canonical_url() : home_url( add_query_arg( array() ) );
		$img   = self::image();
		echo "\n<!-- Site Commander SEO -->\n";
		if ( $desc ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
		printf( '<meta property="og:type" content="%s" />' . "\n", is_singular( 'post' ) ? 'article' : 'website' );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		if ( $desc ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
		if ( $url ) {
			printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		}
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		printf( '<meta property="og:locale" content="%s" />' . "\n", esc_attr( get_locale() ) );
		if ( $img ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $img ) );
		}
		printf( '<meta name="twitter:card" content="%s" />' . "\n", $img ? 'summary_large_image' : 'summary' );
		if ( is_singular( 'post' ) ) {
			printf( '<meta property="article:published_time" content="%s" />' . "\n", esc_attr( get_the_date( 'c' ) ) );
			printf( '<meta property="article:modified_time" content="%s" />' . "\n", esc_attr( get_the_modified_date( 'c' ) ) );
		}
		echo "<!-- /Site Commander SEO -->\n";
	}

	private static function print_ld( $data ) {
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n"; // phpcs:ignore
	}

	public static function auto_schema() {
		$org = array(
			'@type' => 'Organization',
			'@id'   => home_url( '/#organization' ),
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);
		$logo = get_theme_mod( 'custom_logo' );
		if ( $logo ) {
			$org['logo'] = wp_get_attachment_image_url( $logo, 'full' );
		}
		$graph = array( $org );
		if ( is_front_page() ) {
			$graph[] = array(
				'@type'           => 'WebSite',
				'@id'             => home_url( '/#website' ),
				'url'             => home_url( '/' ),
				'name'            => get_bloginfo( 'name' ),
				'publisher'       => array( '@id' => home_url( '/#organization' ) ),
				'potentialAction' => array(
					'@type'       => 'SearchAction',
					'target'      => home_url( '/?s={search_term_string}' ),
					'query-input' => 'required name=search_term_string',
				),
			);
		} elseif ( is_singular() ) {
			$p    = get_queried_object();
			$node = array(
				'@type'         => 'post' === $p->post_type ? 'BlogPosting' : 'WebPage',
				'headline'      => wp_strip_all_tags( get_the_title( $p ) ),
				'url'           => get_permalink( $p ),
				'datePublished' => get_the_date( 'c', $p ),
				'dateModified'  => get_the_modified_date( 'c', $p ),
				'description'   => self::description(),
				'publisher'     => array( '@id' => home_url( '/#organization' ) ),
			);
			if ( 'post' === $p->post_type ) {
				$node['author']           = array( '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', $p->post_author ), 'url' => get_author_posts_url( $p->post_author ) );
				$node['mainEntityOfPage'] = get_permalink( $p );
			}
			if ( has_post_thumbnail( $p ) ) {
				$node['image'] = wp_get_attachment_image_url( get_post_thumbnail_id( $p ), 'full' );
			}
			$graph[] = $node;
		} else {
			return;
		}
		self::print_ld( array( '@context' => 'https://schema.org', '@graph' => $graph ) );
	}

	public static function custom_schema() {
		if ( ! is_singular() ) {
			return;
		}
		$s = get_post_meta( get_queried_object_id(), '_sc_schema', true );
		if ( ! $s ) {
			return;
		}
		$data = json_decode( $s, true );
		if ( ! is_array( $data ) ) {
			return;
		}
		if ( ! isset( $data['@context'] ) && ! wp_is_numeric_array( $data ) ) {
			$data = array_merge( array( '@context' => 'https://schema.org' ), $data );
		}
		self::print_ld( $data );
	}

	/* --------------------------- redirects & 404s ------------------------- */

	public static function redirects() {
		$all = get_option( SC_Tools_SEO::REDIRECTS, array() );
		if ( empty( $all ) ) {
			return;
		}
		$path = SC_Tools_SEO::norm_path( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ); // phpcs:ignore
		$home = SC_Tools_SEO::norm_path( home_url( '/' ) );
		if ( '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home ) - 1 ), '/' );
		}
		if ( ! isset( $all[ $path ] ) ) {
			return;
		}
		$r = $all[ $path ];
		$all[ $path ]['hits'] = (int) ( isset( $r['hits'] ) ? $r['hits'] : 0 ) + 1;
		update_option( SC_Tools_SEO::REDIRECTS, $all, true );
		if ( 410 === (int) $r['code'] ) {
			status_header( 410 );
			nocache_headers();
			echo '<!doctype html><title>Gone</title><p>This page has been removed.</p>';
			exit;
		}
		$to = $r['to'];
		if ( ! wp_parse_url( $to, PHP_URL_HOST ) ) {
			$to = home_url( '/' . ltrim( $to, '/' ) );
		}
		wp_redirect( $to, (int) $r['code'], 'Site Commander' ); // phpcs:ignore
		exit;
	}

	public static function log_404() {
		if ( ! is_404() || ! SC_Plugin::setting( 'log_404' ) ) {
			return;
		}
		$raw = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		if ( preg_match( '#\.(php|env|git|sql|bak|ini|log|ya?ml)(\?|$)|/wp-(admin|includes)/#i', $raw ) ) {
			return; // Vulnerability scanners, not real visitors.
		}
		$path = SC_Tools_SEO::norm_path( $raw );
		$log  = get_option( SC_Tools_SEO::LOG_404, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore
		if ( isset( $log[ $path ] ) ) {
			$log[ $path ]['hits']++;
			$log[ $path ]['last'] = current_time( 'mysql' );
			if ( $ref ) {
				$log[ $path ]['referrer'] = $ref;
			}
		} else {
			if ( count( $log ) >= 300 ) {
				uasort( $log, function ( $a, $b ) { return $b['hits'] - $a['hits']; } );
				$log = array_slice( $log, 0, 250, true );
			}
			$log[ $path ] = array( 'hits' => 1, 'last' => current_time( 'mysql' ), 'referrer' => $ref );
		}
		update_option( SC_Tools_SEO::LOG_404, $log, false );
	}

	public static function robots_txt( $output, $public ) {
		$extra = trim( (string) get_option( SC_Tools_SEO::ROBOTS, '' ) );
		if ( $extra && $public ) {
			$pos = strpos( $output, "\nSitemap:" );
			if ( false !== $pos ) {
				// Keep the rules inside the User-agent group, before the sitemap line.
				$output = rtrim( substr( $output, 0, $pos ) ) . "\n" . $extra . "\n" . substr( $output, $pos );
			} else {
				$output = rtrim( $output ) . "\n" . $extra . "\n";
			}
		}
		return $output;
	}

	/* ------------------------- snippets & maintenance --------------------- */

	public static function snippets( $location ) {
		foreach ( (array) get_option( SC_Tools_Dev::SNIPPETS, array() ) as $s ) {
			if ( ! empty( $s['active'] ) && $location === $s['location'] ) {
				echo "\n" . $s['code'] . "\n"; // phpcs:ignore -- Admin-authorised raw HTML.
			}
		}
	}

	public static function maintenance() {
		if ( ! SC_Plugin::setting( 'maintenance_mode' ) || current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( wp_doing_cron() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$msg = get_option( 'sc_maintenance_message', '' );
		$msg = $msg ? $msg : 'We are making some improvements and will be back shortly.';
		status_header( 503 );
		header( 'Retry-After: 3600' );
		nocache_headers();
		$name = esc_html( get_bloginfo( 'name' ) );
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $name . '</title>'; // phpcs:ignore
		echo '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;background:#f6f7f7;color:#1d2327}main{max-width:32rem;padding:2rem;text-align:center}h1{font-size:1.6rem;margin:0 0 .75rem}p{line-height:1.6;color:#50575e}</style></head>';
		echo '<body><main><h1>' . $name . '</h1><p>' . nl2br( esc_html( $msg ) ) . '</p></main></body></html>'; // phpcs:ignore
		exit;
	}
}
