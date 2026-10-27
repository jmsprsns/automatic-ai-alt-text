<?php
/**
 * The two AI providers: their models, list prices and the HTTP calls.
 *
 * Both are called straight over HTTPS with wp_remote_post() (no SDKs), so
 * the plugin runs on PHP 7.2 and doesn't add any libraries to the site. The
 * hosts are fixed, the image goes up inline (base64) from the site's own
 * files, and the reply is treated as untrusted text by the generator.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provider catalog and requests.
 */
final class Automatic_AI_Alt_Text_AI {

	/**
	 * OpenAI Chat Completions endpoint.
	 */
	const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Anthropic Messages endpoint.
	 */
	const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * Anthropic API version sent with every request.
	 */
	const ANTHROPIC_VERSION = '2026-10-21';

	/**
	 * Room for the reply. Reasoning models count their thinking against this,
	 * so it's generous; only the tokens actually used are billed.
	 */
	const MAX_TOKENS = 2000;

	/**
	 * Typical tokens per image, for the "per 1,000 images" estimates before
	 * any real usage is measured: a ~768px image plus the prompt in, a short
	 * JSON reply (and a little reasoning) out.
	 */
	const EST_INPUT  = 1000;
	const EST_OUTPUT = 150;

	/**
	 * The two providers.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function providers() {
		return array(
			'openai'    => array(
				'label'    => __( 'ChatGPT', 'automatic-ai-alt-text' ),
				'company'  => __( 'OpenAI', 'automatic-ai-alt-text' ),
				'icon'     => 'bot-message-square',
				'keys'     => 'https://platform.openai.com/api-keys',
				'usage'    => 'https://platform.openai.com/usage',
				'limits'   => 'https://platform.openai.com/settings/organization/limits',
				'prefix'   => 'sk-',
				'constant' => 'AUTOMATIC_AI_ALT_TEXT_OPENAI_KEY',
			),
			'anthropic' => array(
				'label'    => __( 'Claude', 'automatic-ai-alt-text' ),
				'company'  => __( 'Anthropic', 'automatic-ai-alt-text' ),
				'icon'     => 'sparkle',
				'keys'     => 'https://platform.claude.com/settings/keys',
				'usage'    => 'https://platform.claude.com/usage',
				'limits'   => 'https://platform.claude.com/settings/limits',
				'prefix'   => 'sk-ant-',
				'constant' => 'AUTOMATIC_AI_ALT_TEXT_ANTHROPIC_KEY',
			),
		);
	}

	/**
	 * Models offered in each provider's dropdown, cheapest first.
	 *
	 * @param string $provider Provider key.
	 * @return array<string,string> Model ID => label.
	 */
	public static function models( $provider ) {
		if ( 'anthropic' === $provider ) {
			return array(
				'claude-haiku-5-5'  => __( 'Claude Haiku 5.5: fastest, lowest cost', 'automatic-ai-alt-text' ),
				'claude-sonnet-5-5' => __( 'Claude Sonnet 5.5: balanced', 'automatic-ai-alt-text' ),
				'claude-opus-5-5'   => __( 'Claude Opus 5.5: most capable', 'automatic-ai-alt-text' ),
			);
		}

		return array(
			'gpt-6-luna'    => __( 'GPT-6 Luna: fastest, lowest cost', 'automatic-ai-alt-text' ),
			'gpt-6.1-terra' => __( 'GPT-6.1 Terra: low cost', 'automatic-ai-alt-text' ),
			'gpt-6.1-sol'   => __( 'GPT-6.1 Sol: balanced', 'automatic-ai-alt-text' ),
			'gpt-6-astra'   => __( 'GPT-6 Astra: flagship, highest cost', 'automatic-ai-alt-text' ),
		);
	}

