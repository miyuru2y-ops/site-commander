<?php
/**
 * Admin screen: connect any AI, permissions, updates, activity log.
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Admin {

	const PAGE = 'site-commander';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_sc_action', array( __CLASS__, 'handle' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SC_FILE ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Connect AI</a>' );
			return $links;
		} );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE === $hook ) {
			wp_enqueue_style( 'sc-admin', SC_URL . 'css/admin.css', array(), SC_VERSION );
		}
	}

	public static function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.5l2.1 4.6 4.9.6-3.6 3.4.9 4.9L10 12.6 5.7 15l.9-4.9L3 6.7l4.9-.6z"/></svg>' );
		add_menu_page( 'Site Commander', 'Site Commander', 'manage_options', self::PAGE, array( __CLASS__, 'render' ), $icon, 81 );
	}

	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( SC_Plugin::setting( 'maintenance_mode' ) ) {
			echo '<div class="notice notice-warning"><p><strong>Site Commander:</strong> Maintenance mode is ON — visitors see the maintenance page. <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '#permissions' ) ) . '">Turn off</a></p></div>';
		}
		$waiting = SC_Approval::count();
		if ( $waiting && ( ! $screen || 'toplevel_page_' . self::PAGE !== $screen->id ) ) {
			echo '<div class="notice notice-warning"><p><strong>Site Commander:</strong> ' . (int) $waiting . ' AI change(s) are waiting for your approval. <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '#approvals' ) ) . '">Review</a></p></div>';
		}
		if ( $screen && 'plugins' === $screen->id && ! SC_Auth::has_key() ) {
			echo '<div class="notice notice-info"><p><strong>Site Commander</strong> is active. <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Create a connection key</a> to let your AI manage this site.</p></div>';
		}
	}

	private static function redirect( $msg, $type = 'success' ) {
		set_transient( 'sc_admin_msg_' . get_current_user_id(), array( $msg, $type ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'sc_action' );
		$do = isset( $_POST['sc_do'] ) ? sanitize_key( wp_unslash( $_POST['sc_do'] ) ) : '';

		switch ( $do ) {
			case 'generate':
				if ( ! SC_License::can_add_key() ) {
					self::redirect( 'The Free plan includes 1 AI connection. ' . SC_License::upgrade_note(), 'error' );
				}
				$name   = isset( $_POST['key_name'] ) ? sanitize_text_field( wp_unslash( $_POST['key_name'] ) ) : '';
				$groups = isset( $_POST['key_groups'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['key_groups'] ) ) : array();
				list( $key, $rec ) = SC_Auth::create_key( $name, $groups, ! empty( $_POST['key_read_only'] ), self::posted_limits() );
				set_transient( 'sc_new_key_' . get_current_user_id(), array( $key, $rec['name'] ), 15 * MINUTE_IN_SECONDS );
				SC_Log::$client = 'wp-admin';
				SC_Log::add( 'create_key', array( 'name' => $rec['name'] ), true, 'Connection created for "' . $rec['name'] . '" (' . implode( ', ', $rec['groups'] ) . ( $rec['read_only'] ? ', read-only' : '' ) . ').' );
				self::redirect( 'Connection for "' . $rec['name'] . '" created. Copy the key now — it will not be shown again.' );
				break;

			case 'revoke':
				$id  = isset( $_POST['key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['key_id'] ) ) : '';
				$rec = SC_Auth::revoke( $id );
				if ( $rec ) {
					SC_Log::$client = 'wp-admin';
					SC_Log::add( 'revoke_key', array( 'name' => $rec['name'] ), true, 'Connection "' . $rec['name'] . '" revoked.' );
				}
				self::redirect( $rec ? '"' . $rec['name'] . '" disconnected. Other AI connections keep working.' : 'Key not found.', 'warning' );
				break;

			case 'scope':
				$id     = isset( $_POST['key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['key_id'] ) ) : '';
				$groups = isset( $_POST['key_groups'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['key_groups'] ) ) : array();
				$rec    = SC_Auth::update_scope( $id, $groups, ! empty( $_POST['key_read_only'] ), self::posted_limits() );
				self::redirect( $rec ? 'Access for "' . $rec['name'] . '" updated.' : 'Key not found.' );
				break;

			case 'settings':
				$bools = array( 'enabled', 'allow_theme_files', 'allow_php_edits', 'allow_extensions', 'allow_permanent_delete', 'allow_users', 'allow_code', 'allow_database', 'allow_updates', 'log_404', 'maintenance_mode' );
				$new   = array();
				foreach ( $bools as $b ) {
					$new[ $b ] = empty( $_POST[ $b ] ) ? 0 : 1;
				}
				$seo               = isset( $_POST['seo_output'] ) ? sanitize_key( wp_unslash( $_POST['seo_output'] ) ) : 'auto';
				$new['seo_output'] = in_array( $seo, array( 'auto', 'on', 'off' ), true ) ? $seo : 'auto';
				$uid               = isset( $_POST['run_as_user'] ) ? absint( $_POST['run_as_user'] ) : 0;
				if ( $uid && user_can( $uid, 'manage_options' ) ) {
					$new['run_as_user'] = $uid;
				}
				SC_Plugin::update_settings( $new );
				self::redirect( 'Settings saved.' );
				break;

			case 'guard_settings':
				$mail = isset( $_POST['guard_email'] ) ? sanitize_email( wp_unslash( $_POST['guard_email'] ) ) : '';
				SC_Plugin::update_settings( array( 'guard_enabled' => empty( $_POST['guard_enabled'] ) ? 0 : 1, 'guard_email' => is_email( $mail ) ? $mail : '' ) );
				SC_Guard::schedule();
				self::redirect( 'Security check settings saved.' );
				break;

			case 'guard_scan':
				$r = SC_Guard::scan( true );
				self::redirect( $r['ok'] ? 'Security check finished: no problems found.' : 'Security check finished: ' . count( $r['issues'] ) . ' finding(s). See the Security check section.', $r['ok'] ? 'success' : 'warning' );
				break;

			case 'guard_repair':
				try {
					$r = SC_Guard::repair();
					self::redirect( 'Core files reinstalled. ' . ( isset( $r['message'] ) ? $r['message'] : '' ) . ' Findings left: ' . (int) $r['remaining_issues'] . '.' );
				} catch ( Exception $e ) {
					self::redirect( 'Repair failed: ' . $e->getMessage(), 'error' );
				}
				break;

			case 'approve':
			case 'reject':
				$pid = isset( $_POST['pending_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pending_id'] ) ) : '';
				if ( 'reject' === $do ) {
					$item = SC_Approval::reject( $pid );
					self::redirect( $item ? 'Change rejected: nothing was applied.' : 'That change is no longer waiting.', 'warning' );
				}
				$r = SC_Approval::approve( $pid );
				self::redirect( $r['ok'] ? 'Approved and applied' . ( isset( $r['data']['message'] ) ? ': ' . $r['data']['message'] : '.' ) : 'Could not apply: ' . $r['error'], $r['ok'] ? 'success' : 'error' );
				break;

			case 'approve_all':
				$n = 0;
				$f = 0;
				foreach ( array_keys( SC_Approval::all() ) as $pid ) {
					$r = SC_Approval::approve( $pid );
					if ( $r['ok'] ) {
						$n++;
					} else {
						$f++;
					}
				}
				self::redirect( "Applied $n change(s)" . ( $f ? ", $f failed (see the activity log)." : '.' ), $f ? 'warning' : 'success' );
				break;

			case 'undo':
				$uid            = isset( $_POST['undo_id'] ) ? sanitize_text_field( wp_unslash( $_POST['undo_id'] ) ) : '';
				SC_Log::$client = 'wp-admin';
				$r              = SC_Tools::call( 'undo_change', array( 'undo_id' => $uid ) );
				self::redirect( $r['ok'] ? $r['data']['message'] : 'Undo failed: ' . $r['error'], $r['ok'] ? 'success' : 'error' );
				break;

			case 'webhook_add':
				if ( ! SC_License::is_pro() ) {
					self::redirect( 'Webhooks are a Pro feature. ' . SC_License::upgrade_note(), 'error' );
				}
				try {
					$url    = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
					$events = isset( $_POST['webhook_events'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['webhook_events'] ) ) : array();
					$h      = SC_Webhooks::add( $url, $events );
					set_transient( 'sc_new_secret_' . get_current_user_id(), $h['secret'], 15 * MINUTE_IN_SECONDS );
					self::redirect( 'Webhook added. Copy its signing secret below.' );
				} catch ( SC_Tool_Error $e ) {
					self::redirect( $e->getMessage(), 'error' );
				}
				break;

			case 'webhook_delete':
				SC_Webhooks::remove( isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '' );
				self::redirect( 'Webhook removed.', 'warning' );
				break;

			case 'webhook_test':
				$r = SC_Webhooks::fire( 'test', array( 'message' => 'Test from Site Commander' ), isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '', true );
				self::redirect( ! $r ? 'Webhook not found.' : ( is_wp_error( $r ) ? 'Test failed: ' . $r->get_error_message() : 'Test sent, the URL answered HTTP ' . wp_remote_retrieve_response_code( $r ) . '.' ), ( $r && ! is_wp_error( $r ) ) ? 'success' : 'error' );
				break;

			case 'site_add':
				if ( ! SC_License::is_pro() ) {
					self::redirect( 'Client sites are a Pro feature. ' . SC_License::upgrade_note(), 'error' );
				}
				try {
					$cs = SC_Hub::add(
						isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : '',
						isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '',
						isset( $_POST['site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) : ''
					);
					self::redirect( 'Client site "' . $cs['name'] . '" added.' );
				} catch ( SC_Tool_Error $e ) {
					self::redirect( $e->getMessage(), 'error' );
				}
				break;

			case 'site_remove':
				SC_Hub::remove( isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) : '' );
				self::redirect( 'Client site removed.', 'warning' );
				break;

			case 'report_settings':
				$mail = isset( $_POST['report_email'] ) ? sanitize_email( wp_unslash( $_POST['report_email'] ) ) : '';
				$freq = ( isset( $_POST['report_frequency'] ) && 'weekly' === $_POST['report_frequency'] ) ? 'weekly' : 'monthly';
				SC_Plugin::update_settings( array( 'report_enabled' => empty( $_POST['report_enabled'] ) ? 0 : 1, 'report_email' => is_email( $mail ) ? $mail : '', 'report_frequency' => $freq ) );
				SC_Report::unschedule();
				SC_Report::init();
				self::redirect( 'Report settings saved.' );
				break;

			case 'report_send':
				$r = SC_Report::send( '', 'weekly' === SC_Plugin::setting( 'report_frequency' ) ? 7 : 30 );
				self::redirect( $r['sent'] ? 'Report sent to ' . $r['to'] . '.' : 'The e-mail could not be sent. Check the site\'s mail setup.', $r['sent'] ? 'success' : 'error' );
				break;

			case 'clear_log':
				SC_Log::clear();
				self::redirect( 'Activity log cleared.' );
				break;
		}
		self::redirect( 'Nothing to do.', 'warning' );
	}

	/** Optional key limits from the add/edit connection form (nonce checked in handle()). */
	private static function posted_limits() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return array(
			'approval' => ! empty( $_POST['key_approval'] ),
			'expires'  => isset( $_POST['key_expires'] ) ? sanitize_text_field( wp_unslash( $_POST['key_expires'] ) ) : '',
			'ips'      => isset( $_POST['key_ips'] ) ? sanitize_text_field( wp_unslash( $_POST['key_ips'] ) ) : '',
			'daily'    => isset( $_POST['key_daily'] ) ? absint( $_POST['key_daily'] ) : 0,
		);
		// phpcs:enable
	}

	private static function limit_fields( array $k ) {
		echo '<label><input type="checkbox" name="key_approval" value="1" ' . checked( ! empty( $k['approval'] ), true, false ) . '> <strong>Needs my approval</strong> — changes wait in an approval list until you approve them</label>';
		echo '<div style="display:flex;gap:14px;flex-wrap:wrap;margin:10px 0">';
		echo '<label>Expires on<br><input type="date" name="key_expires" value="' . esc_attr( isset( $k['expires'] ) ? $k['expires'] : '' ) . '"></label>';
		echo '<label>Max changes per day<br><input type="number" min="0" name="key_daily" value="' . esc_attr( ! empty( $k['daily'] ) ? (int) $k['daily'] : '' ) . '" placeholder="no limit" style="width:110px"></label>';
		echo '<label>Only from these IPs<br><input type="text" name="key_ips" value="' . esc_attr( implode( ', ', isset( $k['ips'] ) ? (array) $k['ips'] : array() ) ) . '" placeholder="any IP (e.g. 203.0.113.5, 10.0.0.0/8)" class="regular-text"></label>';
		echo '</div>';
	}

	private static function form_open( $do ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="sc_action"><input type="hidden" name="sc_do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'sc_action' );
	}

	private static function copy_field( $label, $value, $secret = false ) {
		$id = 'sc_' . md5( $label );
		echo '<div class="sc-copy"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><div class="sc-copy-row">';
		echo '<input id="' . esc_attr( $id ) . '" type="text" readonly value="' . esc_attr( $value ) . '" class="' . ( $secret ? 'sc-secret' : '' ) . '" onclick="this.select()">';
		echo '<button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\'' . esc_js( $id ) . '\').value);this.textContent=\'Copied\';setTimeout(()=>this.textContent=\'Copy\',1500)">Copy</button>';
		echo '</div></div>';
	}

	private static function code_block( $label, $code ) {
		$id = 'sc_' . md5( $label . $code );
		echo '<details class="sc-client"><summary>' . esc_html( $label ) . '</summary><pre id="' . esc_attr( $id ) . '">' . esc_html( $code ) . '</pre>';
		echo '<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById(\'' . esc_js( $id ) . '\').innerText);this.textContent=\'Copied\'">Copy</button></details>';
	}

	private static function group_checkboxes( array $groups, array $checked, $read_only ) {
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:4px 12px;margin:6px 0 10px">';
		foreach ( $groups as $g => $label ) {
			echo '<label title="' . esc_attr( $label ) . '"><input type="checkbox" name="key_groups[]" value="' . esc_attr( $g ) . '" ' . checked( in_array( $g, $checked, true ), true, false ) . '> <strong>' . esc_html( ucfirst( $g ) ) . '</strong> <span style="color:#646970;font-size:12px">' . esc_html( $label ) . '</span></label>';
		}
		echo '</div><label><input type="checkbox" name="key_read_only" value="1" ' . checked( $read_only, true, false ) . '> <strong>Read-only</strong> — can look but not change anything</label><br><br>';
	}

	private static function toggle( $name, $label, $help, $s, $danger = false ) {
		echo '<label class="sc-toggle' . ( $danger ? ' sc-danger' : '' ) . '"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $s[ $name ] ), true, false ) . '>';
		echo '<span><strong>' . esc_html( $label ) . '</strong><em>' . esc_html( $help ) . '</em></span></label>';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = SC_Plugin::settings();
		$msg     = get_transient( 'sc_admin_msg_' . get_current_user_id() );
		$new_key = get_transient( 'sc_new_key_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'sc_admin_msg_' . get_current_user_id() );
		}
		if ( $new_key ) {
			delete_transient( 'sc_new_key_' . get_current_user_id() );
			if ( ! is_array( $new_key ) ) {
				$new_key = array( (string) $new_key, 'AI connection' );
			}
		}
		$mcp     = rest_url( SC_MCP_Server::NS . '/mcp' );
		$rest    = untrailingslashit( rest_url( SC_MCP_Server::NS ) );
		$openapi = rest_url( SC_MCP_Server::NS . '/openapi.json' );
		$key     = $new_key ? $new_key[0] : 'YOUR_KEY';
		$mcp_key = add_query_arg( 'key', $key, $mcp );
		$tools   = SC_Tools::all();
		$plain   = ! get_option( 'permalink_structure' );
		?>
		<div class="wrap sc-wrap">

			<div class="sc-head">
				<h1>Site Commander</h1>
				<?php if ( $s['enabled'] && SC_Auth::has_key() ) : ?>
					<span class="sc-pill sc-on">AI access on</span>
				<?php else : ?>
					<span class="sc-pill sc-off">AI access off</span>
				<?php endif; ?>
				<span style="color:#646970">v<?php echo esc_html( SC_VERSION ); ?> · <?php echo count( $tools ); ?> tools</span>
			</div>

			<?php if ( $msg ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msg[1] ); ?> is-dismissible"><p><?php echo esc_html( $msg[0] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $plain ) : ?>
				<div class="notice notice-warning"><p>Your permalinks are set to "Plain". The MCP and REST URLs below still work, but the OpenAPI import works best with pretty permalinks (Settings → Permalinks → Post name).</p></div>
			<?php endif; ?>

			<div class="sc-card" id="connect">
				<h2>1. AI connections</h2>
				<p class="sc-sub">Give each AI its own key — Claude, ChatGPT, Gemini, Cursor, a script. Each key works only on this site, can be limited to certain tasks, and can be disconnected without affecting the others. Treat keys like admin passwords.</p>

				<?php if ( $new_key ) : ?>
					<div class="sc-keybox">
						<p style="margin-top:0"><strong>Key for "<?php echo esc_html( $new_key[1] ); ?>" — copy it now.</strong> It is stored only as a hash and won't be shown again.</p>
						<?php self::copy_field( 'Key', $new_key[0], true ); ?>
						<?php self::copy_field( 'MCP URL with key (paste into Claude, ChatGPT, or any app that only asks for a URL)', $mcp_key, true ); ?>
						<p class="description" style="margin-bottom:0">The setup snippets further down already contain this key.</p>
					</div>
				<?php endif; ?>

				<?php $groups = SC_Tools::groups(); ?>
				<?php if ( SC_Auth::has_key() ) : ?>
					<table class="widefat striped sc-log" style="margin-bottom:16px">
						<thead><tr><th>AI</th><th>Access</th><th>Key</th><th>Last used</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( SC_Auth::keys() as $k ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $k['name'] ); ?></strong><br><span style="color:#646970">since <?php echo esc_html( substr( $k['created'], 0, 10 ) ); ?></span></td>
								<td><?php echo count( $k['groups'] ) === count( $groups ) ? 'Everything' : esc_html( implode( ', ', $k['groups'] ) ); ?><?php echo $k['read_only'] ? ' · <strong>read-only</strong>' : ''; ?><?php echo ! empty( $k['approval'] ) ? ' · <strong>needs approval</strong>' : ''; ?><?php echo ! empty( $k['expires'] ) ? ' · expires ' . esc_html( $k['expires'] ) : ''; ?><?php echo ! empty( $k['daily'] ) ? ' · max ' . (int) $k['daily'] . '/day' : ''; ?><?php echo ! empty( $k['ips'] ) ? ' · IP-limited' : ''; ?></td>
								<td><code>…<?php echo esc_html( $k['hint'] ); ?></code></td>
								<td><?php echo $k['last_used'] ? esc_html( $k['last_used'] ) : '<span style="color:#646970">never</span>'; ?></td>
								<td style="white-space:nowrap">
									<details style="display:inline-block;vertical-align:top"><summary class="button button-small">Edit access</summary>
										<?php self::form_open( 'scope' ); ?>
											<input type="hidden" name="key_id" value="<?php echo esc_attr( $k['id'] ); ?>">
											<?php self::group_checkboxes( $groups, $k['groups'], $k['read_only'] ); ?>
											<?php self::limit_fields( $k ); ?>
											<button class="button button-small button-primary">Save</button>
										</form>
									</details>
									<?php self::form_open( 'revoke' ); ?>
										<input type="hidden" name="key_id" value="<?php echo esc_attr( $k['id'] ); ?>">
										<button class="button button-small" onclick="return confirm('Disconnect <?php echo esc_js( $k['name'] ); ?>? Other AI connections keep working.')">Disconnect</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><strong>No AI is connected yet.</strong> Add your first connection below.</p>
				<?php endif; ?>

				<details class="sc-client" <?php echo SC_Auth::has_key() ? '' : 'open'; ?>>
					<summary>+ Add an AI connection</summary>
					<?php self::form_open( 'generate' ); ?>
						<p><label><strong>Name</strong><br><input type="text" name="key_name" class="regular-text" placeholder="e.g. Claude, ChatGPT, n8n workflow" required></label></p>
						<p style="margin-bottom:4px"><strong>What may it use?</strong></p>
						<?php self::group_checkboxes( $groups, array_keys( $groups ), false ); ?>
						<details style="margin:0 0 10px"><summary style="cursor:pointer">Safety limits (optional)</summary><div style="padding:8px 0"><?php self::limit_fields( array() ); ?></div></details>
						<p><button class="button button-primary">Create key</button></p>
					</form>
				</details>

				<h3 style="margin-top:22px">Endpoints</h3>
				<?php self::copy_field( 'MCP server (Streamable HTTP)', $mcp ); ?>
				<?php self::copy_field( 'REST API base (GET /tools, POST /tools/{name})', $rest ); ?>
				<?php self::copy_field( 'OpenAPI spec (import into ChatGPT GPT Actions, Gemini, n8n, Postman…)', $openapi ); ?>
				<p class="description">Send the key as <code>Authorization: Bearer KEY</code>, as an <code>X-SC-Key</code> header, or as <code>?key=KEY</code> in the URL.</p>

				<h3 style="margin-top:22px">Setup for popular AI tools</h3>
				<?php
				self::code_block(
					'Claude (claude.ai, desktop, mobile) — custom connector',
					"Add a custom connector and paste this as the server URL:\n$mcp_key"
				);
				self::code_block(
					'Claude Code (terminal)',
					"claude mcp add --transport http wordpress \"$mcp\" --header \"Authorization: Bearer $key\""
				);
				self::code_block(
					'ChatGPT — connector (MCP) or Custom GPT Actions',
					"MCP connector: use this URL (no extra auth):\n$mcp_key\n\nCustom GPT → Actions → Import from URL:\n$openapi\nAuthentication: API Key → Bearer → paste your key"
				);
				self::code_block(
					'Cursor / Windsurf / VS Code (mcp.json)',
					wp_json_encode( array( 'mcpServers' => array( 'wordpress' => array( 'url' => $mcp, 'headers' => array( 'Authorization' => "Bearer $key" ) ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
				);
				self::code_block(
					'Gemini CLI (~/.gemini/settings.json)',
					wp_json_encode( array( 'mcpServers' => array( 'wordpress' => array( 'httpUrl' => $mcp, 'headers' => array( 'Authorization' => "Bearer $key" ) ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
				);
				self::code_block(
					'Any app / script (curl, n8n, Make, Zapier)',
					"# List tools\ncurl -H \"Authorization: Bearer $key\" $rest/tools\n\n# Call a tool\ncurl -X POST -H \"Authorization: Bearer $key\" -H \"Content-Type: application/json\" \\\n  -d '{\"title\":\"Hello\",\"content\":\"<p>Hi</p>\",\"status\":\"draft\"}' \\\n  $rest/tools/create_content"
				);
				?>
			</div>

			<?php $pending = SC_Approval::all(); ?>
			<?php if ( $pending ) : ?>
			<div class="sc-card" id="approvals" style="border-color:#dba617">
				<h2>Waiting for your approval (<?php echo (int) count( $pending ); ?>)</h2>
				<p class="sc-sub">These changes come from connections that need approval. Nothing below has been applied yet.</p>
				<table class="widefat striped sc-log">
					<thead><tr><th>Time</th><th>AI</th><th>Change</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( array_reverse( $pending ) as $p ) : ?>
						<tr>
							<td><?php echo esc_html( $p['time'] ); ?></td>
							<td><?php echo esc_html( $p['client'] ); ?></td>
							<td><code><?php echo esc_html( $p['tool'] ); ?></code><br><span style="color:#50575e"><?php echo esc_html( $p['preview'] ); ?></span></td>
							<td style="white-space:nowrap">
								<?php self::form_open( 'approve' ); ?><input type="hidden" name="pending_id" value="<?php echo esc_attr( $p['id'] ); ?>"><button class="button button-small button-primary">Approve</button></form>
								<?php self::form_open( 'reject' ); ?><input type="hidden" name="pending_id" value="<?php echo esc_attr( $p['id'] ); ?>"><button class="button button-small">Reject</button></form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( count( $pending ) > 1 ) : ?>
					<?php self::form_open( 'approve_all' ); ?><p><button class="button" onclick="return confirm('Apply all waiting changes?')">Approve all</button></p></form>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<div class="sc-card" id="permissions">
				<h2>2. What AI may do</h2>
				<p class="sc-sub">Everyday editing is always available. Riskier abilities stay off until you switch them on. Every change is logged below.</p>
				<?php self::form_open( 'settings' ); ?>
					<div class="sc-grid">
						<?php
						self::toggle( 'enabled', 'AI access enabled', 'Master switch for all connections.', $s );
						self::toggle( 'allow_theme_files', 'Edit theme files', 'CSS, JS, HTML, JSON in themes (backed up first).', $s );
						self::toggle( 'allow_php_edits', 'Edit PHP theme files', 'Syntax-checked, backed up, auto-rolled back if the site breaks.', $s, true );
						self::toggle( 'allow_extensions', 'Install / switch plugins & themes', 'Install from WordPress.org, activate, deactivate.', $s, true );
						self::toggle( 'allow_updates', 'Run updates', 'Update core, plugins, themes, translations.', $s );
						self::toggle( 'allow_permanent_delete', 'Permanent deletion', 'Skip the trash; delete media files.', $s, true );
						self::toggle( 'allow_users', 'Manage users', 'Create users, change non-admin roles and profiles.', $s, true );
						self::toggle( 'allow_code', 'Header / footer code', 'Add analytics, pixels, custom scripts to every page.', $s, true );
						self::toggle( 'allow_database', 'Database tools', 'Database search & replace, edit any plugin option.', $s, true );
						self::toggle( 'log_404', 'Record 404 errors', 'So AI can find broken URLs and add redirects.', $s );
						self::toggle( 'maintenance_mode', 'Maintenance mode', 'Visitors see a "back soon" page; admins see the site.', $s );
						?>
					</div>
					<p style="margin-top:16px">
						<label><strong>SEO tags output</strong>
						<select name="seo_output">
							<option value="auto" <?php selected( $s['seo_output'], 'auto' ); ?>>Automatic — only when no SEO plugin is active</option>
							<option value="on" <?php selected( $s['seo_output'], 'on' ); ?>>Always</option>
							<option value="off" <?php selected( $s['seo_output'], 'off' ); ?>>Off</option>
						</select></label>
						<?php $sp = SC_Tools_Site::seo_plugin(); ?>
						<span class="description"><?php echo $sp ? 'Detected SEO plugin: ' . esc_html( $sp ) . ' — AI edits its fields directly.' : 'No SEO plugin detected — Site Commander prints meta description, Open Graph and schema.'; ?></span>
					</p>
					<p>
						<label><strong>AI acts as</strong>
						<select name="run_as_user">
							<?php foreach ( get_users( array( 'role' => 'administrator' ) ) as $u ) : ?>
								<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $s['run_as_user'], $u->ID ); ?>><?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?></option>
							<?php endforeach; ?>
						</select></label>
						<span class="description">Posts are authored by this administrator unless the AI picks another author.</span>
					</p>
					<?php submit_button( 'Save permissions' ); ?>
				</form>
			</div>

			<div class="sc-card" id="plan">
				<h2>3. Your plan</h2>
				<?php $counts = SC_License::counts(); ?>
				<?php if ( SC_License::is_pro() ) : ?>
					<?php $left = SC_License::trial_days_left(); ?>
					<p><strong>Pro</strong><?php echo $left ? ' &middot; free trial, ' . (int) $left . ' day' . ( 1 === $left ? '' : 's' ) . ' left' : ''; ?>. All tools are unlocked.</p>
				<?php else : ?>
					<p><strong>Free</strong> &middot; <?php echo (int) $counts['post']; ?>/<?php echo (int) SC_License::FREE_POSTS; ?> posts and <?php echo (int) $counts['page']; ?>/<?php echo (int) SC_License::FREE_PAGES; ?> pages created &middot; 1 AI connection. Pro tools (theme and file editing, plugin management, full SEO suite, activity log and more) are locked.</p>
				<?php endif; ?>
				<p>
					<?php if ( ! SC_License::is_pro() && function_exists( 'sc_fs' ) && sc_fs() && ! sc_fs()->is_trial_utilized() ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( sc_fs()->get_trial_url() ); ?>">Start 7-day Pro trial</a>
					<?php endif; ?>
					<?php if ( ! SC_License::is_pro() ) : ?>
						<a class="button" href="<?php echo esc_url( SC_License::upgrade_url() ); ?>">Upgrade to Pro</a>
					<?php endif; ?>
				</p>
			</div>

			<?php $g = SC_Guard::last_report(); ?>
			<div class="sc-card" id="guard">
				<h2>Security check</h2>
				<p class="sc-sub">Compares WordPress core files with the official list and looks for unknown PHP files, odd plugin folders, new administrators and a broken homepage. Runs daily and e-mails you when something new appears.</p>
				<?php if ( empty( $g['time'] ) ) : ?>
					<p>No check has run yet.</p>
				<?php else : ?>
					<p><strong><?php echo ! empty( $g['ok'] ) ? 'No problems found' : esc_html( count( $g['issues'] ) . ' finding(s)' ); ?></strong> &middot; last check <?php echo esc_html( human_time_diff( $g['time'] ) ); ?> ago</p>
					<?php if ( ! empty( $g['issues'] ) ) : ?>
						<ul style="list-style:disc;margin-left:20px">
							<?php foreach ( array_slice( $g['issues'], 0, 25 ) as $i ) : ?>
								<li><?php echo esc_html( $i['message'] ); ?><?php echo $i['item'] ? ' <code>' . esc_html( $i['item'] ) . '</code>' : ''; ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php foreach ( (array) $g['notes'] as $n ) : ?>
						<p style="color:#646970"><?php echo esc_html( $n ); ?></p>
					<?php endforeach; ?>
				<?php endif; ?>
				<div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0">
					<?php self::form_open( 'guard_scan' ); ?><button class="button button-primary" type="submit">Run check now</button></form>
					<?php if ( ! empty( $g['can_repair'] ) ) : ?>
						<?php self::form_open( 'guard_repair' ); ?><button class="button" type="submit" onclick="return confirm('Re-download and reinstall the current WordPress version? Your content, plugins, themes and wp-config.php are not touched.')">Repair core files</button></form>
					<?php endif; ?>
				</div>
				<?php self::form_open( 'guard_settings' ); ?>
					<?php self::toggle( 'guard_enabled', 'Daily check and e-mail alert', 'Checks once a day. You get one e-mail when the list of findings changes.', $s ); ?>
					<p><label>Send alerts to <input type="email" name="guard_email" value="<?php echo esc_attr( $s['guard_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text"></label></p>
					<?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
				</form>
				<p class="sc-sub" style="margin-top:12px">Repair only fixes WordPress core files. Unknown files, plugins or users have to be reviewed and removed by you.</p>
			</div>

			<div class="sc-card" id="activity">
				<h2>Activity log</h2>
				<p class="sc-sub">Every change made by an AI, newest first. Most changes to posts, pages, settings and CSS can be undone with one click.</p>
				<?php $log = SC_Log::get( 50 ); ?>
				<?php if ( ! $log ) : ?>
					<p>No activity yet.</p>
				<?php else : ?>
					<table class="widefat striped sc-log">
						<thead><tr><th>Time</th><th>Client</th><th>Action</th><th>Result</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $log as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['time'] ); ?></td>
								<td><?php echo esc_html( $row['client'] ); ?></td>
								<td><code><?php echo esc_html( $row['tool'] ); ?></code></td>
								<td><?php echo $row['ok'] ? '' : '<strong style="color:#b32d2e">Failed:</strong> '; ?><?php echo esc_html( $row['summary'] ); ?></td>
								<td style="white-space:nowrap">
									<?php if ( ! empty( $row['undone'] ) ) : ?>
										<span style="color:#646970">Undone</span>
									<?php elseif ( ! empty( $row['undo'] ) && SC_Undo::get( $row['undo'] ) ) : ?>
										<?php self::form_open( 'undo' ); ?><input type="hidden" name="undo_id" value="<?php echo esc_attr( $row['undo'] ); ?>"><button class="button button-small" onclick="return confirm('Undo this change?')">Undo</button></form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php self::form_open( 'clear_log' ); ?><p><button class="button button-small">Clear log</button></p></form>
				<?php endif; ?>
			</div>

			<?php
			$hooks  = SC_Webhooks::all();
			$secret = get_transient( 'sc_new_secret_' . get_current_user_id() );
			if ( $secret ) {
				delete_transient( 'sc_new_secret_' . get_current_user_id() );
			}
			?>
			<div class="sc-card" id="webhooks">
				<h2>Webhooks</h2>
				<p class="sc-sub">Tell n8n, Zapier, Make or your own AI agent when something happens: a new order, form entry, comment, or an AI change. Each request is a signed JSON POST (header <code>X-SC-Signature: sha256=HMAC(body, secret)</code>).</p>
				<?php if ( $secret ) : ?>
					<div class="sc-keybox"><?php self::copy_field( 'Signing secret (shown once)', $secret, true ); ?></div>
				<?php endif; ?>
				<?php if ( $hooks ) : ?>
					<table class="widefat striped sc-log" style="margin-bottom:12px">
						<thead><tr><th>URL</th><th>Events</th><th>Last delivery</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $hooks as $h ) : ?>
							<tr>
								<td><code><?php echo esc_html( $h['url'] ); ?></code></td>
								<td><?php echo esc_html( implode( ', ', $h['events'] ) ); ?></td>
								<td><?php echo esc_html( $h['last'] ? $h['last'] : '—' ); ?></td>
								<td style="white-space:nowrap">
									<?php self::form_open( 'webhook_test' ); ?><input type="hidden" name="webhook_id" value="<?php echo esc_attr( $h['id'] ); ?>"><button class="button button-small">Test</button></form>
									<?php self::form_open( 'webhook_delete' ); ?><input type="hidden" name="webhook_id" value="<?php echo esc_attr( $h['id'] ); ?>"><button class="button button-small" onclick="return confirm('Remove this webhook?')">Remove</button></form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php if ( SC_License::is_pro() ) : ?>
					<details class="sc-client"><summary>+ Add a webhook</summary>
						<?php self::form_open( 'webhook_add' ); ?>
							<p><input type="url" name="webhook_url" class="large-text" placeholder="https://your-n8n.example.com/webhook/..." required></p>
							<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:4px 12px;margin:0 0 10px">
								<?php foreach ( SC_Webhooks::events() as $ev => $label ) : ?>
									<label><input type="checkbox" name="webhook_events[]" value="<?php echo esc_attr( $ev ); ?>"> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</div>
							<p><button class="button button-primary">Add webhook</button></p>
						</form>
					</details>
				<?php else : ?>
					<p><em>Webhooks are a Pro feature.</em></p>
				<?php endif; ?>
			</div>

			<?php $sites = SC_Hub::all(); ?>
			<div class="sc-card" id="clients">
				<h2>Client sites</h2>
				<p class="sc-sub">For agencies: let the AI connected to this site also work on your clients' sites. On each client site, create a Site Commander key, then add its MCP URL and key here. The AI can then use <code>list_client_sites</code> and <code>run_on_client_site</code>. Keys are stored on this site only and are never shown to the AI.</p>
				<?php if ( $sites ) : ?>
					<table class="widefat striped sc-log" style="margin-bottom:12px">
						<thead><tr><th>Name</th><th>MCP URL</th><th>Added</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $sites as $cs ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $cs['name'] ); ?></strong></td>
								<td><code><?php echo esc_html( $cs['url'] ); ?></code></td>
								<td><?php echo esc_html( substr( $cs['added'], 0, 10 ) ); ?></td>
								<td><?php self::form_open( 'site_remove' ); ?><input type="hidden" name="site_id" value="<?php echo esc_attr( $cs['id'] ); ?>"><button class="button button-small" onclick="return confirm('Remove this client site?')">Remove</button></form></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php if ( SC_License::is_pro() ) : ?>
					<details class="sc-client"><summary>+ Add a client site</summary>
						<?php self::form_open( 'site_add' ); ?>
							<p><label>Name<br><input type="text" name="site_name" class="regular-text" placeholder="e.g. Bakery client"></label></p>
							<p><label>MCP URL (from the client site's Site Commander screen)<br><input type="url" name="site_url" class="large-text" placeholder="https://client.com/wp-json/site-commander/v1/mcp" required></label></p>
							<p><label>Key<br><input type="password" name="site_key" class="regular-text" autocomplete="off" placeholder="sc_…"></label></p>
							<p><button class="button button-primary">Add client site</button></p>
						</form>
					</details>
				<?php else : ?>
					<p><em>Client sites are a Pro feature.</em></p>
				<?php endif; ?>
			</div>

			<div class="sc-card" id="report">
				<h2>Activity report</h2>
				<p class="sc-sub">E-mail a plain summary of what the AI changed — handy for clients and managers.</p>
				<?php self::form_open( 'report_settings' ); ?>
					<?php self::toggle( 'report_enabled', 'Send the report automatically', 'Number of changes, most common tasks, recent changes and the security status.', $s ); ?>
					<p>
						<label>Every <select name="report_frequency"><option value="monthly" <?php selected( $s['report_frequency'], 'monthly' ); ?>>month</option><option value="weekly" <?php selected( $s['report_frequency'], 'weekly' ); ?>>week</option></select></label>
						<label style="margin-left:12px">to <input type="email" name="report_email" value="<?php echo esc_attr( $s['report_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text"></label>
					</p>
					<?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
				</form>
				<?php self::form_open( 'report_send' ); ?><p><button class="button">Send a report now</button></p></form>
			</div>

			<div class="sc-card">
				<h2>Available tools</h2>
				<?php foreach ( SC_Tools::groups() as $g => $label ) : ?>
					<?php $in = array_filter( $tools, function ( $t ) use ( $g ) { return $t['group'] === $g; } ); ?>
					<?php if ( ! $in ) { continue; } ?>
					<h3 style="margin-bottom:6px"><?php echo esc_html( ucfirst( $g ) ); ?> <span style="font-weight:400;color:#646970">— <?php echo esc_html( $label ); ?></span></h3>
					<div class="sc-tools">
						<?php foreach ( $in as $t ) : ?>
							<div><code><?php echo esc_html( $t['name'] ); ?></code><?php echo SC_Tools::blocked_reason( $t ) ? ' <span title="Switched off">🔒</span>' : ''; ?></div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
				<p class="description" style="margin-top:14px">Developers can add tools with <code>add_action( 'sc_register_tools', fn() =&gt; SC_Tools::add( 'my_tool', [...] ) );</code> — they appear in MCP, REST and OpenAPI automatically.</p>
			</div>
		</div>
		<?php
	}
}
