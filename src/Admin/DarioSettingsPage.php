<?php

declare(strict_types=1);

namespace Procyon\Dario\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Procyon\Dario\Sidecar\DarioBackendConfig;
use Procyon\Dario\Sidecar\DarioClaudeAuth;
use Procyon\Dario\Sidecar\DarioSidecar;

/**
 * Admin settings page for the Dario AI Connector.
 *
 * @since 0.1.5
 */
class DarioSettingsPage {

	public const MENU_SLUG               = 'dario-ai-connector';
	private const NONCE_ACTION           = 'dario_settings_action';
	private const NONCE_FIELD            = 'dario_settings_nonce';
	private const FLASH_OPTION           = 'procyon_dario_flash';
	private const OAUTH_TRANSIENT        = 'procyon_dario_oauth_active';
	private const AUTH_NOTICE_TRANSIENT  = 'procyon_dario_auth_status_cache';
	private const AUTH_NOTICE_TTL        = 60;

	private string $plugin_file;

	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	public static function bootstrap( string $plugin_file ): void {
		$page = new self( $plugin_file );
		add_action( 'admin_menu', [ $page, 'registerMenu' ] );
		add_action( 'admin_notices', [ $page, 'renderAuthNotice' ] );
		add_action( 'admin_post_procyon_dario_save_settings', [ $page, 'handleSaveSettings' ] );
		add_action( 'admin_post_procyon_dario_restart_sidecar', [ $page, 'handleRestartSidecar' ] );
		add_action( 'admin_post_procyon_dario_test_backend', [ $page, 'handleTestBackend' ] );
		add_action( 'admin_post_procyon_dario_remove_backend', [ $page, 'handleRemoveBackend' ] );
		add_action( 'admin_post_procyon_dario_oauth_start', [ $page, 'handleOAuthStart' ] );
		add_action( 'admin_post_procyon_dario_oauth_submit', [ $page, 'handleOAuthSubmit' ] );
		add_action( 'admin_post_procyon_dario_oauth_cancel', [ $page, 'handleOAuthCancel' ] );
		add_action( 'admin_post_procyon_dario_oauth_paste_creds', [ $page, 'handleOAuthPasteCreds' ] );
	}

	public function registerMenu(): void {
		add_options_page(
			__( 'Dario AI Connector', 'procyon-dario-provider' ),
			__( 'Dario AI Connector', 'procyon-dario-provider' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'procyon-dario-provider' ) );
		}

		$plugin_dir = dirname( $this->plugin_file );
		$settings   = DarioSettings::stored();
		$status     = DarioSidecar::status( $plugin_dir );
		$claude     = DarioClaudeAuth::status( $plugin_dir );
		$flash      = $this->consumeFlash();
		$oauth      = $this->getActiveOAuthSession();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Dario AI Connector', 'procyon-dario-provider' ) . '</h1>';

		if ( ! empty( $flash ) ) {
			$class = ! empty( $flash['error'] ) ? 'notice notice-error' : 'notice notice-success';
			echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( (string) $flash['message'] ) . '</p>';
			if ( ! empty( $flash['detail'] ) ) {
				echo '<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;">' . esc_html( (string) $flash['detail'] ) . '</pre>';
			}
			echo '</div>';
		}

		$this->renderStatusSection( $status, $claude );
		$this->renderClaudeAuthSection( $claude, $oauth );
		$this->renderSidecarRestartForm();
		$this->renderSettingsForm( $settings );
		$this->renderBackendActions( $settings );

