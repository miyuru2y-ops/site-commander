<?php
/**
 * Agency hub: one AI connection on this site can work on other Site Commander sites.
 *
 * The owner adds each client site's MCP URL and key in wp-admin. Keys are stored in a
 * non-autoloaded option and are never returned to the AI.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Hub {

	const OPTION = 'sc_remote_sites';

	public static function all() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/** Accepts the MCP URL (with or without ?key=) or the site address. */
	public static function add( $name, $url, $key ) {
		$url   = trim( (string) $url );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			SC_Tools::fail( 'Enter the client site\'s MCP URL from its Site Commander screen.' );
		}
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			if ( ! $key && ! empty( $q['key'] ) ) {
				$key = $q['key'];
			}
			$url = remove_query_arg( 'key', $url );
		}
		if ( false === strpos( $url, 'site-commander/v1' ) ) {
			$url = trailingslashit( $url ) . 'wp-json/site-commander/v1/mcp';
		}
		$key = trim( (string) $key );
		if ( strlen( $key ) < 20 ) {
			SC_Tools::fail( 'Paste the client site\'s Site Commander key.' );
		}
		$sites   = self::all();
		$site    = array( 'id' => 's' . wp_generate_password( 6, false, false ), 'name' => sanitize_text_field( $name ? $name : $parts['host'] ), 'url' => esc_url_raw( $url ), 'key' => $key, 'added' => current_time( 'mysql' ) );
		$sites[] = $site;
		update_option( self::OPTION, $sites, false );
		return $site;
	}

	public static function remove( $id ) {
		update_option( self::OPTION, array_values( array_filter( self::all(), function ( $s ) use ( $id ) {
			return $s['id'] !== $id;
		} ) ), false );
	}

	private static function find( $site ) {
		foreach ( self::all() as $s ) {
			if ( $s['id'] === $site || 0 === strcasecmp( $s['name'], (string) $site ) ) {
				return $s;
			}
		}
		SC_Tools::fail( "Unknown client site '$site'. Use list_client_sites." );
	}

	private static function rpc( array $s, $method, array $params = array() ) {
		$res = wp_remote_post(
			$s['url'],
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . $s['key'] ),
				'body'    => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ? $params : new stdClass() ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			SC_Tools::fail( $s['name'] . ' could not be reached: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 || ! is_array( $body ) ) {
			SC_Tools::fail( $s['name'] . " answered HTTP $code" . ( is_array( $body ) && isset( $body['message'] ) ? ': ' . $body['message'] : '.' ) );
		}
		if ( isset( $body['error'] ) ) {
			SC_Tools::fail( $s['name'] . ': ' . ( isset( $body['error']['message'] ) ? $body['error']['message'] : 'error' ) );
		}
		return isset( $body['result'] ) ? $body['result'] : array();
	}

	public static function register_tools() {
		$t = 'SC_Tools';
		$t::add( 'list_client_sites', array(
			'group'       => 'site',
			'description' => 'Agency: list the other Site Commander sites this connection can work on.',
			'callback'    => function () {
				$out = array();
				foreach ( SC_Hub::all() as $s ) {
					$out[] = array( 'site' => $s['id'], 'name' => $s['name'], 'url' => $s['url'] );
				}
				return $out ? $out : array( 'note' => 'No client sites added yet. The owner adds them in WordPress under Site Commander → Client sites.' );
			},
		) );
		$t::add( 'client_site_tools', array(
			'group'       => 'site',
			'description' => 'Agency: list the tools available on one client site.',
			'properties'  => array( 'site' => $t::p( 'string', 'Site id or name from list_client_sites.' ) ),
			'required'    => array( 'site' ),
			'callback'    => function ( $a ) {
				$r   = self::rpc( self::find( $a['site'] ), 'tools/list' );
				$out = array();
				foreach ( (array) ( isset( $r['tools'] ) ? $r['tools'] : array() ) as $tool ) {
					$out[] = array( 'name' => $tool['name'], 'description' => isset( $tool['description'] ) ? $tool['description'] : '' );
				}
				return $out;
			},
		) );
		$t::add( 'run_on_client_site', array(
			'group'       => 'site',
			'description' => 'Agency: run any Site Commander tool on a client site, e.g. tool="update_content", arguments={...}. The client site applies its own permissions and logs the change.',
			'properties'  => array(
				'site'      => $t::p( 'string', 'Site id or name from list_client_sites.' ),
				'tool'      => $t::p( 'string', 'Tool name on that site.' ),
				'arguments' => array( 'type' => 'object', 'description' => 'Tool arguments.' ),
			),
			'required'    => array( 'site', 'tool' ),
			'write'       => true,
			'callback'    => function ( $a ) {
				$s = self::find( $a['site'] );
				$r = self::rpc( $s, 'tools/call', array( 'name' => (string) $a['tool'], 'arguments' => isset( $a['arguments'] ) && is_array( $a['arguments'] ) ? $a['arguments'] : new stdClass() ) );
				$text = isset( $r['content'][0]['text'] ) ? $r['content'][0]['text'] : wp_json_encode( $r );
				$data = json_decode( $text, true );
				if ( ! empty( $r['isError'] ) ) {
					SC_Tools::fail( $s['name'] . ': ' . $text );
				}
				return array( 'message' => $s['name'] . ': ran ' . $a['tool'] . '.', 'site' => $s['name'], 'result' => null === $data ? $text : $data );
			},
		) );
	}
}
