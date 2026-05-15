<?php

declare(strict_types=1);

namespace Dario\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use Dario\Provider\DarioProvider;

/**
 * Text generation model that routes through the Dario proxy via OpenAI-compatible API.
 *
 * @since 0.1.0
 *
 * @phpstan-type UsageData array{
 *     prompt_tokens?: int,
 *     completion_tokens?: int,
 *     total_tokens?: int
 * }
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     choices?: list<array{index?: int, message?: array{role?: string, content?: string}, finish_reason?: string}>,
 *     usage?: UsageData
 * }
 */
class DarioTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {

	final public function generateTextResult( array $prompt ): GenerativeAiResult {
		$http_transporter = $this->getHttpTransporter();
		$params           = $this->prepareParams( $prompt );

		$request = new Request(
			HttpMethodEnum::POST(),
			DarioProvider::url( 'chat/completions' ),
			[ 'Content-Type' => 'application/json' ],
			$params,
			$this->getRequestOptions()
		);

		$request = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $http_transporter->send( $request );
		ResponseUtil::throwIfNotSuccessful( $response );

		return $this->parseResponse( $response );
	}

	private function prepareParams( array $prompt ): array {
		$config = $this->getConfig();

		$params = [
			'model'    => $this->metadata()->getId(),
			'messages' => $this->prepareMessages( $prompt ),
		];

		$system_instruction = $config->getSystemInstruction();
		if ( $system_instruction ) {
			array_unshift(
				$params['messages'],
				[ 'role' => 'system', 'content' => $system_instruction ]
			);
		}

		$max_tokens = $config->getMaxTokens();
		if ( $max_tokens !== null ) {
			$params['max_tokens'] = $max_tokens;
		}

		$temperature = $config->getTemperature();
		if ( $temperature !== null ) {
			$params['temperature'] = $temperature;
		}

		$top_p = $config->getTopP();
		if ( $top_p !== null ) {
			$params['top_p'] = $top_p;
		}

		$stop_sequences = $config->getStopSequences();
		if ( is_array( $stop_sequences ) ) {
			$params['stop'] = $stop_sequences;
		}

		$output_mime_type = $config->getOutputMimeType();
		$output_schema    = $config->getOutputSchema();
		if ( $output_mime_type === 'application/json' && $output_schema ) {
			$params['response_format'] = [
				'type'       => 'json_schema',
				'json_schema' => [ 'schema' => $output_schema ],
			];
		}

		foreach ( $config->getCustomOptions() as $key => $value ) {
			$params[ $key ] = $value;
		}

		return $params;
	}

	private function prepareMessages( array $messages ): array {
		return array_map(
			static function ( Message $message ): array {
				$role = $message->getRole()->isModel() ? 'assistant' : 'user';

				$parts = $message->getParts();
				$text_parts = array_filter(
					$parts,
					static fn( MessagePart $p ) => $p->getType()->isText()
				);

				if ( count( $text_parts ) === 1 ) {
					$content = reset( $text_parts )->getText();
				} else {
					$content = implode(
						"\n",
						array_map(
							static fn( MessagePart $p ) => $p->getText(),
							$text_parts
						)
					);
				}

				return [
					'role'    => $role,
					'content' => $content,
				];
			},
			$messages
		);
	}

	private function parseResponse( Response $response ): GenerativeAiResult {
		/** @var ResponseData $data */
		$data = $response->getData();

		if ( ! isset( $data['choices'] ) || ! $data['choices'] ) {
			throw ResponseException::fromMissingData( 'Dario', 'choices' );
		}

		$candidates = [];
		foreach ( $data['choices'] as $choice ) {
			$content = $choice['message']['content'] ?? '';
			$role     = ( $choice['message']['role'] ?? '' ) === 'user'
				? MessageRoleEnum::user()
				: MessageRoleEnum::model();

			$finish_reason_str = $choice['finish_reason'] ?? 'stop';
			$finish_reason     = match ( $finish_reason_str ) {
				'stop'       => FinishReasonEnum::stop(),
				'length'     => FinishReasonEnum::length(),
				'content_filter' => FinishReasonEnum::contentFilter(),
				'tool_calls' => FinishReasonEnum::toolCalls(),
				default      => FinishReasonEnum::stop(),
			};

			$candidates[] = new Candidate(
				new Message( $role, [ new MessagePart( $content ) ] ),
				$finish_reason
			);
		}

		$usage      = $data['usage'] ?? [];
		$token_usage = new TokenUsage(
			$usage['prompt_tokens'] ?? 0,
			$usage['completion_tokens'] ?? 0,
			$usage['total_tokens'] ?? 0
		);

		$additional = $data;
		unset( $additional['id'], $additional['choices'], $additional['usage'] );

		return new GenerativeAiResult(
			$data['id'] ?? '',
			$candidates,
			$token_usage,
			$this->providerMetadata(),
			$this->metadata(),
			$additional
		);
	}
}
