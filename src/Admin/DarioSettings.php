<?php

declare(strict_types=1);

namespace Dario\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persisted plugin settings for the Dario AI Connector.
 *
 * Constants and environment variables override stored options so that
 * deploy automation can pin configuration. Override mapping:
 *
 *   manage_sidecar         -> DARIO_MANAGE_SIDECAR
 *   node_binary            -> DARIO_NODE_BINARY
 *   proxy_host             -> DARIO_PROXY_HOST
 *   proxy_port             -> DARIO_PROXY_PORT
 *   proxy_api_key          -> DARIO_API_KEY
 *   openai_backend_enabled -> DARIO_OPENAI_BACKEND_ENABLED
 *   openai_backend_name    -> DARIO_OPENAI_BACKEND_NAME
 *   openai_base_url        -> DARIO_OPENAI_BASE_URL
 *   openai_api_key         -> DARIO_OPENAI_API_KEY
 *   openai_default_model   -> DARIO_OPENAI_DEFAULT_MODEL
 *
 * @since 0.1.5
 */
class DarioSettings {

	public const OPTION_NAME = 'dario_provider_settings';

	private const SECRET_KEYS = [
		'proxy_api_key'  => true,
		'openai_api_key' => true,
	];

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'manage_sidecar'         => true,
			'node_binary'            => 'node',
			'proxy_host'             => '127.0.0.1',
			'proxy_port'             => 3456,
			'proxy_api_key'          => '',
			'openai_backend_enabled' => false,
			'openai_backend_name'    => 'wordpress',
			'openai_base_url'        => 'https://api.openai.com/v1',
			'openai_api_key'         => '',
			'openai_default_model'   => '',
		];
	}

	/**
	 * Returns the merged settings array (defaults + stored options) without
	 * applying constant/env overrides. Use {@see effective()} when reading
	 * for runtime behaviour.
	 *
	 * @return array<string, mixed>
	 */
	public static function stored(): array {
		$defaults = self::defaults();
		$stored   = function_exists( 'get_option' ) ? get_option( self::OPTION_NAME, [] ) : [];
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
	}

	/**
	 * Stored settings with constant/env overrides applied.
	 *
	 * @return array<string, mixed>
	 */
	public static function effective(): array {
		$values = self::stored();

		foreach ( self::overrideMap() as $key => $constant ) {
			$override = self::scalarConfig( $constant );
			if ( $override === null ) {
				continue;
			}

			$values[ $key ] = self::castOverride( $key, $override );
		}

		return $values;
	}

	/**
	 * @return array<string, string>
	 */
	public static function overrideMap(): array {
		return [
			'manage_sidecar'         => 'DARIO_MANAGE_SIDECAR',
			'node_binary'            => 'DARIO_NODE_BINARY',
			'proxy_host'             => 'DARIO_PROXY_HOST',
			'proxy_port'             => 'DARIO_PROXY_PORT',
			'proxy_api_key'          => 'DARIO_API_KEY',
			'openai_backend_enabled' => 'DARIO_OPENAI_BACKEND_ENABLED',
			'openai_backend_name'    => 'DARIO_OPENAI_BACKEND_NAME',
			'openai_base_url'        => 'DARIO_OPENAI_BASE_URL',
			'openai_api_key'         => 'DARIO_OPENAI_API_KEY',
			'openai_default_model'   => 'DARIO_OPENAI_DEFAULT_MODEL',
		];
	}

	public static function isOverridden( string $key ): bool {
		$map = self::overrideMap();
		if ( ! isset( $map[ $key ] ) ) {
			return false;
		}

		return self::scalarConfig( $map[ $key ] ) !== null;
	}

	/**
	 * Sanitize an incoming settings array (typically `$_POST` payload).
	 *
	 * Empty secret fields preserve the previously-stored secret so that
	 * masked form rendering doesn't accidentally clear a saved key.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$current   = self::stored();
		$sanitized = self::defaults();

		foreach ( $sanitized as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				if ( isset( self::SECRET_KEYS[ $key ] ) ) {
					$sanitized[ $key ] = (string) ( $current[ $key ] ?? '' );
					continue;
				}

				if ( is_bool( $default ) ) {
					$sanitized[ $key ] = false;
					continue;
				}

				$sanitized[ $key ] = $current[ $key ] ?? $default;
				continue;
			}

			$value = $input[ $key ];

			if ( isset( self::SECRET_KEYS[ $key ] ) ) {
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( $value === '' ) {
					$sanitized[ $key ] = (string) ( $current[ $key ] ?? '' );
				} else {
					$sanitized[ $key ] = $value;
				}
				continue;
			}

			switch ( $key ) {
				case 'manage_sidecar':
				case 'openai_backend_enabled':
					$sanitized[ $key ] = self::toBool( $value );
					break;

				case 'proxy_port':
					$port = is_scalar( $value ) ? (int) $value : 0;
					$sanitized[ $key ] = max( 1, min( 65535, $port ) );
					break;

				case 'openai_backend_name':
					$name = is_scalar( $value ) ? trim( (string) $value ) : '';
					$sanitized[ $key ] = self::isValidBackendName( $name ) ? $name : (string) $default;
					break;

				case 'openai_base_url':
					$url = is_scalar( $value ) ? trim( (string) $value ) : '';
					if ( $url === '' ) {
						$sanitized[ $key ] = (string) $default;
						break;
					}
					$filtered = filter_var( $url, FILTER_VALIDATE_URL );
					$sanitized[ $key ] = $filtered !== false ? $filtered : (string) $default;
					break;

				case 'node_binary':
				case 'proxy_host':
				case 'openai_default_model':
				default:
					$sanitized[ $key ] = is_scalar( $value ) ? trim( (string) $value ) : (string) $default;
					if ( $sanitized[ $key ] === '' && $default !== '' ) {
						$sanitized[ $key ] = (string) $default;
					}
					break;
			}
		}

		return $sanitized;
	}

	public static function isValidBackendName( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\-.]{0,63}$/', $name );
	}

	public static function update( array $values ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		return (bool) update_option( self::OPTION_NAME, $values, false );
	}

	public static function maskSecret( string $secret ): string {
		$length = strlen( $secret );
		if ( $length === 0 ) {
			return '';
		}
		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return str_repeat( '*', $length - 4 ) . substr( $secret, -4 );
	}

	private static function castOverride( string $key, string $raw ) {
		switch ( $key ) {
			case 'manage_sidecar':
			case 'openai_backend_enabled':
				return self::toBool( $raw );

			case 'proxy_port':
				$port = (int) $raw;
				return max( 1, min( 65535, $port ) );

			default:
				return $raw;
		}
	}

	private static function toBool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return ( (int) $value ) !== 0;
		}
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( $value === '' ) {
				return false;
			}
			return in_array( $value, [ '1', 'true', 'yes', 'on' ], true );
		}

		return (bool) $value;
	}

	private static function scalarConfig( string $name ): ?string {
		if ( defined( $name ) && is_scalar( constant( $name ) ) ) {
			return (string) constant( $name );
		}

		$value = getenv( $name );
		return $value === false ? null : (string) $value;
	}
}
