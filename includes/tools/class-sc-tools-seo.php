<?php
/**
 * SEO tools. Works with Yoast, Rank Math, SEOPress, AIOSEO, The SEO Framework,
 * or on its own (Site Commander then prints the meta tags itself).
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_SEO {

	const REDIRECTS = 'sc_redirects';
	const LOG_404   = 'sc_404_log';
	const ROBOTS    = 'sc_robots_extra';
	const HOME_SEO  = 'sc_home_seo';

	/** Post meta keys per SEO plugin. */
	public static function keys() {
		switch ( SC_Tools_Site::seo_plugin() ) {
			case 'yoast':
				return array( 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'focus_keyword' => '_yoast_wpseo_focuskw', 'canonical' => '_yoast_wpseo_canonical', 'noindex' => '_yoast_wpseo_meta-robots-noindex' );
			case 'rankmath':
				return array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword', 'canonical' => 'rank_math_canonical_url', 'noindex' => 'rank_math_robots' );
			case 'seopress':
				return array( 'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc', 'focus_keyword' => '_seopress_analysis_target_kw', 'canonical' => '_seopress_robots_canonical', 'noindex' => '_seopress_robots_index' );
			case 'seo-framework':
				return array( 'title' => '_genesis_title', 'description' => '_genesis_description', 'focus_keyword' => '_sc_seo_focus_keyword', 'canonical' => '_genesis_canonical_uri', 'noindex' => '_genesis_noindex' );
			default:
				return array( 'title' => '_sc_seo_title', 'description' => '_sc_seo_description', 'focus_keyword' => '_sc_seo_focus_keyword', 'canonical' => '_sc_seo_canonical', 'noindex' => '_sc_seo_noindex' );
		}
	}

	private static function aioseo_post( $id ) {
		if ( 'aioseo' === SC_Tools_Site::seo_plugin() && class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			try {
				return \AIOSEO\Plugin\Common\Models\Post::getPost( $id );
			} catch ( Throwable $e ) {
				return null;
			}
		}
		return null;
	}

	public static function get_seo( $id ) {
		$k    = self::keys();
		$out  = array(
			'plugin'        => SC_Tools_Site::seo_plugin() ? SC_Tools_Site::seo_plugin() : 'site-commander',
			'title'         => (string) get_post_meta( $id, $k['title'], true ),
			'description'   => (string) get_post_meta( $id, $k['description'], true ),
			'focus_keyword' => (string) get_post_meta( $id, $k['focus_keyword'], true ),
			'canonical'     => (string) get_post_meta( $id, $k['canonical'], true ),
		);
		$ni   = get_post_meta( $id, $k['noindex'], true );
		$out['noindex'] = is_array( $ni ) ? in_array( 'noindex', $ni, true ) : in_array( (string) $ni, array( '1', 'yes', 'on' ), true );
		$ai   = self::aioseo_post( $id );
		if ( $ai ) {
			$out['title']       = (string) $ai->title;
			$out['description'] = (string) $ai->description;
			$out['canonical']   = (string) $ai->canonical_url;
			$out['noindex']     = (bool) $ai->robots_noindex;
		}
		$schema = get_post_meta( $id, '_sc_schema', true );
		if ( $schema ) {
			$out['custom_schema'] = json_decode( $schema, true );
		}
		return $out;
	}

	public static function apply_seo( $id, array $seo ) {
		$k = self::keys();
		foreach ( array( 'title', 'description', 'focus_keyword', 'canonical' ) as $f ) {
			if ( array_key_exists( $f, $seo ) ) {
				$v = 'canonical' === $f ? esc_url_raw( (string) $seo[ $f ] ) : sanitize_text_field( (string) $seo[ $f ] );
				if ( '' === $v ) {
					delete_post_meta( $id, $k[ $f ] );
				} else {
					update_post_meta( $id, $k[ $f ], $v );
				}
			}
		}
		if ( array_key_exists( 'noindex', $seo ) ) {
			$on = SC_Tools::bool( $seo, 'noindex' );
			switch ( SC_Tools_Site::seo_plugin() ) {
				case 'rankmath':
					update_post_meta( $id, $k['noindex'], $on ? array( 'noindex' ) : array( 'index' ) );
					break;
				case 'seopress':
					$on ? update_post_meta( $id, $k['noindex'], 'yes' ) : delete_post_meta( $id, $k['noindex'] );
					break;
				default:
					$on ? update_post_meta( $id, $k['noindex'], '1' ) : delete_post_meta( $id, $k['noindex'] );
			}
		}
		if ( array_key_exists( 'schema', $seo ) ) {
			$schema = $seo['schema'];
			if ( empty( $schema ) ) {
				delete_post_meta( $id, '_sc_schema' );
			} else {
				if ( is_string( $schema ) ) {
					$schema = json_decode( $schema, true );
					if ( ! is_array( $schema ) ) {
						SC_Tools::fail( 'schema must be valid JSON-LD.' );
					}
				}
				update_post_meta( $id, '_sc_schema', wp_slash( wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
			}
		}
		$ai = self::aioseo_post( $id );
		if ( $ai ) {
			try {
				if ( isset( $seo['title'] ) ) {
					$ai->title = (string) $seo['title'];
				}
				if ( isset( $seo['description'] ) ) {
					$ai->description = (string) $seo['description'];
				}
				if ( isset( $seo['canonical'] ) ) {
					$ai->canonical_url = (string) $seo['canonical'];
				}
				if ( isset( $seo['noindex'] ) ) {
					$ai->robots_default = false;
					$ai->robots_noindex = SC_Tools::bool( $seo, 'noindex' );
				}
				$ai->post_id = $id;
				$ai->save();
			} catch ( Throwable $e ) {
				// Fall through: values are still in post meta.
			}
		}
	}

	public static function register() {
		$t = 'SC_Tools';

		$seo_props = array(
			'title'         => $t::p( 'string', 'SEO title (50-60 chars ideal). Empty string clears.' ),
			'description'   => $t::p( 'string', 'Meta description (120-160 chars). Empty string clears.' ),
			'focus_keyword' => $t::p( 'string', 'Focus keyword.' ),
			'canonical'     => $t::p( 'string', 'Canonical URL. Empty string clears.' ),
			'noindex'       => $t::p( 'boolean', 'Hide this page from search engines.' ),
			'schema'        => array( 'type' => 'object', 'description' => 'Custom JSON-LD (e.g. FAQPage, LocalBusiness, Product, HowTo) printed on this page. Empty removes.' ),
		);

		$t::add( 'seo_audit', array(
			'group'       => 'seo',
			'description' => 'Scan the site for SEO problems: search engines blocked, permalinks, missing/too long titles and meta descriptions, duplicates, thin content, missing H2s, extra H1s, images without alt text, no internal links, missing featured images, noindex pages, focus keyword not used. Returns site issues, per-page issues and fix suggestions.',
			'properties'  => array(
				'post_types' => $t::p_list( 'Default post and page.' ),
				'limit'      => $t::p( 'integer', 'Max pages to scan, default 200.' ),
				'ids'        => $t::p_list( 'Only these post IDs.', 'integer' ),
			),
			'callback'    => array( __CLASS__, 'audit' ),
		) );

		$t::add( 'get_seo', array(
			'group'       => 'seo',
			'description' => 'Get SEO fields (title, meta description, focus keyword, canonical, noindex, schema) of a post/page.',
			'properties'  => array( 'id' => $t::p( 'integer', 'Post ID.' ) ),
			'required'    => array( 'id' ),
			'callback'    => function ( $a ) {
				if ( ! get_post( (int) $a['id'] ) ) {
					SC_Tools::fail( 'Post not found.' );
				}
				return SC_Tools_SEO::get_seo( (int) $a['id'] );
			},
		) );

		$t::add( 'set_seo', array(
			'group'       => 'seo',
			'description' => 'Set SEO fields for one post/page. Written into the active SEO plugin so they show up there too.',
			'properties'  => array_merge( array( 'id' => $t::p( 'integer', 'Post ID.' ) ), $seo_props ),
			'required'    => array( 'id' ),
			'write'       => true,
			'callback'    => function ( $a ) {
				$id = (int) $a['id'];
				if ( ! get_post( $id ) ) {
					SC_Tools::fail( 'Post not found.' );
				}
				unset( $a['id'] );
				SC_Tools_SEO::apply_seo( $id, $a );
				return array( 'message' => "SEO updated for #$id.", 'seo' => SC_Tools_SEO::get_seo( $id ) );
			},
		) );

		$t::add( 'bulk_set_seo', array(
			'group'       => 'seo',
			'description' => 'Set SEO fields for many posts in one call: items=[{"id":12,"title":"…","description":"…"}, …]. Use after seo_audit.',
			'properties'  => array(
				'items' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object', 'properties' => array_merge( array( 'id' => array( 'type' => 'integer' ) ), $seo_props ), 'required' => array( 'id' ) ),
				),
			),
			'required'    => array( 'items' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'bulk_set' ),
		) );

		$t::add( 'set_home_seo', array(
			'group'       => 'seo',
			'description' => 'Set the homepage SEO title and meta description (used when the homepage shows latest posts, or when no SEO plugin is active). With a static front page you can also use set_seo on that page.',
			'properties'  => array(
				'title'       => $t::p( 'string', 'Homepage title.' ),
				'description' => $t::p( 'string', 'Homepage meta description.' ),
				'og_image'    => $t::p( 'string', 'Default social share image URL.' ),
			),
			'write'       => true,
			'callback'    => array( __CLASS__, 'set_home_seo' ),
		) );

		$t::add( 'fix_image_alts', array(
			'group'       => 'seo',
			'description' => 'Fill in missing alt text in the media library. Give alts={"123":"Red bicycle leaning on a wall"} to set your own (recommended: describe each image), or let it derive alt text from titles/filenames. Dry run by default.',
			'properties'  => array(
				'alts'    => array( 'type' => 'object', 'description' => 'Map of attachment ID to alt text.' ),
				'derive'  => $t::p( 'boolean', 'For images not in alts, derive from title/filename. Default true.' ),
				'limit'   => $t::p( 'integer', 'Max images, default 200.' ),
				'dry_run' => $t::p( 'boolean', 'Default true.' ),
			),
			'write'       => true,
			'callback'    => array( __CLASS__, 'fix_image_alts' ),
		) );

		$t::add( 'fix_content_image_alts', array(
			'group'       => 'seo',
			'description' => 'Fix <img> tags inside post/page content that have no alt attribute, using the media library alt text (run fix_image_alts first). Dry run by default.',
			'properties'  => array(
				'ids'     => $t::p_list( 'Post IDs. Default: all published posts and pages.', 'integer' ),
				'dry_run' => $t::p( 'boolean', 'Default true.' ),
			),
			'write'       => true,
			'callback'    => array( __CLASS__, 'fix_content_image_alts' ),
		) );

		$t::add( 'find_internal_link_opportunities', array(
			'group'       => 'seo',
			'description' => 'Find posts that mention a keyword but do not yet link to a target page — good places to add internal links.',
			'properties'  => array(
				'target_id' => $t::p( 'integer', 'Page/post that should receive links.' ),
				'keyword'   => $t::p( 'string', 'Phrase to look for. Default: target focus keyword or title.' ),
			),
			'required'    => array( 'target_id' ),
			'callback'    => array( __CLASS__, 'link_opportunities' ),
		) );

		$t::add( 'find_broken_links', array(
			'group'       => 'seo',
			'description' => 'Find internal links in post/page content that point to pages that no longer exist. Optionally also checks external links over HTTP (slower).',
			'properties'  => array(
				'check_external' => $t::p( 'boolean', 'Also test external links. Default false.' ),
				'limit'          => $t::p( 'integer', 'Max posts to scan, default 200.' ),
			),
			'callback'    => array( __CLASS__, 'broken_links' ),
		) );

		$t::add( 'list_redirects', array(
			'group'       => 'seo',
			'description' => 'List redirects managed by Site Commander.',
			'callback'    => function () {
				return get_option( SC_Tools_SEO::REDIRECTS, array() );
			},
		) );

		$t::add( 'add_redirect', array(
			'group'       => 'seo',
			'description' => 'Add or update a redirect, e.g. from "/old-page/" to "/new-page/" (301 permanent by default). Use for moved or deleted pages and 404s.',
			'properties'  => array(
				'from' => $t::p( 'string', 'Old path, e.g. "/old-page/".' ),
				'to'   => $t::p( 'string', 'New path or full URL.' ),
				'code' => $t::p( 'integer', '301 (default), 302, 307, 308 or 410 (gone).' ),
			),
			'required'    => array( 'from' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'add_redirect' ),
		) );

		$t::add( 'delete_redirect', array(
			'group'       => 'seo',
			'description' => 'Remove a redirect.',
			'properties'  => array( 'from' => $t::p( 'string', 'Old path.' ) ),
			'required'    => array( 'from' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'delete_redirect' ),
		) );

		$t::add( 'list_404s', array(
			'group'       => 'seo',
			'description' => 'Most-hit "page not found" URLs recorded on the site, with referrers — candidates for redirects.',
			'properties'  => array( 'clear' => $t::p( 'boolean', 'Clear the log after reading.' ) ),
			'callback'    => function ( $a ) {
				$log = get_option( SC_Tools_SEO::LOG_404, array() );
				uasort( $log, function ( $x, $y ) { return $y['hits'] - $x['hits']; } );
				if ( SC_Tools::bool( $a, 'clear' ) ) {
					delete_option( SC_Tools_SEO::LOG_404 );
				}
				return array_slice( $log, 0, 100, true );
			},
		) );

		$t::add( 'get_robots_txt', array(
			'group'       => 'seo',
			'description' => 'Show the robots.txt the site serves and the extra rules added by Site Commander.',
			'callback'    => array( __CLASS__, 'get_robots' ),
		) );

		$t::add( 'set_robots_txt', array(
			'group'       => 'seo',
			'description' => 'Set extra robots.txt rules appended to WordPress\'s virtual robots.txt (e.g. "Disallow: /cart/"). Does not apply if a physical robots.txt file exists.',
			'properties'  => array( 'rules' => $t::p( 'string', 'Lines to append. Empty clears.' ) ),
			'required'    => array( 'rules' ),
			'write'       => true,
			'callback'    => function ( $a ) {
				update_option( SC_Tools_SEO::ROBOTS, sanitize_textarea_field( (string) $a['rules'] ) );
				return array( 'message' => 'robots.txt rules saved.', 'robots_txt' => home_url( '/robots.txt' ) );
			},
		) );
	}

	/* -------------------------------- audit ------------------------------- */

	public static function audit( $a ) {
		$types = SC_Tools::arr( $a, 'post_types' );
		$types = $types ? $types : array( 'post', 'page' );
		$q     = array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => min( 1000, SC_Tools::int( $a, 'limit', 200 ) ),
			'orderby'        => 'modified',
		);
		if ( $ids = SC_Tools::arr( $a, 'ids' ) ) {
			$q['post__in'] = array_map( 'intval', $ids );
		}
		$posts = get_posts( $q );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );

		$site = array();
		if ( ! get_option( 'blog_public' ) ) {
			$site[] = array( 'severity' => 'critical', 'issue' => 'Search engines are discouraged — the whole site is hidden from Google.', 'fix' => 'update_settings {"blog_public":1}' );
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			$site[] = array( 'severity' => 'high', 'issue' => 'Plain permalinks (?p=123) instead of readable URLs.', 'fix' => 'update_settings {"permalink_structure":"/%postname%/"}' );
		}
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			$site[] = array( 'severity' => 'high', 'issue' => 'Site URL is not HTTPS.', 'fix' => 'Install an SSL certificate, then change the site address to https.' );
		}
		$tag = get_bloginfo( 'description' );
		if ( '' === $tag || false !== stripos( $tag, 'Just another WordPress' ) ) {
			$site[] = array( 'severity' => 'low', 'issue' => 'Tagline is empty or the WordPress default.', 'fix' => 'update_settings {"blogdescription":"…"}' );
		}
		if ( ! get_option( 'site_icon' ) ) {
			$site[] = array( 'severity' => 'low', 'issue' => 'No site icon (favicon).', 'fix' => 'set_site_identity {"icon": …}' );
		}
		if ( ! SC_Tools_Site::seo_plugin() && 'off' === SC_Plugin::setting( 'seo_output' ) ) {
			$site[] = array( 'severity' => 'high', 'issue' => 'No SEO plugin and Site Commander SEO output is off, so meta descriptions are not printed.', 'fix' => 'Turn SEO output on in Site Commander settings.' );
		}
		$missing_alt = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
				),
			)
		);
		if ( $missing_alt->found_posts ) {
			$site[] = array( 'severity' => 'medium', 'issue' => $missing_alt->found_posts . ' media library images have no alt text.', 'fix' => 'fix_image_alts' );
		}

		$titles = array();
		$descs  = array();
		$pages  = array();
		$stats  = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );

		foreach ( $posts as $p ) {
			$seo     = self::get_seo( $p->ID );
			$title   = $seo['title'] ? $seo['title'] : $p->post_title;
			$content = $p->post_content;
			$text    = wp_strip_all_tags( strip_shortcodes( $content ) );
			$words   = str_word_count( $text );
			$issues  = array();

			$tl = mb_strlen( wp_strip_all_tags( $title ) );
			if ( ! $tl ) {
				$issues[] = array( 'high', 'missing_title', 'No title.' );
			} elseif ( $tl > 60 && false === strpos( $title, '%%' ) && false === strpos( $title, '%' ) ) {
				$issues[] = array( 'low', 'title_long', "SEO title is $tl chars (over ~60 gets cut off)." );
			} elseif ( $tl < 25 ) {
				$issues[] = array( 'low', 'title_short', "Title is only $tl chars." );
			}
			$dl = mb_strlen( $seo['description'] );
			if ( ! $dl ) {
				$issues[] = array( 'high', 'missing_description', 'No meta description.' );
			} elseif ( $dl > 160 ) {
				$issues[] = array( 'low', 'description_long', "Meta description is $dl chars (over 160)." );
			} elseif ( $dl < 70 ) {
				$issues[] = array( 'medium', 'description_short', "Meta description is only $dl chars." );
			}
			if ( 'post' === $p->post_type && $words < 300 ) {
				$issues[] = array( 'medium', 'thin_content', "Only $words words." );
			}
			if ( preg_match( '#<h1[\s>]#i', $content ) ) {
				$issues[] = array( 'medium', 'h1_in_content', 'Content has an H1; the theme already prints the title as H1. Use H2.' );
			}
			if ( $words > 600 && ! preg_match( '#<h2[\s>]#i', $content ) ) {
				$issues[] = array( 'medium', 'no_subheadings', 'Long content without H2 subheadings.' );
			}
			$noalt = 0;
			if ( preg_match_all( '#<img\b[^>]*>#i', $content, $im ) ) {
				foreach ( $im[0] as $img ) {
					if ( ! preg_match( '#\balt=["\'][^"\']+["\']#i', $img ) ) {
						$noalt++;
					}
				}
			}
			if ( $noalt ) {
				$issues[] = array( 'medium', 'images_missing_alt', "$noalt images in the content have no alt text." );
			}
			$internal = 0;
			if ( preg_match_all( '#<a\b[^>]*href=["\']([^"\']+)#i', $content, $lm ) ) {
				foreach ( $lm[1] as $href ) {
					$h = wp_parse_url( $href, PHP_URL_HOST );
					if ( ( ! $h && 0 === strpos( $href, '/' ) ) || $h === $host ) {
						$internal++;
					}
				}
			}
			if ( 'post' === $p->post_type && ! $internal ) {
				$issues[] = array( 'medium', 'no_internal_links', 'No internal links to other pages.' );
			}
			if ( 'post' === $p->post_type && ! has_post_thumbnail( $p ) ) {
				$issues[] = array( 'low', 'no_featured_image', 'No featured image (used for social sharing).' );
			}
			if ( $seo['noindex'] ) {
				$issues[] = array( 'high', 'noindex', 'Page is set to noindex (hidden from Google). Check this is intended.' );
			}
			if ( $seo['focus_keyword'] ) {
				$kw = mb_strtolower( $seo['focus_keyword'] );
				if ( false === mb_strpos( mb_strtolower( $title ), $kw ) ) {
					$issues[] = array( 'medium', 'keyword_not_in_title', 'Focus keyword not in the SEO title.' );
				}
				if ( false === mb_strpos( mb_strtolower( mb_substr( $text, 0, 600 ) ), $kw ) ) {
					$issues[] = array( 'low', 'keyword_not_in_intro', 'Focus keyword not in the first paragraph.' );
				}
			}
			if ( strlen( $p->post_name ) > 75 ) {
				$issues[] = array( 'low', 'slug_long', 'URL slug is very long.' );
			}

			$titles[ mb_strtolower( $title ) ][] = $p->ID;
			if ( $seo['description'] ) {
				$descs[ mb_strtolower( $seo['description'] ) ][] = $p->ID;
			}

			$pages[ $p->ID ] = array(
				'id'          => $p->ID,
				'type'        => $p->post_type,
				'title'       => $p->post_title,
				'url'         => get_permalink( $p ),
				'words'       => $words,
				'seo_title'   => $seo['title'],
				'description' => $seo['description'],
				'issues'      => $issues,
			);
		}
		foreach ( array( 'duplicate_title' => $titles, 'duplicate_description' => $descs ) as $code => $map ) {
			foreach ( $map as $ids ) {
				if ( count( $ids ) > 1 ) {
					foreach ( $ids as $id ) {
						$pages[ $id ]['issues'][] = array( 'medium', $code, 'Same as #' . implode( ', #', array_diff( $ids, array( $id ) ) ) . '.' );
					}
				}
			}
		}

		$with = array();
		foreach ( $pages as $pg ) {
			if ( empty( $pg['issues'] ) ) {
				continue;
			}
			$pg['issues'] = array_map(
				function ( $i ) use ( &$stats ) {
					$stats[ $i[0] ]++;
					return $i[0] . ' | ' . $i[1] . ' | ' . $i[2];
				},
				$pg['issues']
			);
			$with[] = $pg;
		}
		foreach ( $site as $s ) {
			$stats[ $s['severity'] ]++;
		}
		$penalty = $stats['critical'] * 25 + $stats['high'] * 3 + $stats['medium'] * 1.5 + $stats['low'] * 0.5;
		$score   = max( 0, (int) round( 100 - $penalty / max( 1, count( $posts ) ) * 4 - $stats['critical'] * 20 ) );

		return array(
			'score'          => $score,
			'pages_scanned'  => count( $posts ),
			'pages_ok'       => count( $posts ) - count( $with ),
			'issue_counts'   => $stats,
			'site_issues'    => $site,
			'page_issues'    => $with,
			'sitemap'        => SC_Tools_Site::seo_plugin() ? 'Managed by ' . SC_Tools_Site::seo_plugin() : home_url( '/wp-sitemap.xml' ),
			'how_to_fix'     => 'Missing/short descriptions and titles: bulk_set_seo. Images: fix_image_alts then fix_content_image_alts. Headings/thin content/internal links: get_content then update_content. Duplicates: rewrite one of them.',
		);
	}

	public static function bulk_set( $a ) {
		$done = array();
		foreach ( (array) SC_Tools::arr( $a, 'items' ) as $item ) {
			$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
			if ( ! $id || ! get_post( $id ) ) {
				$done[] = "#$id not found";
				continue;
			}
			unset( $item['id'] );
			self::apply_seo( $id, $item );
			$done[] = "#$id ok";
		}
		return array( 'message' => 'SEO updated for ' . count( $done ) . ' items.', 'results' => $done );
	}

	public static function set_home_seo( $a ) {
		$cur = get_option( self::HOME_SEO, array() );
		foreach ( array( 'title', 'description', 'og_image' ) as $k ) {
			if ( isset( $a[ $k ] ) ) {
				$cur[ $k ] = 'og_image' === $k ? esc_url_raw( $a[ $k ] ) : sanitize_text_field( $a[ $k ] );
			}
		}
		update_option( self::HOME_SEO, $cur );
		$note = '';
		$plugin = SC_Tools_Site::seo_plugin();
		if ( 'yoast' === $plugin ) {
			$opt = get_option( 'wpseo_titles', array() );
			if ( isset( $a['title'] ) ) {
				$opt['title-home-wpseo'] = sanitize_text_field( $a['title'] );
			}
			if ( isset( $a['description'] ) ) {
				$opt['metadesc-home-wpseo'] = sanitize_text_field( $a['description'] );
			}
			update_option( 'wpseo_titles', $opt );
			$note = ' Also saved in Yoast.';
		} elseif ( 'rankmath' === $plugin ) {
			$opt = get_option( 'rank-math-options-titles', array() );
			if ( isset( $a['title'] ) ) {
				$opt['homepage_title'] = sanitize_text_field( $a['title'] );
			}
			if ( isset( $a['description'] ) ) {
				$opt['homepage_description'] = sanitize_text_field( $a['description'] );
			}
			update_option( 'rank-math-options-titles', $opt );
			$note = ' Also saved in Rank Math.';
		}
		if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
			self::apply_seo( (int) get_option( 'page_on_front' ), array_intersect_key( $a, array( 'title' => 1, 'description' => 1 ) ) );
			$note .= ' Applied to the static front page too.';
		}
		return array( 'message' => 'Homepage SEO saved.' . $note );
	}

	public static function derive_alt( $id ) {
		$title = get_the_title( $id );
		$file  = pathinfo( (string) get_attached_file( $id ), PATHINFO_FILENAME );
		$base  = $title ? $title : $file;
		$base  = preg_replace( '/-?\d{2,5}x\d{2,5}$/', '', $base );
		$base  = preg_replace( '/\b(img|image|dsc|pxl|screenshot|photo)[-_]?\d+\b/i', '', $base );
		$base  = preg_replace( '/[-_]+(scaled|edited|e\d{10,})$/i', '', $base );
		$base  = trim( preg_replace( '/[\s_\-]+/', ' ', $base ) );
		$base  = preg_replace( '/^\d+$/', '', $base );
		return $base ? ucfirst( $base ) : '';
	}

	public static function fix_image_alts( $a ) {
		$dry    = SC_Tools::bool( $a, 'dry_run', true );
		$derive = SC_Tools::bool( $a, 'derive', true );
		$given  = SC_Tools::arr( $a, 'alts' );
		$given  = $given ? $given : array();
		$ids    = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'fields'         => 'ids',
				'posts_per_page' => min( 1000, SC_Tools::int( $a, 'limit', 200 ) ),
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
				),
			)
		);
		$ids     = array_unique( array_merge( $ids, array_map( 'intval', array_keys( $given ) ) ) );
		$changes = array();
		$skipped = array();
		foreach ( $ids as $id ) {
			if ( isset( $given[ $id ] ) ) {
				$alt = sanitize_text_field( $given[ $id ] );
			} elseif ( $derive ) {
				$alt = self::derive_alt( $id );
			} else {
				continue;
			}
			if ( '' === $alt ) {
				$skipped[] = array( 'id' => $id, 'url' => wp_get_attachment_url( $id ), 'reason' => 'filename is not descriptive; give alt text in alts' );
				continue;
			}
			$changes[] = array( 'id' => $id, 'alt' => $alt );
			if ( ! $dry ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
			}
		}
		return array(
			'message'      => ( $dry ? 'Dry run: would set ' : 'Set ' ) . count( $changes ) . ' alt texts.',
			'dry_run'      => $dry,
			'changes'      => $changes,
			'needs_manual' => $skipped,
		);
	}

	public static function fix_content_image_alts( $a ) {
		$dry = SC_Tools::bool( $a, 'dry_run', true );
		$ids = SC_Tools::arr( $a, 'ids' );
		if ( ! $ids ) {
			$ids = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1 ) );
		}
		$report = array();
		foreach ( $ids as $id ) {
			$p = get_post( (int) $id );
			if ( ! $p || false === stripos( $p->post_content, '<img' ) ) {
				continue;
			}
			$tags  = new WP_HTML_Tag_Processor( $p->post_content );
			$fixed = 0;
			while ( $tags->next_tag( 'img' ) ) {
				$alt = $tags->get_attribute( 'alt' );
				if ( is_string( $alt ) && '' !== trim( $alt ) ) {
					continue;
				}
				$class = (string) $tags->get_attribute( 'class' );
				$att   = 0;
				if ( preg_match( '/wp-image-(\d+)/', $class, $m ) ) {
					$att = (int) $m[1];
				} else {
					$src = (string) $tags->get_attribute( 'src' );
					$att = $src ? attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $src ) ) : 0;
				}
				$new = $att ? (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) : '';
				if ( '' === $new ) {
					continue;
				}
				$tags->set_attribute( 'alt', $new );
				$fixed++;
			}
			if ( $fixed ) {
				$report[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'fixed' => $fixed );
				if ( ! $dry ) {
					wp_update_post( wp_slash( array( 'ID' => $p->ID, 'post_content' => $tags->get_updated_html() ) ) );
				}
			}
		}
		return array( 'message' => ( $dry ? 'Dry run: would fix ' : 'Fixed ' ) . array_sum( wp_list_pluck( $report, 'fixed' ) ) . ' images in ' . count( $report ) . ' posts.', 'dry_run' => $dry, 'posts' => $report );
	}

	public static function link_opportunities( $a ) {
		$target = get_post( (int) $a['target_id'] );
		if ( ! $target ) {
			SC_Tools::fail( 'Target not found.' );
		}
		$seo = self::get_seo( $target->ID );
		$kw  = SC_Tools::str( $a, 'keyword', $seo['focus_keyword'] ? $seo['focus_keyword'] : $target->post_title );
		$url = get_permalink( $target );
		$rel = wp_make_link_relative( $url );
		$out = array();
		$q   = new WP_Query( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 's' => $kw, 'posts_per_page' => 50, 'post__not_in' => array( $target->ID ) ) );
		foreach ( $q->posts as $p ) {
			if ( false !== strpos( $p->post_content, $url ) || ( $rel && false !== strpos( $p->post_content, 'href="' . $rel ) ) ) {
				continue;
			}
			$text = wp_strip_all_tags( $p->post_content );
			$pos  = mb_stripos( $text, $kw );
			if ( false === $pos ) {
				continue;
			}
			$out[] = array(
				'id'      => $p->ID,
				'title'   => $p->post_title,
				'context' => '…' . mb_substr( $text, max( 0, $pos - 80 ), 200 ) . '…',
			);
		}
		return array( 'target' => $url, 'keyword' => $kw, 'opportunities' => $out, 'how' => 'Use update_content to wrap the phrase in <a href="' . $url . '">…</a>.' );
	}

	public static function broken_links( $a ) {
		$posts    = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => min( 1000, SC_Tools::int( $a, 'limit', 200 ) ) ) );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$external = SC_Tools::bool( $a, 'check_external' );
		$cache    = array();
		$broken   = array();
		$redirect = get_option( self::REDIRECTS, array() );
		foreach ( $posts as $p ) {
			if ( ! preg_match_all( '#<a\b[^>]*href=["\']([^"\'\#]+)#i', $p->post_content, $m ) ) {
				continue;
			}
			foreach ( array_unique( $m[1] ) as $href ) {
				if ( preg_match( '#^(mailto|tel|javascript):#i', $href ) ) {
					continue;
				}
				$h        = wp_parse_url( $href, PHP_URL_HOST );
				$internal = ! $h || $h === $host;
				if ( ! $internal && ! $external ) {
					continue;
				}
				if ( ! isset( $cache[ $href ] ) ) {
					$cache[ $href ] = $internal ? self::internal_link_ok( $href, $redirect ) : self::external_link_ok( $href );
				}
				if ( true !== $cache[ $href ] ) {
					$broken[] = array( 'post_id' => $p->ID, 'post' => $p->post_title, 'link' => $href, 'problem' => $cache[ $href ] );
				}
			}
		}
		return array( 'posts_scanned' => count( $posts ), 'broken' => $broken, 'fix' => 'Change the link with search_replace_content, or add_redirect from the dead path.' );
	}

	private static function internal_link_ok( $href, $redirects ) {
		$path = wp_parse_url( $href, PHP_URL_PATH );
		$path = $path ? $path : '/';
		if ( '/' === $path || isset( $redirects[ self::norm_path( $path ) ] ) ) {
			return true;
		}
		if ( preg_match( '#^/(wp-content|wp-admin|wp-includes|feed|wp-json)\b#', $path ) || preg_match( '#\.\w{2,4}$#', $path ) ) {
			return true; // Files and system paths: not checked.
		}
		$abs = 0 === strpos( $href, 'http' ) ? $href : home_url( $path );
		if ( url_to_postid( $abs ) ) {
			return true;
		}
		$res = wp_remote_head( $abs, array( 'timeout' => 10, 'redirection' => 3, 'sslverify' => false ) );
		$code = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
		return ( $code >= 200 && $code < 400 ) ? true : 'HTTP ' . ( $code ? $code : 'error' );
	}

	private static function external_link_ok( $href ) {
		$res = wp_remote_head( $href, array( 'timeout' => 10, 'redirection' => 5 ) );
		if ( is_wp_error( $res ) ) {
			return 'unreachable: ' . $res->get_error_message();
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 405 === $code || 403 === $code ) {
			return true; // Many sites refuse HEAD requests.
		}
		return $code < 400 ? true : "HTTP $code";
	}

	/* ------------------------------ redirects ----------------------------- */

	public static function norm_path( $path ) {
		$p = wp_parse_url( (string) $path, PHP_URL_PATH );
		$p = '/' . trim( rawurldecode( (string) ( $p ? $p : $path ) ), '/' );
		return strtolower( '/' === $p ? '/' : $p . '/' );
	}

	public static function add_redirect( $a ) {
		$from = self::norm_path( $a['from'] );
		$code = SC_Tools::int( $a, 'code', 301 );
		if ( ! in_array( $code, array( 301, 302, 307, 308, 410 ), true ) ) {
			SC_Tools::fail( 'code must be 301, 302, 307, 308 or 410.' );
		}
		$to = SC_Tools::str( $a, 'to', '' );
		if ( 410 !== $code && '' === $to ) {
			SC_Tools::fail( 'to is required.' );
		}
		if ( '/' === $from ) {
			SC_Tools::fail( 'Cannot redirect the homepage.' );
		}
		if ( $to && self::norm_path( $to ) === $from && ! wp_parse_url( $to, PHP_URL_HOST ) ) {
			SC_Tools::fail( 'A redirect cannot point to itself.' );
		}
		$all          = get_option( self::REDIRECTS, array() );
		$all[ $from ] = array( 'to' => $to, 'code' => $code, 'hits' => isset( $all[ $from ]['hits'] ) ? $all[ $from ]['hits'] : 0, 'created' => current_time( 'mysql' ) );
		update_option( self::REDIRECTS, $all, true );
		$log = get_option( self::LOG_404, array() );
		if ( isset( $log[ $from ] ) ) {
			unset( $log[ $from ] );
			update_option( self::LOG_404, $log, false );
		}
		return array( 'message' => "Redirect $from → " . ( $to ? $to : '(gone)' ) . " ($code)." );
	}

	public static function delete_redirect( $a ) {
		$from = self::norm_path( $a['from'] );
		$all  = get_option( self::REDIRECTS, array() );
		if ( ! isset( $all[ $from ] ) ) {
			SC_Tools::fail( 'No redirect for that path.' );
		}
		unset( $all[ $from ] );
		update_option( self::REDIRECTS, $all, true );
		return array( 'message' => "Removed redirect $from." );
	}

	public static function get_robots() {
		ob_start();
		sc_robots_txt_output();
		return array(
			'physical_file' => file_exists( ABSPATH . 'robots.txt' ),
			'extra_rules'   => get_option( self::ROBOTS, '' ),
			'served'        => ob_get_clean(),
		);
	}
}

/**
 * Render robots.txt as WordPress would serve it.
 */
function sc_robots_txt_output() {
	$public = get_option( 'blog_public' );
	$output = "User-agent: *\n";
	if ( '0' === (string) $public ) {
		$output .= "Disallow: /\n";
	} else {
		$path    = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		$output .= "Disallow: $path/wp-admin/\n";
		$output .= "Allow: $path/wp-admin/admin-ajax.php\n";
	}
	echo apply_filters( 'robots_txt', $output, $public ); // phpcs:ignore
}