	/**
	 * List prices in US dollars per million tokens (input, output), as
	 * published in October 2026. Older models are here too so a custom model
	 * ID still gets a cost estimate. Providers change prices; the
	 * provider's own billing page is always the real number.
	 *
	 * @return array<string,array{0:float,1:float}>
	 */
	public static function prices() {
		$prices = array(
			// Anthropic.
			'claude-haiku-5-5'  => array( 0.10, 0.50 ),
			'claude-sonnet-5-5' => array( 2.00, 10.00 ),
			'claude-opus-5-5'   => array( 4.00, 20.00 ),
			'claude-fable-5-1'  => array( 10.00, 50.00 ),
			'claude-fable-5'    => array( 10.00, 50.00 ),
			'claude-opus-5'     => array( 5.00, 25.00 ),
			'claude-sonnet-5'   => array( 2.00, 10.00 ),
			'claude-opus-4-8'   => array( 5.00, 25.00 ),
			'claude-opus-4-7'   => array( 5.00, 25.00 ),
			'claude-opus-4-6'   => array( 5.00, 25.00 ),
			'claude-sonnet-4-6' => array( 3.00, 15.00 ),
			'claude-haiku-4-5'  => array( 1.00, 5.00 ),
			// OpenAI.
			'gpt-6-luna'        => array( 0.10, 0.50 ),
			'gpt-6.1-terra'     => array( 1.00, 5.00 ),
			'gpt-6.1-sol'       => array( 2.00, 10.00 ),
			'gpt-6-astra'       => array( 10.00, 50.00 ),
			'gpt-5.4'           => array( 2.50, 15.00 ),
			'gpt-5.4-mini'      => array( 0.75, 4.50 ),
			'gpt-5.4-nano'      => array( 0.20, 1.25 ),
			'gpt-5.1'           => array( 1.25, 10.00 ),
			'gpt-5'             => array( 1.25, 10.00 ),
			'gpt-5-mini'        => array( 0.25, 2.00 ),
			'gpt-5-nano'        => array( 0.05, 0.40 ),
			'gpt-4.1'           => array( 2.00, 8.00 ),
			'gpt-4.1-mini'      => array( 0.40, 1.60 ),
			'gpt-4.1-nano'      => array( 0.10, 0.40 ),
			'gpt-4o'            => array( 2.50, 10.00 ),
			'gpt-4o-mini'       => array( 0.15, 0.60 ),
		);

		/**
		 * Filters the per-million-token prices used for cost estimates.
		 *
		 * @param array<string,array{0:float,1:float}> $prices Model ID => array( input, output ) in USD.
		 */
		return (array) apply_filters( 'automatic_ai_alt_text_prices', $prices );
	}

	/**
	 * A model's price, matching dated snapshots ("gpt-5-mini-2025-08-07") to
	 * their base model.
	 *
	 * @param string $model Model ID.
	 * @return array{0:float,1:float}|null Null when unknown.
	 */
	public static function price( $model ) {
		$prices = self::prices();
		$model  = strtolower( (string) $model );

		if ( isset( $prices[ $model ] ) ) {
			return $prices[ $model ];
		}

		$base = preg_replace( '/-(?:\d{4}-\d{2}-\d{2}|\d{8}|latest)$/D', '', $model );

		return isset( $prices[ $base ] ) ? $prices[ $base ] : null;
	}

	/**
	 * Cost of one call in millionths of a dollar.
	 *
	 * @param string $model  Model ID.
	 * @param int    $input  Input tokens.
	 * @param int    $output Output tokens.
	 * @return int|null Null when the model's price is unknown.
	 */
	public static function cost_micros( $model, $input, $output ) {
		$price = self::price( $model );

		if ( null === $price ) {
			return null;
		}

		// Dollars per million tokens times tokens = millionths of a dollar.
		return (int) round( (int) $input * $price[0] + (int) $output * $price[1] );
	}

	/**
	 * Rough cost of 1,000 images with a model, before anything is measured.
	 *
	 * @param string $model Model ID.
	 * @return float|null Dollars.
	 */
	public static function estimate_per_thousand( $model ) {
		$micros = self::cost_micros( $model, self::EST_INPUT, self::EST_OUTPUT );

		return null === $micros ? null : $micros / 1000;
	}

