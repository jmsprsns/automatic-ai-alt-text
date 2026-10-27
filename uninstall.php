<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * The alt text and titles it wrote stay on the images: they're the site's
 * content now. Only the plugin's settings (including the encrypted API
 * keys), its history table, its bookkeeping on each image and its
 * scheduled jobs go.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array(
	'automatic_ai_alt_text_settings',
	'automatic_ai_alt_text_connection',
	'automatic_ai_alt_text_bulk',
	'automatic_ai_alt_text_daily',
	'automatic_ai_alt_text_db',
) as $automatic_ai_alt_text_option ) {
	delete_option( $automatic_ai_alt_text_option );
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One-off clean-up of the plugin's own data.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aaat_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_aaat_time', '_aaat_status', '_aaat_error', '_aaat_attempts' )" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_automatic\\_ai\\_alt\\_text\\_%' OR option_name LIKE '\\_transient\\_timeout\\_automatic\\_ai\\_alt\\_text\\_%'" );
// phpcs:enable

wp_clear_scheduled_hook( 'automatic_ai_alt_text_process' );
wp_clear_scheduled_hook( 'automatic_ai_alt_text_prune' );

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-automatic-ai-alt-text' );
