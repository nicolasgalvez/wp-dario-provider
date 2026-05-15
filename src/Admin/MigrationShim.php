<?php

declare(strict_types=1);

namespace Procyon\Dario\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time migration from the pre-0.2.0 option/transient names that used
 * `dario_*` prefixes to the procyon-prefixed names.
 *
 * Runs from the activation hook in the main plugin file. Idempotent: skips
 * any new option that already has a value, and any legacy option that's
 * missing. Always deletes the legacy key after a successful copy.
 *
 * @since 0.2.0
 */
class MigrationShim {

	/**
	 * Map of pre-0.2.0 option names to their procyon-prefixed equivalents.
	 *
	 * @var array<string, string>
	 */
	private const OPTION_MAP = [
		'dario_provider_settings'      => 'procyon_dario_settings',
		'dario_provider_sidecar_pid'   => 'procyon_dario_sidecar_pid',
		'dario_provider_flash'         => 'procyon_dario_flash',
		'dario_provider_oauth_active'  => 'procyon_dario_oauth_active',
		'connectors_ai_dario_api_key'  => 'connectors_ai_procyon_dario_api_key',
	];

	public static function run(): void {
		foreach ( self::OPTION_MAP as $old => $new ) {
			$legacy = get_option( $old, null );
			if ( $legacy === null || $legacy === false ) {
				continue;
			}
			// Only copy if the new option is unset; never clobber a value the
			// user already set after the rename.
			if ( get_option( $new, null ) === null ) {
				update_option( $new, $legacy, false );
			}
			delete_option( $old );
		}
	}
}
