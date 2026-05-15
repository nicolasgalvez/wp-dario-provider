<?php

declare(strict_types=1);

namespace Dario\Sidecar;

use Dario\Admin\DarioSettings;

/**
 * Starts and stops the optional Node-based Dario proxy sidecar.
 *
 * @since 0.1.4
 */
class DarioSidecar {

	private const PID_OPTION              = 'dario_provider_sidecar_pid';
	private const CONNECTOR_KEY_OPTION    = 'connectors_ai_dario_api_key';
	private const DEFAULT_CONNECTOR_KEY   = 'dario';

	/**
	 * Allow `wp_safe_remote_*` to reach the local Dario sidecar.
	 *
	 * WordPress's safe HTTP wrapper blocks loopback/RFC1918 hosts and
	 * non-standard ports unless explicitly allowlisted. The AI Client
	 * routes its outbound request through `wp_safe_remote_request`, so
	 * without these filters every Dario call fails with "A valid URL
	 * was not provided." Both filters are tightly scoped to our own
	 * configured proxy host/port to avoid widening WP's safe-request
	 * surface for unrelated requests.
	 */
	public static function registerHttpFilters(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter(
			'http_request_host_is_external',
			static function ( $external, $host ) {
				return ( $host === self::host() ) ? true : $external;
			},
			10,
			2
		);

		add_filter(
			'http_allowed_safe_ports',
			static function ( $ports, $host ) {
				if ( $host !== self::host() ) {
					return $ports;
				}
				$port = self::port();
				if ( is_array( $ports ) && ! in_array( $port, $ports, true ) ) {
					$ports[] = $port;
				}
				return $ports;
			},
			10,
			2
		);
	}

	public static function host(): string {
		$settings = DarioSettings::effective();
		return (string) ( $settings['proxy_host'] ?? '127.0.0.1' );
	}

	public static function port(): int {
		$settings = DarioSettings::effective();
		$port     = (int) ( $settings['proxy_port'] ?? 3456 );
		return max( 1, min( 65535, $port ) );
	}

	public static function baseUrl(): string {
		return sprintf( 'http://%s:%d/v1', self::host(), self::port() );
	}

	public static function activate( string $plugin_file ): void {
		self::ensureConnectorApiKey();

		if ( ! self::shouldManageSidecar() ) {
			return;
		}

		$plugin_dir = dirname( $plugin_file );
		self::startIfNeeded( $plugin_dir );
	}

	/**
	 * Ensure the WordPress connector API key option has a value so the AI
	 * Client binds an auth interface to the Dario provider. Dario's local
	 * proxy doesn't validate the key, but the AI Client refuses to fire
	 * outbound requests without one. If our `proxy_api_key` setting is
	 * non-empty we mirror it; otherwise we default to the literal "dario"
	 * from Dario's own docs.
	 */
	public static function ensureConnectorApiKey(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$current = get_option( self::CONNECTOR_KEY_OPTION, '' );
		if ( is_string( $current ) && $current !== '' ) {
			return;
		}

		$desired = self::desiredConnectorApiKey();
		update_option( self::CONNECTOR_KEY_OPTION, $desired, false );
	}