	/**
	 * Format a dollar amount for display: more decimals for tiny amounts.
	 *
	 * @param float $dollars Amount.
	 * @return string
	 */
	public static function money( $dollars ) {
		$dollars = (float) $dollars;

		if ( $dollars <= 0 ) {
			return '$0.00';
		}

		if ( $dollars < 0.01 ) {
			return '$' . rtrim( rtrim( number_format( $dollars, 5, '.', '' ), '0' ), '.' );
		}

		return '$' . number_format_i18n( $dollars, 2 );
	}

	/**
	 * Ask a provider to describe one image.
	 *
	 * @param string $provider Provider key.
	 * @param string $key      API key.
	 * @param string $model    Model ID.
	 * @param array  $prompt   array( 'system' => string, 'user' => string ).
	 * @param array  $image    array( 'data' => base64, 'mime' => string ).
	 * @param array  $options  array( 'timeout' => int, 'detail' => 'low'|'auto' ).
	 * @return array{text:string,input_tokens:int,output_tokens:int}|WP_Error
	 */
	public static function describe( $provider, $key, $model, array $prompt, array $image, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'timeout' => 45,
				'detail'  => 'auto',
			)
		);

		if ( '' === (string) $key ) {
			return new WP_Error( 'aaat_auth', __( 'No API key is saved.', 'automatic-ai-alt-text' ) );
		}

		if ( '' === (string) $model ) {
			return new WP_Error( 'aaat_model', __( 'No model is chosen.', 'automatic-ai-alt-text' ) );
		}

		if ( 'anthropic' === $provider ) {
			return self::anthropic( $key, $model, $prompt, $image, $options );
		}

		return self::openai( $key, $model, $prompt, $image, $options );
	}

	/**
	 * OpenAI Chat Completions call.
	 *
	 * @param string $key     API key.
	 * @param string $model   Model ID.
	 * @param array  $prompt  System and user text.
	 * @param array  $image   Base64 image and its type.
	 * @param array  $options Timeout and detail.
	 * @return array|WP_Error
	 */
	private static function openai( $key, $model, array $prompt, array $image, array $options ) {
		$body = array(
			'model'                 => $model,
			'messages'              => array(
				array(
					'role'    => 'system',
					'content' => $prompt['system'],
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $prompt['user'],
						),
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url'    => 'data:' . $image['mime'] . ';base64,' . $image['data'],
								'detail' => 'low' === $options['detail'] ? 'low' : 'auto',
							),
						),
					),
				),
			),
			'max_completion_tokens' => self::MAX_TOKENS,
		);

		$effort = self::openai_effort( $model );

		if ( '' !== $effort ) {
			$body['reasoning_effort'] = $effort;
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		);

		$response = self::post( self::OPENAI_URL, $headers, $body, $options['timeout'] );

		// Older or custom models can reject the newer parameters: retry once
		// without them rather than failing.
		if ( is_wp_error( $response ) && 'aaat_request' === $response->get_error_code() ) {
			$message = strtolower( $response->get_error_message() );
			$retry   = false;

			if ( isset( $body['reasoning_effort'] ) && false !== strpos( $message, 'reasoning' ) ) {
				unset( $body['reasoning_effort'] );
				$retry = true;
			}

			if ( false !== strpos( $message, 'max_completion_tokens' ) ) {
				unset( $body['max_completion_tokens'] );
				$body['max_tokens'] = self::MAX_TOKENS;
				$retry              = true;
			}

			if ( $retry ) {
				$response = self::post( self::OPENAI_URL, $headers, $body, $options['timeout'] );
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$choice  = isset( $response['choices'][0] ) && is_array( $response['choices'][0] ) ? $response['choices'][0] : array();
		$message = isset( $choice['message'] ) && is_array( $choice['message'] ) ? $choice['message'] : array();

		if ( ! empty( $message['refusal'] ) ) {
			return new WP_Error( 'aaat_refused', __( 'The AI declined to describe this image.', 'automatic-ai-alt-text' ) );
		}

		$text = isset( $message['content'] ) && is_string( $message['content'] ) ? $message['content'] : '';

		if ( '' === trim( $text ) ) {
			$finish = isset( $choice['finish_reason'] ) ? (string) $choice['finish_reason'] : '';

			return new WP_Error(
				'aaat_empty',
				'length' === $finish
					? __( 'The AI ran out of room before answering. Try a different model.', 'automatic-ai-alt-text' )
					: __( 'The AI sent back an empty answer.', 'automatic-ai-alt-text' )
			);
		}

		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();

		return array(
			'text'          => $text,
			'input_tokens'  => isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0,
			'output_tokens' => isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0,
		);
	}

	/**
	 * Reasoning effort for an OpenAI model: as little thinking as the model
	 * allows, since describing a picture doesn't need it and thinking tokens
	 * are billed. Empty for models that don't take the parameter.
	 *
	 * @param string $model Model ID.
	 * @return string
	 */
	private static function openai_effort( $model ) {
		$model = strtolower( $model );

		if ( preg_match( '/^gpt-6(?:\.\d+)?-(?:luna|terra)\b/', $model ) || preg_match( '/^gpt-5\.[1-9]/', $model ) ) {
			return 'none';
		}

		if ( preg_match( '/^(?:gpt-[5-9]|o[1-9])/', $model ) ) {
			return 'low';
		}

		return '';
	}

	/**
	 * Anthropic Messages call.
	 *
	 * @param string $key     API key.
	 * @param string $model   Model ID.
	 * @param array  $prompt  System and user text.
	 * @param array  $image   Base64 image and its type.
	 * @param array  $options Timeout.
	 * @return array|WP_Error
	 */
	private static function anthropic( $key, $model, array $prompt, array $image, array $options ) {
		$body = array(
			'model'      => $model,
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $prompt['system'],
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => $image['mime'],
								'data'       => $image['data'],
							),
						),
						array(
							'type' => 'text',
							'text' => $prompt['user'],
						),
					),
				),
			),
		);

		// Low effort keeps thinking (and its cost) to a minimum on the models
		// that think by default. Older models don't accept the setting.
		if ( preg_match( '/^claude-(?:(?:opus|sonnet|haiku|fable|mythos)-[5-9]|opus-4-[5-9]|sonnet-4-6)/', strtolower( $model ) ) ) {
			$body['output_config'] = array( 'effort' => 'low' );
		}

		$headers = array(
			'x-api-key'         => $key,
			'anthropic-version' => self::ANTHROPIC_VERSION,
			'Content-Type'      => 'application/json',
		);

		$response = self::post( self::ANTHROPIC_URL, $headers, $body, $options['timeout'] );

		if ( is_wp_error( $response ) && 'aaat_request' === $response->get_error_code() && isset( $body['output_config'] ) && false !== stripos( $response->get_error_message(), 'effort' ) ) {
			unset( $body['output_config'] );
			$response = self::post( self::ANTHROPIC_URL, $headers, $body, $options['timeout'] );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$stop = isset( $response['stop_reason'] ) ? (string) $response['stop_reason'] : '';

		if ( 'refusal' === $stop ) {
			return new WP_Error( 'aaat_refused', __( 'The AI declined to describe this image.', 'automatic-ai-alt-text' ) );
		}

		$text = '';

		foreach ( isset( $response['content'] ) && is_array( $response['content'] ) ? $response['content'] : array() as $block ) {
			if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] && is_string( $block['text'] ) ) {
				$text .= $block['text'];
			}
		}

		if ( '' === trim( $text ) ) {
			return new WP_Error(
				'aaat_empty',
				'max_tokens' === $stop
					? __( 'The AI ran out of room before answering. Try a different model.', 'automatic-ai-alt-text' )
					: __( 'The AI sent back an empty answer.', 'automatic-ai-alt-text' )
			);
		}

		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		$input = 0;

		foreach ( array( 'input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens' ) as $field ) {
			$input += isset( $usage[ $field ] ) ? (int) $usage[ $field ] : 0;
		}

		return array(
			'text'          => $text,
			'input_tokens'  => $input,
			'output_tokens' => isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0,
		);
	}

	/**
	 * POST JSON and decode the reply, turning every failure into a WP_Error
	 * whose code says what the caller should do about it:
	 *
	 * - aaat_auth, aaat_quota, aaat_model: stop everything until the settings change
	 * - aaat_rate, aaat_server, aaat_network: try again shortly (data: retry_after)
	 * - aaat_request: this one image or request failed
	 *
	 * @param string $url     Endpoint (one of the class constants).
	 * @param array  $headers Request headers.
	 * @param array  $body    JSON body.
	 * @param int    $timeout Seconds.
	 * @return array|WP_Error Decoded reply.
	 */
	private static function post( $url, array $headers, array $body, $timeout ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => max( 5, (int) $timeout ),
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
				'user-agent'  => 'AutomaticAIAltText/' . AUTOMATIC_AI_ALT_TEXT_VERSION . '; WordPress',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'aaat_network',
				/* translators: %s: error from the HTTP library. */
				sprintf( __( 'Couldn\'t reach the AI service: %s', 'automatic-ai-alt-text' ), self::clip( $response->get_error_message() ) ),
				array( 'retry_after' => 10 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 && is_array( $data ) ) {
			return $data;
		}

		$error   = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		$message = isset( $error['message'] ) && is_string( $error['message'] ) ? self::clip( $error['message'] ) : '';
		$code    = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : '';
		$type    = isset( $error['type'] ) && is_string( $error['type'] ) ? $error['type'] : '';
		$retry   = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		$retry   = $retry > 0 ? min( 60, $retry ) : 0;

		if ( $status >= 200 && $status < 300 ) {
			return new WP_Error( 'aaat_server', __( 'The AI service sent back something unexpected.', 'automatic-ai-alt-text' ), array( 'retry_after' => 10 ) );
		}

		if ( 401 === $status || 403 === $status || 'authentication_error' === $type || 'permission_error' === $type ) {
			return new WP_Error(
				'aaat_auth',
				'' !== $message ? $message : __( 'The API key was rejected.', 'automatic-ai-alt-text' )
			);
		}

		if ( 'insufficient_quota' === $code || 'billing_error' === $type || false !== stripos( $message, 'credit balance' ) || false !== stripos( $message, 'exceeded your current quota' ) ) {
			return new WP_Error(
				'aaat_quota',
				'' !== $message ? $message : __( 'Your AI account is out of credit.', 'automatic-ai-alt-text' )
			);
		}

		if ( 404 === $status || 'not_found_error' === $type || 'model_not_found' === $code ) {
			return new WP_Error(
				'aaat_model',
				'' !== $message ? $message : __( 'That model doesn\'t exist or your account can\'t use it.', 'automatic-ai-alt-text' )
			);
		}

		if ( 429 === $status || 'rate_limit_error' === $type ) {
			return new WP_Error(
				'aaat_rate',
				'' !== $message ? $message : __( 'The AI service is rate limiting requests.', 'automatic-ai-alt-text' ),
				array( 'retry_after' => $retry ? $retry : 20 )
			);
		}

		if ( $status >= 500 || 'overloaded_error' === $type || 'api_error' === $type ) {
			return new WP_Error(
				'aaat_server',
				'' !== $message ? $message : __( 'The AI service is having problems.', 'automatic-ai-alt-text' ),
				array( 'retry_after' => $retry ? $retry : 15 )
			);
		}

		return new WP_Error(
			'aaat_request',
			'' !== $message
				? $message
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'The AI service refused the request (HTTP %d).', 'automatic-ai-alt-text' ), $status )
		);
	}

	/**
	 * Shorten a provider message for logs and screens.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	private static function clip( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 300 ) : substr( $text, 0, 300 );
	}
}
