<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use Dario\Admin\DarioSettings;

$defaults = DarioSettings::defaults();
assert( true === $defaults['manage_sidecar'] );
assert( 'node' === $defaults['node_binary'] );
assert( '127.0.0.1' === $defaults['proxy_host'] );
assert( 3456 === $defaults['proxy_port'] );
assert( '' === $defaults['proxy_api_key'] );
assert( false === $defaults['openai_backend_enabled'] );
assert( 'wordpress' === $defaults['openai_backend_name'] );
assert( 'https://api.openai.com/v1' === $defaults['openai_base_url'] );
assert( '' === $defaults['openai_api_key'] );
assert( '' === $defaults['openai_default_model'] );

$sanitized = DarioSettings::sanitize( [
	'manage_sidecar'         => '1',
	'node_binary'            => '  /usr/local/bin/node  ',
	'proxy_host'             => '127.0.0.1',
	'proxy_port'             => '99999',
	'proxy_api_key'          => 'sk-secret-proxy',
	'openai_backend_enabled' => 'on',
	'openai_backend_name'    => 'wp_main',
	'openai_base_url'        => 'https://api.openai.com/v1',
	'openai_api_key'         => 'sk-secret-openai',
	'openai_default_model'   => 'gpt-4o',
] );

assert( true === $sanitized['manage_sidecar'] );
assert( '/usr/local/bin/node' === $sanitized['node_binary'] );
assert( 65535 === $sanitized['proxy_port'] );
assert( 'sk-secret-proxy' === $sanitized['proxy_api_key'] );
assert( true === $sanitized['openai_backend_enabled'] );
assert( 'wp_main' === $sanitized['openai_backend_name'] );
assert( 'gpt-4o' === $sanitized['openai_default_model'] );

// Empty checkboxes resolve to false on save.
$sanitized2 = DarioSettings::sanitize( [
	'node_binary'    => 'node',
	'proxy_host'     => '127.0.0.1',
	'proxy_port'     => 3456,
] );
assert( false === $sanitized2['manage_sidecar'] );
assert( false === $sanitized2['openai_backend_enabled'] );
assert( '' === $sanitized2['proxy_api_key'] );

// Invalid backend name falls back to default.
$sanitized3 = DarioSettings::sanitize( [
	'openai_backend_name' => '../etc/passwd',
] );
assert( 'wordpress' === $sanitized3['openai_backend_name'] );

// Invalid base URL falls back to default.
$sanitized4 = DarioSettings::sanitize( [
	'openai_base_url' => 'not-a-url',
] );
assert( 'https://api.openai.com/v1' === $sanitized4['openai_base_url'] );

// Backend name validation
assert( true === DarioSettings::isValidBackendName( 'wordpress' ) );
assert( true === DarioSettings::isValidBackendName( 'wp-main' ) );
assert( true === DarioSettings::isValidBackendName( 'a' ) );
assert( false === DarioSettings::isValidBackendName( '' ) );
assert( false === DarioSettings::isValidBackendName( '../foo' ) );
assert( false === DarioSettings::isValidBackendName( '.' ) );
assert( false === DarioSettings::isValidBackendName( '-leading-dash' ) );
assert( false === DarioSettings::isValidBackendName( str_repeat( 'a', 100 ) ) );

// Secret masking
assert( '' === DarioSettings::maskSecret( '' ) );
assert( '****' === DarioSettings::maskSecret( '1234' ) );
$masked = DarioSettings::maskSecret( 'sk-12345abcd' );
assert( '*' === substr( $masked, 0, 1 ) );
assert( 'abcd' === substr( $masked, -4 ) );

// Override map matches documented constants.
$map = DarioSettings::overrideMap();
assert( 'DARIO_API_KEY' === $map['proxy_api_key'] );
assert( 'DARIO_OPENAI_API_KEY' === $map['openai_api_key'] );

// Override behaviour: env var wins over option default.
putenv( 'DARIO_PROXY_PORT=4567' );
$effective = DarioSettings::effective();
assert( 4567 === (int) $effective['proxy_port'] );
assert( true === DarioSettings::isOverridden( 'proxy_port' ) );
putenv( 'DARIO_PROXY_PORT' );
assert( false === DarioSettings::isOverridden( 'proxy_port' ) );

// Override clamps to valid range.
putenv( 'DARIO_PROXY_PORT=99999' );
$effective2 = DarioSettings::effective();
assert( 65535 === (int) $effective2['proxy_port'] );
putenv( 'DARIO_PROXY_PORT' );

echo "test-dario-settings ok\n";
