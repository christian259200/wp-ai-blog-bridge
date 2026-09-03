<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end repairs for themes that mark up article titles badly.
 *
 * Several commercial themes render the single-post title as an H2, which
 * leaves the page with no H1 at all. The H1 is the strongest on-page signal
 * for what a page is about, and it cannot be fixed from a stylesheet: it is
 * markup, not style.
 *
 * The rewrite is deliberately narrow. It runs only on single posts, only on
 * the first heading carrying a known title class, and only when the page
 * genuinely has no H1, so a theme that already does the right thing is left
 * alone and so is every other heading on the page.
 */
class ABB_Frontend {

	/** Title classes used by the themes seen in the wild. */
	private static $title_classes = array( 'entry-title', 'post-title', 'page-title' );

	public static function init() {
		if ( ! ABB_Settings::get( 'force_h1' ) ) {
			return;
		}

		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ) );
	}

	public static function start_buffer() {
		if ( is_admin() || ! is_singular( 'post' ) || is_feed() || is_embed() ) {
			return;
		}

		ob_start( array( __CLASS__, 'promote_title' ) );
	}

	/**
	 * @param string $html Full page markup.
	 * @return string
	 */
	public static function promote_title( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		// A page that already has an H1 needs no help.
		if ( preg_match( '/<h1[\s>]/i', $html ) ) {
			return $html;
		}

		$classes = implode( '|', array_map( 'preg_quote', self::$title_classes ) );
		$pattern = '/<h([2-4])(\s[^>]*class="[^"]*(?:' . $classes . ')[^"]*"[^>]*)>(.*?)<\/h>/is';

		$done = false;

		$result = preg_replace_callback(
			$pattern,
			static function ( $match ) use ( &$done ) {
				if ( $done ) {
					return $match[0];
				}
				$done = true;
				return '<h1' . $match[2] . '>' . $match[3] . '</h1>';
			},
			$html,
			1
		);

		return ( null === $result ) ? $html : $result;
	}
}
