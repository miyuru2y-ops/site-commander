<?php
/**
 * Transports: the same tool registry served as
 *   1. an MCP server (Streamable HTTP, JSON-RPC 2.0)        POST /wp-json/site-commander/v1/mcp
 *   2. a plain REST API                                     GET  /wp-json/site-commander/v1/tools
 *                                                           POST /wp-json/site-commander/v1/tools/{name}
 *   3. an OpenAPI 3.1 spec (ChatGPT Actions, Gemini, n8n…)  GET  /wp-json/site-commander/v1/openapi.json
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_MCP_Server {

	const NS = 'site-commander/v1';

	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_mcp' ),
					'permission_callback' => array( 'SC_Auth', 'permission' ),
				),
				array(
					// Streamable HTTP allows a server to decline the optional SSE stream.
					'methods'             => 'GET',
					'callback'            => function () {
						return new WP_REST_Response( array( 'error' => 'This server does not offer an SSE stream. Use POST.' ), 405, array( 'Allow' => 'POST, DELETE' ) );
					},
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => function () {
						return new WP_REST_Response( null, 200 );
					},
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NS,
			'/tools',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_rest_list' ),
				'permission_callback' => array( 'SC_Auth', 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/tools/(?P<tool>[a-z0-9_]+)',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'handle_rest_call' ),
				'permission_callback' => array( 'SC_Auth', 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/openapi.json',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_openapi' ),
				'permission_callback' => '__return_true', // The spec holds no secrets; every operation still needs the key.
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * MCP
	 * ------------------------------------------------------------------ */

	public static function handle_mcp( WP_REST_Request $request ) {
		self::set_client_from_request( $request, 'MCP' );

		$payload = json_decode( $request->get_body(), true );
		if ( null === $payload ) {
			return new WP_REST_Response( self::rpc_error( null, -32700, 'Parse error: body must be JSON-RPC 2.0' ), 400 );
		}

		$headers = array();
		$batch   = is_array( $payload ) && wp_is_numeric_array( $payload ) && ! empty( $payload );
		$items   = $batch ? $payload : array( $payload );
		$out     = array();

		foreach ( $items as $msg ) {
			$res = self::dispatch( $msg, $headers );
			if ( null !== $res ) {
				$out[] = $res;
			}
		}

		if ( empty( $out ) ) {
			// Only notifications / responses were sent.
			return new WP_REST_Response( null, 202, $headers );
		}
		return new WP_REST_Response( $batch ? $out : $out[0], 200, $headers );
	}

	private static function dispatch( $msg, array &$headers ) {
		if ( ! is_array( $msg ) || ! isset( $msg['method'] ) ) {
			// A JSON-RPC response from the client, or junk. Responses need no reply.
			return ( is_array( $msg ) && ( isset( $msg['result'] ) || isset( $msg['error'] ) ) ) ? null : self::rpc_error( null, -32600, 'Invalid request' );
		}
		$id      = array_key_exists( 'id', $msg ) ? $msg['id'] : null;
		$is_note = ! array_key_exists( 'id', $msg );
		$params  = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		switch ( $msg['method'] ) {
			case 'initialize':
				$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
				$version   = in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSIONS[1];
				$client    = isset( $params['clientInfo']['name'] ) ? (string) $params['clientInfo']['name'] : 'mcp-client';
				// Stateless server: the session id just carries the client's name for the activity log.
				$headers['Mcp-Session-Id'] = 'sc-' . bin2hex( substr( $client, 0, 40 ) ) . '-' . wp_generate_password( 12, false );
				return self::rpc_result(
					$id,
					array(
						'protocolVersion' => $version,
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array(
							'name'    => 'site-commander',
							'title'   => 'Site Commander – ' . get_bloginfo( 'name' ),
							'version' => SC_VERSION,
						),
						'instructions'    => self::instructions(),
					)
				);

			case 'ping':
				return self::rpc_result( $id, new stdClass() );

			case 'tools/list':
				$list = array();
				foreach ( SC_Tools::all() as $tool ) {
					if ( SC_Auth::scope_blocks( $tool ) ) {
						continue; // Only show what this AI's key may use.
					}
					$list[] = self::mcp_tool( $tool );
				}
				return self::rpc_result( $id, array( 'tools' => $list ) );

			case 'tools/call':
				$name = isset( $params['name'] ) ? (string) $params['name'] : '';
				if ( ! SC_Tools::get( $name ) ) {
					return self::rpc_error( $id, -32602, "Unknown tool: $name" );
				}
				$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				$res  = SC_Tools::call( $name, $args );
				$text = $res['ok'] ? self::to_text( $res['data'] ) : 'Error: ' . $res['error'];
				return self::rpc_result(
					$id,
					array(
						'content' => array( array( 'type' => 'text', 'text' => $text ) ),
						'isError' => ! $res['ok'],
					)
				);

			case 'resources/list':
				return self::rpc_result( $id, array( 'resources' => array() ) );

			case 'prompts/list':
				return self::rpc_result( $id, array( 'prompts' => array() ) );

			default:
				if ( $is_note ) {
					return null; // notifications/initialized, notifications/cancelled, etc.
				}
				return self::rpc_error( $id, -32601, 'Method not found: ' . $msg['method'] );
		}
	}

	private static function mcp_tool( array $tool ) {
		$desc    = $tool['description'];
		$blocked = SC_Tools::blocked_reason( $tool );
		if ( $blocked ) {
			$desc = '[Currently disabled by the site owner] ' . $desc;
		}
		return array(
			'name'        => $tool['name'],
			'title'       => $tool['title'],
			'description' => $desc,
			'inputSchema' => SC_Tools::input_schema( $tool ),
			'annotations' => array(
				'title'           => $tool['title'],
				'readOnlyHint'    => ! $tool['write'],
				'destructiveHint' => (bool) $tool['destructive'],
				'openWorldHint'   => false,
			),
		);
	}

	private static function instructions() {
		$k     = SC_Auth::$current;
		$scope = $k ? sprintf( 'Your connection key is "%s": allowed tool groups %s%s.', $k['name'], implode( ', ', (array) $k['groups'] ), empty( $k['read_only'] ) ? '' : ' (read-only: you cannot make changes)' ) : '';
		return implode(
			"\n",
			array(
				'You are connected to the WordPress site "' . get_bloginfo( 'name' ) . '" (' . home_url() . '). This connection only works for this one site.',
				$scope,
				'Call get_site_info first: it gives the site timezone and current local time (needed for scheduling), the active theme (block or classic), and which SEO plugin is active.',
				'Scheduling: create_content or schedule_content with status "future" (or "publish") and a date in the site timezone.',
				'Design: use set_custom_css for styling tweaks; update_global_styles for block-theme colors/fonts; update_template for block-theme layouts; theme files only when needed. For PHP edits on a theme that gets updates, create_child_theme first.',
				'SEO: run seo_audit, then fix with bulk_set_seo, fix_image_alts, fix_content_image_alts, add_redirect.',
				'Deletes go to the trash unless the site owner allowed permanent deletion. Confirm destructive actions with the user before calling them.',
			)
		);
	}

	private static function rpc_result( $id, $result ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	private static function rpc_error( $id, $code, $message ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) );
	}

	private static function to_text( $data ) {
		if ( is_string( $data ) ) {
			return $data;
		}
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function set_client_from_request( WP_REST_Request $request, $transport ) {
		$client  = '';
		$session = (string) $request->get_header( 'mcp_session_id' );
		if ( preg_match( '/^sc-([0-9a-f]+)-/', $session, $m ) ) {
			$decoded = @hex2bin( $m[1] ); // phpcs:ignore
			$client  = $decoded ? $decoded : '';
		}
		if ( ! $client ) {
			$ua     = (string) $request->get_header( 'user_agent' );
			$client = $ua ? substr( $ua, 0, 60 ) : 'unknown';
		}
		$name           = SC_Auth::$current ? SC_Auth::$current['name'] : 'unknown key';
		SC_Log::$client = $name . ' (' . $transport . ': ' . sanitize_text_field( $client ) . ')';
	}

	/* ------------------------------------------------------------------ *
	 * Plain REST
	 * ------------------------------------------------------------------ */

	public static function handle_rest_list( WP_REST_Request $request ) {
		$group = $request->get_param( 'group' );
		$out   = array();
		foreach ( SC_Tools::all() as $tool ) {
			if ( ( $group && $tool['group'] !== $group ) || SC_Auth::scope_blocks( $tool ) ) {
				continue;
			}
			$out[] = array(
				'name'        => $tool['name'],
				'group'       => $tool['group'],
				'description' => $tool['description'],
				'write'       => $tool['write'],
				'enabled'     => null === SC_Tools::blocked_reason( $tool ),
				'endpoint'    => rest_url( self::NS . '/tools/' . $tool['name'] ),
				'input'       => SC_Tools::input_schema( $tool ),
			);
		}
		return new WP_REST_Response( array( 'site' => home_url(), 'tools' => $out ), 200 );
	}

	public static function handle_rest_call( WP_REST_Request $request ) {
		self::set_client_from_request( $request, 'REST' );
		$name = $request->get_param( 'tool' );
		$tool = SC_Tools::get( $name );
		if ( ! $tool ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => "Unknown tool: $name" ), 404 );
		}
		if ( 'GET' === $request->get_method() && $tool['write'] ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Use POST for tools that change the site.' ), 405 );
		}

		$args = $request->get_json_params();
		if ( ! is_array( $args ) || empty( $args ) ) {
			$args = array_merge( $request->get_query_params(), $request->get_body_params() );
		}
		if ( isset( $args['arguments'] ) && is_array( $args['arguments'] ) && count( $args ) === 1 ) {
			$args = $args['arguments'];
		}
		unset( $args['key'], $args['tool'], $args['rest_route'] );

		$res = SC_Tools::call( $name, $args );
		if ( $res['ok'] ) {
			return new WP_REST_Response( array( 'ok' => true, 'result' => $res['data'] ), 200 );
		}
		return new WP_REST_Response( array( 'ok' => false, 'error' => $res['error'] ), 400 );
	}

	/* ------------------------------------------------------------------ *
	 * OpenAPI
	 * ------------------------------------------------------------------ */

	public static function handle_openapi( WP_REST_Request $request ) {
		$groups = $request->get_param( 'groups' );
		$groups = $groups ? array_map( 'trim', explode( ',', (string) $groups ) ) : null;

		$paths = array();
		foreach ( SC_Tools::all() as $tool ) {
			if ( $groups && ! in_array( $tool['group'], $groups, true ) ) {
				continue;
			}
			$desc = $tool['description'];
			if ( strlen( $desc ) > 290 ) {
				$desc = substr( $desc, 0, 287 ) . '...';
			}
			$paths[ '/tools/' . $tool['name'] ] = array(
				'post' => array(
					'operationId' => $tool['name'],
					'summary'     => $tool['title'],
					'description' => $desc,
					'tags'        => array( $tool['group'] ),
					'x-openai-isConsequential' => (bool) $tool['write'],
					'requestBody' => array(
						'required' => ! empty( $tool['required'] ),
						'content'  => array( 'application/json' => array( 'schema' => SC_Tools::input_schema( $tool ) ) ),
					),
					'responses'   => array(
						'200' => array(
							'description' => 'Success',
							'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/Result' ) ) ),
						),
						'400' => array(
							'description' => 'The tool reported an error',
							'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/Result' ) ) ),
						),
					),
				),
			);
		}

		$spec = array(
			'openapi'    => '3.1.0',
			'info'       => array(
				'title'       => 'Site Commander – ' . get_bloginfo( 'name' ),
				'version'     => SC_VERSION,
				'description' => 'Manage the WordPress site ' . home_url() . ' (posts, pages, scheduling, media, design, SEO). Call get_site_info first.',
			),
			'servers'    => array( array( 'url' => untrailingslashit( rest_url( self::NS ) ) ) ),
			'paths'      => $paths,
			'components' => array(
				'schemas'         => array(
					'Result' => array(
						'type'       => 'object',
						'properties' => array(
							'ok'     => array( 'type' => 'boolean' ),
							'result' => array( 'description' => 'Tool output' ),
							'error'  => array( 'type' => 'string' ),
						),
					),
				),
				'securitySchemes' => array(
					'bearerAuth' => array( 'type' => 'http', 'scheme' => 'bearer' ),
					'apiKey'     => array( 'type' => 'apiKey', 'in' => 'header', 'name' => 'X-SC-Key' ),
				),
			),
			'security'   => array( array( 'bearerAuth' => array() ), array( 'apiKey' => array() ) ),
		);
		return new WP_REST_Response( $spec, 200 );
	}
}
