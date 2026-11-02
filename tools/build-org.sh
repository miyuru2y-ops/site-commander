#!/usr/bin/env bash
# Builds the WordPress.org (free) zip: no hidden files, no Freemius plugin updater, is_premium=false.
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="${1:-build/site-commander}"
rm -rf "$OUT" && mkdir -p "$OUT"
tar --exclude='./.*' --exclude='./build' --exclude='./tools' -cf - . | tar -xf - -C "$OUT"
find "$OUT" -name '.*' -not -name '.' -exec rm -rf {} + 2>/dev/null || true
U="$OUT/includes/freemius/includes/class-fs-plugin-updater.php"
cat > "$U" <<'PHP'
<?php
/**
 * WordPress.org build: updates come from WordPress.org, so the SDK updater is replaced by a no-op.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class FS_Org_Build_Stub {
	const UPDATES_CHECK_CACHE_EXPIRATION = 3600;
	public static function instance( $freemius ) {
		return new self();
	}
	public function set_update_data( $v = null ) {}
	public function delete_update_data() {}
	public function install_and_activate_plugin( $id = false ) {
		return array( 'message' => 'Not available in this build.', 'code' => 'not_available' );
	}
	public static function _fetch_plugin_info_from_repository( $action, $args ) {
		return false;
	}
}
PHP
grep -rl "FS_Plugin_Updater" "$OUT/includes/freemius" | xargs -r sed -i 's/FS_Plugin_Updater/FS_Org_Build_Stub/g'
sed -i "/add_filter( 'site_transient_update_plugins'/d" "$OUT/includes/freemius/includes/class-freemius.php"
sed -i "s/'is_premium'     => true/'is_premium'     => false/" "$OUT/includes/class-sc-license.php"
( cd "$(dirname "$OUT")" && rm -f site-commander-org.zip && zip -qr site-commander-org.zip "$(basename "$OUT")" )
echo "Built $(dirname "$OUT")/site-commander-org.zip"
