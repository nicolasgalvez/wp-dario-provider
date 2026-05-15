<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../wordpress/wp-includes/php-ai-client/autoload.php';

use Procyon\Dario\Metadata\DarioModelMetadataDirectory;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;

$directory = new DarioModelMetadataDirectory();

assert( $directory instanceof ModelMetadataDirectoryInterface );
assert( count( $directory->listModelMetadata() ) >= 1 );
assert( $directory->hasModelMetadata( 'gpt-4o' ) );
assert( 'gpt-4o' === $directory->getModelMetadata( 'gpt-4o' )->getId() );

$thrown = false;
try {
	$directory->getModelMetadata( 'missing-model' );
} catch ( InvalidArgumentException $exception ) {
	$thrown = true;
}

assert( $thrown );
