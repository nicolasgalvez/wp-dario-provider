<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Procyon\Dario\Sidecar\DarioSidecar;

$plugin_dir = dirname( __DIR__ );
$log_file   = $plugin_dir . '/sidecar.log';
$command    = DarioSidecar::buildStartCommand( $plugin_dir, $log_file );

assert( '127.0.0.1' === DarioSidecar::host() );
assert( 3456 === DarioSidecar::port() );
assert( 'http://127.0.0.1:3456/v1' === DarioSidecar::baseUrl() );
assert( strpos( $command, 'DARIO_PROXY_HOST=' ) !== false );
assert( strpos( $command, 'DARIO_PROXY_PORT=' ) !== false );
assert( strpos( $command, 'sidecar/dario-sidecar.mjs' ) !== false );
assert( strpos( $command, '2>&1 & echo $!' ) !== false );
