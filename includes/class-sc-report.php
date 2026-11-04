<?php
/**
 * Client report: an e-mail summary of what the AI connections did, weekly or monthly.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Report {

	const CRON = 'sc_report_check';
	const LAST = 'sc_report_last';

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		if ( SC_Plugin::setting( 'report_enabled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function cron() {
		if ( ! SC_Plugin::setting( 'report_enabled' ) ) {
			return;
		}
		$days = 'weekly' === SC_Plugin::setting( 'report_frequency' ) ? 7 : 30;
		$last = (int) get_option( self::LAST, 0 );
		if ( $last && time() - $last < ( $days * DAY_IN_SECONDS ) - HOUR_IN_SECONDS ) {
			return;
		}
		self::send( '', $days );
	}

	/** Build the report text for the last $days days. */
	public static function build( $days ) {
		$since   = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$rows    = array_filter( SC_Log::get( SC_Log::MAX ), function ( $r ) use ( $since ) {
			return $r['time'] >= $since;
		} );
		$ok      = array_filter( $rows, function ( $r ) {
			return $r['ok'];
		} );
		$by_ai   = array();
		$by_tool = array();
		foreach ( $ok as $r ) {
			$c = $r['client'] ? $r['client'] : 'unknown';
			$by_ai[ $c ]           = isset( $by_ai[ $c ] ) ? $by_ai[ $c ] + 1 : 1;
			$by_tool[ $r['tool'] ] = isset( $by_tool[ $r['tool'] ] ) ? $by_tool[ $r['tool'] ] + 1 : 1;
		}
		arsort( $by_tool );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$g     = SC_Guard::last_report();
		$lines = array();
		$lines[] = sprintf( 'Site: %s (%s)', $site, home_url() );
		$lines[] = sprintf( 'Period: last %d days, up to %s', $days, current_time( 'Y-m-d' ) );
		$lines[] = '';
		$lines[] = sprintf( 'Changes made by AI: %d (failed or refused: %d)', count( $ok ), count( $rows ) - count( $ok ) );
		foreach ( $by_ai as $c => $n ) {
			$lines[] = "  - $c: $n";
		}
		if ( $by_tool ) {
			$lines[] = '';
			$lines[] = 'Most common tasks:';
			foreach ( array_slice( $by_tool, 0, 8, true ) as $tool => $n ) {
				$lines[] = '  - ' . str_replace( '_', ' ', $tool ) . ": $n";
			}
		}
		$lines[] = '';
		$lines[] = 'Recent changes:';
		foreach ( array_slice( $ok, 0, 25 ) as $r ) {
			$lines[] = '  - ' . substr( $r['time'], 0, 16 ) . ' · ' . $r['summary'];
		}
		if ( ! $ok ) {
			$lines[] = '  (none)';
		}
		$lines[] = '';
		$lines[] = 'Security check: ' . ( empty( $g['time'] ) ? 'not run yet' : ( ! empty( $g['ok'] ) ? 'no problems found' : count( $g['issues'] ) . ' finding(s) — see Site Commander in WordPress' ) );
		$pending = SC_Approval::count();
		if ( $pending ) {
			$lines[] = "Changes waiting for approval: $pending";
		}
		return array( 'subject' => sprintf( '[%s] AI activity report: %d changes in the last %d days', $site, count( $ok ), $days ), 'body' => implode( "\n", $lines ), 'changes' => count( $ok ) );
	}

	public static function send( $to = '', $days = 30 ) {
		$to = $to ? $to : SC_Plugin::setting( 'report_email' );
		$to = is_email( $to ) ? $to : get_option( 'admin_email' );
		$r  = self::build( max( 1, (int) $days ) );
		$ok = wp_mail( $to, $r['subject'], $r['body'] . "\n\n— Sent by Site Commander\n" );
		update_option( self::LAST, time(), false );
		return array( 'sent' => (bool) $ok, 'to' => $to, 'changes' => $r['changes'] );
	}

	public static function register_tools() {
		$t = 'SC_Tools';
		$t::add( 'activity_report', array(
			'group'       => 'site',
			'description' => 'Summarise what the AI connections changed in the last N days. With send=true it is e-mailed (to the client report address unless email is given).',
			'properties'  => array(
				'days'  => $t::p( 'integer', 'Default 30.' ),
				'send'  => $t::p( 'boolean', 'E-mail it. Default false (just return it).' ),
				'email' => $t::p( 'string', 'Recipient. Default: the report address set in WordPress.' ),
			),
			'callback'    => function ( $a ) {
				$days = max( 1, SC_Tools::int( $a, 'days', 30 ) );
				if ( SC_Tools::bool( $a, 'send' ) ) {
					$email = SC_Tools::str( $a, 'email', '' );
					$r     = SC_Report::send( is_email( $email ) ? $email : '', $days );
					return array( 'message' => $r['sent'] ? "Report e-mailed to {$r['to']}." : 'The e-mail could not be sent (check the site\'s mail setup).' ) + $r;
				}
				return SC_Report::build( $days );
			},
		) );
	}
}
