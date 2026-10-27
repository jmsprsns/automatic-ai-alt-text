<?php
/**
 * Media Library: a "Generate with AI" button in every image's details (the
 * media popup and the Edit Media screen) and a row action in list view.
 *
 * Anyone who can edit an image can use the button on it. People who can't
 * manage the site's settings are limited to 60 images an hour, so an author
 * account can't run up the AI bill.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Media screens.
 */
final class Automatic_AI_Alt_Text_Media {

	/**
	 * Nonce action for the button.
	 */
	const NONCE = 'automatic_ai_alt_text_generate';

	/**
	 * Hourly limit for users without manage_options.
	 */
	const HOURLY_LIMIT = 60;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_field' ), 20, 2 );
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_edit_screen' ) );
		add_action( 'wp_ajax_automatic_ai_alt_text_generate', array( __CLASS__, 'ajax_generate' ) );
		add_filter( 'media_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_post_automatic_ai_alt_text_generate_row', array( __CLASS__, 'handle_row_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'row_action_notice' ) );
	}

	/**
	 * Add the button below the image's fields.
	 *
	 * @param array   $fields Attachment fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public static function add_field( $fields, $post ) {
		if ( ! is_array( $fields ) || ! $post instanceof WP_Post || ! Automatic_AI_Alt_Text_Generator::is_image( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}

		$fields['automatic_ai_alt_text'] = array(
			'label'         => __( 'AI alt text', 'automatic-ai-alt-text' ),
			'input'         => 'html',
			'html'          => self::field_html( $post->ID ),
			'show_in_edit'  => true,
			'show_in_modal' => true,
		);

		return $fields;
	}

	/**
	 * The button and the image's status line.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private static function field_html( $id ) {
		$status = (string) get_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_STATUS, true );
		$time   = (int) get_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_TIME, true );
		$error  = (string) get_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_ERROR, true );
		$ready  = Automatic_AI_Alt_Text_Connection::is_ready();

		if ( ! $ready ) {
			$line = current_user_can( 'manage_options' )
				? sprintf(
					/* translators: %s: settings page link. */
					esc_html__( 'Connect an AI provider in %s to use this.', 'automatic-ai-alt-text' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . Automatic_AI_Alt_Text_Admin::PAGE ) ) . '">' . esc_html__( 'Content Powered > Automatic AI Alt-Text', 'automatic-ai-alt-text' ) . '</a>'
				)
				: esc_html__( 'AI alt text isn\'t set up on this site yet.', 'automatic-ai-alt-text' );

			return '<p class="description">' . $line . '</p>';
		}

		if ( 'queued' === $status ) {
			$line = esc_html__( 'Queued: the AI will fill this in within a minute or two.', 'automatic-ai-alt-text' );
		} elseif ( ( 'error' === $status || 'skipped' === $status ) && '' !== $error ) {
			$line = esc_html( $error );
		} elseif ( $time ) {
			/* translators: %s: how long ago, e.g. "5 mins". */
			$line = esc_html( sprintf( __( 'Written by AI %s ago. Check it reads right.', 'automatic-ai-alt-text' ), human_time_diff( $time ) ) );
		} else {
			$line = '';
		}

		$title = (bool) automatic_ai_alt_text_plugin()->get_setting( 'title' );

		return '<span class="aaat-field" data-id="' . esc_attr( (string) $id ) . '">'
			. '<button type="button" class="button aaat-generate" data-id="' . esc_attr( (string) $id ) . '">'
			. Automatic_AI_Alt_Text_Icons::get( 'sparkles', 15, 'aaat-generate-icon' )
			. '<span>' . ( $title ? esc_html__( 'Write alt text and title', 'automatic-ai-alt-text' ) : esc_html__( 'Write alt text with AI', 'automatic-ai-alt-text' ) ) . '</span>'
			. '</button>'
			. '<span class="aaat-field-status description' . ( 'error' === $status ? ' is-error' : '' ) . '">' . $line . '</span>'
			. '</span>';
	}

	/**
	 * Load the button's script wherever the media popup is.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! current_user_can( 'upload_files' ) || wp_script_is( 'automatic-ai-alt-text-media', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style( 'automatic-ai-alt-text-media', AUTOMATIC_AI_ALT_TEXT_URL . 'assets/media.css', array(), AUTOMATIC_AI_ALT_TEXT_VERSION );
		wp_enqueue_script( 'automatic-ai-alt-text-media', AUTOMATIC_AI_ALT_TEXT_URL . 'assets/media.js', array(), AUTOMATIC_AI_ALT_TEXT_VERSION, true );

		wp_localize_script(
			'automatic-ai-alt-text-media',
			'automatic_ai_alt_text_media',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'working' => __( 'Asking the AI...', 'automatic-ai-alt-text' ),
					'done'    => __( 'Written by AI just now. Check it reads right.', 'automatic-ai-alt-text' ),
					'failed'  => __( 'Something went wrong. Try again.', 'automatic-ai-alt-text' ),
					'replace' => __( 'Replace the current alt text with a new one from the AI?', 'automatic-ai-alt-text' ),
				),
			)
		);
	}

	/**
	 * The Edit Media screen and the Media Library (list view) don't always
	 * load the popup scripts, so load the button there too.
	 *
	 * @param string $hook Admin page.
	 * @return void
	 */
	public static function enqueue_edit_screen( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( 'upload.php' === $hook || ( 'post.php' === $hook && $screen && 'attachment' === $screen->post_type ) ) {
			self::enqueue();
		}
	}

	/**
	 * Generate for one image from the button.
	 *
	 * @return void
	 */
	public static function ajax_generate() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'automatic-ai-alt-text' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You can\'t edit this image.', 'automatic-ai-alt-text' ) ), 403 );
		}

		$result = self::generate_one( $id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'alt'   => $result['alt'],
				'title' => $result['title'],
			)
		);
	}

	/**
	 * Generate for one image on someone's request, with the hourly limit.
	 *
	 * @param int $id Attachment ID.
	 * @return array|WP_Error
	 */
	public static function generate_one( $id ) {
		if ( ! Automatic_AI_Alt_Text_Connection::is_ready() ) {
			return new WP_Error( 'aaat_not_ready', __( 'AI alt text isn\'t connected yet. An administrator can set it up under Content Powered > Automatic AI Alt-Text.', 'automatic-ai-alt-text' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$key   = 'automatic_ai_alt_text_rate_' . get_current_user_id();
			$count = (int) get_transient( $key );

			if ( $count >= self::HOURLY_LIMIT ) {
				return new WP_Error( 'aaat_limit', __( 'You\'ve reached the limit of 60 AI images an hour. Try again later.', 'automatic-ai-alt-text' ) );
			}

			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		}

		$result = Automatic_AI_Alt_Text_Generator::generate(
			$id,
			array(
				'source'    => 'manual',
				'alt'       => true,
				'overwrite' => true,
			)
		);

		return $result;
	}

	/**
	 * "Write alt text" under each image in the list view.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Attachment.
	 * @return array
	 */
	public static function row_action( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof WP_Post || ! Automatic_AI_Alt_Text_Connection::is_ready() || ! Automatic_AI_Alt_Text_Generator::is_image( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'automatic_ai_alt_text_generate_row',
					'id'     => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'automatic_ai_alt_text_row_' . $post->ID
		);

		$actions['automatic_ai_alt_text'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Write alt text with AI', 'automatic-ai-alt-text' ) . '</a>';

		return $actions;
	}

	/**
	 * Run the row action and go back to the list.
	 *
	 * @return void
	 */
	public static function handle_row_action() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		check_admin_referer( 'automatic_ai_alt_text_row_' . $id );

		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You can\'t edit this image.', 'automatic-ai-alt-text' ), 403 );
		}

		$result = self::generate_one( $id );
		$back   = wp_get_referer();
		$back   = $back ? $back : admin_url( 'upload.php?mode=list' );

		set_transient(
			'automatic_ai_alt_text_notice_' . get_current_user_id(),
			is_wp_error( $result )
				? array( 'error', $result->get_error_message() )
				/* translators: %s: alt text. */
				: array( 'success', sprintf( __( 'New alt text: %s', 'automatic-ai-alt-text' ), $result['alt'] ) ),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( remove_query_arg( array( 'automatic_ai_alt_text' ), $back ) );
		exit;
	}

	/**
	 * Show the row action's result on the Media Library.
	 *
	 * @return void
	 */
	public static function row_action_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$key    = 'automatic_ai_alt_text_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || 2 !== count( $notice ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p></div>',
			'error' === $notice[0] ? 'error' : 'success',
			esc_html__( 'Automatic AI Alt-Text:', 'automatic-ai-alt-text' ),
			esc_html( $notice[1] )
		);
	}
}
