<?php
/**
 * Your pages: adds the alt text to images already placed in posts.
 *
 * The block and classic editors copy an image's alt text into the post
 * when the image is inserted. An image inserted before it had alt text
 * keeps an empty alt="" in the post, even after the Media Library is
 * filled in. This fills those in as the page is shown, from the Media
 * Library, without rewriting any post content: nothing is stored, it's
 * instantly reversible, and alt text that was typed into the post always
 * wins.
 *
 * Only images this plugin has written text for are touched, and images
 * marked decorative (role="presentation" or aria-hidden) are left alone.
 * Featured images, galleries and anything else drawn with
 * wp_get_attachment_image() read the Media Library already.
 *
 * @package Automatic_AI_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end alt filler.
 */
final class Automatic_AI_Alt_Text_Content {

	/**
	 * Hooks. On WordPress 6.0+ this rides on core's own pass over the
	 * content's images (which already loads their metadata in one query);
	 * older versions get an equivalent filter.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! automatic_ai_alt_text_plugin()->get_setting( 'fill_content' ) ) {
			return;
		}

		if ( version_compare( get_bloginfo( 'version' ), '6.0', '>=' ) ) {
			add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_tag' ), 10, 3 );
			return;
		}

		foreach ( array( 'the_content', 'widget_text_content', 'widget_block_content' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'filter_content' ), 13 );
		}
	}

	/**
	 * Fill one image tag (WordPress 6.0+).
	 *
	 * @param string $tag           The img tag.
	 * @param string $context       Where it's from.
	 * @param int    $attachment_id Image ID, 0 when unknown.
	 * @return string
	 */
	public static function filter_tag( $tag, $context = '', $attachment_id = 0 ) {
		return self::fill( (string) $tag, (int) $attachment_id );
	}

	/**
	 * Fill every image in a block of content (WordPress 5.8 and 5.9).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		if ( ! is_string( $content ) || false === stripos( $content, 'wp-image-' ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			'/<img\s[^>]*>/i',
			function ( $found ) {
				return preg_match( '/\bwp-image-(\d+)\b/i', $found[0], $id ) ? self::fill( $found[0], (int) $id[1] ) : $found[0];
			},
			$content
		);
	}

	/**
	 * Add the Media Library alt text to a tag whose alt is missing or empty.
	 *
	 * @param string $tag           The img tag.
	 * @param int    $attachment_id Image ID.
	 * @return string
	 */
	private static function fill( $tag, $attachment_id ) {
		if ( $attachment_id <= 0 || ! preg_match( '/^<img\b/i', $tag ) ) {
			return $tag;
		}

		$has_alt = preg_match( '/\salt\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $alt );

		if ( $has_alt && '' !== trim( implode( '', array_slice( $alt, 1 ) ) ) ) {
			return $tag;
		}

		if ( preg_match( '/\srole\s*=\s*["\']?(?:presentation|none)\b|\saria-hidden\s*=\s*["\']?true\b/i', $tag ) ) {
			return $tag;
		}

		if ( ! get_post_meta( $attachment_id, Automatic_AI_Alt_Text_Generator::META_TIME, true ) ) {
			return $tag;
		}

		$text = trim( wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) );

		if ( '' === $text ) {
			return $tag;
		}

		$attribute = ' alt="' . esc_attr( $text ) . '"';

		if ( $has_alt ) {
			return str_replace( $alt[0], $attribute, $tag );
		}

		return (string) preg_replace( '/^<img\b/i', '<img' . $attribute, $tag, 1 );
	}
}
