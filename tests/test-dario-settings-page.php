<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// The "Run from a shell instead" snippet on the settings page must show
// users only the supported shell command. Lando is a developer-only env;
// its commands don't belong in the admin UI (WPD-31).

$source = file_get_contents( __DIR__ . '/../src/Admin/DarioSettingsPage.php' );
assert( is_string( $source ) && '' !== $source, 'expected to read DarioSettingsPage.php' );

assert(
	false === stripos( $source, 'lando' ),
	'DarioSettingsPage.php must not reference lando (found a lando string in the source).'
);

assert(
	false !== strpos( $source, 'dario login' ),
	'DarioSettingsPage.php must still show `dario login` as the shell fallback.'
);

echo "test-dario-settings-page ok\n";
