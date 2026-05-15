<?php

/**
 * Plugin Name:       Dario AI Connector
 * Plugin URI:        https://github.com/nicolasgalvez/wp-dario-provider
 * Description:       Connects WordPress to AI models via the Dario local LLM router using the WordPress 7.0 Connectors API.
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Version:           0.1.3
 * Author:            Nicolas Galvez
 * Author URI:        https://github.com/nicolasgalvez
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       wp-dario-provider
 * Update URI:        https://github.com/nicolasgalvez/wp-dario-provider/
 *
 * @package Dario
 */

declare(strict_types=1);

namespace Dario;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/autoload.php';

$my_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/nicolasgalvez/wp-dario-provider/',
	__FILE__,
	'wp-dario-provider'
);
$my_update_checker->getVcsApi()->enableReleaseAssets();
$my_update_checker->setBranch( 'main' );

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