		echo '</div>';
	}

	/**
	 * Site-wide admin notice when Claude is not authenticated. Suppressed on
	 * the settings page itself (the page already shows full status) and
	 * cached in a transient so we don't shell out to the helper script on
	 * every admin page load.
	 */
	public function renderAuthNotice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen instanceof \WP_Screen && false !== strpos( $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		$claude = get_transient( self::AUTH_NOTICE_TRANSIENT );
		if ( ! is_array( $claude ) ) {
			$claude = DarioClaudeAuth::status( dirname( $this->plugin_file ) );
			set_transient( self::AUTH_NOTICE_TRANSIENT, $claude, self::AUTH_NOTICE_TTL );
		}

		if ( ! empty( $claude['authenticated'] ) ) {
			return;
		}

		$status      = (string) ( $claude['status'] ?? 'none' );
		$is_warning  = in_array( $status, [ 'expiring', 'broken' ], true ) || ! empty( $claude['hasCredentials'] );
		$class       = $is_warning ? 'notice notice-warning' : 'notice notice-error';
		$message     = $is_warning
			? __( 'Dario AI Connector: Claude credentials are present but not currently valid. The AI provider will not work until you re-authenticate.', 'procyon-dario-provider' )
			: __( 'Dario AI Connector: Claude is not authenticated. The AI provider will not work until you log in.', 'procyon-dario-provider' );
		$url         = admin_url( 'options-general.php?page=' . self::MENU_SLUG );

		echo '<div class="' . esc_attr( $class ) . '"><p><strong>' . esc_html( $message ) . '</strong> ';
		echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Configure now →', 'procyon-dario-provider' ) . '</a></p></div>';
	}

	private function renderStatusSection( array $status, array $claude ): void {
		echo '<h2>' . esc_html__( 'Status', 'procyon-dario-provider' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px;">';
		$rows = [
			__( 'Plugin version', 'procyon-dario-provider' )    => [ 'value' => $this->pluginVersion() ],
			__( 'Node available', 'procyon-dario-provider' )    => [ 'value' => $status['node_available'] ? __( 'Yes', 'procyon-dario-provider' ) : __( 'No', 'procyon-dario-provider' ) ],
			__( 'Node version', 'procyon-dario-provider' )      => [ 'value' => $status['node_version'] ?? __( 'unknown', 'procyon-dario-provider' ) ],
			__( 'Sidecar running', 'procyon-dario-provider' )   => [ 'value' => $status['sidecar_running'] ? __( 'Yes', 'procyon-dario-provider' ) : __( 'No', 'procyon-dario-provider' ) ],
			__( 'Sidecar PID', 'procyon-dario-provider' )       => [ 'value' => $status['pid'] ? (string) $status['pid'] : __( 'unknown', 'procyon-dario-provider' ) ],
			__( 'Proxy URL', 'procyon-dario-provider' )         => [ 'value' => $status['proxy_url'] ],
			__( 'Claude auth', 'procyon-dario-provider' )       => [
				'value'    => $this->formatClaudeStatus( $claude ),
				'severity' => $this->claudeStatusSeverity( $claude ),
			],
		];
		foreach ( $rows as $label => $row ) {
			$severity   = $row['severity'] ?? 'ok';
			$style      = '';
			$show_icon  = false;
			if ( $severity === 'error' ) {
				$style     = 'color:#b32d2e;font-weight:600;';
				$show_icon = true;
			} elseif ( $severity === 'warning' ) {
				$style     = 'color:#996800;font-weight:600;';
				$show_icon = true;
			}
			echo '<tr><th scope="row" style="text-align:left;width:200px;">' . esc_html( (string) $label ) . '</th><td style="' . esc_attr( $style ) . '">';
			if ( $show_icon ) {
				echo '<span class="dashicons dashicons-warning" aria-hidden="true" style="margin-right:4px;"></span>';
			}
			echo esc_html( (string) $row['value'] );
			echo '</td></tr>';
		}
		echo '</table>';

		if ( ! empty( $status['log_tail'] ) ) {
			echo '<details style="margin-top:8px;"><summary>' . esc_html__( 'Recent sidecar log', 'procyon-dario-provider' ) . '</summary>';
			echo '<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;">' . esc_html( implode( "\n", (array) $status['log_tail'] ) ) . '</pre>';
			echo '</details>';
		}
	}

	/**
	 * @return string 'ok' | 'warning' | 'error'
	 */
	private function claudeStatusSeverity( array $claude ): string {
		if ( ! empty( $claude['error'] ) ) {
			return 'error';
		}
		if ( ! empty( $claude['authenticated'] ) ) {
			$status = (string) ( $claude['status'] ?? 'healthy' );
			return $status === 'expiring' ? 'warning' : 'ok';
		}
		return ! empty( $claude['hasCredentials'] ) ? 'warning' : 'error';
	}

	private function renderSidecarRestartForm(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
		echo '<input type="hidden" name="action" value="procyon_dario_restart_sidecar">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		submit_button( __( 'Restart sidecar', 'procyon-dario-provider' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function renderSettingsForm( array $settings ): void {
		echo '<h2 style="margin-top:24px;">' . esc_html__( 'Sidecar Settings', 'procyon-dario-provider' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="procyon_dario_save_settings">';

		echo '<table class="form-table">';
		$this->renderCheckboxRow( 'manage_sidecar', __( 'Manage Dario sidecar', 'procyon-dario-provider' ), (bool) $settings['manage_sidecar'] );
		$this->renderTextRow( 'node_binary', __( 'Node binary', 'procyon-dario-provider' ), (string) $settings['node_binary'] );
		$this->renderTextRow( 'proxy_host', __( 'Proxy host', 'procyon-dario-provider' ), (string) $settings['proxy_host'] );
		$this->renderNumberRow( 'proxy_port', __( 'Proxy port', 'procyon-dario-provider' ), (int) $settings['proxy_port'] );
		$this->renderSecretRow( 'proxy_api_key', __( 'Proxy API key', 'procyon-dario-provider' ), (string) $settings['proxy_api_key'], __( 'Sent to the local Dario proxy as DARIO_API_KEY. Leave blank to keep the existing key.', 'procyon-dario-provider' ) );
		echo '</table>';

		echo '<h2 style="margin-top:24px;">' . esc_html__( 'OpenAI-Compatible Backend', 'procyon-dario-provider' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Optional. Saves a backend file under ~/.dario/backends/<name>.json so Dario can route OpenAI-compat traffic.', 'procyon-dario-provider' ) . '</p>';
		echo '<table class="form-table">';
		$this->renderCheckboxRow( 'openai_backend_enabled', __( 'Write backend file on save', 'procyon-dario-provider' ), (bool) $settings['openai_backend_enabled'] );
		$this->renderTextRow( 'openai_backend_name', __( 'Backend name', 'procyon-dario-provider' ), (string) $settings['openai_backend_name'] );
		$this->renderTextRow( 'openai_base_url', __( 'Base URL', 'procyon-dario-provider' ), (string) $settings['openai_base_url'] );
		$this->renderSecretRow( 'openai_api_key', __( 'API key', 'procyon-dario-provider' ), (string) $settings['openai_api_key'], __( 'Stored as a WordPress option (not encrypted). Use a constant or environment variable for production. Leave blank to keep the existing key.', 'procyon-dario-provider' ) );
		$this->renderTextRow( 'openai_default_model', __( 'Default model (optional)', 'procyon-dario-provider' ), (string) $settings['openai_default_model'] );
		echo '</table>';

		submit_button( __( 'Save settings', 'procyon-dario-provider' ) );
		echo '</form>';
	}

	private function renderBackendActions( array $settings ): void {
		if ( empty( $settings['openai_backend_name'] ) ) {
			return;
		}

		$exists = DarioBackendConfig::exists( (string) $settings['openai_backend_name'] );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;display:inline-block;">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="procyon_dario_test_backend">';
		submit_button( __( 'Test backend (list models)', 'procyon-dario-provider' ), 'secondary', 'submit', false );
		echo '</form> ';

		if ( $exists ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			echo '<input type="hidden" name="action" value="procyon_dario_remove_backend">';
			submit_button( __( 'Remove backend file', 'procyon-dario-provider' ), 'delete', 'submit', false );
			echo '</form>';
		}
	}

	private function renderClaudeAuthSection( array $claude, ?array $oauth ): void {
		echo '<h2 style="margin-top:24px;">' . esc_html__( 'Claude Authentication', 'procyon-dario-provider' ) . '</h2>';
		echo '<p>' . esc_html__( 'Claude subscription auth uses Dario\'s OAuth flow. An API key cannot replace the OAuth login.', 'procyon-dario-provider' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Current status:', 'procyon-dario-provider' ) . '</strong> ' . esc_html( $this->formatClaudeStatus( $claude ) ) . '</p>';

		if ( $oauth !== null ) {
			$this->renderOAuthInProgress( $oauth );
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="procyon_dario_oauth_start">';
		submit_button( __( 'Start Claude login', 'procyon-dario-provider' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<details style="margin-top:12px;"><summary>' . esc_html__( 'Run from a shell instead', 'procyon-dario-provider' ) . '</summary>';
		echo '<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;">';
		echo esc_html( "# SSH\ndario login\n\n# Lando\nlando dario login\nlando wp plugin deactivate procyon-dario-provider\nlando wp plugin activate procyon-dario-provider" );
		echo '</pre></details>';

		echo '<details style="margin-top:12px;"><summary>' . esc_html__( 'Paste credentials.json from another machine', 'procyon-dario-provider' ) . '</summary>';
		echo '<p>' . esc_html__( 'Run `dario login` on a machine with a browser, then paste the contents of `~/.dario/credentials.json` below.', 'procyon-dario-provider' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="procyon_dario_oauth_paste_creds">';
		echo '<p><textarea name="credentials" rows="6" cols="80" placeholder=\'{"claudeAiOauth":{"accessToken":"...","refreshToken":"...","expiresAt":...,"scopes":["..."]}}\' required></textarea></p>';
		submit_button( __( 'Import credentials', 'procyon-dario-provider' ), 'secondary', 'submit', false );
		echo '</form></details>';
	}

	private function renderOAuthInProgress( array $oauth ): void {
		echo '<div class="notice notice-info" style="padding:12px;">';
		echo '<p><strong>' . esc_html__( 'Claude login in progress.', 'procyon-dario-provider' ) . '</strong></p>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Open this URL in any browser:', 'procyon-dario-provider' ) . '<br><a href="' . esc_url( (string) $oauth['authorize_url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $oauth['authorize_url'] ) . '</a></li>';
		echo '<li>' . esc_html__( 'Approve the request. Anthropic will display a code (format: code#state).', 'procyon-dario-provider' ) . '</li>';
		echo '<li>' . esc_html__( 'Paste the code below and click Submit.', 'procyon-dario-provider' ) . '</li>';
		echo '</ol>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="procyon_dario_oauth_submit">';
		echo '<input type="hidden" name="session_id" value="' . esc_attr( (string) $oauth['session_id'] ) . '">';
		echo '<p><textarea name="paste" rows="3" cols="80" placeholder="code#state" required></textarea></p>';
		submit_button( __( 'Submit code', 'procyon-dario-provider' ), 'primary', 'submit', false );
		echo ' ';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="procyon_dario_oauth_cancel">';
		echo '<input type="hidden" name="session_id" value="' . esc_attr( (string) $oauth['session_id'] ) . '">';
		submit_button( __( 'Cancel login attempt', 'procyon-dario-provider' ), 'delete', 'submit', false );
		echo '</form>';

		echo '</div>';
	}

	private function renderCheckboxRow( string $key, string $label, bool $value ): void {
		$disabled = DarioSettings::isOverridden( $key ) ? 'disabled' : '';
		echo '<tr><th scope="row"><label for="dario-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="checkbox" id="dario-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( $value, true, false ) . ' ' . esc_attr( $disabled ) . '>';
		$this->renderOverrideNote( $key );
		echo '</td></tr>';
	}

	private function renderTextRow( string $key, string $label, string $value ): void {
		$disabled = DarioSettings::isOverridden( $key ) ? 'disabled' : '';
		echo '<tr><th scope="row"><label for="dario-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="dario-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" ' . esc_attr( $disabled ) . '>';
		$this->renderOverrideNote( $key );
		echo '</td></tr>';
	}

	private function renderNumberRow( string $key, string $label, int $value ): void {
		$disabled = DarioSettings::isOverridden( $key ) ? 'disabled' : '';
		echo '<tr><th scope="row"><label for="dario-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" min="1" max="65535" id="dario-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" ' . esc_attr( $disabled ) . '>';
		$this->renderOverrideNote( $key );
		echo '</td></tr>';
	}

	private function renderSecretRow( string $key, string $label, string $stored, string $description ): void {
		$is_overridden  = DarioSettings::isOverridden( $key );
		$disabled       = $is_overridden ? 'disabled' : '';
		$has_value      = $stored !== '' || $is_overridden;
		// `*****` placeholder when a value (stored or override-supplied) exists,
		// otherwise empty so the field reads as unset. The actual stored bytes
		// are never echoed.
		$placeholder    = $has_value ? '*****' : '';
		echo '<tr><th scope="row"><label for="dario-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="password" class="regular-text" id="dario-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="new-password" ' . esc_attr( $disabled ) . '>';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
		$this->renderOverrideNote( $key );
		echo '</td></tr>';
	}

	private function renderOverrideNote( string $key ): void {
		if ( ! DarioSettings::isOverridden( $key ) ) {
			return;
		}

		$map      = DarioSettings::overrideMap();
		$constant = $map[ $key ] ?? '';
		echo '<p class="description"><em>' . esc_html(
			/* translators: %s: PHP constant or environment variable name (e.g. DARIO_PROXY_HOST). */
			sprintf( __( 'Overridden by %s constant or environment variable.', 'procyon-dario-provider' ), $constant )
		) . '</em></p>';
	}

	private function formatClaudeStatus( array $claude ): string {
		if ( ! empty( $claude['error'] ) ) {
			/* translators: %s: error message returned from the Dario auth status check. */
			return sprintf( __( 'Status check failed: %s', 'procyon-dario-provider' ), (string) $claude['error'] );
		}

		if ( ! empty( $claude['authenticated'] ) ) {
			$status = (string) ( $claude['status'] ?? 'healthy' );
			$expiry = ! empty( $claude['expiresIn'] ) ? sprintf( ' (%s)', (string) $claude['expiresIn'] ) : '';
			/* translators: 1: dario auth status word (healthy/expiring/etc.); 2: optional expiry text in parentheses. */
			return sprintf( __( 'Authenticated — %1$s%2$s', 'procyon-dario-provider' ), $status, $expiry );
		}

		if ( ! empty( $claude['hasCredentials'] ) ) {
			return __( 'Credentials present but not currently valid. Try running login again.', 'procyon-dario-provider' );
		}

		return __( 'Not authenticated.', 'procyon-dario-provider' );
	}

	public function handleSaveSettings(): void {
		$this->verifyRequest();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verifyRequest().
		$sanitized = DarioSettings::sanitize( wp_unslash( $_POST ) );
		DarioSettings::update( $sanitized );
		DarioSidecar::syncConnectorApiKey();

		$detail = '';
		if ( $sanitized['openai_backend_enabled'] && $sanitized['openai_api_key'] !== '' ) {
			$result = DarioBackendConfig::save(
				(string) $sanitized['openai_backend_name'],
				(string) $sanitized['openai_api_key'],
				(string) $sanitized['openai_base_url']
			);
			$detail = $result['ok']
				/* translators: %s: full filesystem path of the written backend JSON file. */
				? sprintf( __( 'Backend file written to %s.', 'procyon-dario-provider' ), (string) ( $result['path'] ?? '' ) )
				/* translators: %s: error message describing why the backend file could not be written. */
				: sprintf( __( 'Backend file not written: %s.', 'procyon-dario-provider' ), (string) ( $result['error'] ?? 'unknown error' ) );
		} elseif ( ! $sanitized['openai_backend_enabled'] ) {
			DarioBackendConfig::remove( (string) $sanitized['openai_backend_name'] );
		}

		$this->setFlash( __( 'Settings saved.', 'procyon-dario-provider' ), $detail );
		$this->redirectBack();
	}

	public function handleRestartSidecar(): void {
		$this->verifyRequest();
		$result = DarioSidecar::restart( dirname( $this->plugin_file ) );
		$this->setFlash( (string) $result['message'], '', ! $result['ok'] );
		$this->redirectBack();
	}

	public function handleTestBackend(): void {
		$this->verifyRequest();
		$base    = rtrim( DarioSidecar::baseUrl(), '/' );
		$timeout = 5;
		$response = wp_remote_get(
			$base . '/models',
			[
				'timeout' => $timeout,
				'headers' => [ 'Accept' => 'application/json' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->setFlash( __( 'Backend test failed.', 'procyon-dario-provider' ), $response->get_error_message(), true );
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$body   = (string) wp_remote_retrieve_body( $response );
			$ok     = $code >= 200 && $code < 300;
			$detail = sprintf( "HTTP %d\n%s", $code, substr( $body, 0, 1500 ) );
			$this->setFlash(
				$ok ? __( 'Backend reachable.', 'procyon-dario-provider' ) : __( 'Backend returned an error response.', 'procyon-dario-provider' ),
				$detail,
				! $ok
			);
		}

		$this->redirectBack();
	}

	public function handleRemoveBackend(): void {
		$this->verifyRequest();
		$settings = DarioSettings::stored();
		$removed  = DarioBackendConfig::remove( (string) $settings['openai_backend_name'] );
		$this->setFlash(
			$removed ? __( 'Backend file removed.', 'procyon-dario-provider' ) : __( 'No backend file to remove.', 'procyon-dario-provider' ),
			'',
			! $removed
		);
		$this->redirectBack();
	}

	public function handleOAuthStart(): void {
		$this->verifyRequest();
		$plugin_dir = dirname( $this->plugin_file );
		$result     = DarioClaudeAuth::startManualLogin( $plugin_dir );
		if ( ! $result['ok'] ) {
			$this->setFlash( (string) ( $result['message'] ?? __( 'Could not start login.', 'procyon-dario-provider' ) ), (string) ( $result['log_tail'] ?? '' ), true );
		} else {
			$this->saveActiveOAuthSession( [ 'session_id' => $result['session_id'], 'authorize_url' => $result['authorize_url'] ] );
			$this->setFlash( __( 'Login started. Open the authorize URL and paste the code below.', 'procyon-dario-provider' ) );
		}
		$this->redirectBack();
	}

	public function handleOAuthSubmit(): void {
		$this->verifyRequest();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verifyRequest().
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['session_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verifyRequest().
		$pasted = isset( $_POST['paste'] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $_POST['paste'] ) ) ) : '';

		$result = DarioClaudeAuth::submitManualCode( $session_id, $pasted );
		if ( $result['ok'] ) {
			$this->clearActiveOAuthSession();
			delete_transient( self::AUTH_NOTICE_TRANSIENT );
		}
		$this->setFlash( (string) $result['message'], (string) ( $result['log_tail'] ?? '' ), ! $result['ok'] );
		$this->redirectBack();
	}

	public function handleOAuthPasteCreds(): void {
		$this->verifyRequest();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verifyRequest().
		$pasted = isset( $_POST['credentials'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['credentials'] ) ) : '';
		$result = DarioClaudeAuth::importCredentialsJson( $pasted );
		if ( $result['ok'] ) {
			delete_transient( self::AUTH_NOTICE_TRANSIENT );
		}
		$this->setFlash(
			(string) $result['message'],
			isset( $result['path'] ) ? sprintf( 'Wrote %s', (string) $result['path'] ) : '',
			! $result['ok']
		);
		$this->redirectBack();
	}

	public function handleOAuthCancel(): void {
		$this->verifyRequest();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verifyRequest().
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['session_id'] ) ) : '';
		if ( $session_id !== '' ) {
			DarioClaudeAuth::cancelSession( $session_id );
		}
		$this->clearActiveOAuthSession();
		$this->setFlash( __( 'Login cancelled.', 'procyon-dario-provider' ) );
		$this->redirectBack();
	}

	private function verifyRequest(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'procyon-dario-provider' ) );
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'procyon-dario-provider' ) );
		}
	}

	private function redirectBack(): void {
		$url = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'options-general.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function setFlash( string $message, string $detail = '', bool $error = false ): void {
		update_option(
			self::FLASH_OPTION,
			[
				'message' => $message,
				'detail'  => $detail,
				'error'   => $error,
			],
			false
		);
	}

	/**
	 * @return array{message:string,detail:string,error:bool}|null
	 */
	private function consumeFlash(): ?array {
		$flash = get_option( self::FLASH_OPTION );
		if ( ! is_array( $flash ) || empty( $flash['message'] ) ) {
			return null;
		}
		delete_option( self::FLASH_OPTION );
		return [
			'message' => (string) $flash['message'],
			'detail'  => (string) ( $flash['detail'] ?? '' ),
			'error'   => (bool) ( $flash['error'] ?? false ),
		];
	}

	/**
	 * @return array{session_id:string,authorize_url:string}|null
	 */
	private function getActiveOAuthSession(): ?array {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}
		$value = get_transient( self::OAUTH_TRANSIENT );
		if ( ! is_array( $value ) || empty( $value['session_id'] ) || empty( $value['authorize_url'] ) ) {
			return null;
		}
		return [
			'session_id'    => (string) $value['session_id'],
			'authorize_url' => (string) $value['authorize_url'],
		];
	}

	private function saveActiveOAuthSession( array $session ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::OAUTH_TRANSIENT, $session, 600 );
		}
	}

	private function clearActiveOAuthSession(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::OAUTH_TRANSIENT );
		}
	}

	private function pluginVersion(): string {
		if ( function_exists( 'get_file_data' ) ) {
			$data = get_file_data( $this->plugin_file, [ 'Version' => 'Version' ] );
			if ( ! empty( $data['Version'] ) ) {
				return (string) $data['Version'];
			}
		}
		return 'unknown';
	}
}
