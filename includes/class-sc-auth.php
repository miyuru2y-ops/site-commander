<?php
/**
 * Connection keys — one per AI.
 *
 * Each AI (Claude, ChatGPT, a script…) gets its own named key with its own scope:
 * which tool groups it may use and whether it may change anything. Keys are stored
 * only as SHA-256 hashes; the plain key is shown once, right after it is created.
 * A key works only on the site that issued it.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Auth {

	const OPTION = 'sc_keys';

	/** Key record used for the current request (null outside API requests). */
	public static $current = null;

	/**
	 * All keys, indexed by hash. Migrates the single key of version 1.0.0.
	 */
	public static function keys() {
		$keys = get_option( self::OPTION, null );
		if ( null === $keys ) {
			$keys   = array();
			$legacy = get_option( 'sc_key_hash' );
			if ( $legacy ) {
				$keys[ $legacy ] = self::record( 'Default', (string) get_option( 'sc_key_hint', '' ), array_keys( SC_Tools::groups() ), false );
				delete_option( 'sc_key_hash' );
				delete_option( 'sc_key_hint' );
			}
			update_option( self::OPTION, $keys, false );
		}
		return is_array( $keys ) ? $keys : array();
	}

	private static function save( array $keys ) {
		update_option( self::OPTION, $keys, false );
	}

	private static function record( $name, $hint, array $groups, $read_only ) {
		return array(
			'id'        => wp_generate_password( 8, false, false ),
			'name'      => $name,
			'hint'      => $hint,
			'groups'    => array_values( $groups ),
			'read_only' => (bool) $read_only,
			'created'   => current_time( 'mysql' ),
			'last_used' => null,
			'last_ip'   => null,
			'approval'  => false,
			'expires'   => '',
			'ips'       => array(),
			'daily'     => 0,
		);
	}

	/** Clean the optional limits posted from the admin form or passed by code. */
	public static function clean_limits( array $in ) {
		$out = array(
			'approval' => ! empty( $in['approval'] ),
			'expires'  => '',
			'ips'      => array(),
			'daily'    => isset( $in['daily'] ) ? max( 0, (int) $in['daily'] ) : 0,
		);
		if ( ! empty( $in['expires'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['expires'] ) ) {
			$out['expires'] = (string) $in['expires'];
		}
		$ips = isset( $in['ips'] ) ? ( is_array( $in['ips'] ) ? $in['ips'] : preg_split( '/[\s,]+/', (string) $in['ips'] ) ) : array();
		foreach ( $ips as $ip ) {
			$ip = trim( (string) $ip );
			if ( '' !== $ip && ( filter_var( $ip, FILTER_VALIDATE_IP ) || preg_match( '#^[0-9a-f:.]+/\d{1,3}$#i', $ip ) ) ) {
				$out['ips'][] = $ip;
			}
		}
		return $out;
	}

	/** Whether an IP matches an allow-list entry (exact IP or CIDR range). */
	public static function ip_matches( $ip, $rule ) {
		if ( false === strpos( $rule, '/' ) ) {
			return $ip === $rule;
		}
		list( $net, $bits ) = explode( '/', $rule, 2 );
		$ip_bin  = @inet_pton( $ip ); // phpcs:ignore
		$net_bin = @inet_pton( $net ); // phpcs:ignore
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		$rem = $bits % 8;
		if ( ! $rem ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $net_bin[ $bytes ] & $mask );
	}

	/** Count a change for the key and return a reason string once its daily limit is used up. */
	public static function over_daily_limit( array $rec ) {
		$max = isset( $rec['daily'] ) ? (int) $rec['daily'] : 0;
		if ( $max <= 0 ) {
			return null;
		}
		$opt   = 'sc_key_usage';
		$usage = get_option( $opt, array() );
		$usage = is_array( $usage ) ? $usage : array();
		$day   = current_time( 'Y-m-d' );
		$used  = ( isset( $usage[ $rec['id'] ]['day'] ) && $usage[ $rec['id'] ]['day'] === $day ) ? (int) $usage[ $rec['id'] ]['n'] : 0;
		if ( $used >= $max ) {
			return sprintf( 'The key "%s" has used its limit of %d changes for today. The site owner can raise it under Site Commander → AI connections.', $rec['name'], $max );
		}
		$usage[ $rec['id'] ] = array( 'day' => $day, 'n' => $used + 1 );
		update_option( $opt, $usage, false );
		return null;
	}

	public static function has_key() {
		return (bool) self::keys();
	}

	/**
	 * Create a key for one AI. Returns array( plain key, record ).
	 */
	public static function create_key( $name, array $groups, $read_only = false, array $limits = array() ) {
		$valid  = array_keys( SC_Tools::groups() );
		$groups = array_values( array_intersect( $groups, $valid ) );
		if ( ! $groups ) {
			$groups = $valid;
		}
		$name = trim( sanitize_text_field( $name ) );
		$key  = 'sc_' . bin2hex( random_bytes( 24 ) );
		$rec  = array_merge( self::record( $name ? $name : 'AI connection', substr( $key, -4 ), $groups, $read_only ), self::clean_limits( $limits ) );
		$keys = self::keys();
		$keys[ hash( 'sha256', $key ) ] = $rec;
		self::save( $keys );
		return array( $key, $rec );
	}

	public static function revoke( $id ) {
		$keys = self::keys();
		foreach ( $keys as $hash => $rec ) {
			if ( $rec['id'] === $id ) {
				unset( $keys[ $hash ] );
				self::save( $keys );
				return $rec;
			}
		}
		return null;
	}

	public static function update_scope( $id, array $groups, $read_only, $limits = null ) {
		$keys  = self::keys();
		$valid = array_keys( SC_Tools::groups() );
		foreach ( $keys as $hash => $rec ) {
			if ( $rec['id'] === $id ) {
				$groups                     = array_values( array_intersect( $groups, $valid ) );
				$keys[ $hash ]['groups']    = $groups ? $groups : $valid;
				$keys[ $hash ]['read_only'] = (bool) $read_only;
				if ( is_array( $limits ) ) {
					$keys[ $hash ] = array_merge( $keys[ $hash ], self::clean_limits( $limits ) );
				}
				self::save( $keys );
				return $keys[ $hash ];
			}
		}
		return null;
	}

	/**
	 * Pull the key from the request: Bearer header, X-SC-Key header, or ?key= in the URL.
	 * The URL form exists because many hosts strip the Authorization header and some
	 * MCP clients can only be given a URL.
	 */
	public static function key_from_request( WP_REST_Request $request ) {
		$auth = $request->get_header( 'authorization' );
		if ( $auth && preg_match( '/^\s*Bearer\s+(\S+)/i', $auth, $m ) ) {
			return $m[1];
		}
		$header = $request->get_header( 'x_sc_key' );
		if ( $header ) {
			return trim( $header );
		}
		$param = $request->get_param( 'key' );
		return is_string( $param ) ? trim( $param ) : '';
	}

	/**
	 * Returns the key record for a plain key, or null.
	 */
	public static function find( $key ) {
		if ( ! is_string( $key ) || strlen( $key ) < 20 ) {
			return null;
		}
		$hash = hash( 'sha256', $key );
		$keys = self::keys();
		foreach ( $keys as $stored => $rec ) {
			if ( hash_equals( (string) $stored, $hash ) ) {
				return array_merge( $rec, array( 'hash' => $stored ) );
			}
		}
		return null;
	}

	/**
	 * Whether the current key may use a tool. Returns a reason string, or null if allowed.
	 */
	public static function scope_blocks( array $tool ) {
		$rec = self::$current;
		if ( ! $rec ) {
			return null; // Internal call (admin, tests).
		}
		if ( ! in_array( $tool['group'], (array) $rec['groups'], true ) ) {
			return sprintf( 'The key "%s" is not allowed to use %s tools. The site owner can widen its access under Site Commander → AI connections.', $rec['name'], $tool['group'] );
		}
		if ( ! empty( $rec['read_only'] ) && $tool['write'] ) {
			return sprintf( 'The key "%s" is read-only, so it cannot make changes.', $rec['name'] );
		}
		return null;
	}

	/**
	 * REST permission callback for every authenticated endpoint.
	 */
	public static function permission( WP_REST_Request $request ) {
		if ( ! SC_Plugin::setting( 'enabled' ) ) {
			return new WP_Error( 'sc_disabled', 'Site Commander is switched off in WordPress.', array( 'status' => 403 ) );
		}
		$rec = self::find( self::key_from_request( $request ) );
		if ( ! $rec ) {
			usleep( 300000 ); // Slows down key guessing.
			return new WP_Error( 'sc_bad_key', 'Missing or invalid Site Commander key.', array( 'status' => 401 ) );
		}

		if ( ! empty( $rec['expires'] ) && current_time( 'Y-m-d' ) > $rec['expires'] ) {
			return new WP_Error( 'sc_key_expired', sprintf( 'The key "%s" expired on %s. The site owner can extend it under Site Commander → AI connections.', $rec['name'], $rec['expires'] ), array( 'status' => 401 ) );
		}
		if ( ! empty( $rec['ips'] ) ) {
			$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore
			$hit = false;
			foreach ( (array) $rec['ips'] as $rule ) {
				if ( self::ip_matches( $ip, $rule ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				return new WP_Error( 'sc_ip_blocked', sprintf( 'The key "%s" may not be used from this IP address (%s).', $rec['name'], $ip ), array( 'status' => 403 ) );
			}
		}

		$user_id = (int) SC_Plugin::setting( 'run_as_user' );
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
			$user   = $admins ? $admins[0] : false;
		}
		if ( ! $user ) {
			return new WP_Error( 'sc_no_user', 'No administrator account is available to act as.', array( 'status' => 403 ) );
		}
		wp_set_current_user( $user->ID );
		self::$current  = $rec;
		SC_Log::$client = $rec['name'];

		// Record last use, at most every 5 minutes to avoid a database write per request.
		if ( empty( $rec['last_used'] ) || strtotime( $rec['last_used'] ) < current_time( 'timestamp' ) - 300 ) {
			$keys = self::keys();
			if ( isset( $keys[ $rec['hash'] ] ) ) {
				$keys[ $rec['hash'] ]['last_used'] = current_time( 'mysql' );
				$keys[ $rec['hash'] ]['last_ip']   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null; // phpcs:ignore
				self::save( $keys );
			}
		}
		return true;
	}
}
