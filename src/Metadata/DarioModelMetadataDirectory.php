<?php

declare(strict_types=1);

namespace Dario\Metadata;

use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;

/**
 * Model metadata directory for the Dario provider.
 *
 * Dario is a local LLM router — it doesn't expose a /v1/models endpoint,
 * so we provide a curated list of commonly routed models.
 *
 * @since 0.1.0
 */
class DarioModelMetadataDirectory implements ModelMetadataDirectoryInterface {

	private const DEFAULT_MODELS = [
		[
			'id'          => 'claude-sonnet-4-6',
			'name'        => 'Claude Sonnet 4.6',
			'sort_order'  => 1,
		],
		[
			'id'          => 'claude-opus-4-7',
			'name'        => 'Claude Opus 4.7',
			'sort_order'  => 2,
		],
		[
			'id'          => 'gpt-4o',
			'name'        => 'GPT-4o',
			'sort_order'  => 3,
		],
		[
			'id'          => 'o3-mini',
			'name'        => 'o3-mini',
			'sort_order'  => 4,
		],
		[
			'id'          => 'gpt-4.1',
			'name'        => 'GPT-4.1',
			'sort_order'  => 5,
		],
		[
			'id'          => 'gpt-4.1-mini',
			'name'        => 'GPT-4.1 Mini',
			'sort_order'  => 6,
		],
		[
			'id'          => 'gpt-4.1-nano',
			'name'        => 'GPT-4.1 Nano',
			'sort_order'  => 7,
		],
	];

	public function listModelMetadata(): array {
		$capabilities = [
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		];

		$options = [
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::topK() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::outputMimeType(), [ 'text/plain', 'application/json' ] ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption(
				OptionEnum::inputModalities(),
				[
					[ ModalityEnum::text() ],
					[ ModalityEnum::text(), ModalityEnum::image() ],
				]
			),
			new SupportedOption( OptionEnum::outputModalities(), [ [ ModalityEnum::text() ] ] ),
		];

		$models = array_map(
			static function ( array $model_data ) use ( $capabilities, $options ): ModelMetadata {
				return new ModelMetadata(
					$model_data['id'],
					$model_data['name'],
					$capabilities,
					$options
				);
			},
			self::DEFAULT_MODELS
		);

		usort( $models, static function ( ModelMetadata $a, ModelMetadata $b ): int {
			$a_order = self::getSortOrder( $a->getId() );
			$b_order = self::getSortOrder( $b->getId() );
			return $a_order <=> $b_order;
		});

		return $models;
	}

	public function hasModelMetadata( string $model_id ): bool {
		foreach ( $this->listModelMetadata() as $model ) {
			if ( $model->getId() === $model_id ) {
				return true;
			}
		}
		return false;
	}

	public function getModelMetadata( string $model_id ): ModelMetadata {
		foreach ( $this->listModelMetadata() as $model ) {
			if ( $model->getId() === $model_id ) {
				return $model;
			}
		}

		throw new InvalidArgumentException(
			sprintf( 'No model with ID %s was found in the provider', $model_id )
		);
	}

	public function listModels(): array {
		return $this->listModelMetadata();
	}

	public function getModel( string $model_id ): ?ModelMetadata {
		try {
			return $this->getModelMetadata( $model_id );
		} catch ( InvalidArgumentException $exception ) {
			return null;
		}
	}

	private static function getSortOrder( string $model_id ): int {
		foreach ( self::DEFAULT_MODELS as $model ) {
			if ( $model['id'] === $model_id ) {
				return $model['sort_order'];
			}
		}
		return 99;
	}
}
