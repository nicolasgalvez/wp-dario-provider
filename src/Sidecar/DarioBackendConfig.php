<?php

declare(strict_types=1);

namespace Dario\Sidecar;

/**
 * Writes Dario OpenAI-compatible backend credentials to disk in the format
 * Dario's own `saveBackend()` produces (`~/.dario/backends/<name>.json`).
 *
 * @since 0.1.5
 */
class DarioBackendConfig {

	private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_\-.]{0,63}$/';

	public static function isValidName( string $name ): bool {
		if ( $name === '' ) {
			return false;
		}
		if ( basename( $name ) !== $name ) {
			return false;
		}
		if ( $name === '.' || $name === '..' ) {
			return false;
		}

		return (bool) preg_match( self::NAME_PATTERN, $name );
	}

	public static function darioDir(): string {
		$home = self::homeDir();
		return rtrim( $home, '/\\' ) . '/.dario';
	}

	public static function backendsDir(): string {
		return self::darioDir() . '/backends';
	}

	public static function backendPath( string $name ): ?string {
		if ( ! self::isValidName( $name ) ) {
			return null;
		}

		return self::backendsDir() . '/' . $name . '.json';
	}

	/**
	 * @return array{name:string, provider:string, apiKey:string, baseUrl:string}
	 */
	public static function buildPayload( string $name, string $apiKey, string $baseUrl, string $provider = 'openai' ): array {
		return [
			'name'     => $name,
			'provider' => $provider,
			'apiKey'   => $apiKey,
			'baseUrl'  => $baseUrl,
		];
	}

	/**
	 * Encodes a backend payload exactly the way Dario does (pretty 2-space JSON).
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function encode( array $payload ): string {
		$encoded = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @return array{ok:bool, path?:string, error?:string}
	 */
	public static function save( string $name, string $apiKey, string $baseUrl, string $provider = 'openai' ): array {
		$path = self::backendPath( $name );
		if ( $path === null ) {
			return [ 'ok' => false, 'error' => 'invalid backend name' ];
		}
		if ( $apiKey === '' ) {
			return [ 'ok' => false, 'error' => 'missing api key' ];
		}
		if ( $baseUrl === '' ) {
			return [ 'ok' => false, 'error' => 'missing base url' ];
		}

		$dir = self::backendsDir();
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			return [ 'ok' => false, 'error' => 'unable to create backends directory: ' . $dir ];
		}

		$payload = self::buildPayload( $name, $apiKey, $baseUrl, $provider );
		$json    = self::encode( $payload );

		$tmp = $path . '.tmp';
		if ( file_put_contents( $tmp, $json ) === false ) {
			return [ 'ok' => false, 'error' => 'unable to write backend file' ];
		}

		@chmod( $tmp, 0600 );
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return [ 'ok' => false, 'error' => 'unable to move backend file into place' ];
		}
		@chmod( $path, 0600 );

		return [ 'ok' => true, 'path' => $path ];
	}

	public static function remove( string $name ): bool {
		$path = self::backendPath( $name );
		if ( $path === null || ! is_file( $path ) ) {
			return false;
		}

		return @unlink( $path );
	}

	public static function exists( string $name ): bool {
		$path = self::backendPath( $name );
		return $path !== null && is_file( $path );
	}

	private static function homeDir(): string {
		$home = getenv( 'HOME' );
		if ( is_string( $home ) && $home !== '' ) {
			return $home;
		}

		if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$info = @posix_getpwuid( posix_geteuid() );
			if ( is_array( $info ) && isset( $info['dir'] ) && is_string( $info['dir'] ) && $info['dir'] !== '' ) {
				return $info['dir'];
			}
		}

		$user_profile = getenv( 'USERPROFILE' );
		if ( is_string( $user_profile ) && $user_profile !== '' ) {
			return $user_profile;
		}

		return sys_get_temp_dir();
	}
}
