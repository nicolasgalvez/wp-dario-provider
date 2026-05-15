<?php

declare(strict_types=1);

namespace Procyon\Dario\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use Procyon\Dario\Metadata\DarioModelMetadataDirectory;
use Procyon\Dario\Models\DarioTextGenerationModel;
use Procyon\Dario\Sidecar\DarioSidecar;

/**
 * Dario AI provider.
 *
 * Routes AI requests through the Dario local LLM proxy, which supports
 * OpenAI-compatible and Anthropic-compatible backends.
 *
 * @since 0.1.0
 */
class DarioProvider extends AbstractApiProvider {

	/**
	 * Returns the base URL for the Dario API.
	 *
	 * Resolution order:
	 *   1. `DARIO_BASE_URL` constant
	 *   2. `DARIO_BASE_URL` environment variable
	 *   3. Settings-derived sidecar URL (host/port from plugin settings)
	 *   4. `http://127.0.0.1:3456/v1`
	 *
	 * @since 0.1.0
	 *
	 * @return string The Dario API base URL.
	 */
	protected static function baseUrl(): string {
		if ( defined( 'DARIO_BASE_URL' ) ) {
			return DARIO_BASE_URL;
		}

		$env_url = getenv( 'DARIO_BASE_URL' );
		if ( $env_url ) {
			return $env_url;
		}

		if ( class_exists( DarioSidecar::class ) ) {
			return DarioSidecar::baseUrl();
		}

		return 'http://127.0.0.1:3456/v1';
	}

	/**
	 * Creates a model instance based on the model metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param ModelMetadata   $model_metadata   The model metadata.
	 * @param ProviderMetadata $provider_metadata The provider metadata.
	 * @return ModelInterface The model instance.
	 *
	 * @throws RuntimeException If the model capabilities are unsupported.
	 */
	protected static function createModel(
		ModelMetadata $model_metadata,
		ProviderMetadata $provider_metadata
	): ModelInterface {
		$capabilities = $model_metadata->getSupportedCapabilities();
		foreach ( $capabilities as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new DarioTextGenerationModel( $model_metadata, $provider_metadata );
			}
		}

		throw new RuntimeException(
			esc_html( 'Unsupported model capabilities: ' . implode( ', ', $capabilities ) )
		);
	}

	/**
	 * Creates the Dario provider metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderMetadata The provider metadata.
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		$provider_metadata_args = [
			'procyon_dario',
			'Dario',
			ProviderTypeEnum::cloud(),
			'https://github.com/askalf/dario',
			RequestAuthenticationMethod::apiKey(),
		];

		// Provider description support was added in php-ai-client 1.2.0.
		if ( class_exists( 'WordPress\AiClient\AiClient' ) && defined( 'WordPress\AiClient\AiClient::VERSION' ) ) {
			if ( version_compare( \WordPress\AiClient\AiClient::VERSION, '1.2.0', '>=' ) ) {
				if ( function_exists( '__' ) ) {
					$provider_metadata_args[] = __( 'Local LLM router supporting Claude, GPT, and OpenAI-compatible backends.', 'procyon-dario-provider' );
				} else {
					$provider_metadata_args[] = 'Local LLM router supporting Claude, GPT, and OpenAI-compatible backends.';
				}
			}
		}

		return new ProviderMetadata( ...$provider_metadata_args );
	}

	/**
	 * Creates the provider availability checker.
	 *
	 * Checks availability by attempting to list models from the Dario API.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderAvailabilityInterface The availability checker.
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new ListModelsApiBasedProviderAvailability(
			static::modelMetadataDirectory()
		);
	}

	/**
	 * Creates the model metadata directory for Dario.
	 *
	 * @since 0.1.0
	 *
	 * @return ModelMetadataDirectoryInterface The model metadata directory.
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new DarioModelMetadataDirectory();
	}
}
