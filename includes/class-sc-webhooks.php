<?php
/**
 * Webhooks: tell n8n, Zapier, Make or your own AI agent when something happens on the site.
 *
 * Each request is a JSON POST signed with HMAC-SHA256 (header X-SC-Signature: sha256=<hex>).
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Webhooks {

	const OPTION = 'sc_webhooks';

	public static function events() {
		return array(
			'post_published'   => 'A post, page or product is published',
			'comment_posted'   => 'A new comment or review arrives',
			'form_submitted'   => 'A form is submitted (Gravity Forms, WPForms, Contact Form 7, Fluent Forms)',
			'order_created'    => 'A WooCommerce order is placed',
			'user_registered'  => 'A new user registers',
			'ai_change'        => 'An AI connection changed something',
			'pending_approval' => 'An AI change is waiting for approval',
			'security_alert'   => 'The security check found something new',
		);
	}

	public static function all() {
		$h = get_option( self::OPTION, array() );
		return is_array( $h ) ? $h : array();
	}

	public static function init() {
		if ( ! self::all() ) {
			return;
		}
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'comment_post', array( __CLASS__, 'on_comment' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'on_user' ) );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_order' ) );
		add_action( 'gform_after_submission', function ( $entry, $form ) {
			SC_Webhooks::fire( 'form_submitted', array( 'plugin' => 'gravityforms', 'form_id' => (int) $form['id'], 'form' => $form['title'], 'entry_id' => (int) $entry['id'] ) );
		}, 10, 2 );
		add_action( 'wpforms_process_complete', function ( $fields, $entry, $form, $entry_id ) {
			$data = array();
			foreach ( (array) $fields as $f ) {
				if ( isset( $f['name'] ) ) {
					$data[ $f['name'] ] = isset( $f['value'] ) ? $f['value'] : '';
				}
			}
			SC_Webhooks::fire( 'form_submitted', array( 'plugin' => 'wpforms', 'form_id' => (int) $form['id'], 'entry_id' => (int) $entry_id, 'fields' => $data ) );
		}, 10, 4 );
		add_action( 'wpcf7_mail_sent', function ( $cf ) {
			$sub  = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
			$data = $sub ? $sub->get_posted_data() : array();
			SC_Webhooks::fire( 'form_submitted', array( 'plugin' => 'cf7', 'form_id' => (int) $cf->id(), 'form' => $cf->title(), 'fields' => $data ) );
		} );
		add_action( 'fluentform/submission_inserted', function ( $id, $data, $form ) {
			SC_Webhooks::fire( 'form_submitted', array( 'plugin' => 'fluentforms', 'form_id' => is_object( $form ) ? (int) $form->id : 0, 'entry_id' => (int) $id, 'fields' => $data ) );
		}, 10, 3 );
		add_action( 'sc_tool_done', function ( $tool, $args, $summary ) {
			SC_Webhooks::fire( 'ai_change', array( 'client' => SC_Log::$client, 'tool' => $tool, 'summary' => $summary ) );
		}, 10, 3 );
		add_action( 'sc_pending_change', function ( $item ) {
			SC_Webhooks::fire( 'pending_approval', array( 'client' => $item['client'], 'tool' => $item['tool'], 'preview' => $item['preview'], 'review_url' => admin_url( 'admin.php?page=site-commander#approvals' ) ) );
		} );
		add_action( 'sc_security_alert', function ( $report ) {
			SC_Webhooks::fire( 'security_alert', array( 'findings' => $report['issues'] ) );
		} );
	}

	public static function on_status( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || wp_is_post_revision( $post ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		self::fire( 'post_published', array( 'id' => $post->ID, 'type' => $post->post_type, 'title' => $post->post_title, 'url' => get_permalink( $post ) ) );
	}

	public static function on_comment( $id, $approved ) {
		$c = get_comment( $id );
		if ( ! $c || 'spam' === $approved ) {
			return;
		}
		self::fire( 'comment_posted', array( 'id' => (int) $id, 'post_id' => (int) $c->comment_post_ID, 'post' => get_the_title( $c->comment_post_ID ), 'author' => $c->comment_author, 'content' => $c->comment_content, 'type' => $c->comment_type, 'approved' => $approved ) );
	}

	public static function on_user( $id ) {
		$u = get_userdata( $id );
		self::fire( 'user_registered', array( 'id' => (int) $id, 'login' => $u ? $u->user_login : '', 'roles' => $u ? $u->roles : array() ) );
	}

	public static function on_order( $id ) {
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		self::fire( 'order_created', array( 'id' => (int) $id, 'total' => $o ? $o->get_total() : '', 'currency' => $o ? $o->get_currency() : '', 'status' => $o ? $o->get_status() : '', 'items' => $o ? count( $o->get_items() ) : 0 ) );
	}

	/** Send an event to every webhook that listens for it. */
	public static function fire( $event, array $data, $only_id = '', $blocking = false ) {
		$hooks = self::all();
		$res   = null;
		foreach ( $hooks as $i => $h ) {
			if ( $only_id ? $h['id'] !== $only_id : ! in_array( $event, (array) $h['events'], true ) ) {
				continue;
			}
			$body = wp_json_encode( array( 'event' => $event, 'site' => home_url(), 'time' => gmdate( 'c' ), 'data' => $data ) );
			$res  = wp_remote_post(
				$h['url'],
				array(
					'timeout'  => $blocking ? 10 : 3,
					'blocking' => $blocking,
					'headers'  => array(
						'Content-Type'   => 'application/json',
						'X-SC-Event'     => $event,
						'X-SC-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $h['secret'] ),
					),
					'body'     => $body,
				)
			);
			$hooks[ $i ]['last'] = current_time( 'mysql' ) . ' ' . $event . ( $blocking ? ' → ' . ( is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res ) ) : '' );
		}
		if ( $res ) {
			update_option( self::OPTION, $hooks, false );
		}
		return $res;
	}

	public static function add( $url, array $events ) {
		$url = esc_url_raw( trim( (string) $url ) );
		$scheme = $url ? wp_parse_url( $url, PHP_URL_SCHEME ) : '';
		if ( ! $url || ! in_array( $scheme, array( 'http', 'https' ), true ) || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			SC_Tools::fail( 'The webhook URL is not a valid public http(s) URL.' );
		}
		$events = array_values( array_intersect( $events, array_keys( self::events() ) ) );
		if ( ! $events ) {
			SC_Tools::fail( 'Choose at least one event: ' . implode( ', ', array_keys( self::events() ) ) . '.' );
		}
		$hooks   = self::all();
		$h       = array( 'id' => 'w' . wp_generate_password( 6, false, false ), 'url' => $url, 'events' => $events, 'secret' => wp_generate_password( 32, false, false ), 'created' => current_time( 'mysql' ), 'last' => '' );
		$hooks[] = $h;
		update_option( self::OPTION, $hooks, false );
		return $h;
	}

	public static function remove( $id ) {
		$hooks = self::all();
		$left  = array_values( array_filter( $hooks, function ( $h ) use ( $id ) {
			return $h['id'] !== $id;
		} ) );
		update_option( self::OPTION, $left, false );
		return count( $left ) < count( $hooks );
	}

	public static function register_tools() {
		$t = 'SC_Tools';
		$t::add( 'list_webhooks', array(
			'group'       => 'dev',
			'description' => 'List webhooks (URL, events, last delivery) and all available events.',
			'callback'    => function () {
				$out = array();
				foreach ( SC_Webhooks::all() as $h ) {
					$out[] = array( 'id' => $h['id'], 'url' => $h['url'], 'events' => $h['events'], 'last' => $h['last'] );
				}
				return array( 'webhooks' => $out, 'available_events' => SC_Webhooks::events() );
			},
		) );
		$t::add( 'add_webhook', array(
			'group'       => 'dev',
			'description' => 'Send site events to a URL (n8n, Zapier, Make, your agent). Requests are JSON POSTs signed with X-SC-Signature: sha256=HMAC(body, secret). Returns the secret.',
			'properties'  => array(
				'url'    => $t::p( 'string', 'Webhook URL.' ),
				'events' => $t::p_list( 'Events: ' . implode( ', ', array_keys( self::events() ) ) . '.' ),
			),
			'required'    => array( 'url', 'events' ),
			'write'       => true,
			'callback'    => function ( $a ) {
				$h = SC_Webhooks::add( $a['url'], (array) SC_Tools::arr( $a, 'events' ) );
				return array( 'message' => 'Webhook added for ' . implode( ', ', $h['events'] ) . '.', 'id' => $h['id'], 'secret' => $h['secret'] );
			},
		) );
		$t::add( 'delete_webhook', array(
			'group'       => 'dev',
			'description' => 'Remove a webhook.',
			'properties'  => array( 'id' => $t::p( 'string', 'Webhook id.' ) ),
			'required'    => array( 'id' ),
			'write'       => true,
			'callback'    => function ( $a ) {
				if ( ! SC_Webhooks::remove( (string) $a['id'] ) ) {
					SC_Tools::fail( 'Webhook not found.' );
				}
				return array( 'message' => 'Webhook removed.' );
			},
		) );
		$t::add( 'test_webhook', array(
			'group'       => 'dev',
			'description' => 'Send a test event to a webhook and show the response code.',
			'properties'  => array( 'id' => $t::p( 'string', 'Webhook id.' ) ),
			'required'    => array( 'id' ),
			'callback'    => function ( $a ) {
				$r = SC_Webhooks::fire( 'test', array( 'message' => 'Test from Site Commander' ), (string) $a['id'], true );
				if ( ! $r ) {
					SC_Tools::fail( 'Webhook not found.' );
				}
				return is_wp_error( $r ) ? array( 'ok' => false, 'error' => $r->get_error_message() ) : array( 'ok' => true, 'status' => wp_remote_retrieve_response_code( $r ) );
			},
		) );
	}
}
