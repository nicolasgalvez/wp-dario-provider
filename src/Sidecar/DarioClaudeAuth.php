<?php

declare(strict_types=1);

namespace Procyon\Dario\Sidecar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Procyon\Dario\Admin\DarioSettings;
use Procyon\Dario\Sidecar\Concerns\WithFilesystem;

/**
 * Claude OAuth helpers for the WordPress admin UI.
 *
 * Read-only status comes from the Node helper script. Interactive login is
 * driven by spawning Dario's CLI in `--manual` mode with a FIFO on stdin so
 * the admin can paste back the authorization code from a separate request.
 *
 * @since 0.1.5
 */
class DarioClaudeAuth {

	use WithFilesystem;

	private const SESSION_TRANSIENT_PREFIX = 'procyon_dario_oauth_session_';
	private const SESSION_TTL              = 600;
	private const URL_WAIT_MICROSECONDS    = 5_000_000;
	private const EXIT_WAIT_MICROSECONDS   = 15_000_000;

	/**
	 * Returns the current Dario auth status.
	 *
	 * @return array{
	 *   ok:bool,
	 *   authenticated:bool,
	 *   status:string,
	 *   hasCredentials:bool,
	 *   expiresAt?:int,
	 *   expiresIn?:string,
	 *   error?:string,
	 *   raw?:string
	 * }
	 */
	public static function status( string $plugin_dir ): array {
		$helper = self::helperScript( $plugin_dir );
		if ( ! is_file( $helper ) ) {
			return [ 'ok' => false, 'authenticated' => false, 'status' => 'none', 'hasCredentials' => false, 'error' => 'helper missing' ];
		}

		$cmd = sprintf(
			'%s %s status 2>&1',
			escapeshellcmd( self::nodeBinary() ),
			escapeshellarg( $helper )
		);

		$output = shell_exec( $cmd );
		if ( ! is_string( $output ) ) {
			return [ 'ok' => false, 'authenticated' => false, 'status' => 'none', 'hasCredentials' => false, 'error' => 'helper exec failed' ];
		}

		$line   = trim( self::lastJsonLine( $output ) );
		$parsed = json_decode( $line, true );
		if ( ! is_array( $parsed ) ) {
			return [ 'ok' => false, 'authenticated' => false, 'status' => 'none', 'hasCredentials' => false, 'error' => 'unparseable helper output', 'raw' => $output ];
		}

		return array_merge(
			[ 'ok' => true, 'authenticated' => false, 'status' => 'none', 'hasCredentials' => false ],
			$parsed
		);
	}

