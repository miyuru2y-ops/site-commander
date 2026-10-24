<?php
/**
 * Removes Site Commander's settings when the plugin is deleted.
 * SEO titles/descriptions saved on posts are kept, because they are site content.
 *
 * @package SiteCommander
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'sc_settings', 'sc_keys', 'sc_key_hash', 'sc_key_hint', 'sc_activity_log', 'sc_404_log', 'sc_redirects', 'sc_robots_extra', 'sc_home_seo', 'sc_snippets', 'sc_maintenance_message' ) as $opt ) {
	delete_option( $opt );
}
delete_site_transient( 'sc_update_release' );
