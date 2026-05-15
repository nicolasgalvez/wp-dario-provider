<?php

/**
 * Plugin Name:       Dario AI Connector
 * Plugin URI:        https://github.com/procyon-creative/wp-dario-provider
 * Description:       Connects WordPress to AI models via the Dario local LLM router using the WordPress 7.0 Connectors API.
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Version:           0.1.5
 * Author:            Procyon Creative
 * Author URI:        https://github.com/procyon-creative
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       wp-dario-provider
 * Update URI:        https://github.com/procyon-creative/wp-dario-provider/
 *
 * @package Dario
 */

declare(strict_types=1);

namespace Dario;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use Dario\Admin\DarioSettingsPage;
use Dario\Sidecar\DarioSidecar;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, static function (): void {
	DarioSidecar::activate( __FILE__ );
} );

register_deactivation_hook( __FILE__, static function (): void {
	DarioSidecar::deactivate();
} );

if ( is_admin() ) {
	DarioSettingsPage::bootstrap( __FILE__ );
}

$dario_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/procyon-creative/wp-dario-provider/',
	__FILE__,
	'wp-dario-provider'
);
$dario_update_checker->getVcsApi()->enableReleaseAssets();
$dario_update_checker->setBranch( 'main' );

function register_provider(): void {
	if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
		return;
	}

	$registry = \WordPress\AiClient\AiClient::defaultRegistry();

	if ( $registry->hasProvider( \Dario\Provider\DarioProvider::class ) ) {
		return;
	}

	$registry->registerProvider( \Dario\Provider\DarioProvider::class );
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