	/**
	 * Spawn `dario login --manual` and wait briefly for the authorize URL.
	 *
	 * @return array{ok:bool, message?:string, session_id?:string, authorize_url?:string, log_tail?:string}
	 */
	public static function startManualLogin( string $plugin_dir ): array {
		$cli = self::darioCli( $plugin_dir );
		if ( ! is_file( $cli ) ) {
			return [ 'ok' => false, 'message' => 'Dario CLI not found inside the plugin. Reinstall the plugin to restore node_modules.' ];
		}

		if ( ! self::commandExists( self::nodeBinary() ) ) {
			return [ 'ok' => false, 'message' => 'Node.js is not available on the WordPress server.' ];
		}

		if ( ! function_exists( 'proc_open' ) || ! function_exists( 'posix_mkfifo' ) ) {
			return [ 'ok' => false, 'message' => 'PHP needs proc_open and posix_mkfifo for the manual OAuth flow. Run `dario login --manual` from a shell instead.' ];
		}

		$session_id = self::generateSessionId();
		$dir        = self::sessionDir( $plugin_dir );
		$fs         = self::fs();
		if ( ! $fs->is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return [ 'ok' => false, 'message' => 'Could not create OAuth session directory.' ];
		}

		$fifo = $dir . '/' . $session_id . '.fifo';
		$log  = $dir . '/' . $session_id . '.log';

		if ( $fs->exists( $fifo ) ) {
			// FIFO sentinel: WP_Filesystem treats FIFOs unreliably, use wp_delete_file.
			wp_delete_file( $fifo );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_posix_mkfifo -- WP_Filesystem has no equivalent for named pipes.
		if ( ! @posix_mkfifo( $fifo, 0600 ) ) {
			return [ 'ok' => false, 'message' => 'Could not create OAuth FIFO.' ];
		}

		// Note the `0<>` redirection: opens the FIFO read+write so the open
		// succeeds without waiting for a peer. The shell holds the write end
		// for the lifetime of the child, so dario won't see EOF until we
		// later write a line into the FIFO from PHP's submit handler.
		$cmd = sprintf(
			'%s %s login --manual 0<> %s >> %s 2>&1 & echo $!',
			escapeshellcmd( self::nodeBinary() ),
			escapeshellarg( $cli ),
			escapeshellarg( $fifo ),
			escapeshellarg( $log )
		);

		$pid_output = shell_exec( $cmd );
		$pid        = is_string( $pid_output ) ? (int) trim( $pid_output ) : 0;
		if ( $pid <= 0 ) {
			wp_delete_file( $fifo );
			return [ 'ok' => false, 'message' => 'Failed to start Dario CLI.' ];
		}

		self::saveSession(
			$session_id,
			[
				'pid'         => $pid,
				'fifo'        => $fifo,
				'log'         => $log,
				'started_at'  => time(),
				'plugin_dir'  => $plugin_dir,
			]
		);

		$url = self::waitForAuthorizeUrl( $log, self::URL_WAIT_MICROSECONDS );
		if ( $url === null ) {
			$tail = self::tailFile( $log, 4000 );
			return [
				'ok'         => false,
				'session_id' => $session_id,
				'message'    => 'Dario did not print an authorize URL within 5 seconds.',
				'log_tail'   => $tail,
			];
		}

		return [
			'ok'            => true,
			'session_id'    => $session_id,
			'authorize_url' => $url,
		];
	}

	/**
	 * Submit a pasted authorization code into a previously-started session.
	 *
	 * @return array{ok:bool, message:string, log_tail?:string}
	 */
	public static function submitManualCode( string $session_id, string $pasted ): array {
		$session = self::loadSession( $session_id );
		if ( $session === null ) {
			return [ 'ok' => false, 'message' => 'OAuth session expired. Start a new login attempt.' ];
		}

		$pasted = trim( $pasted );
		if ( $pasted === '' ) {
			return [ 'ok' => false, 'message' => 'Paste the code Anthropic gave you on the success page.' ];
		}

		$fifo = (string) ( $session['fifo'] ?? '' );
		$log  = (string) ( $session['log'] ?? '' );
		$pid  = (int) ( $session['pid'] ?? 0 );

		if ( ! self::fs()->exists( $fifo ) ) {
			self::cleanupSession( $session_id );
			return [ 'ok' => false, 'message' => 'OAuth session is no longer active. Start a new login attempt.' ];
		}

		// FIFO write: WP_Filesystem::put_contents would truncate-and-write, which
		// doesn't work for named pipes (no seekable target). Use direct fopen/
		// fwrite/fclose. phpcs:ignore for the next four file-op calls.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- FIFO write; no WP_Filesystem equivalent.
		$handle = @fopen( $fifo, 'wb' );
		if ( $handle === false ) {
			return [ 'ok' => false, 'message' => 'Could not write to OAuth session FIFO.' ];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- FIFO write.
		fwrite( $handle, $pasted . "\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- FIFO write.
		fclose( $handle );

		$exited = self::waitForExit( $pid, self::EXIT_WAIT_MICROSECONDS );
		$tail   = self::tailFile( $log, 4000 );

		self::cleanupSession( $session_id );

		if ( ! $exited ) {
			return [ 'ok' => false, 'message' => 'Dario CLI is still running after submitting the code. Check the log.', 'log_tail' => $tail ];
		}

		$ok = self::logIndicatesSuccess( $tail );
		if ( $ok ) {
			return [ 'ok' => true, 'message' => 'Successfully authenticated with Dario.', 'log_tail' => $tail ];
		}

		return [ 'ok' => false, 'message' => 'Dario CLI exited without saving credentials. See log.', 'log_tail' => $tail ];
	}

	public static function cancelSession( string $session_id ): void {
		self::cleanupSession( $session_id );
	}

	/**
	 * Validate and persist a pasted Dario `credentials.json` payload.
	 *
	 * Accepts the exact JSON shape `dario login` writes to
	 * `~/.dario/credentials.json`. Useful for installs where running the
	 * interactive login from the WordPress server isn't practical: log in
	 * elsewhere, copy the file, paste it.
	 *
	 * @return array{ok:bool, message:string, path?:string}
	 */
	public static function importCredentialsJson( string $json ): array {
		$json = trim( $json );
		if ( $json === '' ) {
			return [ 'ok' => false, 'message' => 'Paste the contents of ~/.dario/credentials.json.' ];
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return [ 'ok' => false, 'message' => 'Pasted content is not valid JSON.' ];
		}

		$oauth = $decoded['claudeAiOauth'] ?? null;
		if ( ! is_array( $oauth ) ) {
			return [ 'ok' => false, 'message' => 'Missing claudeAiOauth object — this does not look like a Dario credentials.json.' ];
		}

		$required_strings = [ 'accessToken', 'refreshToken' ];
		foreach ( $required_strings as $key ) {
			if ( empty( $oauth[ $key ] ) || ! is_string( $oauth[ $key ] ) ) {
				return [ 'ok' => false, 'message' => sprintf( 'claudeAiOauth.%s is missing or not a string.', $key ) ];
			}
		}

		if ( ! isset( $oauth['expiresAt'] ) || ! is_numeric( $oauth['expiresAt'] ) ) {
			return [ 'ok' => false, 'message' => 'claudeAiOauth.expiresAt is missing or not numeric.' ];
		}

		$expires_at = (int) $oauth['expiresAt'];
		if ( $expires_at <= ( time() * 1000 ) ) {
			return [ 'ok' => false, 'message' => 'These credentials are already expired. Run `dario login` again to refresh them.' ];
		}

		if ( isset( $oauth['scopes'] ) && ! is_array( $oauth['scopes'] ) ) {
			return [ 'ok' => false, 'message' => 'claudeAiOauth.scopes must be an array if provided.' ];
		}

		$fs  = self::fs();
		$dir = self::darioConfigDir();
		if ( ! $fs->is_dir( $dir ) && ! $fs->mkdir( $dir, 0700 ) ) {
			return [ 'ok' => false, 'message' => 'Could not create ~/.dario directory.' ];
		}

		$path = $dir . '/credentials.json';
		$tmp  = $path . '.tmp.' . bin2hex( random_bytes( 4 ) );
		$payload = wp_json_encode(
			[ 'claudeAiOauth' => [
				'accessToken'  => (string) $oauth['accessToken'],
				'refreshToken' => (string) $oauth['refreshToken'],
				'expiresAt'    => $expires_at,
				'scopes'       => isset( $oauth['scopes'] ) ? array_values( array_map( 'strval', (array) $oauth['scopes'] ) ) : [ 'user:inference' ],
			] ],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		if ( ! $fs->put_contents( $tmp, (string) $payload, 0600 ) ) {
			return [ 'ok' => false, 'message' => 'Could not write credentials file.' ];
		}
		if ( ! $fs->move( $tmp, $path, true ) ) {
			$fs->delete( $tmp );
			return [ 'ok' => false, 'message' => 'Could not move credentials file into place.' ];
		}
		$fs->chmod( $path, 0600 );

		return [ 'ok' => true, 'message' => 'Dario credentials imported.', 'path' => $path ];
	}

	private static function darioConfigDir(): string {
		$home = self::homeDir();
		return rtrim( $home, '/\\' ) . '/.dario';
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

	public static function helperScript( string $plugin_dir ): string {
		return rtrim( $plugin_dir, '/\\' ) . '/sidecar/dario-auth.mjs';
	}

	public static function darioCli( string $plugin_dir ): string {
		return rtrim( $plugin_dir, '/\\' ) . '/node_modules/@askalf/dario/dist/cli.js';
	}

	private static function nodeBinary(): string {
		$settings = DarioSettings::effective();
		$binary   = (string) ( $settings['node_binary'] ?? 'node' );
		return $binary !== '' ? $binary : 'node';
	}

	private static function sessionDir( string $plugin_dir ): string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$dir = rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/dario-provider/oauth';
			$fs  = self::fs();
			if ( ! $fs->is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			if ( $fs->is_dir( $dir ) && $fs->is_writable( $dir ) ) {
				return $dir;
			}
		}

		return rtrim( $plugin_dir, '/\\' ) . '/.oauth-sessions';
	}

	private static function generateSessionId(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return uniqid( 'sid', true );
		}
	}

	private static function saveSession( string $id, array $data ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::SESSION_TRANSIENT_PREFIX . $id, $data, self::SESSION_TTL );
		}
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function loadSession( string $id ): ?array {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$data = get_transient( self::SESSION_TRANSIENT_PREFIX . $id );
		return is_array( $data ) ? $data : null;
	}

	private static function cleanupSession( string $id ): void {
		$data = self::loadSession( $id );
		if ( is_array( $data ) ) {
			$pid  = (int) ( $data['pid'] ?? 0 );
			$fifo = (string) ( $data['fifo'] ?? '' );
			$log  = (string) ( $data['log'] ?? '' );
			$fs   = self::fs();

			if ( $pid > 0 ) {
				@shell_exec( 'kill ' . escapeshellarg( (string) $pid ) . ' 2>/dev/null' );
			}
			if ( $fifo !== '' && $fs->exists( $fifo ) ) {
				wp_delete_file( $fifo );
			}
			if ( $log !== '' && $fs->exists( $log ) ) {
				wp_delete_file( $log );
			}
		}

		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::SESSION_TRANSIENT_PREFIX . $id );
		}
	}

