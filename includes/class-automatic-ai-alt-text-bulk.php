<?php
/**
 * Bulk Generate: alt text for images already in the library.
 *
 * Runs from the browser, one image per request, so it never hits a PHP
 * time limit, shows every result as it arrives and stops the moment the
 * page is closed or Stop is pressed. Before any batch, a test run on 5
 * images has to pass and be reviewed; changing the model, prompt or any
 * other setting that shapes the output needs a new test.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bulk mode endpoints.
 */
final class Automatic_AI_Alt_Text_Bulk {

	/**
	 * Option holding the unlocked test fingerprint.
	 */
	const OPTION = 'automatic_ai_alt_text_bulk';

	/**
	 * Nonce action shared by the bulk and history screens.
	 */
	const NONCE = 'automatic_ai_alt_text_admin';

	/**
	 * Images in a test run.
	 */
	const TEST_SIZE = 5;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_automatic_ai_alt_text_bulk_stats', array( __CLASS__, 'ajax_stats' ) );
		add_action( 'wp_ajax_automatic_ai_alt_text_bulk_next', array( __CLASS__, 'ajax_next' ) );
		add_action( 'wp_ajax_automatic_ai_alt_text_bulk_process', array( __CLASS__, 'ajax_process' ) );
		add_action( 'wp_ajax_automatic_ai_alt_text_bulk_unlock', array( __CLASS__, 'ajax_unlock' ) );
	}

	/**
	 * Refuse unless the request is from an administrator on this screen.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You don\'t have permission to do that.', 'automatic-ai-alt-text' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'automatic-ai-alt-text' ) ), 403 );
		}
	}

	/**
	 * Fingerprint of everything that shapes the AI's output. A test run
	 * only counts for the settings it was run with.
	 *
	 * @return string
	 */
	public static function test_fingerprint() {
		$plugin = automatic_ai_alt_text_plugin();
		$parts  = array( Automatic_AI_Alt_Text_Connection::provider(), Automatic_AI_Alt_Text_Connection::model() );

		foreach ( array( 'alt', 'title', 'title_mode', 'language', 'instructions', 'max_length', 'context_parent', 'context_filename', 'image_size' ) as $key ) {
			$parts[] = (string) $plugin->get_setting( $key );
		}

		return md5( implode( "\n", $parts ) );
	}

	/**
	 * Whether bulk mode is unlocked for the current settings.
	 *
	 * @return bool
	 */
	public static function is_unlocked() {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) && ! empty( $state['unlocked'] ) && hash_equals( (string) $state['unlocked'], self::test_fingerprint() );
	}

	/**
	 * When bulk mode was last unlocked (any settings).
	 *
	 * @return int Unix time, 0 if never.
	 */
	public static function unlocked_at() {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) && ! empty( $state['time'] ) ? (int) $state['time'] : 0;
	}

	/**
	 * SQL for the images a scope covers.
	 *
	 * @param string $scope "missing" (no alt text) or "all".
	 * @return string FROM/WHERE clause (no user input in it).
	 */
	private static function scope_sql( $scope ) {
		global $wpdb;

		$sql = "FROM {$wpdb->posts} p"
			. ( 'missing' === $scope ? " LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt'" : '' )
			. " WHERE p.post_type = 'attachment' AND p.post_status IN ( 'inherit', 'private', 'publish' )"
			. " AND p.post_mime_type LIKE 'image/%' AND p.post_mime_type NOT LIKE '%svg%' AND p.post_mime_type NOT LIKE '%icon%'";

		if ( 'missing' === $scope ) {
			$sql .= " AND ( a.meta_value IS NULL OR TRIM( a.meta_value ) = '' )";
		}

		return $sql;
	}

	/**
	 * Library counts.
	 *
	 * @return array{total:int,missing:int,done:int,queued:int}
	 */
	public static function stats() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Counts over the library; the clauses are fixed strings.
		$total   = (int) $wpdb->get_var( 'SELECT COUNT( DISTINCT p.ID ) ' . self::scope_sql( 'all' ) );
		$missing = (int) $wpdb->get_var( 'SELECT COUNT( DISTINCT p.ID ) ' . self::scope_sql( 'missing' ) );
		$done    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta} WHERE meta_key = %s", Automatic_AI_Alt_Text_Generator::META_TIME ) );
		$queued  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'queued'", Automatic_AI_Alt_Text_Generator::META_STATUS ) );
		// phpcs:enable

		return array(
			'total'   => $total,
			'missing' => $missing,
			'done'    => $done,
			'queued'  => $queued,
		);
	}

	/**
	 * Image IDs for a batch, newest first, below a cursor.
	 *
	 * @param string $scope  "missing" or "all".
	 * @param int    $before Only IDs below this (0 = from the newest).
	 * @param int    $limit  How many.
	 * @return int[]
	 */
	public static function candidates( $scope, $before, $limit ) {
		global $wpdb;

		$sql = 'SELECT DISTINCT p.ID ' . self::scope_sql( $scope );

		if ( $before > 0 ) {
			$sql .= $wpdb->prepare( ' AND p.ID < %d', (int) $before );
		}

		$sql .= $wpdb->prepare( ' ORDER BY p.ID DESC LIMIT %d', max( 1, min( 100, (int) $limit ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Built from fixed clauses and prepared parts above.
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Scope from the request.
	 *
	 * @return string
	 */
	private static function request_scope() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().
		return isset( $_POST['scope'] ) && 'all' === $_POST['scope'] ? 'all' : 'missing';
	}

	/**
	 * Counts, after a run.
	 *
	 * @return void
	 */
	public static function ajax_stats() {
		self::guard();
		wp_send_json_success( self::stats() );
	}

	/**
	 * The next IDs to work on.
	 *
	 * @return void
	 */
	public static function ajax_next() {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$before = isset( $_POST['before'] ) ? absint( $_POST['before'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50;
		// phpcs:enable

		wp_send_json_success( array( 'ids' => self::candidates( self::request_scope(), $before, $limit ) ) );
	}

	/**
	 * Describe one image.
	 *
	 * @return void
	 */
	public static function ajax_process() {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$mode    = isset( $_POST['mode'] ) && 'test' === $_POST['mode'] ? 'test' : 'bulk';
		$attempt = isset( $_POST['attempt'] ) ? absint( $_POST['attempt'] ) : 1;
		// phpcs:enable

		if ( ! Automatic_AI_Alt_Text_Connection::is_ready() ) {
			self::send_error( $id, new WP_Error( 'aaat_not_ready', __( 'The AI connection isn\'t ready. Test it on the Settings tab.', 'automatic-ai-alt-text' ) ), true );
		}

		if ( 'bulk' === $mode && ! self::is_unlocked() ) {
			self::send_error( $id, new WP_Error( 'aaat_locked', __( 'Run and review a test on 5 images first.', 'automatic-ai-alt-text' ) ), true );
		}

		if ( ! Automatic_AI_Alt_Text_Generator::is_image( $id ) ) {
			self::send_error( $id, new WP_Error( 'aaat_unsupported', __( 'Not an image.', 'automatic-ai-alt-text' ) ), false );
		}

		// Two open tabs shouldn't pay for the same image twice.
		$busy = 'automatic_ai_alt_text_busy_' . $id;

		if ( get_transient( $busy ) ) {
			self::send_error( $id, new WP_Error( 'aaat_busy', __( 'Already being worked on in another tab.', 'automatic-ai-alt-text' ) ), false );
		}

		set_transient( $busy, 1, 2 * MINUTE_IN_SECONDS );

		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- One API call with retries.
		}

		$result = Automatic_AI_Alt_Text_Generator::generate(
			$id,
			array(
				'source'    => $mode,
				'overwrite' => 'all' === self::request_scope(),
				'run'       => 'test' === $mode ? self::test_fingerprint() : '',
				'last_try'  => $attempt >= 3,
			)
		);

		delete_transient( $busy );

		if ( is_wp_error( $result ) ) {
			self::send_error( $id, $result, Automatic_AI_Alt_Text_Connection::is_fatal( $result ) );
		}

		wp_send_json_success( array_merge( self::describe_image( $id ), self::result_fields( $result ) ) );
	}

	/**
	 * Unlock bulk mode after a reviewed test run.
	 *
	 * @return void
	 */
	public static function ajax_unlock() {
		self::guard();

		$fp   = self::test_fingerprint();
		$test = Automatic_AI_Alt_Text_Log::test_results( $fp );

		if ( $test['count'] < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Run a test first. At least one image has to come back successfully.', 'automatic-ai-alt-text' ) ) );
		}

		update_option(
			self::OPTION,
			array(
				'unlocked' => $fp,
				'time'     => time(),
				'user'     => get_current_user_id(),
			),
			false
		);

		wp_send_json_success( array( 'average' => $test['avg'] ) );
	}

	/**
	 * Thumbnail, name and edit link for a result row.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string,string>
	 */
	public static function describe_image( $id ) {
		$file = get_attached_file( $id );

		return array(
			'id'    => (int) $id,
			'thumb' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
			'name'  => $file ? wp_basename( $file ) : get_the_title( $id ),
			'edit'  => (string) get_edit_post_link( $id, 'raw' ),
		);
	}

	/**
	 * A generator result for the screen.
	 *
	 * @param array $result Generator result.
	 * @return array<string,mixed>
	 */
	private static function result_fields( array $result ) {
		return array(
			'status'    => $result['status'],
			'alt'       => $result['alt'],
			'title'     => $result['title'],
			'old_alt'   => $result['old_alt'],
			'old_title' => $result['old_title'],
			'cost'      => (int) $result['cost'],
			'costLabel' => Automatic_AI_Alt_Text_AI::money( $result['cost'] / 1000000 ),
			'undo'      => $result['log_id'] ? Automatic_AI_Alt_Text_History::undo_url( $result['log_id'] ) : '',
		);
	}

	/**
	 * Send a failed image back to the screen (as a normal reply, so the
	 * run can decide whether to stop, wait or carry on).
	 *
	 * @param int      $id    Attachment ID.
	 * @param WP_Error $error What went wrong.
	 * @param bool     $fatal Whether the whole run has to stop.
	 * @return void
	 */
	private static function send_error( $id, WP_Error $error, $fatal ) {
		$data = $error->get_error_data();

		wp_send_json_success(
			array_merge(
				$id ? self::describe_image( $id ) : array( 'id' => 0 ),
				array(
					'status'     => 'error',
					'code'       => $error->get_error_code(),
					'message'    => $error->get_error_message(),
					'fatal'      => (bool) $fatal,
					'retry'      => Automatic_AI_Alt_Text_Connection::is_retryable( $error ),
					'retryAfter' => is_array( $data ) && ! empty( $data['retry_after'] ) ? (int) $data['retry_after'] : 10,
				)
			)
		);
	}
}
