<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Procyon\Dario\Sidecar\DarioBackendConfig;

// Name validation matches Dario's own regex.
assert( true === DarioBackendConfig::isValidName( 'wordpress' ) );
assert( true === DarioBackendConfig::isValidName( 'wp-main_2' ) );
assert( true === DarioBackendConfig::isValidName( 'a' ) );
assert( false === DarioBackendConfig::isValidName( '' ) );
assert( false === DarioBackendConfig::isValidName( '..' ) );
assert( false === DarioBackendConfig::isValidName( '.' ) );
assert( false === DarioBackendConfig::isValidName( '-bad' ) );
assert( false === DarioBackendConfig::isValidName( '../passwd' ) );
assert( false === DarioBackendConfig::isValidName( 'has/slash' ) );
assert( false === DarioBackendConfig::isValidName( str_repeat( 'a', 100 ) ) );

// Payload shape mirrors Dario's saveBackend output.
$payload = DarioBackendConfig::buildPayload( 'wordpress', 'sk-test', 'https://api.openai.com/v1' );
assert( 'wordpress' === $payload['name'] );
assert( 'openai' === $payload['provider'] );
assert( 'sk-test' === $payload['apiKey'] );
assert( 'https://api.openai.com/v1' === $payload['baseUrl'] );

// Encoded JSON preserves field order with two-space indent (matches Dario).
$encoded = DarioBackendConfig::encode( $payload );
assert( strpos( $encoded, '"name": "wordpress"' ) !== false );
assert( strpos( $encoded, '"apiKey": "sk-test"' ) !== false );
assert( strpos( $encoded, '/' ) !== false );
assert( strpos( $encoded, "\\/" ) === false );

// Round-trip via tmp HOME so we don't touch the real ~/.dario.
$tmp_home = sys_get_temp_dir() . '/wp-dario-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp_home, 0700, true );
putenv( 'HOME=' . $tmp_home );
$_SERVER['HOME'] = $tmp_home;

$result = DarioBackendConfig::save( 'wordpress-test', 'sk-real', 'https://api.openai.com/v1' );
assert( true === $result['ok'] );
assert( strpos( (string) $result['path'], $tmp_home . '/.dario/backends/wordpress-test.json' ) === 0 );

assert( true === DarioBackendConfig::exists( 'wordpress-test' ) );

$contents = (string) file_get_contents( (string) $result['path'] );
$decoded  = json_decode( $contents, true );
assert( is_array( $decoded ) );
assert( 'sk-real' === $decoded['apiKey'] );

// Permission bits (best-effort — POSIX systems only).
$perms = fileperms( (string) $result['path'] ) & 0777;
assert( 0600 === $perms );

// Invalid name fails cleanly.
$bad = DarioBackendConfig::save( '../escape', 'k', 'https://x' );
assert( false === $bad['ok'] );

// Missing api key fails cleanly.
$blank = DarioBackendConfig::save( 'wp', '', 'https://x' );
assert( false === $blank['ok'] );

// Remove cleans up.
assert( true === DarioBackendConfig::remove( 'wordpress-test' ) );
assert( false === DarioBackendConfig::exists( 'wordpress-test' ) );

// Encoded JSON never appears with the secret in remove path output.
assert( strpos( var_export( $bad, true ), 'sk-real' ) === false );

// Cleanup
@rmdir( $tmp_home . '/.dario/backends' );
@rmdir( $tmp_home . '/.dario' );
@rmdir( $tmp_home );

echo "test-dario-backend-config ok\n";