	/**
	 * Force the connector option back in sync with the proxy_api_key
	 * setting. Called from the admin save handler after proxy_api_key
	 * changes so requests keep working.
	 */
	public static function syncConnectorApiKey(): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		update_option( self::CONNECTOR_KEY_OPTION, self::desiredConnectorApiKey(), false );
	}

	private static function desiredConnectorApiKey(): string {
		$settings = DarioSettings::effective();
		$proxy_key = isset( $settings['proxy_api_key'] ) ? (string) $settings['proxy_api_key'] : '';
		return $proxy_key !== '' ? $proxy_key : self::DEFAULT_CONNECTOR_KEY;
	}

	public static function deactivate(): void {
		$pid = self::getOption( self::PID_OPTION );
		if ( is_numeric( $pid ) && (int) $pid > 0 ) {
			self::stopProcess( (int) $pid );
		}
		self::deleteOption( self::PID_OPTION );
	}

	/**
	 * Public restart entrypoint for the admin page.
	 *
	 * @return array{ok:bool, message:string, pid?:int}
	 */
	public static function restart( string $plugin_dir ): array {
		self::deactivate();

		if ( ! self::shouldManageSidecar() ) {
			return [ 'ok' => false, 'message' => 'Sidecar management is disabled.' ];
		}

		$pid = self::startIfNeeded( $plugin_dir );
		if ( $pid > 0 ) {
			return [ 'ok' => true, 'message' => 'Sidecar started.', 'pid' => $pid ];
		}

		if ( self::isPortOpen( self::host(), self::port() ) ) {
			return [ 'ok' => true, 'message' => 'Sidecar already running on the configured port.' ];
		}

		return [ 'ok' => false, 'message' => 'Sidecar did not start. See log for details.' ];
	}

	/**
	 * Snapshot of sidecar runtime state for the admin page.
	 *
	 * @return array{
	 *   node_available: bool,
	 *   node_version: ?string,
	 *   sidecar_running: bool,
	 *   pid: ?int,
	 *   proxy_url: string,
	 *   log_path: string,
	 *   log_tail: array<int, string>
	 * }
	 */
	public static function status( string $plugin_dir ): array {
		$node_binary    = self::nodeBinary();
		$node_available = self::commandExists( $node_binary );
		$node_version   = $node_available ? self::nodeVersion( $node_binary ) : null;

		$pid_option = self::getOption( self::PID_OPTION );
		$pid        = is_numeric( $pid_option ) && (int) $pid_option > 0 ? (int) $pid_option : null;

		$running = self::isPortOpen( self::host(), self::port() );

		return [
			'node_available'  => $node_available,
			'node_version'    => $node_version,
			'sidecar_running' => $running,
			'pid'             => $pid,
			'proxy_url'       => self::baseUrl(),
			'log_path'        => self::logFile( $plugin_dir ),
			'log_tail'        => self::lastLogLines( $plugin_dir, 25 ),
		];
	}

	public static function start( string $plugin_dir ): int {
		$log_file = self::logFile( $plugin_dir );
		$command  = self::buildStartCommand( $plugin_dir, $log_file );
		$output   = shell_exec( $command );

		return is_string( $output ) ? (int) trim( $output ) : 0;
	}

	public static function buildStartCommand( string $plugin_dir, string $log_file ): string {
		$env_pairs = [
			'DARIO_PROXY_HOST' => self::host(),
			'DARIO_PROXY_PORT' => (string) self::port(),
			'DARIO_LOG_FILE'   => self::requestLogFile( $plugin_dir ),
		];

		$proxy_key = self::proxyApiKey();
		if ( $proxy_key !== '' ) {
			$env_pairs['DARIO_API_KEY'] = $proxy_key;
		}

		$env_parts = [];
		foreach ( $env_pairs as $name => $value ) {
			$env_parts[] = $name . '=' . escapeshellarg( $value );
		}
		$env = implode( ' ', $env_parts );

		return sprintf(
			'%s nohup %s %s >> %s 2>&1 & echo $!',
			$env,
			escapeshellcmd( self::nodeBinary() ),
			escapeshellarg( self::scriptPath( $plugin_dir ) ),
			escapeshellarg( $log_file )
		);
	}

	public static function scriptPath( string $plugin_dir ): string {
		return rtrim( $plugin_dir, '/\\' ) . '/sidecar/dario-sidecar.mjs';
	}

	public static function logFile( string $plugin_dir ): string {
		return self::writableDirectory( $plugin_dir ) . '/dario-sidecar.log';
	}

	public static function requestLogFile( string $plugin_dir ): string {
		return self::writableDirectory( $plugin_dir ) . '/dario-requests.jsonl';
	}

	/**
	 * @return array<int, string>
	 */
	public static function lastLogLines( string $plugin_dir, int $lines = 25 ): array {
		$path = self::logFile( $plugin_dir );
		if ( ! is_file( $path ) ) {
			return [];
		}

		$contents = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $contents ) ) {
			return [];
		}

		if ( count( $contents ) <= $lines ) {
			return $contents;
		}

		return array_slice( $contents, -1 * $lines );
	}

	private static function startIfNeeded( string $plugin_dir ): int {
		$host = self::host();
		$port = self::port();
		if ( self::isPortOpen( $host, $port ) ) {
			self::updateOption( self::PID_OPTION, 0 );
			return 0;
		}

		$script = self::scriptPath( $plugin_dir );
		if ( ! is_file( $script ) ) {
			self::log( $plugin_dir, 'Dario sidecar script is missing.' );
			return 0;
		}

		if ( ! self::commandExists( self::nodeBinary() ) ) {
			self::log( $plugin_dir, 'Node.js is not available; Dario sidecar was not started.' );
			return 0;
		}

		$pid = self::start( $plugin_dir );
		if ( $pid <= 0 ) {
			return 0;
		}

		if ( self::waitForPort( $host, $port ) ) {
			self::updateOption( self::PID_OPTION, $pid );
			return $pid;
		}

		self::stopProcess( $pid );
		self::deleteOption( self::PID_OPTION );
		self::log( $plugin_dir, 'Dario sidecar started but did not open the proxy port.' );
		return 0;
	}

	private static function writableDirectory( string $plugin_dir ): string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$dir = rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/dario-provider';
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			if ( is_dir( $dir ) && is_writable( $dir ) ) {
				return $dir;
			}
		}

		return $plugin_dir;
	}

	private static function nodeBinary(): string {
		$settings = DarioSettings::effective();
		$binary   = (string) ( $settings['node_binary'] ?? 'node' );
		return $binary !== '' ? $binary : 'node';
	}

	private static function proxyApiKey(): string {
		$settings = DarioSettings::effective();
		return (string) ( $settings['proxy_api_key'] ?? '' );
	}

	private static function shouldManageSidecar(): bool {
		$settings = DarioSettings::effective();
		return (bool) ( $settings['manage_sidecar'] ?? true );
	}

	private static function commandExists( string $command ): bool {
		$output = shell_exec( 'command -v ' . escapeshellcmd( $command ) . ' 2>/dev/null' );
		return is_string( $output ) && trim( $output ) !== '';
	}

	private static function nodeVersion( string $binary ): ?string {
		$output = shell_exec( escapeshellcmd( $binary ) . ' --version 2>/dev/null' );
		if ( ! is_string( $output ) ) {
			return null;
		}
		$version = trim( $output );
		return $version === '' ? null : $version;
	}

	private static function isPortOpen( string $host, int $port ): bool {
		$socket = @fsockopen( $host, $port, $errno, $errstr, 0.2 );
		if ( is_resource( $socket ) ) {
			fclose( $socket );
			return true;
		}

		return false;
	}

	private static function waitForPort( string $host, int $port ): bool {
		for ( $i = 0; $i < 10; $i++ ) {
			if ( self::isPortOpen( $host, $port ) ) {
				return true;
			}
			usleep( 200000 );
		}

		return false;
	}

	private static function stopProcess( int $pid ): void {
		if ( $pid <= 0 ) {
			return;
		}

		shell_exec( 'kill ' . escapeshellarg( (string) $pid ) . ' 2>/dev/null' );
	}

	private static function log( string $plugin_dir, string $message ): void {
		file_put_contents(
			self::logFile( $plugin_dir ),
			sprintf( "[%s] %s\n", gmdate( 'c' ), $message ),
			FILE_APPEND
		);
	}

	private static function updateOption( string $name, int $value ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( $name, $value, false );
		}
	}

	private static function getOption( string $name ) {
		return function_exists( 'get_option' ) ? get_option( $name ) : null;
	}

	private static function deleteOption( string $name ): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( $name );
		}
	}
}
