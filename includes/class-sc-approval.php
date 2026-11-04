<?php
/**
 * Approval mode: changes from a key marked "needs approval" wait in a queue until the owner approves them.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Approval {

	const OPTION = 'sc_pending';
	const MAX    = 200;

	public static function all() {
		$q = get_option( self::OPTION, array() );
		return is_array( $q ) ? $q : array();
	}

	public static function count() {
		return count( self::all() );
	}

	/** Queue a change. Returns the pending id. */
	public static function queue( $tool, array $args, array $key ) {
		$q  = self::all();
		$id = 'p' . wp_generate_password( 8, false, false );
		$q[ $id ] = array(
			'id'      => $id,
			'tool'    => $tool,
			'args'    => $args,
			'key_id'  => $key['id'],
			'client'  => $key['name'],
			'time'    => current_time( 'mysql' ),
			'preview' => self::preview( $tool, $args ),
		);
		if ( count( $q ) > self::MAX ) {
			$q = array_slice( $q, -self::MAX, null, true );
		}
		update_option( self::OPTION, $q, false );
		self::notify();
		do_action( 'sc_pending_change', $q[ $id ] );
		return $id;
	}

	/** Short human description of what the change will do. */
	private static function preview( $tool, array $args ) {
		$bits = array();
		foreach ( $args as $k => $v ) {
			if ( in_array( $k, array( 'base64', 'key' ), true ) ) {
				continue;
			}
			$s = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
			$s = wp_strip_all_tags( (string) $s );
			$bits[] = $k . ': ' . ( mb_strlen( $s ) > 120 ? mb_substr( $s, 0, 117 ) . '…' : $s );
		}
		return implode( ' · ', array_slice( $bits, 0, 8 ) );
	}

	/** E-mail the owner, at most once an hour, that changes are waiting. */
	private static function notify() {
		if ( get_transient( 'sc_pending_mailed' ) ) {
			return;
		}
		set_transient( 'sc_pending_mailed', 1, HOUR_IN_SECONDS );
		$to   = SC_Plugin::setting( 'guard_email' );
		$to   = is_email( $to ) ? $to : get_option( 'admin_email' );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			$to,
			sprintf( '[%s] AI changes are waiting for your approval', $site ),
			sprintf( "An AI connection asked to change your site. Nothing has been applied yet.\n\nReview and approve or reject the changes here:\n%s\n", admin_url( 'admin.php?page=site-commander#approvals' ) )
		);
	}

	/** Apply a queued change. */
	public static function approve( $id ) {
		$q = self::all();
		if ( ! isset( $q[ $id ] ) ) {
			return array( 'ok' => false, 'error' => 'This change is no longer in the queue.' );
		}
		$item = $q[ $id ];
		unset( $q[ $id ] );
		update_option( self::OPTION, $q, false );
		$prev           = SC_Auth::$current;
		SC_Auth::$current = null;
		SC_Log::$client = $item['client'] . ' (approved)';
		$res            = SC_Tools::call( $item['tool'], $item['args'] );
		SC_Auth::$current = $prev;
		return $res;
	}

	public static function reject( $id ) {
		$q = self::all();
		if ( ! isset( $q[ $id ] ) ) {
			return null;
		}
		$item = $q[ $id ];
		unset( $q[ $id ] );
		update_option( self::OPTION, $q, false );
		SC_Log::$client = 'wp-admin';
		SC_Log::add( $item['tool'], $item['args'], false, 'Rejected by the site owner (asked by ' . $item['client'] . ').' );
		return $item;
	}

	public static function register_tools() {
		$t = 'SC_Tools';
		$t::add(
			'list_pending_changes',
			array(
				'group'       => 'site',
				'description' => 'List changes waiting for the site owner\'s approval (from keys that need approval).',
				'callback'    => function () {
					$out = array();
					foreach ( SC_Approval::all() as $p ) {
						$out[] = array( 'pending_id' => $p['id'], 'tool' => $p['tool'], 'client' => $p['client'], 'time' => $p['time'], 'preview' => $p['preview'] );
					}
					return $out;
				},
			)
		);
	}
}
