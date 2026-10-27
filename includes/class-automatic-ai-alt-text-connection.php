<?php
/**
 * The active AI connection: provider, key, model, and whether it has passed
 * a live test.
 *
 * Nothing is sent to an AI service until the saved provider, key and model
 * have passed "Save & test connection". The test result is stored as a
 * keyed hash of those three, so changing any of them needs a new test. A
 * rejected key, an empty account or a missing model later on pauses
 * everything the same way, so a broken setup can't keep making failing (or
 * billed) calls in the background.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connection state.
 */
final class Automatic_AI_Alt_Text_Connection {

	/**
	 * Option holding the test result.
	 */
	const OPTION = 'automatic_ai_alt_text_connection';

	/**
	 * Settings value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private static function setting( $key ) {
		return automatic_ai_alt_text_plugin()->get_setting( $key );
	}

	/**
	 * The chosen provider.
	 *
	 * @return string "openai" or "anthropic".
	 */
	public static function provider() {
		return 'anthropic' === self::setting( 'provider' ) ? 'anthropic' : 'openai';
	}

	/**
	 * Provider details.
	 *
	 * @param string $provider Provider key, default the chosen one.
	 * @return array<string,string>
	 */
	public static function info( $provider = '' ) {
		$providers = Automatic_AI_Alt_Text_AI::providers();
		$provider  = '' === $provider ? self::provider() : $provider;

		return isset( $providers[ $provider ] ) ? $providers[ $provider ] : $providers['openai'];
	}

	/**
	 * Whether a provider's key comes from a wp-config.php constant.
	 *
	 * @param string $provider Provider key.
	 * @return bool
	 */
	public static function key_from_constant( $provider ) {
		$info = self::info( $provider );

		return defined( $info['constant'] ) && '' !== (string) constant( $info['constant'] );
	}

	/**
	 * A provider's API key, in the clear. Only ever used to call the provider.
	 *
	 * @param string $provider Provider key, default the chosen one.
	 * @return string
	 */
	public static function key( $provider = '' ) {
		static $cache = array();

		$provider = '' === $provider ? self::provider() : $provider;

		if ( self::key_from_constant( $provider ) ) {
			return trim( (string) constant( self::info( $provider )['constant'] ) );
		}

		$stored = (string) self::setting( $provider . '_key' );

		if ( '' === $stored ) {
			return '';
		}

		// Decrypted once per request (the media grid asks for every image).
		if ( ! isset( $cache[ $stored ] ) ) {
			$cache[ $stored ] = Automatic_AI_Alt_Text_Crypto::decrypt( $stored );
		}

		return $cache[ $stored ];
	}

	/**
	 * Whether a key is stored but can't be decrypted any more (the site's
	 * security keys in wp-config.php changed).
	 *
	 * @param string $provider Provider key.
	 * @return bool
	 */
	public static function key_unreadable( $provider ) {
		return ! self::key_from_constant( $provider ) && '' !== (string) self::setting( $provider . '_key' ) && '' === self::key( $provider );
	}

	/**
	 * The last four characters of a saved key, for "ends in ..." hints.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	public static function key_hint( $provider ) {
		$key = self::key( $provider );

		return strlen( $key ) > 12 ? substr( $key, -4 ) : '';
	}

	/**
	 * The chosen model ID for a provider ("custom" resolves to the typed ID).
	 *
	 * @param string $provider Provider key, default the chosen one.
	 * @return string
	 */
	public static function model( $provider = '' ) {
		$provider = '' === $provider ? self::provider() : $provider;
		$model    = (string) self::setting( $provider . '_model' );

		if ( 'custom' === $model ) {
			return (string) self::setting( $provider . '_custom' );
		}

		return $model;
	}

	/**
	 * Display name of the chosen model.
	 *
	 * @param string $model Model ID, default the chosen one.
	 * @return string
	 */
	public static function model_label( $model = '' ) {
		$model = '' === $model ? self::model() : $model;

		foreach ( array_keys( Automatic_AI_Alt_Text_AI::providers() ) as $provider ) {
			$models = Automatic_AI_Alt_Text_AI::models( $provider );

			if ( isset( $models[ $model ] ) ) {
				$parts = explode( ':', $models[ $model ] );

				return trim( $parts[0] );
			}
		}

		return $model;
	}

	/**
	 * Keyed hash of the provider, model and key in use.
	 *
	 * @return string Empty when something is missing.
	 */
	public static function fingerprint() {
		$key   = self::key();
		$model = self::model();

		if ( '' === $key || '' === $model ) {
			return '';
		}

		return hash_hmac( 'sha256', self::provider() . '|' . $model . '|' . $key, wp_salt( 'auth' ) );
	}

	/**
	 * Stored test state.
	 *
	 * @return array{fp:string,time:int,sample:string,error:string,error_time:int,paused:int}
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'fp'         => '',
				'time'       => 0,
				'sample'     => '',
				'error'      => '',
				'error_time' => 0,
				'paused'     => 0,
			)
		);
	}

	/**
	 * Whether the saved settings have passed a test and nothing has gone
	 * wrong since. Everything that calls an AI service checks this first.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		$state = self::state();
		$fp    = self::fingerprint();

		return '' !== $fp && '' !== $state['fp'] && hash_equals( $state['fp'], $fp ) && ! $state['paused'];
	}

	/**
	 * Short status for the screens.
	 *
	 * @return string "ready", "untested", "paused" or "missing".
	 */
	public static function status() {
		if ( '' === self::key() || '' === self::model() ) {
			return 'missing';
		}

		$state = self::state();

		if ( '' === $state['fp'] || ! hash_equals( $state['fp'], self::fingerprint() ) ) {
			return 'untested';
		}

		return $state['paused'] ? 'paused' : 'ready';
	}

	/**
	 * Record a passed test for the current settings.
	 *
	 * @param string $sample The alt text the test produced.
	 * @return void
	 */
	public static function mark_ready( $sample ) {
		update_option(
			self::OPTION,
			array(
				'fp'         => self::fingerprint(),
				'time'       => time(),
				'sample'     => (string) $sample,
				'error'      => '',
				'error_time' => 0,
				'paused'     => 0,
			),
			false
		);
	}

	/**
	 * Record a failed test.
	 *
	 * @param string $message What went wrong.
	 * @return void
	 */
	public static function mark_failed( $message ) {
		update_option(
			self::OPTION,
			array(
				'fp'         => '',
				'time'       => 0,
				'sample'     => '',
				'error'      => (string) $message,
				'error_time' => time(),
				'paused'     => 0,
			),
			false
		);
	}

	/**
	 * Stop all AI calls after an error that won't fix itself (rejected key,
	 * no credit, missing model). A new test lifts it.
	 *
	 * @param string $message What went wrong.
	 * @return void
	 */
	public static function pause( $message ) {
		$state               = self::state();
		$state['paused']     = 1;
		$state['error']      = (string) $message;
		$state['error_time'] = time();

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Whether an error should pause everything.
	 *
	 * @param WP_Error $error Error from {@see Automatic_AI_Alt_Text_AI::describe()}.
	 * @return bool
	 */
	public static function is_fatal( WP_Error $error ) {
		return in_array( $error->get_error_code(), array( 'aaat_auth', 'aaat_quota', 'aaat_model' ), true );
	}

	/**
	 * Whether an error is worth retrying in a moment.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	public static function is_retryable( WP_Error $error ) {
		return in_array( $error->get_error_code(), array( 'aaat_rate', 'aaat_server', 'aaat_network' ), true );
	}
}
