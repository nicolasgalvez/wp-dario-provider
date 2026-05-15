<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Procyon\Dario\Sidecar\DarioClaudeAuth;

// Use a tmp HOME so the test never touches a real ~/.dario.
$tmp_home = sys_get_temp_dir() . '/dario-claude-auth-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp_home, 0700, true );
putenv( 'HOME=' . $tmp_home );
$_SERVER['HOME'] = $tmp_home;

// --- Empty input -> friendly error.
$r = DarioClaudeAuth::importCredentialsJson( '' );
assert( false === $r['ok'], 'empty input rejected' );
assert( str_contains( $r['message'], '~/.dario/credentials.json' ), 'empty error mentions the source path' );

// --- Garbage input -> JSON-decode error.
$r = DarioClaudeAuth::importCredentialsJson( 'this is not json {{' );
assert( false === $r['ok'] );
assert( str_contains( strtolower( $r['message'] ), 'json' ), 'json error mentions json' );

// --- Valid JSON but missing the claudeAiOauth wrapper.
$r = DarioClaudeAuth::importCredentialsJson( json_encode( [ 'wrongKey' => 'value' ] ) );
assert( false === $r['ok'] );
assert( str_contains( $r['message'], 'claudeAiOauth' ) );

// --- Missing accessToken.
$r = DarioClaudeAuth::importCredentialsJson( json_encode( [ 'claudeAiOauth' => [ 'refreshToken' => 'r', 'expiresAt' => 9999999999999 ] ] ) );
assert( false === $r['ok'] );
assert( str_contains( $r['message'], 'accessToken' ) );

// --- Missing refreshToken.
$r = DarioClaudeAuth::importCredentialsJson( json_encode( [ 'claudeAiOauth' => [ 'accessToken' => 'a', 'expiresAt' => 9999999999999 ] ] ) );
assert( false === $r['ok'] );
assert( str_contains( $r['message'], 'refreshToken' ) );

// --- Missing or non-numeric expiresAt.
$r = DarioClaudeAuth::importCredentialsJson( json_encode( [ 'claudeAiOauth' => [ 'accessToken' => 'a', 'refreshToken' => 'r' ] ] ) );
assert( false === $r['ok'] );
assert( str_contains( $r['message'], 'expiresAt' ) );

// --- Already expired.
$past = ( time() - 3600 ) * 1000;
$r = DarioClaudeAuth::importCredentialsJson( json_encode( [ 'claudeAiOauth' => [ 'accessToken' => 'a', 'refreshToken' => 'r', 'expiresAt' => $past ] ] ) );
assert( false === $r['ok'] );
assert( str_contains( $r['message'], 'expired' ) );

// --- scopes wrong type.
$future = ( time() + 3600 ) * 1000;
$r = DarioClaudeAuth::importCredentialsJson( json_encode( [ 'claudeAiOauth' => [ 'accessToken' => 'a', 'refreshToken' => 'r', 'expiresAt' => $future, 'scopes' => 'not-an-array' ] ] ) );
assert( false === $r['ok'] );
assert( str_contains( $r['message'], 'scopes' ) );

// --- Happy path: writes ~/.dario/credentials.json with mode 0600 and the
// canonical JSON shape Dario itself uses.
$valid = [
	'claudeAiOauth' => [
		'accessToken'  => 'sk-ant-test',
		'refreshToken' => 'rt-test',
		'expiresAt'    => $future,
		'scopes'       => [ 'user:inference', 'user:profile' ],
	],
];
$r = DarioClaudeAuth::importCredentialsJson( json_encode( $valid ) );
assert( true === $r['ok'], 'valid creds accepted: ' . ( $r['message'] ?? '' ) );
assert( $tmp_home . '/.dario/credentials.json' === $r['path'], 'wrote to expected path' );
assert( file_exists( $r['path'] ), 'credentials file exists' );

$perms = fileperms( $r['path'] ) & 0777;
assert( 0600 === $perms, 'mode 0600 (got ' . decoct( $perms ) . ')' );

$decoded = json_decode( file_get_contents( $r['path'] ), true );
assert( 'sk-ant-test' === $decoded['claudeAiOauth']['accessToken'], 'accessToken preserved' );
assert( 'rt-test' === $decoded['claudeAiOauth']['refreshToken'], 'refreshToken preserved' );
assert( $future === $decoded['claudeAiOauth']['expiresAt'], 'expiresAt preserved' );
assert( [ 'user:inference', 'user:profile' ] === $decoded['claudeAiOauth']['scopes'], 'scopes preserved' );

// --- Default scopes when not provided: writes user:inference as a sane default.
$r = DarioClaudeAuth::importCredentialsJson( json_encode( [
	'claudeAiOauth' => [
		'accessToken'  => 'a2',
		'refreshToken' => 'r2',
		'expiresAt'    => $future,
	],
] ) );
assert( true === $r['ok'] );
$decoded = json_decode( file_get_contents( $r['path'] ), true );
assert( [ 'user:inference' ] === $decoded['claudeAiOauth']['scopes'], 'default scopes = [user:inference]' );

// Cleanup.
@unlink( $tmp_home . '/.dario/credentials.json' );
@rmdir( $tmp_home . '/.dario' );
@rmdir( $tmp_home );

echo "test-dario-claude-auth ok\n";
