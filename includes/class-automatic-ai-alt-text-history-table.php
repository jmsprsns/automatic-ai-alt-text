<?php
/**
 * The History tab's list: every change, newest first, with Edit, Undo and
 * "Write again" on each image's newest change.
 *
 * Loaded only on that tab, after WP_List_Table.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * History list table.
 */
final class Automatic_AI_Alt_Text_History_Table extends WP_List_Table {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Newest change per image on this page.
	 *
	 * @var array<int,int>
	 */
	private $latest = array();

	/**
	 * Set up.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'change',
				'plural'   => 'changes',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'     => '<input type="checkbox">',
			'image'  => __( 'Image', 'automatic-ai-alt-text' ),
			'alt'    => __( 'Alt text', 'automatic-ai-alt-text' ),
			'name'   => __( 'Title', 'automatic-ai-alt-text' ),
			'source' => __( 'Source', 'automatic-ai-alt-text' ),
			'cost'   => __( 'Cost', 'automatic-ai-alt-text' ),
			'date'   => __( 'When', 'automatic-ai-alt-text' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array( 'undo' => __( 'Undo', 'automatic-ai-alt-text' ) );
	}

	/**
	 * The current filter.
	 *
	 * @return string
	 */
	private function view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';

		return in_array( $view, array( 'all', 'ai', 'edits', 'undone', 'errors' ), true ) ? $view : 'all';
	}

	/**
	 * WHERE clause for a filter.
	 *
	 * @param string $view Filter.
	 * @return string Fixed SQL.
	 */
	private static function view_sql( $view ) {
		switch ( $view ) {
			case 'ai':
				return "status = 'success' AND source IN ( 'upload', 'bulk', 'test', 'manual' )";
			case 'edits':
				return "source = 'edit'";
			case 'undone':
				return "source = 'undo'";
			case 'errors':
				return "status IN ( 'error', 'skipped' )";
			default:
				return "status <> 'preview'";
		}
	}

	/**
	 * Filter links with counts.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		global $wpdb;

		$table  = Automatic_AI_Alt_Text_Log::table();
		$labels = array(
			'all'    => __( 'All', 'automatic-ai-alt-text' ),
			'ai'     => __( 'Written by AI', 'automatic-ai-alt-text' ),
			'edits'  => __( 'Edited by you', 'automatic-ai-alt-text' ),
			'undone' => __( 'Undone', 'automatic-ai-alt-text' ),
			'errors' => __( 'Errors and skipped', 'automatic-ai-alt-text' ),
		);
		$views  = array();

		foreach ( $labels as $view => $label ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin's own table; fixed clauses.
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE " . self::view_sql( $view ) );

			if ( 'all' !== $view && ! $count ) {
				continue;
			}

			$views[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'view', $view, Automatic_AI_Alt_Text_Admin::tab_url( 'history' ) ) ),
				$this->view() === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * Load the page of rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$table = Automatic_AI_Alt_Text_Log::table();
		$where = self::view_sql( $this->view() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$search     = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		$attachment = isset( $_GET['attachment'] ) ? absint( $_GET['attachment'] ) : 0;
		// phpcs:enable

		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND ( new_alt LIKE %s OR old_alt LIKE %s OR new_title LIKE %s OR old_title LIKE %s OR message LIKE %s OR attachment_id = %d )', $like, $like, $like, $like, $like, absint( $search ) );
		}

		if ( $attachment ) {
			$where .= $wpdb->prepare( ' AND attachment_id = %d', $attachment );
		}

		$page   = $this->get_pagenum();
		$offset = ( $page - 1 ) * self::PER_PAGE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin's own table; the WHERE is fixed clauses plus prepared parts.
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$this->items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE, $offset ) );
		// phpcs:enable

		$ids = array_unique( array_map( 'intval', wp_list_pluck( $this->items, 'attachment_id' ) ) );

		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}

		$this->latest = Automatic_AI_Alt_Text_Log::latest_changes( $ids );

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'image' );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Message when there's nothing.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Nothing here yet. Changes appear as soon as the AI writes alt text for an image.', 'automatic-ai-alt-text' );
	}

	/**
	 * Whether a row is its image's newest change (and can be edited or undone).
	 *
	 * @param object $item Row.
	 * @return bool
	 */
	private function is_latest( $item ) {
		$id = (int) $item->attachment_id;

		return 'success' === $item->status && isset( $this->latest[ $id ] ) && (int) $item->id === $this->latest[ $id ];
	}

	/**
	 * Checkbox (only on rows that can be undone).
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		if ( ! $this->is_latest( $item ) ) {
			return '';
		}

		return '<input type="checkbox" name="changes[]" value="' . esc_attr( (string) $item->id ) . '">';
	}

	/**
	 * Thumbnail, file name and actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_image( $item ) {
		$id   = (int) $item->attachment_id;
		$post = $id ? get_post( $id ) : null;

		if ( ! $post ) {
			return '<span class="aaat-thumb is-missing">' . Automatic_AI_Alt_Text_Icons::get( 'image-off', 20 ) . '</span><span class="aaat-file aaat-muted">' . esc_html__( 'Image deleted', 'automatic-ai-alt-text' ) . '</span>';
		}

		$file  = get_attached_file( $id );
		$thumb = wp_get_attachment_image(
			$id,
			array( 60, 60 ),
			false,
			array(
				'alt'     => '',
				'loading' => 'lazy',
			)
		);
		$out   = '<a class="aaat-thumb" href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . ( $thumb ? $thumb : Automatic_AI_Alt_Text_Icons::get( 'image', 20 ) ) . '</a>';
		$out  .= '<strong class="aaat-file"><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( $file ? wp_basename( $file ) : get_the_title( $id ) ) . '</a></strong>';

		$actions = array();

		if ( $this->is_latest( $item ) ) {
			$actions['edit'] = '<button type="button" class="button-link aaat-edit-open" data-log="' . esc_attr( (string) $item->id ) . '">' . esc_html__( 'Edit', 'automatic-ai-alt-text' ) . '</button>';
			$actions['undo'] = '<a href="' . esc_url( Automatic_AI_Alt_Text_History::undo_url( (int) $item->id ) ) . '">' . esc_html__( 'Undo', 'automatic-ai-alt-text' ) . '</a>';
		}

		if ( Automatic_AI_Alt_Text_Connection::is_ready() && Automatic_AI_Alt_Text_Generator::is_image( $id ) ) {
			$actions['again'] = '<button type="button" class="button-link aaat-again" data-id="' . esc_attr( (string) $id ) . '">' . esc_html__( 'Write again', 'automatic-ai-alt-text' ) . '</button>';
		}

		return $out . $this->row_actions( $actions );
	}

	/**
	 * A value and what it replaced.
	 *
	 * @param string $after  Value after.
	 * @param string $before Value before.
	 * @return string
	 */
	private static function before_after( $after, $before ) {
		$out = '' !== $after ? '<span class="aaat-new">' . esc_html( $after ) . '</span>' : '<span class="aaat-muted">' . esc_html__( '(empty)', 'automatic-ai-alt-text' ) . '</span>';

		if ( $before !== $after ) {
			$out .= '<span class="aaat-old">' . esc_html__( 'Before:', 'automatic-ai-alt-text' ) . ' ' . ( '' !== $before ? esc_html( $before ) : '<em>' . esc_html__( 'empty', 'automatic-ai-alt-text' ) . '</em>' ) . '</span>';
		}

		return $out;
	}

	/**
	 * Alt text, with the edit form on editable rows.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_alt( $item ) {
		if ( 'success' !== $item->status ) {
			return '<span class="aaat-row-error">' . Automatic_AI_Alt_Text_Icons::get( 'error' === $item->status ? 'circle-alert' : 'circle-pause', 15 ) . esc_html( (string) $item->message ) . '</span>';
		}

		$out = self::before_after( (string) $item->new_alt, (string) $item->old_alt );

		if ( $this->is_latest( $item ) ) {
			$id   = (int) $item->attachment_id;
			$post = get_post( $id );
			$out .= '<div class="aaat-edit" data-log="' . esc_attr( (string) $item->id ) . '" hidden>'
				. '<label><span>' . esc_html__( 'Alt text', 'automatic-ai-alt-text' ) . '</span><textarea rows="3" maxlength="500">' . esc_textarea( Automatic_AI_Alt_Text_Generator::alt( $id ) ) . '</textarea></label>'
				. '<label><span>' . esc_html__( 'Title', 'automatic-ai-alt-text' ) . '</span><input type="text" maxlength="200" value="' . esc_attr( $post ? $post->post_title : '' ) . '"></label>'
				. '<span class="aaat-edit-buttons"><button type="button" class="button button-primary aaat-edit-save">' . esc_html__( 'Save', 'automatic-ai-alt-text' ) . '</button> <button type="button" class="button aaat-edit-cancel">' . esc_html__( 'Cancel', 'automatic-ai-alt-text' ) . '</button><span class="aaat-edit-status"></span></span>'
				. '</div>';
		}

		return $out;
	}

	/**
	 * Title.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_name( $item ) {
		if ( 'success' !== $item->status ) {
			return '<span class="aaat-muted">&ndash;</span>';
		}

		return self::before_after( (string) $item->new_title, (string) $item->old_title );
	}

	/**
	 * Where the change came from, and the model.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_source( $item ) {
		$labels = array(
			'upload' => array( 'image-up', __( 'New upload', 'automatic-ai-alt-text' ) ),
			'bulk'   => array( 'layers', __( 'Bulk', 'automatic-ai-alt-text' ) ),
			'test'   => array( 'flask-conical', __( 'Test run', 'automatic-ai-alt-text' ) ),
			'manual' => array( 'sparkles', __( 'Button', 'automatic-ai-alt-text' ) ),
			'edit'   => array( 'pencil', __( 'Edited by you', 'automatic-ai-alt-text' ) ),
			'undo'   => array( 'undo-2', __( 'Undone', 'automatic-ai-alt-text' ) ),
		);
		$label  = isset( $labels[ $item->source ] ) ? $labels[ $item->source ] : array( 'circle', (string) $item->source );
		$tone   = 'error' === $item->status ? 'bad' : ( 'skipped' === $item->status ? 'warn' : ( in_array( $item->source, array( 'edit', 'undo' ), true ) ? 'none' : 'ai' ) );
		$out    = '<span class="aaat-tag is-' . esc_attr( $tone ) . '">' . Automatic_AI_Alt_Text_Icons::get( $label[0], 13 ) . esc_html( $label[1] ) . '</span>';

		if ( '' !== (string) $item->model && 'edit' !== $item->source ) {
			$out .= '<span class="aaat-muted aaat-model">' . esc_html( Automatic_AI_Alt_Text_Connection::model_label( (string) $item->model ) ) . '</span>';
		}

		if ( (int) $item->user_id && in_array( $item->source, array( 'edit', 'undo', 'manual' ), true ) ) {
			$user = get_userdata( (int) $item->user_id );

			if ( $user ) {
				$out .= '<span class="aaat-muted aaat-model">' . esc_html( $user->display_name ) . '</span>';
			}
		}

		return $out;
	}

	/**
	 * Estimated cost.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_cost( $item ) {
		if ( ! (int) $item->input_tokens ) {
			return '<span class="aaat-muted">&ndash;</span>';
		}

		$title = sprintf(
			/* translators: 1: input tokens, 2: output tokens. */
			__( '%1$s tokens in, %2$s out', 'automatic-ai-alt-text' ),
			number_format_i18n( (int) $item->input_tokens ),
			number_format_i18n( (int) $item->output_tokens )
		);

		return '<span title="' . esc_attr( $title ) . '">' . esc_html( Automatic_AI_Alt_Text_AI::money( (int) $item->cost / 1000000 ) ) . '</span>';
	}

	/**
	 * When.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_date( $item ) {
		$time = strtotime( $item->created_at . ' UTC' );

		if ( ! $time ) {
			return '';
		}

		/* translators: %s: time difference, e.g. "5 mins". */
		$ago = time() - $time < DAY_IN_SECONDS ? sprintf( __( '%s ago', 'automatic-ai-alt-text' ), human_time_diff( $time ) ) : wp_date( get_option( 'date_format' ), $time );

		return '<span title="' . esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ) . '">' . esc_html( $ago ) . '</span>';
	}
}
