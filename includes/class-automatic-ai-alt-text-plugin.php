<?php
/**
 * Main plugin orchestrator: owns the settings and boots each part.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Automatic_AI_Alt_Text_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'automatic_ai_alt_text_settings';

	/**
	 * Single shared instance.
	 *
	 * @var Automatic_AI_Alt_Text_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings cache for this request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Automatic_AI_Alt_Text_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			// After the assignment: the parts read settings through this instance.
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Use instance().
	 */
	private function __construct() {}

	/**
	 * Wire everything up.
	 *
	 * @return void
	 */
	private function boot() {
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_settings' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_settings' ) );

		Automatic_AI_Alt_Text_Log::init();
		Automatic_AI_Alt_Text_Queue::init();
		Automatic_AI_Alt_Text_Content::init();

		if ( is_admin() ) {
			Automatic_AI_Alt_Text_Bulk::init();
			Automatic_AI_Alt_Text_Media::init();
			Automatic_AI_Alt_Text_History::init();
			new Automatic_AI_Alt_Text_Admin( $this );
		}
	}

	/**
	 * The instructions the AI gets unless the site owner rewrites them.
	 *
	 * @return string
	 */
	public static function default_instructions() {
		return implode(
			"\n",
			array(
				__( 'Describe what the image shows for someone who can\'t see it, in one plain sentence.', 'automatic-ai-alt-text' ),
				__( '- Start with the main subject, then add the setting or action if it matters.', 'automatic-ai-alt-text' ),
				__( '- Include important words that appear in the image, like signs, labels or headlines.', 'automatic-ai-alt-text' ),
				__( '- Don\'t start with "Image of", "Picture of" or "Photo of".', 'automatic-ai-alt-text' ),
				__( '- Don\'t guess who people are or their age, ethnicity or other personal traits. Describe only what is visible.', 'automatic-ai-alt-text' ),
				__( '- No quotation marks, emoji, hashtags or sales language.', 'automatic-ai-alt-text' ),
				__( '- Use the page title and file name only as hints about the subject.', 'automatic-ai-alt-text' ),
			)
		);
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Connection.
			'provider'         => 'openai',
			'openai_key'       => '',
			'openai_model'     => '',
			'openai_custom'    => '',
			'anthropic_key'    => '',
			'anthropic_model'  => '',
			'anthropic_custom' => '',

			// What to write.
			'alt'              => 1,
			'title'            => 0,
			'title_mode'       => 'filename',
			'language'         => '',

			// New uploads.
			'auto'             => 1,
			'timing'           => 'background',
			'daily_limit'      => 200,

			// Pages.
			'fill_content'     => 1,

			// Advanced.
			'instructions'     => self::default_instructions(),
			'max_length'       => 125,
			'context_parent'   => 1,
			'context_filename' => 1,
			'image_size'       => 'medium',
			'history_days'     => 365,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Forget the cached settings after they change.
	 *
	 * @return void
	 */
	public function flush_settings() {
		$this->settings = null;
	}
}
