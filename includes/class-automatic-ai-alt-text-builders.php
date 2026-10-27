<?php
/**
 * Page builder detection, for the note about images placed with a builder.
 *
 * Builders keep their own copy of each image's settings, outside the
 * block and classic editors. Elementor's Image widget and Beaver Builder's
 * Photo module read alt text from the Media Library every time the page is
 * shown (checked in their code), so they pick up the AI's alt text. The
 * others may store their own copy when the image is placed, and those
 * copies aren't changed.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builder detection.
 */
final class Automatic_AI_Alt_Text_Builders {

	/**
	 * Active page builders.
	 *
	 * @return array<string,array{name:string,reads_library:bool}>
	 */
	public static function detect() {
		$theme  = wp_get_theme();
		$themes = array( strtolower( (string) $theme->get_template() ), strtolower( (string) $theme->get_stylesheet() ) );
		$found  = array();
		$checks = array(
			'elementor'   => array( 'Elementor', defined( 'ELEMENTOR_VERSION' ), true ),
			'beaver'      => array( 'Beaver Builder', class_exists( 'FLBuilder' ) || defined( 'FL_BUILDER_VERSION' ), true ),
			'divi'        => array( 'Divi', defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_BUILDER_PLUGIN_VERSION' ) || in_array( 'divi', $themes, true ) || in_array( 'extra', $themes, true ), false ),
			'avada'       => array( 'Avada (Fusion Builder)', defined( 'FUSION_BUILDER_VERSION' ) || in_array( 'avada', $themes, true ), false ),
			'wpbakery'    => array( 'WPBakery Page Builder', defined( 'WPB_VC_VERSION' ), false ),
			'oxygen'      => array( 'Oxygen', defined( 'CT_VERSION' ), false ),
			'bricks'      => array( 'Bricks', defined( 'BRICKS_VERSION' ) || in_array( 'bricks', $themes, true ), false ),
			'breakdance'  => array( 'Breakdance', defined( '__BREAKDANCE_VERSION' ), false ),
			'thrive'      => array( 'Thrive Architect', defined( 'TVE_VERSION' ), false ),
			'siteorigin'  => array( 'SiteOrigin Page Builder', defined( 'SITEORIGIN_PANELS_VERSION' ), false ),
			'brizy'       => array( 'Brizy', defined( 'BRIZY_VERSION' ), false ),
			'themify'     => array( 'Themify Builder', defined( 'THEMIFY_VERSION' ) || class_exists( 'Themify_Builder' ), false ),
			'cornerstone' => array( 'Cornerstone', class_exists( 'Cornerstone_Plugin' ) || function_exists( 'cornerstone' ), false ),
			'zion'        => array( 'Zion Builder', defined( 'ZIONBUILDER_VERSION' ), false ),
		);

		foreach ( $checks as $slug => $check ) {
			if ( $check[1] ) {
				$found[ $slug ] = array(
					'name'          => $check[0],
					'reads_library' => $check[2],
				);
			}
		}

		/**
		 * Filters the page builders the settings page warns about.
		 *
		 * @param array<string,array{name:string,reads_library:bool}> $found Slug => name and whether
		 *                                                                    it reads alt text from the Media Library.
		 */
		return (array) apply_filters( 'automatic_ai_alt_text_page_builders', $found );
	}
}