	private static function waitForAuthorizeUrl( string $log_path, int $microseconds ): ?string {
		$fs      = self::fs();
		$elapsed = 0;
		$step    = 200_000;
		while ( $elapsed < $microseconds ) {
			if ( $fs->is_file( $log_path ) ) {
				$contents = (string) $fs->get_contents( $log_path );
				$url      = self::extractAuthorizeUrl( $contents );
				if ( $url !== null ) {
					return $url;
				}
			}
			usleep( $step );
			$elapsed += $step;
		}

		return null;
	}

	public static function extractAuthorizeUrl( string $haystack ): ?string {
		if ( preg_match( '#https?://[^\s]+oauth/authorize[^\s]*#i', $haystack, $matches ) ) {
			return rtrim( $matches[0], ".,);" );
		}

		return null;
	}

	private static function waitForExit( int $pid, int $microseconds ): bool {
		if ( $pid <= 0 ) {
			return false;
		}

		$elapsed = 0;
		$step    = 200_000;
		while ( $elapsed < $microseconds ) {
			if ( ! self::processAlive( $pid ) ) {
				return true;
			}
			usleep( $step );
			$elapsed += $step;
		}

		return ! self::processAlive( $pid );
	}

	private static function processAlive( int $pid ): bool {
		if ( $pid <= 0 ) {
			return false;
		}

		if ( function_exists( 'posix_kill' ) ) {
			return @posix_kill( $pid, 0 );
		}

		$out = shell_exec( 'kill -0 ' . escapeshellarg( (string) $pid ) . ' 2>&1' );
		return is_string( $out ) && trim( $out ) === '';
	}

