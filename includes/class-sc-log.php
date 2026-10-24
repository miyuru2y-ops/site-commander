<?php
/**
 * Activity log of every change an AI makes.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Log {

	const OPTION = 'sc_activity_log';
	const MAX    = 300;

	/** Name of the AI client making the current request (set by the transport). */
	public static $client = '';

	public static function add( $tool, $args, $ok, $summary = '' ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		// Never store large payloads (file contents, base64 uploads) in the log.
		$clean = array();
		foreach ( (array) $args as $k => $v ) {
			if ( in_array( $k, array( 'content', 'base64', 'css', 'key' ), true ) ) {
				$clean[ $k ] = '[' . ( is_string( $v ) ? strlen( $v ) : 0 ) . ' chars]';
			} else {
				$enc         = wp_json_encode( $v );
				$clean[ $k ] = ( is_string( $enc ) && strlen( $enc ) > 160 ) ? substr( $enc, 0, 157 ) . '...' : $v;
			}
		}
		array_unshift(
			$log,
			array(
				'time'    => current_time( 'mysql' ),
				'client'  => self::$client,
				'tool'    => $tool,
				'ok'      => (bool) $ok,
				'summary' => mb_substr( (string) $summary, 0, 240 ),
				'args'    => $clean,
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	public static function get( $limit = 50 ) {
		$log = get_option( self::OPTION, array() );
		return array_slice( is_array( $log ) ? $log : array(), 0, max( 1, (int) $limit ) );
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
