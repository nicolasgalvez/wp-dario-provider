<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Procyon\Dario\Admin\MigrationShim;

// Case 1: legacy options present, new ones absent. Migration copies each one
// and deletes the legacy keys.
test_option_store_reset();
update_option( 'dario_provider_settings', [ 'proxy_host' => '127.0.0.1', 'proxy_port' => 3456 ] );
update_option( 'dario_provider_sidecar_pid', 1234 );
update_option( 'dario_provider_flash', [ 'message' => 'old' ] );
update_option( 'dario_provider_oauth_active', [ 'session_id' => 'abc' ] );
update_option( 'connectors_ai_dario_api_key', 'dario' );

MigrationShim::run();

assert( false === get_option( 'dario_provider_settings' ), 'legacy settings should be deleted' );
assert( false === get_option( 'dario_provider_sidecar_pid' ), 'legacy pid should be deleted' );
assert( false === get_option( 'dario_provider_flash' ), 'legacy flash should be deleted' );
assert( false === get_option( 'dario_provider_oauth_active' ), 'legacy oauth should be deleted' );
assert( false === get_option( 'connectors_ai_dario_api_key' ), 'legacy connector key should be deleted' );

assert( [ 'proxy_host' => '127.0.0.1', 'proxy_port' => 3456 ] === get_option( 'procyon_dario_settings' ), 'settings copied' );
assert( 1234 === get_option( 'procyon_dario_sidecar_pid' ), 'pid copied' );
assert( [ 'message' => 'old' ] === get_option( 'procyon_dario_flash' ), 'flash copied' );
assert( [ 'session_id' => 'abc' ] === get_option( 'procyon_dario_oauth_active' ), 'oauth copied' );
assert( 'dario' === get_option( 'connectors_ai_procyon_dario_api_key' ), 'connector key copied' );

// Case 2: idempotent. Run with empty store; no errors, no values.
test_option_store_reset();
MigrationShim::run();
assert( false === get_option( 'procyon_dario_settings' ), 'no-op when no legacy options' );

// Case 3: new option already has a user-set value; migration must NOT clobber
// it even if a legacy value exists.
test_option_store_reset();
update_option( 'dario_provider_settings', [ 'proxy_port' => 9000 ] );
update_option( 'procyon_dario_settings', [ 'proxy_port' => 4444 ] );

MigrationShim::run();

assert( [ 'proxy_port' => 4444 ] === get_option( 'procyon_dario_settings' ), 'new option must not be clobbered' );
assert( false === get_option( 'dario_provider_settings' ), 'legacy still gets deleted' );

// Case 4: only some legacy keys exist; partial migration runs cleanly.
test_option_store_reset();
update_option( 'dario_provider_settings', [ 'manage_sidecar' => true ] );

MigrationShim::run();

assert( [ 'manage_sidecar' => true ] === get_option( 'procyon_dario_settings' ), 'partial migration copies what is present' );
assert( false === get_option( 'procyon_dario_sidecar_pid' ), 'absent legacy stays absent' );
assert( false === get_option( 'dario_provider_settings' ), 'present legacy still gets deleted' );

echo "test-migration-shim ok\n";
