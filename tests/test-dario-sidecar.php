<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Procyon\Dario\Sidecar\DarioSidecar;

$plugin_dir = dirname( __DIR__ );
$log_file   = $plugin_dir . '/sidecar.log';

// Defaults (no settings stored, no env vars).
test_option_store_reset();
assert( '127.0.0.1' === DarioSidecar::host() );
assert( 3456 === DarioSidecar::port() );
assert( 'http://127.0.0.1:3456/v1' === DarioSidecar::baseUrl() );

$command = DarioSidecar::buildStartCommand( $plugin_dir, $log_file );
assert( strpos( $command, 'DARIO_PROXY_HOST=' ) !== false, 'host env var present' );
assert( strpos( $command, 'DARIO_PROXY_PORT=' ) !== false, 'port env var present' );
assert( strpos( $command, 'sidecar/dario-sidecar.mjs' ) !== false, 'script path present' );
assert( strpos( $command, '2>&1 & echo $!' ) !== false, 'background + pid capture present' );
assert( strpos( $command, 'DARIO_API_KEY=' ) === false, 'no api key env when proxy_api_key unset' );

// With proxy_api_key set, DARIO_API_KEY is included in the env.
test_option_store_reset();
update_option( 'procyon_dario_settings', [
	'manage_sidecar' => true,
	'node_binary'    => 'node',
	'proxy_host'     => '127.0.0.1',
	'proxy_port'     => 3456,
	'proxy_api_key'  => 'sk-test-secret',
] );
$command_with_key = DarioSidecar::buildStartCommand( $plugin_dir, $log_file );
assert( strpos( $command_with_key, 'DARIO_API_KEY=' ) !== false, 'api key env var present when proxy_api_key set' );
assert( strpos( $command_with_key, 'sk-test-secret' ) !== false, 'api key value present in command' );

// Custom host/port flow through to baseUrl + the start command.
test_option_store_reset();
update_option( 'procyon_dario_settings', [
	'manage_sidecar' => true,
	'node_binary'    => 'node',
	'proxy_host'     => '192.168.1.50',
	'proxy_port'     => 4567,
	'proxy_api_key'  => '',
] );
assert( '192.168.1.50' === DarioSidecar::host(), 'host comes from settings' );
assert( 4567 === DarioSidecar::port(), 'port comes from settings' );
assert( 'http://192.168.1.50:4567/v1' === DarioSidecar::baseUrl(), 'baseUrl built from settings' );
$command_custom = DarioSidecar::buildStartCommand( $plugin_dir, $log_file );
assert( strpos( $command_custom, "DARIO_PROXY_HOST='192.168.1.50'" ) !== false, 'custom host shell-escaped' );
assert( strpos( $command_custom, "DARIO_PROXY_PORT='4567'" ) !== false, 'custom port shell-escaped' );

// ensureConnectorApiKey: empty store, gets default 'dario'.
test_option_store_reset();
DarioSidecar::ensureConnectorApiKey();
assert( 'dario' === get_option( 'connectors_ai_procyon_dario_api_key' ), 'default connector key written' );

// ensureConnectorApiKey: existing value preserved (idempotent).
test_option_store_reset();
update_option( 'connectors_ai_procyon_dario_api_key', 'previously-set' );
DarioSidecar::ensureConnectorApiKey();
assert( 'previously-set' === get_option( 'connectors_ai_procyon_dario_api_key' ), 'existing connector key preserved' );

// syncConnectorApiKey: mirrors proxy_api_key when set.
test_option_store_reset();
update_option( 'procyon_dario_settings', [ 'proxy_api_key' => 'sk-mirrored' ] );
DarioSidecar::syncConnectorApiKey();
assert( 'sk-mirrored' === get_option( 'connectors_ai_procyon_dario_api_key' ), 'syncs proxy_api_key into connector key' );

// syncConnectorApiKey: falls back to 'dario' when proxy_api_key empty.
test_option_store_reset();
update_option( 'procyon_dario_settings', [ 'proxy_api_key' => '' ] );
DarioSidecar::syncConnectorApiKey();
assert( 'dario' === get_option( 'connectors_ai_procyon_dario_api_key' ), 'syncs to default when proxy_api_key empty' );

// --- allowedHostPort: WPD-27. The HTTP filters need to whitelist whatever
// host+port the AI Client will actually request, which is DARIO_BASE_URL
// when the override is set, not just the sidecar settings. Without this,
// `wp_safe_remote_request` blocks override-targeted Dario calls and the
// AI Client surfaces "A valid URL was not provided."

// 1. No override -> falls back to sidecar settings.
test_option_store_reset();
update_option( 'procyon_dario_settings', [
	'proxy_host' => '127.0.0.1',
	'proxy_port' => 3456,
] );
$allowed = DarioSidecar::allowedHostPort();
assert( '127.0.0.1' === $allowed['host'], 'no override -> sidecar host' );
assert( 3456 === $allowed['port'], 'no override -> sidecar port' );

// 2. DARIO_BASE_URL env override with explicit port.
putenv( 'DARIO_BASE_URL=http://192.168.1.50:4567/v1' );
$allowed = DarioSidecar::allowedHostPort();
assert( '192.168.1.50' === $allowed['host'], 'override host extracted' );
assert( 4567 === $allowed['port'], 'override port extracted' );
putenv( 'DARIO_BASE_URL' );

// 3. https:// URL with no explicit port -> 443.
putenv( 'DARIO_BASE_URL=https://dario.internal/v1' );
$allowed = DarioSidecar::allowedHostPort();
assert( 'dario.internal' === $allowed['host'] );
assert( 443 === $allowed['port'], 'https default port = 443' );
putenv( 'DARIO_BASE_URL' );

// 4. http:// URL with no explicit port -> 80.
putenv( 'DARIO_BASE_URL=http://dario.internal/v1' );
$allowed = DarioSidecar::allowedHostPort();
assert( 80 === $allowed['port'], 'http default port = 80' );
putenv( 'DARIO_BASE_URL' );

// 5. Malformed override -> falls back to sidecar settings (don't open the filter wide).
putenv( 'DARIO_BASE_URL=not a url' );
$allowed = DarioSidecar::allowedHostPort();
assert( '127.0.0.1' === $allowed['host'], 'malformed override -> fallback host' );
assert( 3456 === $allowed['port'], 'malformed override -> fallback port' );
putenv( 'DARIO_BASE_URL' );

// 6. Empty override -> fallback (treat empty same as unset).
putenv( 'DARIO_BASE_URL=' );
$allowed = DarioSidecar::allowedHostPort();
assert( '127.0.0.1' === $allowed['host'] );
putenv( 'DARIO_BASE_URL' );

echo "test-dario-sidecar ok\n";
