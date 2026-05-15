<?php

declare(strict_types=1);

// Test bootstrap: defines ABSPATH and stubs the handful of WP functions our
// non-network code paths reach. Loaded first by every test file. Lets us run
// fast unit tests without spinning up Lando or PHPUnit's WP testing suite.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../wordpress/' );
}

// esc_html() is used in exception messages we throw from src/. Tests trigger
// these exceptions; without a stub the test crashes inside esc_html.
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

// WP_Error is the only WP class WP_Filesystem_Direct's constructor touches.
// Stub it as a no-op container so the constructor doesn't fatal.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( $code = '', $message = '', $data = '' ) {}
	}
}

// WP_Filesystem_Direct calls untrailingslashit() and trailingslashit() in
// mkdir/move/etc. Stubs match WP core behavior.
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return untrailingslashit( $value ) . '/';
	}
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '|(?<=.)/+|', '/', $path );
		return $path;
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( @unlink( $file ) ) {
			return true;
		}
		return false;
	}
}
// WP_Filesystem_Direct::put_contents wraps writes in mbstring_binary_safe_encoding
// to prevent any mbstring overload from mangling bytes. No-op when mbstring isn't
// in 7-bit overload mode (default), so safe to stub.
if ( ! function_exists( 'mbstring_binary_safe_encoding' ) ) {
	function mbstring_binary_safe_encoding( $reset = false ) {}
}
if ( ! function_exists( 'reset_mbstring_encoding' ) ) {
	function reset_mbstring_encoding() {}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ) {
		if ( file_exists( $target ) ) {
			return is_dir( $target );
		}
		if ( @mkdir( $target, 0777, true ) ) {
			return true;
		}
		return false;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

// Several src/ files call function_exists() before update_option/get_option/
// set_transient/etc. so they work fine without stubs in unit tests.

// In-memory option store for tests that need WP option semantics. Tests opt
// in by calling test_option_store_reset() at the top of the test file. The
// stubs are no-op-shaped: if a test never resets the store, the functions
// return the default and look like un-stubbed calls.
$GLOBALS['__test_option_store'] = [];
function test_option_store_reset(): void {
	$GLOBALS['__test_option_store'] = [];
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__test_option_store'] )
			? $GLOBALS['__test_option_store'][ $name ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__test_option_store'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['__test_option_store'] ) ) {
			return false;
		}
		unset( $GLOBALS['__test_option_store'][ $name ] );
		return true;
	}
}

require_once __DIR__ . '/../src/autoload.php';
