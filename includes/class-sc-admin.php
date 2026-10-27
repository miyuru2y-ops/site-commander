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
				list( $key, $rec ) = SC_Auth::create_key( $name, $groups, ! empty( $_POST['key_read_only'] ) );
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
				$rec    = SC_Auth::update_scope( $id, $groups, ! empty( $_POST['key_read_only'] ) );
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

			case 'clear_log':
				SC_Log::clear();
				self::redirect( 'Activity log cleared.' );
				break;
		}
		self::redirect( 'Nothing to do.', 'warning' );
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
			<style>
				.sc-wrap{max-width:1040px}
				.sc-head{display:flex;align-items:center;gap:12px;margin:8px 0 18px}
				.sc-head h1{margin:0;font-size:24px}
				.sc-pill{padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600}
				.sc-on{background:#e7f6ec;color:#116329}.sc-off{background:#fcf0f1;color:#8a2424}
				.sc-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 22px;margin:0 0 18px}
				.sc-card h2{margin:0 0 4px;font-size:17px}.sc-card>p.sc-sub{margin:0 0 16px;color:#50575e}
				.sc-copy{margin:0 0 12px}.sc-copy label{display:block;font-weight:600;margin-bottom:4px}
				.sc-copy-row{display:flex;gap:6px}.sc-copy-row input{flex:1;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
				.sc-secret{background:#fffbe6!important;border-color:#dba617!important}
				.sc-keybox{border:2px solid #dba617;background:#fffbea;border-radius:8px;padding:14px 16px;margin:0 0 16px}
				.sc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px}
				.sc-toggle{display:flex;gap:10px;align-items:flex-start;border:1px solid #e0e0e0;border-radius:6px;padding:10px 12px;cursor:pointer}
				.sc-toggle span{display:flex;flex-direction:column;gap:2px}.sc-toggle em{font-style:normal;color:#646970;font-size:12px}
				.sc-danger{border-color:#f0c2c2}
				.sc-client{border:1px solid #e0e0e0;border-radius:6px;margin:0 0 8px;padding:8px 12px}
				.sc-client summary{cursor:pointer;font-weight:600}
				.sc-client pre{background:#1d2327;color:#f0f0f1;padding:12px;border-radius:6px;overflow:auto;font-size:12px;white-space:pre-wrap;word-break:break-all}
				.sc-tools{columns:3 220px;font-size:12px;color:#3c434a}.sc-tools code{font-size:11px}
				.sc-log td,.sc-log th{font-size:12px;vertical-align:top}
				.sc-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
				@media (max-width:782px){.sc-copy-row{flex-direction:column}}
			</style>

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
								<td><?php echo count( $k['groups'] ) === count( $groups ) ? 'Everything' : esc_html( implode( ', ', $k['groups'] ) ); ?><?php echo $k['read_only'] ? ' · <strong>read-only</strong>' : ''; ?></td>
								<td><code>…<?php echo esc_html( $k['hint'] ); ?></code></td>
								<td><?php echo $k['last_used'] ? esc_html( $k['last_used'] ) : '<span style="color:#646970">never</span>'; ?></td>
								<td style="white-space:nowrap">
									<details style="display:inline-block;vertical-align:top"><summary class="button button-small">Edit access</summary>
										<?php self::form_open( 'scope' ); ?>
											<input type="hidden" name="key_id" value="<?php echo esc_attr( $k['id'] ); ?>">
											<?php self::group_checkboxes( $groups, $k['groups'], $k['read_only'] ); ?>
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
				<p class="sc-sub">Every change made by an AI, newest first.</p>
				<?php $log = SC_Log::get( 50 ); ?>
				<?php if ( ! $log ) : ?>
					<p>No activity yet.</p>
				<?php else : ?>
					<table class="widefat striped sc-log">
						<thead><tr><th>Time</th><th>Client</th><th>Action</th><th>Result</th></tr></thead>
						<tbody>
						<?php foreach ( $log as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['time'] ); ?></td>
								<td><?php echo esc_html( $row['client'] ); ?></td>
								<td><code><?php echo esc_html( $row['tool'] ); ?></code></td>
								<td><?php echo $row['ok'] ? '' : '<strong style="color:#b32d2e">Failed:</strong> '; ?><?php echo esc_html( $row['summary'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php self::form_open( 'clear_log' ); ?><p><button class="button button-small">Clear log</button></p></form>
				<?php endif; ?>
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
