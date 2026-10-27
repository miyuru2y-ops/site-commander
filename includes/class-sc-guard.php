<?php
/**
 * Security check: file integrity, suspicious items and e-mail alerts.
 *
 * Compares WordPress core files with the official checksums, looks for things that
 * should not be there (unknown PHP files in core folders, PHP in uploads, odd plugin
 * folders, new administrators, a homepage that is down) and e-mails the site owner.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Guard {

	const CRON   = 'sc_guard_scan';
	const REPORT = 'sc_guard_report';
	const ADMINS = 'sc_guard_admins';

	/** Core files that hosts and owners commonly remove on purpose. */
	private static function ignored_core() {
		return array( 'readme.html', 'license.txt', 'wp-config-sample.php' );
	}

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		$next = wp_next_scheduled( self::CRON );
		if ( SC_Plugin::setting( 'guard_enabled' ) ) {
			if ( ! $next ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
			}
		} elseif ( $next ) {
			wp_unschedule_event( $next, self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function alert_email() {
		$mail = SC_Plugin::setting( 'guard_email' );
		return is_email( $mail ) ? $mail : get_option( 'admin_email' );
	}

	public static function last_report() {
		$r = get_option( self::REPORT, array() );
		return is_array( $r ) ? $r : array();
	}

	/** Scheduled run: scan, store, and mail only when the problems are new. */
	public static function cron() {
		if ( ! SC_Plugin::setting( 'guard_enabled' ) ) {
			return;
		}
		self::scan( true );
	}

	private static function issue( $type, $severity, $message, $item = '', $fix = '' ) {
		return array(
			'type'     => $type,
			'severity' => $severity,
			'message'  => $message,
			'item'     => $item,
			'fix'      => $fix,
		);
	}

	/**
	 * Run all checks.
	 *
	 * @param bool $notify Send the alert e-mail when there are new problems.
	 * @return array Report.
	 */
	public static function scan( $notify = false ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}

		$issues = array();
		$notes  = array();

		self::check_homepage( $issues );
		$sums = self::check_core( $issues, $notes );
		if ( $sums ) {
			self::check_unknown_core_files( $sums, $issues );
		}
		self::check_uploads( $issues );
		self::check_plugins( $issues );
		self::check_admins( $issues );

		$core_broken = false;
		foreach ( $issues as $i ) {
			if ( 'repair' === $i['fix'] ) {
				$core_broken = true;
				break;
			}
		}

		$report = array(
			'time'        => time(),
			'ok'          => empty( $issues ),
			'issues'      => $issues,
			'notes'       => $notes,
			'can_repair'  => $core_broken,
			'wp_version'  => get_bloginfo( 'version' ),
			'emailed'     => false,
		);

		$keys = array();
		foreach ( $issues as $i ) {
			$keys[] = $i['type'] . '|' . $i['item'];
		}
		sort( $keys );
		$sig = $keys ? md5( implode( "\n", $keys ) ) : '';
		$old = self::last_report();
		if ( $notify && $issues && ( empty( $old['sig'] ) || $old['sig'] !== $sig ) ) {
			$report['emailed'] = self::send_alert( $report );
		} elseif ( $issues && ! empty( $old['emailed'] ) && ! empty( $old['sig'] ) && $old['sig'] === $sig ) {
			$report['emailed'] = true; // Already told them about exactly this.
		}
		$report['sig'] = $sig;
		update_option( self::REPORT, $report, false );
		return $report;
	}

	private static function check_homepage( array &$issues ) {
		$res = wp_remote_get( add_query_arg( 'sc_check', wp_generate_password( 6, false ), home_url( '/' ) ), array( 'timeout' => 20, 'sslverify' => false, 'redirection' => 3 ) );
		if ( is_wp_error( $res ) ) {
			return; // Server may not be able to request itself; not a finding.
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		if ( preg_match( '/<title>\s*Index of \//i', $body ) ) {
			$issues[] = self::issue( 'homepage', 'high', 'The homepage shows a folder listing instead of the site (index.php is probably missing).', 'homepage', file_exists( ABSPATH . 'index.php' ) ? '' : 'repair' );
		} elseif ( $code >= 400 && 503 !== $code ) {
			$issues[] = self::issue( 'homepage', 'high', "The homepage returns HTTP $code.", 'homepage', '' );
		} elseif ( false !== stripos( $body, 'critical error on this website' ) ) {
			$issues[] = self::issue( 'homepage', 'high', 'The homepage shows a PHP critical error.', 'homepage', '' );
		}
	}

	/** @return array|false Checksums, or false when they could not be fetched. */
	private static function check_core( array &$issues, array &$notes ) {
		global $wp_version;
		$sums = get_core_checksums( $wp_version, get_locale() );
		if ( ! is_array( $sums ) ) {
			$sums = get_core_checksums( $wp_version, 'en_US' );
		}
		if ( ! is_array( $sums ) ) {
			$notes[] = 'Could not download the official WordPress file list, so core files were not compared. Try again later.';
			return false;
		}
		$skip = self::ignored_core();
		foreach ( $sums as $file => $md5 ) {
			if ( 0 === strpos( $file, 'wp-content/' ) || in_array( $file, $skip, true ) ) {
				continue;
			}
			$path = ABSPATH . $file;
			if ( ! file_exists( $path ) ) {
				$issues[] = self::issue( 'core_missing', 'high', 'A WordPress core file is missing.', $file, 'repair' );
			} elseif ( md5_file( $path ) !== $md5 ) {
				$issues[] = self::issue( 'core_modified', 'high', 'A WordPress core file was changed.', $file, 'repair' );
			}
		}
		return $sums;
	}

	/** PHP files in core folders that are not part of WordPress. */
	private static function check_unknown_core_files( array $sums, array &$issues ) {
		$found = 0;
		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			if ( ! is_dir( ABSPATH . $dir ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH . $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( ! $f->isFile() || ! preg_match( '/\.(php\d?|phtml|phar)$/i', $f->getFilename() ) ) {
					continue;
				}
				$rel = str_replace( '\\', '/', substr( $f->getPathname(), strlen( ABSPATH ) ) );
				if ( ! isset( $sums[ $rel ] ) ) {
					$issues[] = self::issue( 'core_unknown_file', 'high', 'A PHP file that is not part of WordPress is inside a core folder.', $rel, '' );
					if ( ++$found >= 50 ) {
						return;
					}
				}
			}
		}
		// Loose PHP files in the site root.
		foreach ( (array) glob( ABSPATH . '*.php' ) as $path ) {
			$rel = basename( $path );
			if ( ! isset( $sums[ $rel ] ) && 'wp-config.php' !== $rel ) {
				$issues[] = self::issue( 'root_unknown_php', 'medium', 'An unknown PHP file is in the site root.', $rel, '' );
			}
		}
	}

	/** PHP files in uploads are almost always a problem. */
	private static function check_uploads( array &$issues ) {
		$up = wp_upload_dir( null, false );
		if ( empty( $up['basedir'] ) || ! is_dir( $up['basedir'] ) ) {
			return;
		}
		$found = 0;
		$seen  = 0;
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $up['basedir'], FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( ++$seen > 60000 ) {
				break;
			}
			if ( ! $f->isFile() || ! preg_match( '/\.(php\d?|phtml|phar)$/i', $f->getFilename() ) ) {
				continue;
			}
			if ( $f->getSize() <= 80 && 'index.php' === $f->getFilename() ) {
				continue; // The usual "Silence is golden" file.
			}
			$rel      = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $up['basedir'] ) + 1 ) );
			$issues[] = self::issue( 'php_in_uploads', 'high', 'A PHP file is inside the uploads folder, where only media should be.', 'uploads/' . $rel, '' );
			if ( ++$found >= 30 ) {
				break;
			}
		}
	}

	private static function check_plugins( array &$issues ) {
		foreach ( array_keys( get_plugins() ) as $file ) {
			$dir = dirname( $file );
			if ( preg_match( '/^[a-f0-9]{32}$/i', $dir ) ) {
				$issues[] = self::issue( 'suspicious_plugin', 'high', 'A plugin has a random 32-character folder name, which is typical of malware.', $file, '' );
			}
		}
	}

	/** Tell the owner when an administrator appears that was not there before. */
	private static function check_admins( array &$issues ) {
		$now = array();
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login' ) ) ) as $u ) {
			$now[ (int) $u->ID ] = $u->user_login;
		}
		$base = get_option( self::ADMINS, null );
		if ( is_array( $base ) ) {
			foreach ( array_diff_key( $now, $base ) as $id => $login ) {
				$issues[] = self::issue( 'new_admin', 'high', 'A new administrator account appeared.', $login . ' (#' . $id . ')', '' );
			}
		}
		update_option( self::ADMINS, $now, false );
	}

	private static function send_alert( array $report ) {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$count = count( $report['issues'] );
		$lines = array();
		foreach ( array_slice( $report['issues'], 0, 25 ) as $i ) {
			$lines[] = '- ' . $i['message'] . ( $i['item'] ? ' [' . $i['item'] . ']' : '' );
		}
		if ( $count > 25 ) {
			$lines[] = '- ...and ' . ( $count - 25 ) . ' more.';
		}
		$body  = "Site Commander found $count possible problem" . ( 1 === $count ? '' : 's' ) . " on $site (" . home_url() . "):\n\n" . implode( "\n", $lines ) . "\n\n";
		$body .= $report['can_repair']
			? "WordPress core files can be repaired in one click: open " . admin_url( 'admin.php?page=site-commander#guard' ) . " and press \"Repair core files\" (or ask your AI to run reinstall_core). Your content, plugins, themes and wp-config.php are not touched.\n\n"
			: '';
		$body .= "Other items must be checked and removed by you (hosting file manager or wp-admin). If you do not recognise them, change your passwords and run a malware scan with your host.\n\nThis check runs daily. You will only get another e-mail when the list changes. Turn it off in " . admin_url( 'admin.php?page=site-commander#guard' ) . ".\n";
		return (bool) wp_mail( self::alert_email(), "[Site Commander] $count security check finding" . ( 1 === $count ? '' : 's' ) . " on $site", $body );
	}

	/** Reinstall the current WordPress version, then check again. */
	public static function repair() {
		$res    = SC_Tools_Dev::reinstall_core( array() );
		$report = self::scan( false );
		$res['remaining_issues'] = count( $report['issues'] );
		return $res;
	}

	public static function register_tools() {
		$t = 'SC_Tools';
		$t::add( 'check_integrity', array(
			'group'       => 'dev',
			'description' => 'Security check: compares WordPress core files with the official checksums and looks for missing or changed core files, unknown PHP files in core folders or uploads, odd plugin folders, new administrators and a broken homepage. Returns the findings. If core files are damaged, run reinstall_core to repair them. Runs daily on its own and e-mails the owner when something new appears.',
			'callback'    => function () {
				$r = SC_Guard::scan( false );
				return array(
					'ok'         => $r['ok'],
					'issues'     => $r['issues'],
					'notes'      => $r['notes'],
					'can_repair' => $r['can_repair'],
					'next_step'  => $r['ok'] ? 'No problems found.' : ( $r['can_repair'] ? 'Core files are damaged: run reinstall_core to repair them. Other items need to be checked and removed by the site owner.' : 'These items need to be checked and removed by the site owner.' ),
				);
			},
		) );
	}
}
