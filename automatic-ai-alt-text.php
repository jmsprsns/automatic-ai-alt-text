<?php
/**
 * Plugin Name:       Automatic AI Alt-Text
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Writes alt text and titles for your images with ChatGPT or Claude: automatically for new uploads, in safe batches for your existing library, with a full history you can edit or undo.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       automatic-ai-alt-text
 * Update URI:        https://github.com/jmsprsns/automatic-ai-alt-text
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$automatic_ai_alt_text_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'AUTOMATIC_AI_ALT_TEXT_VERSION', $automatic_ai_alt_text_headers['version'] );
define( 'AUTOMATIC_AI_ALT_TEXT_REPO', $automatic_ai_alt_text_headers['repo'] );
define( 'AUTOMATIC_AI_ALT_TEXT_FILE', __FILE__ );
define( 'AUTOMATIC_AI_ALT_TEXT_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUTOMATIC_AI_ALT_TEXT_URL', plugin_dir_url( __FILE__ ) );

unset( $automatic_ai_alt_text_headers );

require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-icons.php';
require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-updater.php';
require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-crypto.php';
require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-ai.php';
require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-connection.php';
require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-log.php';
require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-generator.php';
require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-queue.php';
require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-content.php';
require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-plugin.php';

if ( is_admin() ) {
	require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-menu.php';
	require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-feedback.php';
	require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-builders.php';
	require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-bulk.php';
	require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-media.php';
	require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-history.php';
	require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-admin.php';
}

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Automatic_AI_Alt_Text_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Automatic_AI_Alt_Text_Plugin
 */
function automatic_ai_alt_text_plugin() {
	return Automatic_AI_Alt_Text_Plugin::instance();
}
add_action( 'plugins_loaded', 'automatic_ai_alt_text_plugin' );

/**
 * Create the history table and schedule the daily clean-up.
 *
 * @return void
 */
function automatic_ai_alt_text_activate() {
	Automatic_AI_Alt_Text_Log::install();

	if ( ! wp_next_scheduled( Automatic_AI_Alt_Text_Log::PRUNE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Automatic_AI_Alt_Text_Log::PRUNE_HOOK );
	}
}
register_activation_hook( AUTOMATIC_AI_ALT_TEXT_FILE, 'automatic_ai_alt_text_activate' );

/**
 * Stop the background jobs. Settings, history and alt text stay put
 * (uninstall.php removes the plugin's own data; alt text is the site's).
 *
 * @return void
 */
function automatic_ai_alt_text_deactivate() {
	wp_clear_scheduled_hook( Automatic_AI_Alt_Text_Queue::HOOK );
	wp_clear_scheduled_hook( Automatic_AI_Alt_Text_Log::PRUNE_HOOK );
}
register_deactivation_hook( AUTOMATIC_AI_ALT_TEXT_FILE, 'automatic_ai_alt_text_deactivate' );
