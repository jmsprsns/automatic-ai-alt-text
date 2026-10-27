<?php
/**
 * The History tab's actions: edit, undo and bulk undo.
 *
 * Only the newest change to an image can be edited or undone, so an old
 * row can never overwrite something that was changed after it. Every edit
 * and undo is itself a new row, so nothing is lost and an undo can be
 * undone.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * History actions.
 */
final class Automatic_AI_Alt_Text_History {

	/**
	 * Sources whose changes came from the AI.
	 */
	const AI_SOURCES = array( 'upload', 'bulk', 'test', 'manual' );

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_automatic_ai_alt_text_undo', array( __CLASS__, 'handle_undo' ) );
		add_action( 'wp_ajax_automatic_ai_alt_text_history_save', array( __CLASS__, 'ajax_save' ) );
	}

	/**
	 * Link that undoes a row.
	 *
	 * @param int $log_id Row ID.
	 * @return string
	 */
	public static function undo_url( $log_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'automatic_ai_alt_text_undo',
					'log'    => (int) $log_id,
				),
				admin_url( 'admin-post.php' )
			),
			'automatic_ai_alt_text_undo_' . (int) $log_id
		);
	}

	/**
	 * A row that can still be changed: the newest successful change to an
	 * image the current user can edit.
	 *
	 * @param int $log_id Row ID.
	 * @return object|WP_Error
	 */
	private static function changeable( $log_id ) {
		$row = Automatic_AI_Alt_Text_Log::get( $log_id );

		if ( ! $row || 'success' !== $row->status ) {
			return new WP_Error( 'aaat_row', __( 'That change can\'t be found.', 'automatic-ai-alt-text' ) );
		}

		$id = (int) $row->attachment_id;

		if ( ! $id || ! get_post( $id ) ) {
			return new WP_Error( 'aaat_row', __( 'That image has been deleted.', 'automatic-ai-alt-text' ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'aaat_row', __( 'You can\'t edit this image.', 'automatic-ai-alt-text' ) );
		}

		$latest = Automatic_AI_Alt_Text_Log::latest_changes( array( $id ) );

		if ( empty( $latest[ $id ] ) || (int) $row->id !== $latest[ $id ] ) {
			return new WP_Error( 'aaat_row', __( 'This image has been changed again since. Only its newest change can be edited or undone.', 'automatic-ai-alt-text' ) );
		}

		return $row;
	}

	/**
	 * Undo a row: put the values from before it back.
	 *
	 * @param int $log_id Row ID.
	 * @return true|WP_Error
	 */
	public static function undo( $log_id ) {
		$row = self::changeable( $log_id );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$id      = (int) $row->attachment_id;
		$post    = get_post( $id );
		$current = array(
			'alt'   => Automatic_AI_Alt_Text_Generator::alt( $id ),
			'title' => (string) $post->post_title,
		);
		$restore = array(
			'alt'   => (string) $row->old_alt,
			'title' => '' !== (string) $row->old_title ? (string) $row->old_title : $current['title'],
		);

		Automatic_AI_Alt_Text_Generator::save( $id, $restore, $current );

		if ( in_array( $row->source, self::AI_SOURCES, true ) ) {
			// Back to how it was before the AI: the pages stop using it too.
			delete_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_TIME );
			update_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_STATUS, 'undone' );
		} elseif ( 'undo' === $row->source ) {
			// Undoing an undo brings the AI's text back.
			update_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_TIME, time() );
			update_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_STATUS, 'done' );
		}

		Automatic_AI_Alt_Text_Log::add(
			array(
				'attachment_id' => $id,
				'source'        => 'undo',
				'status'        => 'success',
				'provider'      => $row->provider,
				'model'         => $row->model,
				'old_alt'       => $current['alt'],
				'new_alt'       => $restore['alt'],
				'old_title'     => $current['title'],
				'new_title'     => $restore['title'],
			)
		);

		return true;
	}

	/**
	 * Undo link from the History tab or a test result.
	 *
	 * @return void
	 */
	public static function handle_undo() {
		$log_id = isset( $_GET['log'] ) ? absint( $_GET['log'] ) : 0;

		check_admin_referer( 'automatic_ai_alt_text_undo_' . $log_id );

		$result = self::undo( $log_id );

		self::redirect_with_notice(
			is_wp_error( $result ) ? array( 'error', $result->get_error_message() ) : array( 'success', __( 'Undone. The image has its previous text back.', 'automatic-ai-alt-text' ) )
		);
	}

	/**
	 * Undo several rows (the list's bulk action).
	 *
	 * @param int[] $log_ids Row IDs.
	 * @return void
	 */
	public static function bulk_undo( array $log_ids ) {
		$done   = 0;
		$failed = 0;

		foreach ( array_slice( array_map( 'absint', $log_ids ), 0, 200 ) as $log_id ) {
			if ( true === self::undo( $log_id ) ) {
				++$done;
			} else {
				++$failed;
			}
		}

		$message = sprintf(
			/* translators: %d: number of changes. */
			_n( '%d change undone.', '%d changes undone.', $done, 'automatic-ai-alt-text' ),
			$done
		);

		if ( $failed ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of changes. */
				_n( '%d couldn\'t be undone because the image changed again since.', '%d couldn\'t be undone because those images changed again since.', $failed, 'automatic-ai-alt-text' ),
				$failed
			);
		}

		self::redirect_with_notice( array( $failed && ! $done ? 'error' : 'success', $message ) );
	}

	/**
	 * Save a hand edit from the History tab.
	 *
	 * @return void
	 */
	public static function ajax_save() {
		if ( ! check_ajax_referer( Automatic_AI_Alt_Text_Bulk::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'automatic-ai-alt-text' ) ), 403 );
		}

		$row = self::changeable( isset( $_POST['log'] ) ? absint( $_POST['log'] ) : 0 );

		if ( is_wp_error( $row ) ) {
			wp_send_json_error( array( 'message' => $row->get_error_message() ) );
		}

		$id      = (int) $row->attachment_id;
		$post    = get_post( $id );
		$current = array(
			'alt'   => Automatic_AI_Alt_Text_Generator::alt( $id ),
			'title' => (string) $post->post_title,
		);
		$new     = array(
			'alt'   => isset( $_POST['alt'] ) ? self::limit( sanitize_text_field( wp_unslash( $_POST['alt'] ) ), 500 ) : $current['alt'],
			'title' => isset( $_POST['title'] ) ? self::limit( sanitize_text_field( wp_unslash( $_POST['title'] ) ), 200 ) : $current['title'],
		);

		if ( '' === $new['title'] ) {
			$new['title'] = $current['title'];
		}

		if ( $new === $current ) {
			wp_send_json_success( array( 'changed' => false ) );
		}

		Automatic_AI_Alt_Text_Generator::save( $id, $new, $current );
		update_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_TIME, time() );
		update_post_meta( $id, Automatic_AI_Alt_Text_Generator::META_STATUS, 'done' );

		Automatic_AI_Alt_Text_Log::add(
			array(
				'attachment_id' => $id,
				'source'        => 'edit',
				'status'        => 'success',
				'old_alt'       => $current['alt'],
				'new_alt'       => $new['alt'],
				'old_title'     => $current['title'],
				'new_title'     => $new['title'],
			)
		);

		wp_send_json_success( array( 'changed' => true ) );
	}

	/**
	 * Cut text to a length.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	private static function limit( $text, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Back to the History tab with a message.
	 *
	 * @param array{0:string,1:string} $notice Type and text.
	 * @return void
	 */
	private static function redirect_with_notice( array $notice ) {
		set_transient( 'automatic_ai_alt_text_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );

		$back = wp_get_referer();
		$back = $back ? remove_query_arg( array( 'action', 'action2', 'changes', '_wpnonce', '_wp_http_referer' ), $back ) : admin_url( 'admin.php?page=' . Automatic_AI_Alt_Text_Admin::PAGE . '&tab=history' );

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Pop the message left by an action, if any.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public static function take_notice() {
		$key    = 'automatic_ai_alt_text_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || 2 !== count( $notice ) ) {
			return null;
		}

		delete_transient( $key );

		return $notice;
	}
}
