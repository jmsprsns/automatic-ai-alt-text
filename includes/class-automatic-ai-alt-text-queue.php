<?php
/**
 * New uploads: writes alt text for images as they're added to the library.
 *
 * By default each new image is queued and handled a moment later by
 * WP-Cron, so uploading stays exactly as fast as before. The "right away"
 * option instead asks the AI while the upload is still being processed, so
 * the alt text is already there when the image lands in the editor.
 *
 * Only runs once a provider has passed a live test, and stops at the daily
 * limit so a bulk import (WooCommerce products, a migration) can't quietly
 * send thousands of images to a paid API.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Upload queue.
 */
final class Automatic_AI_Alt_Text_Queue {

	/**
	 * Cron hook that works through the queue.
	 */
	const HOOK = 'automatic_ai_alt_text_process';

	/**
	 * Transient that stops two runs overlapping.
	 */
	const LOCK = 'automatic_ai_alt_text_lock';

	/**
	 * Option counting today's automatic images.
	 */
	const DAILY = 'automatic_ai_alt_text_daily';

	/**
	 * Images per cron run, and attempts before giving up on one.
	 */
	const BATCH    = 10;
	const ATTEMPTS = 3;

	/**
	 * Images uploaded in this request waiting for their metadata ("right away" mode).
	 *
	 * @var array<int,bool>
	 */
	private static $pending = array();

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_attachment', array( __CLASS__, 'on_upload' ) );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_metadata' ), 20, 2 );
		add_action( self::HOOK, array( __CLASS__, 'process' ) );
	}

	/**
	 * Setting shortcut.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private static function setting( $key ) {
		return automatic_ai_alt_text_plugin()->get_setting( $key );
	}

	/**
	 * Whether new uploads get alt text right now.
	 *
	 * @return bool
	 */
	public static function is_on() {
		return (bool) self::setting( 'auto' ) && ( self::setting( 'alt' ) || self::setting( 'title' ) ) && Automatic_AI_Alt_Text_Connection::is_ready();
	}

	/**
	 * A new attachment was added.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	public static function on_upload( $id ) {
		if ( ! self::is_on() || ! Automatic_AI_Alt_Text_Generator::is_image( $id ) ) {
			return;
		}

		// Images added by the site's own users (and imports run from WP-CLI
		// or cron) count. Images visitors send through a front-end form or
		// review plugin don't, so strangers can't spend the site's AI budget.
		$trusted = ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || current_user_can( 'upload_files' );

		/**
		 * Filters whether a new upload gets AI alt text.
		 *
		 * @param bool $generate Whether to generate. False by default for
		 *                       uploads from visitors who can't upload files.
		 * @param int  $id       Attachment ID.
		 */
		if ( ! apply_filters( 'automatic_ai_alt_text_auto_generate', $trusted, $id ) ) {
			return;
		}

		if ( 'upload' === self::setting( 'timing' ) ) {
			// Wait for the resized copies, then ask (see on_metadata()).
			self::$pending[ (int) $id ] = true;

			if ( 1 === count( self::$pending ) ) {
				add_action( 'shutdown', array( __CLASS__, 'queue_leftovers' ) );
			}

			return;
		}

		self::enqueue( $id );
	}

	/**
	 * "Right away" mode: write the text once WordPress has made the resized
	 * copies, so a small one can be sent, before the upload finishes.
	 *
	 * @param array $metadata Attachment metadata.
	 * @param int   $id       Attachment ID.
	 * @return array Unchanged metadata.
	 */
	public static function on_metadata( $metadata, $id ) {
		$id = (int) $id;

		if ( empty( self::$pending[ $id ] ) ) {
			return $metadata;
		}

		unset( self::$pending[ $id ] );

		if ( ! self::take_daily_slot() ) {
			self::skip_for_today( array( $id ) );
			return $metadata;
		}

		$result = Automatic_AI_Alt_Text_Generator::generate(
			$id,
			array(
				'source'   => 'upload',
				'metadata' => is_array( $metadata ) ? $metadata : null,
				'timeout'  => 25,
				'last_try' => false,
			)
		);

		// A busy or rate-limited provider shouldn't lose the image: try again
		// in the background.
		if ( is_wp_error( $result ) && Automatic_AI_Alt_Text_Connection::is_retryable( $result ) ) {
			self::give_back_daily_slot();
			self::enqueue( $id );
		}

		return $metadata;
	}

	/**
	 * Uploads whose metadata never got generated in this request go to the
	 * background queue instead.
	 *
	 * @return void
	 */
	public static function queue_leftovers() {
		foreach ( array_keys( self::$pending ) as $id ) {
			self::enqueue( $id );
		}

		self::$pending = array();
	}

	/**
	 * Queue an image and make sure a run is scheduled.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	public static function enqueue( $id ) {
		update_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_STATUS, 'queued' );
		self::schedule();
	}

	/**
	 * Schedule a run now, unless one is already waiting.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public static function schedule( $delay = 0 ) {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), self::HOOK );
		}
	}

	/**
	 * Queued image IDs, oldest first.
	 *
	 * @param int $limit How many (-1 for all).
	 * @return int[]
	 */
	public static function queued( $limit = self::BATCH ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'any',
					'meta_key'               => Automatic_AI_Alt_Text_Generator::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small, indexed lookup.
					'meta_value'             => 'queued', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'posts_per_page'         => (int) $limit,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'suppress_filters'       => true,
				)
			)
		);
	}

	/**
	 * Work through the queue (WP-Cron).
	 *
	 * @return void
	 */
	public static function process() {
		if ( ! Automatic_AI_Alt_Text_Connection::is_ready() || get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Background run with several API calls.
		}

		$started = time();
		$wait    = 0;

		foreach ( self::queued() as $id ) {
			if ( time() - $started > 120 || ! Automatic_AI_Alt_Text_Connection::is_ready() ) {
				break;
			}

			if ( ! self::take_daily_slot() ) {
				// In chunks, so a huge import doesn't make one run take forever.
				self::skip_for_today( self::queued( 500 ) );
				break;
			}

			$attempts = (int) get_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_ATTEMPTS, true ) + 1;

			update_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_ATTEMPTS, $attempts );

			$result = Automatic_AI_Alt_Text_Generator::generate(
				$id,
				array(
					'source'   => 'upload',
					'last_try' => $attempts >= self::ATTEMPTS,
				)
			);

			if ( ! is_wp_error( $result ) ) {
				continue;
			}

			if ( Automatic_AI_Alt_Text_Connection::is_retryable( $result ) ) {
				self::give_back_daily_slot();

				if ( $attempts < self::ATTEMPTS ) {
					$data = $result->get_error_data();
					$wait = max( $wait, is_array( $data ) && ! empty( $data['retry_after'] ) ? (int) $data['retry_after'] : 30 );
					// Still queued; try the rest later rather than hammering a busy API.
					break;
				}
			}

			if ( Automatic_AI_Alt_Text_Connection::is_fatal( $result ) ) {
				// Paused by the generator: leave the queue as it is until the
				// settings are fixed and tested again.
				update_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_STATUS, 'queued' );
				delete_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_ATTEMPTS );
				break;
			}
		}

		delete_transient( self::LOCK );

		if ( Automatic_AI_Alt_Text_Connection::is_ready() && self::queued( 1 ) ) {
			self::schedule( max( 5, $wait ) );
		}
	}

	/**
	 * Count one image against today's limit.
	 *
	 * @return bool False when the limit is used up.
	 */
	private static function take_daily_slot() {
		$limit = (int) self::setting( 'daily_limit' );
		$today = current_time( 'Y-m-d' );
		$count = get_option( self::DAILY, array() );
		$count = is_array( $count ) && isset( $count['day'], $count['count'] ) && $today === $count['day'] ? (int) $count['count'] : 0;

		if ( $limit > 0 && $count >= $limit ) {
			return false;
		}

		update_option(
			self::DAILY,
			array(
				'day'   => $today,
				'count' => $count + 1,
			),
			false
		);

		return true;
	}

	/**
	 * Hand back a slot for an image that will be retried.
	 *
	 * @return void
	 */
	private static function give_back_daily_slot() {
		$count = get_option( self::DAILY, array() );

		if ( is_array( $count ) && isset( $count['count'] ) && $count['count'] > 0 ) {
			--$count['count'];
			update_option( self::DAILY, $count, false );
		}
	}

	/**
	 * Images counted today.
	 *
	 * @return int
	 */
	public static function today() {
		$count = get_option( self::DAILY, array() );

		return is_array( $count ) && isset( $count['day'], $count['count'] ) && current_time( 'Y-m-d' ) === $count['day'] ? (int) $count['count'] : 0;
	}

	/**
	 * Mark images as skipped because the daily limit is reached. Bulk
	 * Generate picks them up later, when someone chooses to.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return void
	 */
	private static function skip_for_today( array $ids ) {
		$message = sprintf(
			/* translators: %d: daily limit. */
			__( 'Skipped: the daily limit of %d automatic images was reached. Use Bulk Generate to fill these in.', 'automatic-ai-alt-text' ),
			(int) self::setting( 'daily_limit' )
		);

		foreach ( $ids as $id ) {
			update_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_STATUS, 'skipped' );
			update_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_ERROR, $message );
			delete_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_ATTEMPTS );

			Automatic_AI_Alt_Text_Log::add(
				array(
					'attachment_id' => $id,
					'source'        => 'upload',
					'status'        => 'skipped',
					'provider'      => Automatic_AI_Alt_Text_Connection::provider(),
					'model'         => Automatic_AI_Alt_Text_Connection::model(),
					'message'       => $message,
				)
			);
		}
	}
}
