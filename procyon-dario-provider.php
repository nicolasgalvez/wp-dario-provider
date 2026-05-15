<?php

/**
 * Plugin Name:       Dario AI Connector
 * Plugin URI:        https://github.com/procyon-creative/wp-dario-provider
 * Description:       Connects WordPress to AI models via the Dario local LLM router using the WordPress 7.0 Connectors API.
 * Requires at least: 7.0
 * Requires PHP:      8.0
 * Version:           0.2.1
 * Author:            Procyon Creative
 * Author URI:        https://github.com/procyon-creative
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       procyon-dario-provider
 * Update URI:        https://github.com/procyon-creative/wp-dario-provider/
 *
 * @package Procyon\Dario
 */

declare(strict_types=1);

namespace Procyon\Dario;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use Procyon\Dario\Admin\DarioSettingsPage;
use Procyon\Dario\Sidecar\DarioSidecar;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, static function (): void {
	migrate_legacy_options();
	DarioSidecar::activate( __FILE__ );
} );

/**
 * One-time migration from the pre-0.2.0 option/transient names that used
 * `dario_*` prefixes to the procyon-prefixed names. Runs on activation;
 * idempotent (no-op if the new option already has a value or the legacy
 * option is missing).
 */
function migrate_legacy_options(): void {
	$option_map = [
		'dario_provider_settings'      => 'procyon_dario_settings',
		'dario_provider_sidecar_pid'   => 'procyon_dario_sidecar_pid',
		'dario_provider_flash'         => 'procyon_dario_flash',
		'dario_provider_oauth_active'  => 'procyon_dario_oauth_active',
		'connectors_ai_dario_api_key'  => 'connectors_ai_procyon_dario_api_key',
	];
	foreach ( $option_map as $old => $new ) {
		$legacy = get_option( $old, null );
		if ( $legacy === null || $legacy === false ) {
			continue;
		}
		// Only copy if the new option is unset; never clobber a value the user
		// already set after the rename.
		if ( get_option( $new, null ) === null ) {
			update_option( $new, $legacy, false );
		}
		delete_option( $old );
	}
}

register_deactivation_hook( __FILE__, static function (): void {
	DarioSidecar::deactivate();
} );

if ( is_admin() ) {
	DarioSettingsPage::bootstrap( __FILE__ );
}

$procyon_dario_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/procyon-creative/wp-dario-provider/',
	__FILE__,
	'procyon-dario-provider'
);
$procyon_dario_vcs_api = $procyon_dario_update_checker->getVcsApi();
if ( $procyon_dario_vcs_api instanceof \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\GitHubApi ) {
	$procyon_dario_vcs_api->enableReleaseAssets();
}
$procyon_dario_update_checker->setBranch( 'main' );

function register_provider(): void {
	if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
		return;
	}

	$registry = \WordPress\AiClient\AiClient::defaultRegistry();

	if ( $registry->hasProvider( \Procyon\Dario\Provider\DarioProvider::class ) ) {
		return;
	}

	$registry->registerProvider( \Procyon\Dario\Provider\DarioProvider::class );
}
add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );

// Backfill the WP connector API key option on every load if missing so
// the AI Client always has an auth interface to bind. Cheap (one
// get_option) when the option is already populated.
add_action( 'init', static function (): void {
	DarioSidecar::ensureConnectorApiKey();
}, 5 );

// Allow wp_safe_remote_* to reach the local Dario sidecar (loopback host
// + non-standard port). Filters are scoped to our configured host/port.
DarioSidecar::registerHttpFilters();
