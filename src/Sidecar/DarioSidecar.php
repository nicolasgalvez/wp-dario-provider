<?php

declare(strict_types=1);

namespace Dario\Sidecar;

/**
 * Starts and stops the optional Node-based Dario proxy sidecar.
 *
 * @since 0.1.4
 */
class DarioSidecar {

	private const PID_OPTION = 'dario_provider_sidecar_pid';

	public static function host(): string {
		$host = self::scalarConfig( 'DARIO_PROXY_HOST' );
		return $host !== null ? $host : '127.0.0.1';
	}

	public static function port(): int {
		$port = self::scalarConfig( 'DARIO_PROXY_PORT' );
		return $port !== null ? max( 1, min( 65535, (int) $port ) ) : 3456;
	}

	public static function baseUrl(): string {
		return sprintf( 'http://%s:%d/v1', self::host(), self::port() );
	}

	public static function activate( string $plugin_file ): void {
		if ( ! self::shouldManageSidecar() ) {
			return;
		}

		$host = self::host();
		$port = self::port();
		if ( self::isPortOpen( $host, $port ) ) {
			self::updateOption( self::PID_OPTION, 0 );
			return;
		}

		$plugin_dir = dirname( $plugin_file );
		$script     = self::scriptPath( $plugin_dir );
		if ( ! is_file( $script ) ) {
			self::log( $plugin_dir, 'Dario sidecar script is missing.' );
			return;
		}

		if ( ! self::commandExists( self::nodeBinary() ) ) {
			self::log( $plugin_dir, 'Node.js is not available; Dario sidecar was not started.' );
			return;
		}

		$pid = self::start( $plugin_dir );
		if ( $pid > 0 ) {
			if ( self::waitForPort( $host, $port ) ) {
				self::updateOption( self::PID_OPTION, $pid );
				return;
			}

			self::stopProcess( $pid );
			self::deleteOption( self::PID_OPTION );
			self::log( $plugin_dir, 'Dario sidecar started but did not open the proxy port.' );
		}
	}

	public static function deactivate(): void {
		$pid = self::getOption( self::PID_OPTION );
		if ( is_numeric( $pid ) && (int) $pid > 0 ) {
			self::stopProcess( (int) $pid );
		}
		self::deleteOption( self::PID_OPTION );
	}

	public static function start( string $plugin_dir ): int {
		$log_file = self::logFile( $plugin_dir );
		$command  = self::buildStartCommand( $plugin_dir, $log_file );
		$output   = shell_exec( $command );

		return is_string( $output ) ? (int) trim( $output ) : 0;
	}

	public static function buildStartCommand( string $plugin_dir, string $log_file ): string {
		$env = sprintf(
			'DARIO_PROXY_HOST=%s DARIO_PROXY_PORT=%s DARIO_LOG_FILE=%s',
			escapeshellarg( self::host() ),
			escapeshellarg( (string) self::port() ),
			escapeshellarg( self::requestLogFile( $plugin_dir ) )
		);

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
		$binary = self::scalarConfig( 'DARIO_NODE_BINARY' );
		return $binary !== null ? $binary : 'node';
	}

	private static function shouldManageSidecar(): bool {
		$value = self::scalarConfig( 'DARIO_MANAGE_SIDECAR' );
		if ( $value === null ) {
			return true;
		}

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	private static function scalarConfig( string $name ): ?string {
		if ( defined( $name ) && is_scalar( constant( $name ) ) ) {
			return (string) constant( $name );
		}

		$value = getenv( $name );
		return $value === false ? null : (string) $value;
	}

	private static function commandExists( string $command ): bool {
		$output = shell_exec( 'command -v ' . escapeshellcmd( $command ) . ' 2>/dev/null' );
		return is_string( $output ) && trim( $output ) !== '';
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
