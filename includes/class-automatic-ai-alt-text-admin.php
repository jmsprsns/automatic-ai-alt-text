<?php
/**
 * Admin side: the Content Powered > Automatic AI Alt-Text page (Settings,
 * Bulk Generate, History and Advanced tabs) and the Plugins-screen link.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Automatic_AI_Alt_Text_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'automatic-ai-alt-text';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'scan-eye';

	/**
	 * Tabs and their icons.
	 */
	const TABS = array(
		'settings' => 'sliders-horizontal',
		'bulk'     => 'layers',
		'history'  => 'history',
		'advanced' => 'settings-2',
	);

	/**
	 * Plugin instance.
	 *
	 * @var Automatic_AI_Alt_Text_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Automatic_AI_Alt_Text_Plugin $plugin Plugin instance.
	 */
	public function __construct( Automatic_AI_Alt_Text_Plugin $plugin ) {
		$this->plugin = $plugin;

		Automatic_AI_Alt_Text_Menu::init();
		Automatic_AI_Alt_Text_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_text' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'wp_ajax_automatic_ai_alt_text_test', array( $this, 'ajax_test' ) );

		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( AUTOMATIC_AI_ALT_TEXT_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab Tab ID.
	 * @return string
	 */
	public static function tab_url( $tab = 'settings' ) {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The tab being shown.
	 *
	 * @return string
	 */
	private function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

		return array_key_exists( $tab, self::TABS ) ? $tab : 'settings';
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'automatic-ai-alt-text' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Automatic_AI_Alt_Text_Icons::get( self::ICON, 17, 'automatic-ai-alt-text-menu-icon' );
		$menu_title = $icon . '<span class="automatic-ai-alt-text-menu-label">' . esc_html__( 'Automatic AI Alt-Text', 'automatic-ai-alt-text' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Automatic_AI_Alt_Text_Menu::SLUG,
			__( 'Automatic AI Alt-Text', 'automatic-ai-alt-text' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'load-' . $this->hook_suffix, array( $this, 'handle_bulk_undo' ) );
		}
	}

	/**
	 * Mark the page's body so the header can span the full width.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && $this->hook_suffix && $screen->id === $this->hook_suffix ) {
			$classes .= ' aaat-settings-page';
		}

		return $classes;
	}

	/**
	 * Register the settings option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Automatic_AI_Alt_Text_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Automatic_AI_Alt_Text_Plugin::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Suggested text for the site's privacy policy (Settings > Privacy).
	 *
	 * @return void
	 */
	public function add_privacy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Automatic AI Alt-Text', 'automatic-ai-alt-text' ),
			'<p>' . esc_html__( 'To write descriptions (alt text) for images uploaded to this site, a reduced copy of each image, its file name and the title of the page it was uploaded to are sent to an AI service (OpenAI or Anthropic, as chosen by the site owner). Images that visitors never upload are not sent. The AI service\'s own privacy policy applies to that data.', 'automatic-ai-alt-text' ) . '</p>'
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * The page has two forms (Settings and Advanced), so only the fields of
	 * the submitted tab change; everything else keeps its saved value.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$defaults = Automatic_AI_Alt_Text_Plugin::defaults();

		if ( ! is_array( $input ) ) {
			return $this->plugin->get_settings();
		}

		// Already clean: WordPress runs this twice on the first save, and
		// code may save the option directly.
		if ( ! isset( $input['_tab'] ) ) {
			return array_intersect_key( wp_parse_args( $input, $defaults ), $defaults );
		}

		$saved = get_option( Automatic_AI_Alt_Text_Plugin::OPTION, array() );
		$out   = array_intersect_key( wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults ), $defaults );
		$flag  = function ( $key ) use ( $input ) {
			return empty( $input[ $key ] ) ? 0 : 1;
		};
		$num   = function ( $key, $min, $max ) use ( $input, $defaults ) {
			return isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? max( $min, min( $max, (int) $input[ $key ] ) ) : $defaults[ $key ];
		};

		if ( 'advanced' === $input['_tab'] ) {
			$instructions = isset( $input['instructions'] ) ? trim( sanitize_textarea_field( wp_unslash( $input['instructions'] ) ) ) : '';
			$instructions = function_exists( 'mb_substr' ) ? mb_substr( $instructions, 0, 4000 ) : substr( $instructions, 0, 4000 );

			$out['instructions']     = '' === $instructions ? Automatic_AI_Alt_Text_Plugin::default_instructions() : $instructions;
			$out['max_length']       = $num( 'max_length', 40, 300 );
			$out['context_parent']   = $flag( 'context_parent' );
			$out['context_filename'] = $flag( 'context_filename' );
			$out['image_size']       = isset( $input['image_size'] ) && array_key_exists( (string) $input['image_size'], Automatic_AI_Alt_Text_Generator::SIZES ) ? (string) $input['image_size'] : 'medium';
			$out['history_days']     = $num( 'history_days', 0, 3650 );

			return $out;
		}

		$providers       = Automatic_AI_Alt_Text_AI::providers();
		$out['provider'] = isset( $input['provider'], $providers[ $input['provider'] ] ) ? $input['provider'] : 'openai';

		foreach ( $providers as $provider => $info ) {
			if ( ! empty( $input[ $provider . '_key_remove' ] ) ) {
				$out[ $provider . '_key' ] = '';
			}

			$new = isset( $input[ $provider . '_key_new' ] ) ? trim( (string) wp_unslash( $input[ $provider . '_key_new' ] ), " \t\n\r\0\x0B\"'" ) : '';

			if ( '' !== $new ) {
				if ( ! preg_match( '/^[A-Za-z0-9_\-\.]{20,400}$/D', $new ) ) {
					add_settings_error( self::PAGE, 'aaat_key_' . $provider, sprintf( /* translators: %s: provider name. */ __( 'That %s API key has characters an API key can\'t have, so it wasn\'t saved. Copy it again from your account.', 'automatic-ai-alt-text' ), $info['label'] ) );
				} else {
					$sealed = Automatic_AI_Alt_Text_Crypto::encrypt( $new );

					if ( '' === $sealed ) {
						add_settings_error( self::PAGE, 'aaat_key_' . $provider, __( 'The key couldn\'t be stored securely on this server (no libsodium or OpenSSL). Add it to wp-config.php instead.', 'automatic-ai-alt-text' ) );
					} else {
						$out[ $provider . '_key' ] = $sealed;

						if ( 'openai' === $provider && 0 === strpos( $new, 'sk-ant-' ) ) {
							add_settings_error( self::PAGE, 'aaat_key_hint', __( 'That looks like a Claude (Anthropic) key, saved in the ChatGPT box. Choose Claude above if it is.', 'automatic-ai-alt-text' ), 'warning' );
						} elseif ( 0 !== strpos( $new, $info['prefix'] ) ) {
							/* translators: 1: provider name, 2: key prefix. */
							add_settings_error( self::PAGE, 'aaat_key_hint', sprintf( __( '%1$s keys usually start with %2$s. Check you copied the whole key.', 'automatic-ai-alt-text' ), $info['label'], $info['prefix'] ), 'warning' );
						}
					}
				}
			}

			$models = Automatic_AI_Alt_Text_AI::models( $provider );
			$model  = isset( $input[ $provider . '_model' ] ) ? (string) $input[ $provider . '_model' ] : '';

			$out[ $provider . '_model' ]  = isset( $models[ $model ] ) || 'custom' === $model ? $model : '';
			$custom                       = isset( $input[ $provider . '_custom' ] ) ? trim( sanitize_text_field( wp_unslash( $input[ $provider . '_custom' ] ) ) ) : '';
			$out[ $provider . '_custom' ] = preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/\-]{0,99}$/D', $custom ) ? $custom : '';
		}

		$languages = Automatic_AI_Alt_Text_Generator::languages();

		$out['alt']          = $flag( 'alt' );
		$out['title']        = $flag( 'title' );
		$out['title_mode']   = isset( $input['title_mode'] ) && 'always' === $input['title_mode'] ? 'always' : 'filename';
		$out['language']     = isset( $input['language'], $languages[ $input['language'] ] ) ? $input['language'] : '';
		$out['auto']         = $flag( 'auto' );
		$out['timing']       = isset( $input['timing'] ) && 'upload' === $input['timing'] ? 'upload' : 'background';
		$out['daily_limit']  = $num( 'daily_limit', 0, 100000 );
		$out['fill_content'] = $flag( 'fill_content' );

		if ( ! $out['alt'] && ! $out['title'] ) {
			$out['alt'] = 1;
			add_settings_error( self::PAGE, 'aaat_nothing', __( 'Alt text and titles were both switched off, which leaves nothing to write, so alt text was switched back on.', 'automatic-ai-alt-text' ), 'warning' );
		}

		if ( ! empty( $input['_test'] ) ) {
			set_transient( 'automatic_ai_alt_text_autotest_' . get_current_user_id(), 1, 5 * MINUTE_IN_SECONDS );
		}

		return $out;
	}

	/**
	 * Run the connection test (after "Save & test connection").
	 *
	 * @return void
	 */
	public function ajax_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You don\'t have permission to do that.', 'automatic-ai-alt-text' ) ), 403 );
		}

		if ( ! check_ajax_referer( Automatic_AI_Alt_Text_Bulk::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'automatic-ai-alt-text' ) ), 403 );
		}

		$result = Automatic_AI_Alt_Text_Generator::test_connection();

		if ( is_wp_error( $result ) ) {
			Automatic_AI_Alt_Text_Connection::mark_failed( $result->get_error_message() );

			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				)
			);
		}

		Automatic_AI_Alt_Text_Connection::mark_ready( $result['alt'] );

		if ( Automatic_AI_Alt_Text_Queue::queued( 1 ) ) {
			Automatic_AI_Alt_Text_Queue::schedule();
		}

		$result['costLabel'] = null === $result['cost'] ? __( 'unknown', 'automatic-ai-alt-text' ) : Automatic_AI_Alt_Text_AI::money( $result['cost'] / 1000000 );

		wp_send_json_success( $result );
	}

	/**
	 * Bulk "Undo" on the History list.
	 *
	 * @return void
	 */
	public function handle_bulk_undo() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below before acting.
		$action = isset( $_REQUEST['action'] ) && 'undo' === $_REQUEST['action'] ? 'undo' : ( isset( $_REQUEST['action2'] ) && 'undo' === $_REQUEST['action2'] ? 'undo' : '' );
		$ids    = isset( $_REQUEST['changes'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['changes'] ) ) : array();
		// phpcs:enable

		if ( 'undo' !== $action || ! $ids ) {
			return;
		}

		check_admin_referer( 'bulk-changes' );

		Automatic_AI_Alt_Text_History::bulk_undo( $ids );
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="automatic-ai-alt-text-menu-icon-style">'
			. '#adminmenu a:has(>.automatic-ai-alt-text-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .automatic-ai-alt-text-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .automatic-ai-alt-text-menu-icon,#adminmenu a:hover .automatic-ai-alt-text-menu-icon,#adminmenu a:focus .automatic-ai-alt-text-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'automatic-ai-alt-text-admin', AUTOMATIC_AI_ALT_TEXT_URL . 'assets/admin.css', array(), AUTOMATIC_AI_ALT_TEXT_VERSION );
		wp_enqueue_script( 'automatic-ai-alt-text-admin', AUTOMATIC_AI_ALT_TEXT_URL . 'assets/admin.js', array(), AUTOMATIC_AI_ALT_TEXT_VERSION, true );

		$test = Automatic_AI_Alt_Text_Log::test_results( Automatic_AI_Alt_Text_Bulk::test_fingerprint() );
		$avg  = Automatic_AI_Alt_Text_Log::average_cost( Automatic_AI_Alt_Text_Connection::model() );

		if ( null === $avg ) {
			$est = Automatic_AI_Alt_Text_AI::estimate_per_thousand( Automatic_AI_Alt_Text_Connection::model() );
			$avg = null === $est ? null : (int) round( $est * 1000 );
		}

		wp_localize_script(
			'automatic-ai-alt-text-admin',
			'automatic_ai_alt_text_admin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( Automatic_AI_Alt_Text_Bulk::NONCE ),
				'mediaNonce'     => wp_create_nonce( Automatic_AI_Alt_Text_Media::NONCE ),
				'instructions'   => Automatic_AI_Alt_Text_Plugin::default_instructions(),
				'testSize'       => Automatic_AI_Alt_Text_Bulk::TEST_SIZE,
				'avgCost'        => $test['count'] ? $test['avg'] : $avg,
				'connectedLabel' => $this->connected_label(),
				'okIcon'         => self::icon( 'circle-check', 16 ),
				'okIconSmall'    => self::icon( 'circle-check', 15 ),
				'i18n'           => array(
					'connected'    => __( 'Connected and tested', 'automatic-ai-alt-text' ),
					'notConnected' => __( 'The test failed: fix the problem below and test again', 'automatic-ai-alt-text' ),
					'testing'      => __( 'Sending a real image to the AI...', 'automatic-ai-alt-text' ),
					'testOk'       => __( 'Connected. Here\'s what the AI wrote for your image (nothing was saved):', 'automatic-ai-alt-text' ),
					'testFailed'   => __( 'The test failed:', 'automatic-ai-alt-text' ),
					'cost'         => __( 'Cost of this test:', 'automatic-ai-alt-text' ),
					'failed'       => __( 'Something went wrong. Reload the page and try again.', 'automatic-ai-alt-text' ),
					'resetConfirm' => __( 'Replace your instructions with the default ones?', 'automatic-ai-alt-text' ),
					'skipped'      => __( 'Nothing to do', 'automatic-ai-alt-text' ),
					'undo'         => __( 'Undo', 'automatic-ai-alt-text' ),
					'title'        => __( 'Title:', 'automatic-ai-alt-text' ),
					'before'       => __( 'Before:', 'automatic-ai-alt-text' ),
					'empty'        => __( 'empty', 'automatic-ai-alt-text' ),
					/* translators: %d: seconds. */
					'waiting'      => __( 'The AI is busy. Waiting %d seconds, then trying again...', 'automatic-ai-alt-text' ),
					'stopped'      => __( 'Stopped.', 'automatic-ai-alt-text' ),
					'stoppedError' => __( 'Stopped because of an error:', 'automatic-ai-alt-text' ),
					'tooMany'      => __( 'Stopped after several images in a row failed. Check the errors below and the History tab.', 'automatic-ai-alt-text' ),
					'finished'     => __( 'Finished.', 'automatic-ai-alt-text' ),
					'noImages'     => __( 'No images left to do in this scope.', 'automatic-ai-alt-text' ),
					/* translators: 1: images done, 2: images in the batch. */
					'progress'     => __( '%1$d of %2$d done', 'automatic-ai-alt-text' ),
					/* translators: %s: dollar amount. */
					'spent'        => __( 'Estimated cost: %s', 'automatic-ai-alt-text' ),
					'testDone'     => __( 'Test finished. Check the results above, then unlock bulk mode.', 'automatic-ai-alt-text' ),
					'testNone'     => __( 'None of the test images came back. Check the errors above.', 'automatic-ai-alt-text' ),
					'leave'        => __( 'A batch is still running. Leave the page and stop it?', 'automatic-ai-alt-text' ),
					'saving'       => __( 'Saving...', 'automatic-ai-alt-text' ),
					'writing'      => __( 'Writing...', 'automatic-ai-alt-text' ),
					'confirmAll'   => __( 'Process every remaining image now? This could take a long time and cost more than you expect. Smaller batches are safer.', 'automatic-ai-alt-text' ),
					/* translators: 1: number of images, 2: cost per image, 3: total cost. */
					'projection'   => __( '%1$s images at about %2$s each: roughly %3$s for this batch, based on your test run.', 'automatic-ai-alt-text' ),
				),
			)
		);
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Automatic_AI_Alt_Text_Icons::get( $name, $size );
	}

	/**
	 * Tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'settings' => __( 'Settings', 'automatic-ai-alt-text' ),
			'bulk'     => __( 'Bulk Generate', 'automatic-ai-alt-text' ),
			'history'  => __( 'History', 'automatic-ai-alt-text' ),
			'advanced' => __( 'Advanced', 'automatic-ai-alt-text' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * Render the page: a header with the tabs, then the tab.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = $this->current_tab();
		?>
		<div class="aaat-header">
			<div class="aaat-header-title">
				<span class="aaat-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="aaat-version"><?php echo esc_html( 'v' . AUTOMATIC_AI_ALT_TEXT_VERSION ); ?></span>
			</div>
			<nav class="aaat-tabs" aria-label="<?php esc_attr_e( 'Sections', 'automatic-ai-alt-text' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $id ) ); ?>" class="aaat-tab<?php echo $tab === $id ? ' is-active' : ''; ?>"<?php echo $tab === $id ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap aaat-wrap">
			<hr class="wp-header-end">
			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors( self::PAGE );
			settings_errors( 'general' );

			$notice = Automatic_AI_Alt_Text_History::take_notice();

			if ( $notice ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $notice[0] ? 'error' : 'success', esc_html( $notice[1] ) );
			}

			$this->render_status_notice( $tab );

			switch ( $tab ) {
				case 'bulk':
					$this->render_bulk_tab();
					break;
				case 'history':
					$this->render_history_tab();
					break;
				case 'advanced':
					$this->render_form_tab( 'advanced' );
					break;
				default:
					$this->render_form_tab( 'settings' );
			}
			?>
		</div>
		<?php
	}

	/**
	 * A warning above every tab when the AI is paused or not set up.
	 *
	 * @param string $tab Current tab.
	 * @return void
	 */
	private function render_status_notice( $tab ) {
		$status = Automatic_AI_Alt_Text_Connection::status();
		$state  = Automatic_AI_Alt_Text_Connection::state();

		if ( 'paused' === $status ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Paused: no images are being sent to the AI.', 'automatic-ai-alt-text' ) . '</strong> ' . esc_html( $state['error'] ) . ' ';
			echo '<a href="' . esc_url( self::tab_url( 'settings' ) . '#aaat-box-connection' ) . '">' . esc_html__( 'Fix the connection and test it again', 'automatic-ai-alt-text' ) . '</a></p></div>';
			return;
		}

		if ( 'settings' !== $tab && 'advanced' !== $tab && 'ready' !== $status ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Connect an AI provider and pass the connection test before generating alt text.', 'automatic-ai-alt-text' ) . ' <a href="' . esc_url( self::tab_url( 'settings' ) ) . '">' . esc_html__( 'Go to Settings', 'automatic-ai-alt-text' ) . '</a></p></div>';
		}
	}

	/**
	 * A settings form tab (Settings or Advanced) in WordPress's two-column
	 * box layout, with Save and status on the right.
	 *
	 * @param string $tab "settings" or "advanced".
	 * @return void
	 */
	private function render_form_tab( $tab ) {
		$autotest = false;

		if ( 'settings' === $tab && get_transient( 'automatic_ai_alt_text_autotest_' . get_current_user_id() ) ) {
			delete_transient( 'automatic_ai_alt_text_autotest_' . get_current_user_id() );
			$autotest = 'missing' !== Automatic_AI_Alt_Text_Connection::status();
		}
		?>
		<form action="options.php" method="post" class="aaat-form" data-aaat-autotest="<?php echo $autotest ? '1' : '0'; ?>">
			<?php settings_fields( self::PAGE ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::name( '_tab' ) ); ?>" value="<?php echo esc_attr( $tab ); ?>">

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php
						if ( 'advanced' === $tab ) {
							$this->box( 'prompt', 'message-square-text', __( 'Instructions for the AI', 'automatic-ai-alt-text' ), 'render_prompt' );
							$this->box( 'context', 'file-image', __( 'Context sent with each image', 'automatic-ai-alt-text' ), 'render_context' );
							$this->box( 'detail', 'image-upscale', __( 'Image detail and length', 'automatic-ai-alt-text' ), 'render_detail' );
							$this->box( 'data', 'history', __( 'History', 'automatic-ai-alt-text' ), 'render_data' );
						} else {
							$this->box( 'connection', 'plug-zap', __( 'AI provider', 'automatic-ai-alt-text' ), 'render_connection' );
							$this->box( 'write', 'text-cursor-input', __( 'What to write', 'automatic-ai-alt-text' ), 'render_write' );
							$this->box( 'uploads', 'image-up', __( 'New uploads', 'automatic-ai-alt-text' ), 'render_uploads' );
							$this->box( 'pages', 'layout-template', __( 'Your pages and page builders', 'automatic-ai-alt-text' ), 'render_pages' );
						}
						?>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="aaat-sidebar">
							<?php
							$this->render_save_box( $tab );
							$this->render_status_box();
							$this->render_updates_box();
							?>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>
		</form>
		<?php
	}

	/**
	 * One box.
	 *
	 * @param string $id     Box ID.
	 * @param string $icon   Lucide icon.
	 * @param string $title  Heading.
	 * @param string $render Method that prints the inside.
	 * @param string $extra  Extra classes.
	 * @return void
	 */
	private function box( $id, $icon, $title, $render, $extra = '' ) {
		?>
		<div class="postbox aaat-box <?php echo esc_attr( $extra ); ?>" id="aaat-box-<?php echo esc_attr( $id ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $title ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php call_user_func( array( $this, $render ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Automatic_AI_Alt_Text_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private function value( $key ) {
		return $this->plugin->get_setting( $key );
	}

	/**
	 * Checkbox with a label and optional description.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="aaat-check"><input type="checkbox" name="%1$s" value="1" data-aaat-toggle="%2$s" %3$s> <span>%4$s</span></label>',
			esc_attr( self::name( $key ) ),
			esc_attr( $key ),
			checked( 1, (int) $this->value( $key ), false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Radio option cards.
	 *
	 * @param string $key     Setting key.
	 * @param array  $options Value => array( icon, title, text ).
	 * @return void
	 */
	private function option_cards( $key, array $options ) {
		echo '<div class="aaat-options">';

		foreach ( $options as $value => $option ) {
			printf(
				'<label class="aaat-option"><input type="radio" name="%1$s" value="%2$s" %3$s><span class="aaat-option-icon">%4$s</span><span class="aaat-option-text"><strong>%5$s</strong><span>%6$s</span></span></label>',
				esc_attr( self::name( $key ) ),
				esc_attr( $value ),
				checked( (string) $this->value( $key ), (string) $value, false ),
				self::icon( $option[0], 18 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				esc_html( $option[1] ),
				esc_html( $option[2] )
			);
		}

		echo '</div>';
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @param string   $depends   Setting that has to be on for this row to apply.
	 * @return void
	 */
	private function row( $label, $content, $label_for = '', $depends = '' ) {
		echo '<tr' . ( '' !== $depends ? ' data-aaat-depends="' . esc_attr( $depends ) . '"' : '' ) . '><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * Settings: provider, key, model and the test.
	 *
	 * @return void
	 */
	public function render_connection() {
		$providers = Automatic_AI_Alt_Text_AI::providers();
		$current   = Automatic_AI_Alt_Text_Connection::provider();
		$status    = Automatic_AI_Alt_Text_Connection::status();
		$state     = Automatic_AI_Alt_Text_Connection::state();
		$cards     = array();

		foreach ( $providers as $provider => $info ) {
			$cards[ $provider ] = array(
				$info['icon'],
				$info['label'],
				/* translators: %s: company name. */
				sprintf( __( 'by %s, with your own API key', 'automatic-ai-alt-text' ), $info['company'] ),
			);
		}

		echo '<p class="aaat-intro">' . esc_html__( 'Choose the AI that writes your alt text. It uses your own API key, so usage is billed by the provider to your account. Nothing is sent until the connection test below passes.', 'automatic-ai-alt-text' ) . '</p>';
		echo '<div class="aaat-provider-pick">';
		$this->option_cards( 'provider', $cards );
		echo '</div>';

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $providers as $provider => $info ) {
			$this->render_provider_rows( $provider, $info, $provider === $current );
		}

		$this->row(
			__( 'Connection', 'automatic-ai-alt-text' ),
			function () use ( $status, $state ) {
				$labels = array(
					'ready'    => array( 'is-ok', 'circle-check', __( 'Connected and tested', 'automatic-ai-alt-text' ) ),
					'untested' => array( 'is-warn', 'circle-alert', __( 'Not tested yet: save and test to switch it on', 'automatic-ai-alt-text' ) ),
					'paused'   => array( 'is-bad', 'circle-pause', __( 'Paused after an error', 'automatic-ai-alt-text' ) ),
					'missing'  => array( 'is-none', 'plug', __( 'Add an API key and choose a model', 'automatic-ai-alt-text' ) ),
				);
				$label  = $labels[ $status ];

				echo '<p class="aaat-connection ' . esc_attr( $label[0] ) . '">' . self::icon( $label[1], 16 ) . '<span>' . esc_html( $label[2] ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.

				if ( 'ready' === $status && $state['time'] ) {
					/* translators: 1: when, 2: sample alt text. */
					echo '<p class="description">' . esc_html( sprintf( __( 'Tested %1$s ago. It wrote: "%2$s"', 'automatic-ai-alt-text' ), human_time_diff( (int) $state['time'] ), $state['sample'] ) ) . '</p>';
				} elseif ( '' !== $state['error'] && 'missing' !== $status ) {
					echo '<p class="description aaat-error-text">' . esc_html( $state['error'] ) . '</p>';
				}

				echo '<p class="aaat-test-actions">';
				submit_button( __( 'Save & test connection', 'automatic-ai-alt-text' ), 'secondary', self::name( '_test' ), false, array( 'id' => 'aaat-save-test' ) );
				echo '<span class="aaat-hint">' . esc_html__( 'Sends one real image (your newest upload, or a sample) and shows what comes back. Costs a fraction of a cent.', 'automatic-ai-alt-text' ) . '</span>';
				echo '</p>';
				echo '<div class="aaat-test-result" aria-live="polite"></div>';
			}
		);

		echo '</tbody></table>';
	}

	/**
	 * Key and model rows for one provider (only the chosen one shows).
	 *
	 * @param string $provider Provider key.
	 * @param array  $info     Provider details.
	 * @param bool   $active   Whether it's the chosen provider.
	 * @return void
	 */
	private function render_provider_rows( $provider, array $info, $active ) {
		$constant   = Automatic_AI_Alt_Text_Connection::key_from_constant( $provider );
		$hint       = Automatic_AI_Alt_Text_Connection::key_hint( $provider );
		$unreadable = Automatic_AI_Alt_Text_Connection::key_unreadable( $provider );
		$model      = (string) $this->value( $provider . '_model' );
		$models     = Automatic_AI_Alt_Text_AI::models( $provider );

		echo '<tr data-aaat-provider="' . esc_attr( $provider ) . '"' . ( $active ? '' : ' hidden' ) . '><th scope="row"><label for="aaat-key-' . esc_attr( $provider ) . '">';
		/* translators: %s: provider name. */
		echo esc_html( sprintf( __( '%s API key', 'automatic-ai-alt-text' ), $info['label'] ) );
		echo '</label></th><td>';

		if ( $constant ) {
			echo '<input type="password" class="regular-text" id="aaat-key-' . esc_attr( $provider ) . '" value="••••••••••••••••" disabled>';
			/* translators: %s: constant name. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Set in wp-config.php (%s).', 'automatic-ai-alt-text' ), $info['constant'] ) ) . '</p>';
		} else {
			printf(
				'<input type="password" class="regular-text code" id="aaat-key-%1$s" name="%2$s" value="" autocomplete="new-password" spellcheck="false" placeholder="%3$s">',
				esc_attr( $provider ),
				esc_attr( self::name( $provider . '_key_new' ) ),
				esc_attr( '' !== $hint ? sprintf( /* translators: %s: last 4 characters. */ __( 'Saved key ending in %s', 'automatic-ai-alt-text' ), $hint ) : $info['prefix'] . '...' )
			);

			if ( $unreadable ) {
				echo '<p class="description aaat-error-text">' . esc_html__( 'The saved key can\'t be read any more (this site\'s security keys in wp-config.php changed). Paste it again.', 'automatic-ai-alt-text' ) . '</p>';
			} elseif ( '' !== $hint ) {
				echo '<p class="description">' . esc_html__( 'Saved and encrypted. Leave this blank to keep it.', 'automatic-ai-alt-text' ) . ' ';
				printf(
					'<label class="aaat-inline-check"><input type="checkbox" name="%1$s" value="1"> %2$s</label>',
					esc_attr( self::name( $provider . '_key_remove' ) ),
					esc_html__( 'Remove the saved key', 'automatic-ai-alt-text' )
				);
				echo '</p>';
			} else {
				echo '<p class="description">';
				printf(
					/* translators: %s: link to the provider's key page. */
					esc_html__( 'Create one at %s. It\'s encrypted before it\'s stored, and never shown again.', 'automatic-ai-alt-text' ),
					'<a href="' . esc_url( $info['keys'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( wp_parse_url( $info['keys'], PHP_URL_HOST ) ) . '</a>'
				);
				echo '</p>';
			}
		}

		echo '</td></tr>';

		echo '<tr data-aaat-provider="' . esc_attr( $provider ) . '"' . ( $active ? '' : ' hidden' ) . '><th scope="row"><label for="aaat-model-' . esc_attr( $provider ) . '">' . esc_html__( 'Model', 'automatic-ai-alt-text' ) . '</label></th><td>';
		echo '<select id="aaat-model-' . esc_attr( $provider ) . '" name="' . esc_attr( self::name( $provider . '_model' ) ) . '" class="aaat-model-select">';
		echo '<option value=""' . selected( $model, '', false ) . '>' . esc_html__( 'Choose a model...', 'automatic-ai-alt-text' ) . '</option>';

		foreach ( $models as $id => $label ) {
			$per = Automatic_AI_Alt_Text_AI::estimate_per_thousand( $id );
			/* translators: 1: model label, 2: estimated cost. */
			$text = null === $per ? $label : sprintf( __( '%1$s (about %2$s per 1,000 images)', 'automatic-ai-alt-text' ), $label, Automatic_AI_Alt_Text_AI::money( $per ) );

			echo '<option value="' . esc_attr( $id ) . '"' . selected( $model, $id, false ) . '>' . esc_html( $text ) . '</option>';
		}

		echo '<option value="custom"' . selected( $model, 'custom', false ) . '>' . esc_html__( 'Another model (type its ID)', 'automatic-ai-alt-text' ) . '</option>';
		echo '</select>';

		printf(
			'<p class="aaat-custom-model"%1$s><input type="text" class="regular-text code" name="%2$s" value="%3$s" placeholder="%4$s" spellcheck="false"></p>',
			'custom' === $model ? '' : ' hidden',
			esc_attr( self::name( $provider . '_custom' ) ),
			esc_attr( (string) $this->value( $provider . '_custom' ) ),
			esc_attr( 'anthropic' === $provider ? 'claude-sonnet-4-6' : 'gpt-5-mini' )
		);

		echo '<p class="description">' . esc_html__( 'Cheaper models are usually plenty for alt text. Prices are rough estimates from the provider\'s list prices; your real cost per image shows after the first few images.', 'automatic-ai-alt-text' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Settings: alt text, titles and language.
	 *
	 * @return void
	 */
	public function render_write() {
		$languages = Automatic_AI_Alt_Text_Generator::languages();
		$chosen    = (string) $this->value( 'language' );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->row(
			__( 'Alt text', 'automatic-ai-alt-text' ),
			function () {
				$this->checkbox( 'alt', __( 'Write alt text', 'automatic-ai-alt-text' ), __( 'New uploads only get alt text when theirs is empty. Bulk Generate can also rewrite existing alt text, if you choose.', 'automatic-ai-alt-text' ) );
			}
		);

		$this->row(
			__( 'Titles', 'automatic-ai-alt-text' ),
			function () {
				$this->checkbox( 'title', __( 'Write image titles too', 'automatic-ai-alt-text' ) );
				echo '<div class="aaat-radios aaat-sub" data-aaat-depends="title">';
				foreach (
					array(
						'filename' => __( 'Only replace titles that are still the file name (IMG_2034, screenshot-1, DSC00412)', 'automatic-ai-alt-text' ),
						'always'   => __( 'Always replace the title', 'automatic-ai-alt-text' ),
					) as $value => $label
				) {
					printf(
						'<label><input type="radio" name="%1$s" value="%2$s" %3$s> %4$s</label>',
						esc_attr( self::name( 'title_mode' ) ),
						esc_attr( $value ),
						checked( (string) $this->value( 'title_mode' ), $value, false ),
						esc_html( $label )
					);
				}
				echo '</div>';
				echo '<p class="description">' . esc_html__( 'The title is the image\'s name in the Media Library. Some themes and page builders show it as a tooltip or use it when alt text is missing.', 'automatic-ai-alt-text' ) . '</p>';
			}
		);

		$this->row(
			__( 'Language', 'automatic-ai-alt-text' ),
			function () use ( $languages, $chosen ) {
				$site = Automatic_AI_Alt_Text_Generator::languages();
				$name = '';

				foreach ( $site as $code => $label ) {
					if ( get_locale() === $code ) {
						$name = $label;
					}
				}

				echo '<select id="aaat-language" name="' . esc_attr( self::name( 'language' ) ) . '">';
				/* translators: %s: language name. */
				echo '<option value="">' . esc_html( '' !== $name ? sprintf( __( 'Site language (%s)', 'automatic-ai-alt-text' ), $name ) : __( 'Site language', 'automatic-ai-alt-text' ) ) . '</option>';

				foreach ( $languages as $code => $label ) {
					echo '<option value="' . esc_attr( $code ) . '"' . selected( $chosen, $code, false ) . '>' . esc_html( $label ) . '</option>';
				}

				echo '</select>';
			},
			'aaat-language'
		);

		echo '</tbody></table>';
	}

	/**
	 * Settings: new uploads.
	 *
	 * @return void
	 */
	public function render_uploads() {
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->row(
			__( 'Automatic', 'automatic-ai-alt-text' ),
			function () {
				$this->checkbox( 'auto', __( 'Write alt text for new images automatically', 'automatic-ai-alt-text' ), __( 'Works for images uploaded anywhere: the Media Library, the block and classic editors, WooCommerce products and page builders. Starts once the connection test has passed. Images already in your library are left alone; use Bulk Generate for those.', 'automatic-ai-alt-text' ) );
			}
		);

		$this->row(
			__( 'When', 'automatic-ai-alt-text' ),
			function () {
				echo '<div class="aaat-radios">';
				foreach (
					array(
						'background' => array( __( 'In the background (recommended)', 'automatic-ai-alt-text' ), __( 'Uploads stay exactly as fast as now. The alt text arrives a few seconds to a minute later, and your pages pick it up automatically.', 'automatic-ai-alt-text' ) ),
						'upload'     => array( __( 'Right away, during the upload', 'automatic-ai-alt-text' ), __( 'The alt text is already there when the image lands in the editor. Each upload waits for the AI, usually 2 to 5 seconds.', 'automatic-ai-alt-text' ) ),
					) as $value => $label
				) {
					printf(
						'<label><input type="radio" name="%1$s" value="%2$s" %3$s> <strong>%4$s</strong><span class="description aaat-radio-desc">%5$s</span></label>',
						esc_attr( self::name( 'timing' ) ),
						esc_attr( $value ),
						checked( (string) $this->value( 'timing' ), $value, false ),
						esc_html( $label[0] ),
						esc_html( $label[1] )
					);
				}
				echo '</div>';
			},
			'',
			'auto'
		);

		$this->row(
			__( 'Daily limit', 'automatic-ai-alt-text' ),
			function () {
				printf(
					'<input type="number" class="small-text" id="aaat-daily" name="%1$s" value="%2$d" min="0" max="100000" step="1"> %3$s',
					esc_attr( self::name( 'daily_limit' ) ),
					(int) $this->value( 'daily_limit' ),
					esc_html__( 'new images a day', 'automatic-ai-alt-text' )
				);
				echo '<p class="description">' . esc_html__( 'A safety net for imports and migrations that add thousands of images at once. Images over the limit are skipped (and listed in History); Bulk Generate can fill them in later. 0 means no limit.', 'automatic-ai-alt-text' ) . '</p>';
				/* translators: %d: images today. */
				echo '<p class="description">' . esc_html( sprintf( _n( '%d automatic image so far today.', '%d automatic images so far today.', Automatic_AI_Alt_Text_Queue::today(), 'automatic-ai-alt-text' ), Automatic_AI_Alt_Text_Queue::today() ) ) . '</p>';
			},
			'aaat-daily',
			'auto'
		);

		echo '</tbody></table>';
	}

	/**
	 * Settings: posts, pages and page builders.
	 *
	 * @return void
	 */
	public function render_pages() {
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->row(
			__( 'Existing posts', 'automatic-ai-alt-text' ),
			function () {
				$this->checkbox( 'fill_content', __( 'Use the new alt text in posts and pages that already contain the image', 'automatic-ai-alt-text' ), __( 'The block and classic editors copy alt text into the post when an image is inserted, so an image inserted with empty alt text stays empty there. This adds the alt text the AI wrote as the page is shown, without editing your posts. Alt text you typed into a post always wins, and images marked as decorative are left alone.', 'automatic-ai-alt-text' ) );
			}
		);

		echo '</tbody></table>';

		$this->render_builder_note();
	}

	/**
	 * Note about page builders, naming the ones that are active.
	 *
	 * @return void
	 */
	private function render_builder_note() {
		$builders = Automatic_AI_Alt_Text_Builders::detect();

		if ( ! $builders ) {
			echo '<p class="aaat-callout">' . self::icon( 'circle-check', 16 ) . '<span>' . esc_html__( 'No page builder detected. Images in the block editor, the classic editor, featured images, galleries and WooCommerce products all get the AI\'s alt text.', 'automatic-ai-alt-text' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		$reads  = array();
		$copies = array();

		foreach ( $builders as $builder ) {
			if ( $builder['reads_library'] ) {
				$reads[] = $builder['name'];
			} else {
				$copies[] = $builder['name'];
			}
		}

		echo '<div class="aaat-callout is-warning">' . self::icon( 'puzzle', 16 ) . '<div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
		/* translators: %s: page builder names. */
		echo '<p><strong>' . esc_html( sprintf( _n( 'Page builder detected: %s', 'Page builders detected: %s', count( $builders ), 'automatic-ai-alt-text' ), wp_sprintf_l( '%l', wp_list_pluck( $builders, 'name' ) ) ) ) . '</strong></p>';
		echo '<p>' . esc_html__( 'This plugin is designed for the block and classic editors. Page builders work outside the native WordPress editor and keep their own copy of each image\'s settings, so images inserted with a page builder may not get the AI\'s alt text on your pages. The alt text is always saved to the Media Library either way.', 'automatic-ai-alt-text' ) . '</p>';

		if ( $reads ) {
			/* translators: %s: page builder names. */
			echo '<p>' . esc_html( sprintf( __( '%s: the Image widget reads alt text from the Media Library each time a page is shown, so those images do pick it up. Images used as backgrounds or in sliders and add-on widgets may not.', 'automatic-ai-alt-text' ), wp_sprintf_l( '%l', $reads ) ) ) . '</p>';
		}

		if ( $copies ) {
			/* translators: %s: page builder names. */
			echo '<p>' . esc_html( sprintf( __( '%s: check the alt text in the builder\'s image settings. Images placed before the AI filled them in may need their alt text copied in, or the image picked again.', 'automatic-ai-alt-text' ), wp_sprintf_l( '%l', $copies ) ) ) . '</p>';
		}

		echo '</div></div>';
	}

	/**
	 * Advanced: the editable instructions.
	 *
	 * @return void
	 */
	public function render_prompt() {
		echo '<p class="aaat-intro">' . esc_html__( 'Tell the AI how to describe your images: what to focus on, what to leave out, the tone, words your brand uses. These instructions are sent with every image.', 'automatic-ai-alt-text' ) . '</p>';
		printf(
			'<textarea id="aaat-instructions" name="%1$s" rows="10" class="large-text aaat-prompt" maxlength="4000">%2$s</textarea>',
			esc_attr( self::name( 'instructions' ) ),
			esc_textarea( (string) $this->value( 'instructions' ) )
		);
		echo '<p class="aaat-inline"><button type="button" class="button" id="aaat-reset-prompt">' . self::icon( 'rotate-ccw', 15 ) . esc_html__( 'Reset to default', 'automatic-ai-alt-text' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
		echo '<span class="description">' . esc_html__( 'Examples: "Mention the product name when it\'s visible." "Never describe people\'s clothing." "We\'re a bakery: name the type of pastry."', 'automatic-ai-alt-text' ) . '</span></p>';

		echo '<details class="aaat-rules"><summary>' . esc_html__( 'Rules the plugin always adds', 'automatic-ai-alt-text' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'These keep the answer in a shape the plugin can read and stop text inside an image from steering the AI, so they can\'t be edited.', 'automatic-ai-alt-text' ) . '</p>';
		echo '<pre>' . esc_html( Automatic_AI_Alt_Text_Generator::rules( (bool) $this->value( 'alt' ) || ! $this->value( 'title' ), (bool) $this->value( 'title' ) ) ) . '</pre></details>';
	}

	/**
	 * Advanced: context.
	 *
	 * @return void
	 */
	public function render_context() {
		echo '<p class="aaat-intro">' . esc_html__( 'A little context helps the AI name things it can\'t know from the picture alone, like a product or a place.', 'automatic-ai-alt-text' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->row(
			__( 'Send with each image', 'automatic-ai-alt-text' ),
			function () {
				$this->checkbox( 'context_parent', __( 'The title of the post or page it was uploaded to', 'automatic-ai-alt-text' ) );
				echo '<br>';
				$this->checkbox( 'context_filename', __( 'The file name', 'automatic-ai-alt-text' ) );
				echo '<p class="description">' . esc_html__( 'Your site\'s name is always included. The AI is told to use these only as hints.', 'automatic-ai-alt-text' ) . '</p>';
			}
		);

		echo '</tbody></table>';
	}

	/**
	 * Advanced: image size and alt length.
	 *
	 * @return void
	 */
	public function render_detail() {
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->row(
			__( 'Image sent to the AI', 'automatic-ai-alt-text' ),
			function () {
				echo '<div class="aaat-radios">';
				foreach (
					array(
						'small'  => array( __( 'Small (512 px)', 'automatic-ai-alt-text' ), __( 'Cheapest. Fine for photos with one clear subject.', 'automatic-ai-alt-text' ) ),
						'medium' => array( __( 'Medium (768 px, recommended)', 'automatic-ai-alt-text' ), __( 'Enough detail to read most text and spot small objects.', 'automatic-ai-alt-text' ) ),
						'large'  => array( __( 'Large (1024 px)', 'automatic-ai-alt-text' ), __( 'For screenshots, charts and infographics with small text. Costs roughly twice as much as medium.', 'automatic-ai-alt-text' ) ),
					) as $value => $label
				) {
					printf(
						'<label><input type="radio" name="%1$s" value="%2$s" %3$s> <strong>%4$s</strong><span class="description aaat-radio-desc">%5$s</span></label>',
						esc_attr( self::name( 'image_size' ) ),
						esc_attr( $value ),
						checked( (string) $this->value( 'image_size' ), $value, false ),
						esc_html( $label[0] ),
						esc_html( $label[1] )
					);
				}
				echo '</div>';
				echo '<p class="description">' . esc_html__( 'A smaller copy of the image is sent, never the full-size original. WordPress\'s own resized copies are used, so nothing extra is created on your server.', 'automatic-ai-alt-text' ) . '</p>';
			}
		);

		$this->row(
			__( 'Longest alt text', 'automatic-ai-alt-text' ),
			function () {
				printf(
					'<input type="number" class="small-text" id="aaat-max" name="%1$s" value="%2$d" min="40" max="300" step="5"> %3$s',
					esc_attr( self::name( 'max_length' ) ),
					(int) $this->value( 'max_length' ),
					esc_html__( 'characters', 'automatic-ai-alt-text' )
				);
				echo '<p class="description">' . esc_html__( 'Screen readers read alt text in one go, so shorter is kinder. 125 is a common guideline.', 'automatic-ai-alt-text' ) . '</p>';
			},
			'aaat-max'
		);

		echo '</tbody></table>';
	}

	/**
	 * Advanced: history retention.
	 *
	 * @return void
	 */
	public function render_data() {
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->row(
			__( 'Keep history for', 'automatic-ai-alt-text' ),
			function () {
				printf(
					'<input type="number" class="small-text" id="aaat-days" name="%1$s" value="%2$d" min="0" max="3650" step="1"> %3$s',
					esc_attr( self::name( 'history_days' ) ),
					(int) $this->value( 'history_days' ),
					esc_html__( 'days', 'automatic-ai-alt-text' )
				);
				echo '<p class="description">' . esc_html__( 'Older entries are deleted once a day (0 keeps everything). Only the history goes; the alt text stays on your images. Deleting the plugin removes its settings and history, and leaves all alt text in place.', 'automatic-ai-alt-text' ) . '</p>';
			},
			'aaat-days'
		);

		echo '</tbody></table>';
	}

	/**
	 * Bulk Generate tab.
	 *
	 * @return void
	 */
	private function render_bulk_tab() {
		$stats    = Automatic_AI_Alt_Text_Bulk::stats();
		$ready    = Automatic_AI_Alt_Text_Connection::is_ready();
		$unlocked = Automatic_AI_Alt_Text_Bulk::is_unlocked();
		$test     = Automatic_AI_Alt_Text_Log::test_results( Automatic_AI_Alt_Text_Bulk::test_fingerprint() );
		$info     = Automatic_AI_Alt_Text_Connection::info();
		?>
		<div class="aaat-bulk" data-ready="<?php echo $ready ? '1' : '0'; ?>" data-unlocked="<?php echo $unlocked ? '1' : '0'; ?>" data-missing="<?php echo esc_attr( (string) $stats['missing'] ); ?>" data-total="<?php echo esc_attr( (string) $stats['total'] ); ?>">
			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<div class="postbox aaat-box" id="aaat-box-library">
							<div class="postbox-header"><h2 class="hndle"><?php echo self::icon( 'images' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Your library', 'automatic-ai-alt-text' ); ?></h2></div>
							<div class="inside">
								<div class="aaat-stats">
									<?php
									foreach (
										array(
											array( 'images', $stats['total'], __( 'Images', 'automatic-ai-alt-text' ), '', 'total' ),
											array( 'image-off', $stats['missing'], __( 'Missing alt text', 'automatic-ai-alt-text' ), $stats['missing'] ? 'warn' : 'ok', 'missing' ),
											array( 'sparkles', $stats['done'], __( 'Written by AI', 'automatic-ai-alt-text' ), '', 'done' ),
											array( 'clock', $stats['queued'], __( 'Queued uploads', 'automatic-ai-alt-text' ), '', 'queued' ),
										) as $tile
									) :
										?>
										<div class="aaat-stat<?php echo '' !== $tile[3] ? ' is-' . esc_attr( $tile[3] ) : ''; ?>">
											<span class="aaat-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
											<span class="aaat-stat-num" data-aaat-stat="<?php echo esc_attr( $tile[4] ); ?>"><?php echo esc_html( number_format_i18n( (int) $tile[1] ) ); ?></span>
											<span class="aaat-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
										</div>
									<?php endforeach; ?>
								</div>

								<h3 class="aaat-subhead"><?php esc_html_e( 'Which images', 'automatic-ai-alt-text' ); ?></h3>
								<div class="aaat-options aaat-scope">
									<label class="aaat-option">
										<input type="radio" name="aaat_scope" value="missing" checked>
										<span class="aaat-option-icon"><?php echo self::icon( 'image-plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
										<span class="aaat-option-text">
											<strong><?php esc_html_e( 'Images with no alt text', 'automatic-ai-alt-text' ); ?></strong>
											<span>
												<?php
												/* translators: %s: number of images. */
												echo esc_html( sprintf( _n( '%s image. Alt text you or anyone else wrote is never touched.', '%s images. Alt text you or anyone else wrote is never touched.', $stats['missing'], 'automatic-ai-alt-text' ), number_format_i18n( $stats['missing'] ) ) );
												?>
											</span>
										</span>
									</label>
									<label class="aaat-option">
										<input type="radio" name="aaat_scope" value="all">
										<span class="aaat-option-icon"><?php echo self::icon( 'refresh-cw', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
										<span class="aaat-option-text">
											<strong><?php esc_html_e( 'All images, replacing existing alt text', 'automatic-ai-alt-text' ); ?></strong>
											<span>
												<?php
												/* translators: %s: number of images. */
												echo esc_html( sprintf( _n( '%s image. Every change can be undone from the History tab.', '%s images. Every change can be undone from the History tab.', $stats['total'], 'automatic-ai-alt-text' ), number_format_i18n( $stats['total'] ) ) );
												?>
											</span>
										</span>
									</label>
								</div>
								<?php if ( automatic_ai_alt_text_plugin()->get_setting( 'title' ) ) : ?>
									<p class="description"><?php esc_html_e( 'Titles are written too, as set on the Settings tab.', 'automatic-ai-alt-text' ); ?></p>
								<?php endif; ?>
							</div>
						</div>

						<div class="postbox aaat-box aaat-step<?php echo $unlocked ? ' is-done' : ''; ?>" id="aaat-box-test">
							<div class="postbox-header"><h2 class="hndle"><?php echo self::icon( 'flask-conical' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Step 1: Test on 5 images', 'automatic-ai-alt-text' ); ?></h2></div>
							<div class="inside">
								<p class="aaat-intro">
									<?php esc_html_e( 'Before anything runs on your whole library, try your settings on 5 images and read what comes back. If it\'s not right, adjust the model or the instructions (Advanced tab) and test again. Changing either needs a new test.', 'automatic-ai-alt-text' ); ?>
								</p>

								<?php if ( $unlocked ) : ?>
									<p class="aaat-callout is-ok"><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
										<span>
											<?php
											printf(
												/* translators: 1: number of images, 2: average cost. */
												esc_html__( 'Tested on %1$d images with these settings and approved. Average cost: %2$s per image.', 'automatic-ai-alt-text' ),
												(int) $test['count'],
												esc_html( Automatic_AI_Alt_Text_AI::money( $test['avg'] / 1000000 ) )
											);
											?>
										</span>
									</p>
								<?php elseif ( Automatic_AI_Alt_Text_Bulk::unlocked_at() ) : ?>
									<p class="aaat-callout is-warning"><?php echo self::icon( 'circle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'The model, instructions or another setting that shapes the alt text changed since your last test. Run a new test before the next batch.', 'automatic-ai-alt-text' ); ?></span></p>
								<?php endif; ?>

								<p class="aaat-inline">
									<button type="button" class="button button-primary" id="aaat-run-test"<?php disabled( ! $ready ); ?>>
										<?php echo self::icon( 'play', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
										<?php echo $unlocked ? esc_html__( 'Run another test', 'automatic-ai-alt-text' ) : esc_html__( 'Run the test', 'automatic-ai-alt-text' ); ?>
									</button>
									<span class="description"><?php esc_html_e( 'The 5 results are saved to the images (each one can be undone) and shown below.', 'automatic-ai-alt-text' ); ?></span>
								</p>

								<div class="aaat-run" id="aaat-test-run" hidden>
									<p class="aaat-run-status" aria-live="polite"></p>
									<ul class="aaat-results"></ul>
								</div>

								<div class="aaat-approve" id="aaat-approve" hidden>
									<label class="aaat-check"><input type="checkbox" id="aaat-approve-check"> <span><?php esc_html_e( 'I\'ve read these results and they look right.', 'automatic-ai-alt-text' ); ?></span></label>
									<p><button type="button" class="button button-primary" id="aaat-unlock" disabled><?php echo self::icon( 'lock-open', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Unlock bulk mode', 'automatic-ai-alt-text' ); ?></button></p>
								</div>
							</div>
						</div>

						<div class="postbox aaat-box aaat-step<?php echo $unlocked ? '' : ' is-locked'; ?>" id="aaat-box-batch">
							<div class="postbox-header"><h2 class="hndle"><?php echo self::icon( $unlocked ? 'layers' : 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Step 2: Generate in batches', 'automatic-ai-alt-text' ); ?></h2></div>
							<div class="inside">
								<?php if ( ! $unlocked ) : ?>
									<p class="aaat-locked-note"><?php echo self::icon( 'lock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Locked until a test run with your current settings has been reviewed.', 'automatic-ai-alt-text' ); ?></span></p>
								<?php endif; ?>

								<fieldset class="aaat-batch-fields"<?php disabled( ! $unlocked ); ?>>
									<h3 class="aaat-subhead"><?php esc_html_e( 'Batch size', 'automatic-ai-alt-text' ); ?></h3>
									<div class="aaat-batches">
										<?php
										foreach ( array( 100, 250, 500 ) as $size ) :
											?>
											<label class="aaat-batch">
												<input type="radio" name="aaat_batch" value="<?php echo esc_attr( (string) $size ); ?>" <?php checked( 100, $size ); ?>>
												<strong><?php echo esc_html( number_format_i18n( $size ) ); ?></strong>
												<span class="aaat-batch-cost" data-count="<?php echo esc_attr( (string) $size ); ?>"></span>
												<?php if ( 100 === $size ) : ?>
													<em><?php esc_html_e( 'Recommended', 'automatic-ai-alt-text' ); ?></em>
												<?php endif; ?>
											</label>
										<?php endforeach; ?>
										<label class="aaat-batch is-all">
											<input type="radio" name="aaat_batch" value="all">
											<strong><?php esc_html_e( 'All remaining', 'automatic-ai-alt-text' ); ?></strong>
											<span class="aaat-batch-cost" data-count="all"></span>
										</label>
									</div>

									<div class="aaat-callout is-warning aaat-cost-warning">
										<?php echo self::icon( 'triangle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
										<div>
											<p><strong><?php esc_html_e( 'The cost adds up, and it\'s your responsibility.', 'automatic-ai-alt-text' ); ?></strong></p>
											<p>
												<?php
												printf(
													/* translators: %s: provider company. */
													esc_html__( 'Every image is a separate paid request to %s, billed to your own account. We strongly suggest 100 or 500 images at a time rather than your whole library at once, and checking the results and your usage between batches.', 'automatic-ai-alt-text' ),
													esc_html( $info['company'] )
												);
												?>
											</p>
											<p class="aaat-projection" hidden></p>
										</div>
									</div>

									<p class="aaat-all-confirm" hidden>
										<label class="aaat-check"><input type="checkbox" id="aaat-all-check"> <span><?php esc_html_e( 'I understand processing everything at once could take hours and cost more than I expect.', 'automatic-ai-alt-text' ); ?></span></label>
									</p>
									<p>
										<label class="aaat-check"><input type="checkbox" id="aaat-cost-check"> <span>
											<?php
											printf(
												/* translators: %s: provider company. */
												esc_html__( 'I understand this batch is billed to my %s account and the cost is my responsibility.', 'automatic-ai-alt-text' ),
												esc_html( $info['company'] )
											);
											?>
										</span></label>
									</p>

									<p class="aaat-inline">
										<button type="button" class="button button-primary button-hero aaat-start" id="aaat-start" disabled><?php echo self::icon( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Start batch', 'automatic-ai-alt-text' ); ?></button>
										<button type="button" class="button aaat-stop" id="aaat-stop" hidden><?php echo self::icon( 'square', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Stop', 'automatic-ai-alt-text' ); ?></button>
										<span class="description"><?php esc_html_e( 'Keep this page open while it runs. Closing it or pressing Stop ends the batch straight away.', 'automatic-ai-alt-text' ); ?></span>
									</p>
								</fieldset>

								<div class="aaat-run" id="aaat-batch-run" hidden>
									<div class="aaat-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
									<p class="aaat-run-status" aria-live="polite"></p>
									<ul class="aaat-results"></ul>
								</div>
							</div>
						</div>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="aaat-sidebar">
							<?php
							$this->render_status_box();
							$this->render_cost_box();
							?>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	/**
	 * History tab: the list of changes.
	 *
	 * @return void
	 */
	private function render_history_tab() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once AUTOMATIC_AI_ALT_TEXT_DIR . 'includes/class-automatic-ai-alt-text-history-table.php';

		$table = new Automatic_AI_Alt_Text_History_Table();
		$table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$attachment = isset( $_GET['attachment'] ) ? absint( $_GET['attachment'] ) : 0;
		?>
		<div class="aaat-history">
			<p class="aaat-intro">
				<?php esc_html_e( 'Everything the AI has written, and every edit and undo. Read through it, fix anything with Edit, or put the previous text back with Undo. Only an image\'s newest change can be edited or undone.', 'automatic-ai-alt-text' ); ?>
			</p>
			<?php if ( $attachment ) : ?>
				<p class="aaat-filter-note">
					<?php
					/* translators: %s: image file name. */
					echo esc_html( sprintf( __( 'Showing changes to %s.', 'automatic-ai-alt-text' ), wp_basename( (string) get_attached_file( $attachment ) ) ) );
					?>
					<a href="<?php echo esc_url( self::tab_url( 'history' ) ); ?>"><?php esc_html_e( 'Show all', 'automatic-ai-alt-text' ); ?></a>
				</p>
			<?php endif; ?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="tab" value="history">
				<?php if ( '' !== $view ) : ?>
					<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
				<?php endif; ?>
				<?php if ( $attachment ) : ?>
					<input type="hidden" name="attachment" value="<?php echo esc_attr( (string) $attachment ); ?>">
				<?php endif; ?>
				<?php
				$table->search_box( __( 'Search alt text', 'automatic-ai-alt-text' ), 'aaat-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sidebar: Save.
	 *
	 * @param string $tab Current tab.
	 * @return void
	 */
	private function render_save_box( $tab ) {
		?>
		<div class="postbox aaat-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'automatic-ai-alt-text' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					echo 'advanced' === $tab
						? esc_html__( 'Changing the instructions, context or image size needs a new 5-image test before the next bulk batch.', 'automatic-ai-alt-text' )
						: esc_html__( 'Changing the provider, key or model needs a new connection test. Use "Save & test connection".', 'automatic-ai-alt-text' );
					?>
				</p>
				<?php submit_button( __( 'Save Changes', 'automatic-ai-alt-text' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Sidebar: what's on, and this month's usage.
	 *
	 * @return void
	 */
	private function render_status_box() {
		$status = Automatic_AI_Alt_Text_Connection::status();
		$info   = Automatic_AI_Alt_Text_Connection::info();
		$month  = Automatic_AI_Alt_Text_Log::totals( strtotime( gmdate( 'Y-m-01 00:00:00' ) ) );
		$plugin = $this->plugin;
		$items  = array(
			array( 'ready' === $status, 'ready' === $status ? $this->connected_label() : ( 'paused' === $status ? __( 'Paused after an error', 'automatic-ai-alt-text' ) : __( 'AI not connected yet', 'automatic-ai-alt-text' ) ), 'connection' ),
			array( 'ready' === $status && $plugin->get_setting( 'auto' ), $plugin->get_setting( 'auto' ) ? ( 'upload' === $plugin->get_setting( 'timing' ) ? __( 'New uploads: right away', 'automatic-ai-alt-text' ) : __( 'New uploads: in the background', 'automatic-ai-alt-text' ) ) : __( 'New uploads: off', 'automatic-ai-alt-text' ), 'uploads' ),
			array( (bool) $plugin->get_setting( 'fill_content' ), $plugin->get_setting( 'fill_content' ) ? __( 'Filling empty alt text in posts', 'automatic-ai-alt-text' ) : __( 'Not filling alt text in posts', 'automatic-ai-alt-text' ) ),
			array( Automatic_AI_Alt_Text_Bulk::is_unlocked(), Automatic_AI_Alt_Text_Bulk::is_unlocked() ? __( 'Bulk mode unlocked', 'automatic-ai-alt-text' ) : __( 'Bulk mode locked until tested', 'automatic-ai-alt-text' ) ),
		);
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'gauge' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'automatic-ai-alt-text' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="aaat-checklist">
					<?php foreach ( $items as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"<?php echo isset( $item[2] ) ? ' data-aaat-status="' . esc_attr( $item[2] ) . '" data-aaat-on="' . ( 'uploads' !== $item[2] || $plugin->get_setting( 'auto' ) ? '1' : '0' ) . '"' : ''; ?>><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dashed', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<div class="aaat-usage">
					<span class="aaat-usage-label"><?php esc_html_e( 'This month', 'automatic-ai-alt-text' ); ?></span>
					<span class="aaat-usage-row"><span><?php esc_html_e( 'Images written', 'automatic-ai-alt-text' ); ?></span><strong><?php echo esc_html( number_format_i18n( $month['images'] ) ); ?></strong></span>
					<span class="aaat-usage-row"><span><?php esc_html_e( 'Estimated cost', 'automatic-ai-alt-text' ); ?></span><strong><?php echo esc_html( Automatic_AI_Alt_Text_AI::money( $month['cost'] / 1000000 ) ); ?></strong></span>
				</div>
				<p class="aaat-muted aaat-small">
					<?php
					printf(
						/* translators: %s: provider usage page link. */
						esc_html__( 'Estimates from list prices. Your real bill is on %s.', 'automatic-ai-alt-text' ),
						'<a href="' . esc_url( $info['usage'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( sprintf( /* translators: %s: provider company. */ __( 'your %s usage page', 'automatic-ai-alt-text' ), $info['company'] ) ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * "Claude connected (Claude Haiku 5.5)".
	 *
	 * @return string
	 */
	private function connected_label() {
		$info = Automatic_AI_Alt_Text_Connection::info();

		/* translators: 1: provider, 2: model. */
		return sprintf( __( '%1$s connected (%2$s)', 'automatic-ai-alt-text' ), $info['label'], Automatic_AI_Alt_Text_Connection::model_label() );
	}

	/**
	 * Sidebar: the cost advice on the Bulk tab.
	 *
	 * @return void
	 */
	private function render_cost_box() {
		$info = Automatic_AI_Alt_Text_Connection::info();
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'coins' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Keeping costs in check', 'automatic-ai-alt-text' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="aaat-tips">
					<li><?php esc_html_e( 'Run 100 or 500 images at a time, not the whole library.', 'automatic-ai-alt-text' ); ?></li>
					<li><?php esc_html_e( 'Check the results and your usage between batches.', 'automatic-ai-alt-text' ); ?></li>
					<li><?php esc_html_e( 'A cheaper model and the medium image size are plenty for most alt text.', 'automatic-ai-alt-text' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s: link to the provider's limits page. */
							esc_html__( 'Set a monthly spending limit in %s as a safety net.', 'automatic-ai-alt-text' ),
							'<a href="' . esc_url( $info['limits'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( sprintf( /* translators: %s: provider company. */ __( 'your %s account', 'automatic-ai-alt-text' ), $info['company'] ) ) . '</a>'
						);
						?>
					</li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Sidebar: where updates come from.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( AUTOMATIC_AI_ALT_TEXT_REPO ) );
		$check_url  = Automatic_AI_Alt_Text_Updater::check_now_url();
		?>
		<div class="postbox aaat-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'automatic-ai-alt-text' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'automatic-ai-alt-text' ), '<strong>' . esc_html( AUTOMATIC_AI_ALT_TEXT_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Automatic_AI_Alt_Text_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'automatic-ai-alt-text' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( AUTOMATIC_AI_ALT_TEXT_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Automatic_AI_Alt_Text_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'automatic-ai-alt-text' ),
							'<a href="' . esc_url( AUTOMATIC_AI_ALT_TEXT_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>AUTOMATIC_AI_ALT_TEXT_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'automatic-ai-alt-text' ),
							'<a href="' . esc_url( AUTOMATIC_AI_ALT_TEXT_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'automatic-ai-alt-text' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
