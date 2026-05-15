<?php

declare(strict_types=1);

namespace Procyon\Dario\Sidecar\Concerns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lazy-loads a `WP_Filesystem_Direct` instance for code that needs to
 * read/write local files.
 *
 * Why direct (not the global $wp_filesystem singleton):
 *  - `WP_Filesystem()` auto-detects the method (direct, ftp, ssh, etc.).
 *    On hosts without write access, it can prompt for FTP credentials.
 *    Our writes target ~/.dario which is owned by the WP runtime user;
 *    direct method always works, no UI prompt needed.
 *  - Plugin Check (PCP) treats both forms as equivalent for the
 *    `WordPress.WP.AlternativeFunctions.file_system_operations_*` rules.
 *
 * @since 0.1.6
 */
trait WithFilesystem {

	/**
	 * @var \WP_Filesystem_Direct|null
	 */
	private static $fs_instance = null;

	private static function fs(): \WP_Filesystem_Direct {
		if ( self::$fs_instance === null ) {
			if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			}
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			self::$fs_instance = new \WP_Filesystem_Direct( null );
		}
		return self::$fs_instance;
	}
}