	private static function tailFile( string $path, int $bytes ): string {
		$fs = self::fs();
		if ( ! $fs->is_file( $path ) ) {
			return '';
		}

		// WP_Filesystem doesn't expose seekable reads, so we read the whole
		// file and slice. OAuth log files are bounded (a few KB at most), so
		// this is fine.
		$contents = (string) $fs->get_contents( $path );
		if ( strlen( $contents ) <= $bytes ) {
			return $contents;
		}
		return substr( $contents, -1 * $bytes );
	}

	private static function logIndicatesSuccess( string $log ): bool {
		if ( $log === '' ) {
			return false;
		}

		if ( stripos( $log, 'Token exchange failed' ) !== false ) {
			return false;
		}
		if ( stripos( $log, 'No authorization code entered' ) !== false ) {
			return false;
		}
		if ( stripos( $log, 'State mismatch' ) !== false ) {
			return false;
		}

		// Dario prints a success line containing "Authenticated" or "Logged in" on completion.
		return (bool) preg_match( '/(authenticated|logged in|login successful)/i', $log );
	}

	private static function lastJsonLine( string $output ): string {
		$lines = preg_split( '/\r?\n/', trim( $output ) );
		if ( ! is_array( $lines ) ) {
			return '';
		}

		for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
			$line = trim( (string) $lines[ $i ] );
			if ( $line !== '' && ( $line[0] === '{' || $line[0] === '[' ) ) {
				return $line;
			}
		}

		return '';
	}

	private static function commandExists( string $command ): bool {
		$output = shell_exec( 'command -v ' . escapeshellcmd( $command ) . ' 2>/dev/null' );
		return is_string( $output ) && trim( $output ) !== '';
	}
}
