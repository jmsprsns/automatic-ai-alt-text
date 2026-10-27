<?php
/**
 * The history: one row per change the plugin makes (or tries to make) to
 * an image, with the values before and after so any of them can be undone.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * History table.
 */
final class Automatic_AI_Alt_Text_Log {

	/**
	 * Table schema version.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'automatic_ai_alt_text_db';

	/**
	 * Daily clean-up of old rows.
	 */
	const PRUNE_HOOK = 'automatic_ai_alt_text_prune';

	/**
	 * Where a row came from.
	 */
	const SOURCES = array( 'upload', 'bulk', 'test', 'manual', 'connect', 'edit', 'undo' );

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'aaat_log';
	}

	/**
	 * Create or upgrade the table when the schema version changes (covers
	 * updates, which don't run the activation hook).
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}

		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Create the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				source varchar(10) NOT NULL DEFAULT '',
				status varchar(10) NOT NULL DEFAULT '',
				provider varchar(20) NOT NULL DEFAULT '',
				model varchar(100) NOT NULL DEFAULT '',
				old_alt text NULL,
				new_alt text NULL,
				old_title text NULL,
				new_title text NULL,
				message text NULL,
				input_tokens int(10) unsigned NOT NULL DEFAULT 0,
				output_tokens int(10) unsigned NOT NULL DEFAULT 0,
				cost bigint(20) unsigned NOT NULL DEFAULT 0,
				run varchar(64) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY attachment_id (attachment_id),
				KEY created_at (created_at),
				KEY status_source (status,source)
			) {$charset};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Add a row.
	 *
	 * @param array $row Column values (see the table).
	 * @return int Row ID, 0 on failure.
	 */
	public static function add( array $row ) {
		global $wpdb;

		$defaults = array(
			'attachment_id' => 0,
			'created_at'    => current_time( 'mysql', true ),
			'source'        => 'manual',
			'status'        => 'success',
			'provider'      => '',
			'model'         => '',
			'old_alt'       => null,
			'new_alt'       => null,
			'old_title'     => null,
			'new_title'     => null,
			'message'       => '',
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'cost'          => 0,
			'run'           => '',
			'user_id'       => get_current_user_id(),
		);

		// Same column order as the formats below, and nothing else.
		$row = array_intersect_key( array_merge( $defaults, $row ), $defaults );

		$row['source'] = in_array( $row['source'], self::SOURCES, true ) ? $row['source'] : 'manual';
		$row['model']  = substr( (string) $row['model'], 0, 100 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own table.
		$ok = $wpdb->insert(
			self::table(),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One row.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * The newest row that changed an image, per image. Only the newest row can
	 * be edited or undone, so an old row can't overwrite a later change.
	 *
	 * @param int[] $attachment_ids Image IDs.
	 * @return array<int,int> Image ID => newest row ID.
	 */
	public static function latest_changes( array $attachment_ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $attachment_ids ) );

		if ( ! $ids ) {
			return array();
		}

		$table = self::table();
		$in    = implode( ',', $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; IDs are integers.
		$rows = $wpdb->get_results( "SELECT attachment_id, MAX(id) AS id FROM {$table} WHERE attachment_id IN ({$in}) AND status = 'success' GROUP BY attachment_id" );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->attachment_id ] = (int) $row->id;
		}

		return $out;
	}

	/**
	 * Totals since a time: images changed and estimated spend.
	 *
	 * @param int $since Unix time.
	 * @return array{images:int,calls:int,cost:int} Cost in millionths of a dollar.
	 */
	public static function totals( $since ) {
		global $wpdb;

		$table = self::table();
		$from  = gmdate( 'Y-m-d H:i:s', (int) $since );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM( status = 'success' AND source IN ( 'upload', 'bulk', 'test', 'manual' ) ) AS images, SUM( input_tokens > 0 ) AS calls, SUM( cost ) AS cost FROM {$table} WHERE created_at >= %s", $from ) );

		return array(
			'images' => $row ? (int) $row->images : 0,
			'calls'  => $row ? (int) $row->calls : 0,
			'cost'   => $row ? (int) $row->cost : 0,
		);
	}

	/**
	 * Average cost per image of the successful test-run rows for a settings
	 * fingerprint.
	 *
	 * @param string $run Test fingerprint.
	 * @return array{count:int,avg:int} Average in millionths of a dollar.
	 */
	public static function test_results( $run ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, AVG( cost ) AS avg FROM {$table} WHERE source = 'test' AND status = 'success' AND run = %s", (string) $run ) );

		return array(
			'count' => $row ? (int) $row->total : 0,
			'avg'   => $row ? (int) round( (float) $row->avg ) : 0,
		);
	}

	/**
	 * Average cost per image across the last 200 AI calls with a model.
	 *
	 * @param string $model Model ID.
	 * @return int|null Millionths of a dollar, null with no data.
	 */
	public static function average_cost( $model ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table.
		$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG( cost ) FROM ( SELECT cost FROM {$table} WHERE model = %s AND input_tokens > 0 ORDER BY id DESC LIMIT 200 ) recent", (string) $model ) );

		return null === $avg ? null : (int) round( (float) $avg );
	}

	/**
	 * Delete rows older than the "Keep history" setting.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$days = (int) automatic_ai_alt_text_plugin()->get_setting( 'history_days' );

		if ( $days <= 0 ) {
			return;
		}

		$table = self::table();
		$until = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $until ) );
	}
}
