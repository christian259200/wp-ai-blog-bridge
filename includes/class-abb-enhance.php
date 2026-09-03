<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content enhancements applied after markdown conversion.
 *
 * Everything here comes straight out of the on-page playbook: inline internal
 * links with varied anchor text, title attributes (hover tooltips) on images
 * and links, and a visible freshness stamp.
 */
class ABB_Enhance {

	/**
	 * Inject internal links inline, into the body text where the anchor phrase
	 * already occurs naturally. Google weights in-content links far above
	 * link lists bolted to the bottom of a page.
	 *
	 * Payload shape:
	 *   "internal_links": [ { "anchor": "serigrafía textil", "url": "...", "title": "..." } ]
	 *
	 * @return array [ $content, $injected, $missed ]
	 */
	public static function inject_internal_links( $content, array $links ) {
		$injected = array();
		$missed   = array();

		foreach ( $links as $link ) {
			$anchor = isset( $link['anchor'] ) ? trim( wp_strip_all_tags( $link['anchor'] ) ) : '';
			$url    = isset( $link['url'] ) ? esc_url_raw( $link['url'] ) : '';

			if ( '' === $anchor || '' === $url ) {
				continue;
			}

			$title = isset( $link['title'] ) ? sanitize_text_field( $link['title'] ) : '';
			$done  = false;

			// Paragraphs and list items both carry body copy. Skipping list items
			// silently dropped links whose anchor only appeared inside a list.
			$content = preg_replace_callback(
				'/<(p|li)>(.*?)<\/\1>/s',
				static function ( $match ) use ( $anchor, $url, $title, &$done ) {
					if ( $done ) {
						return $match[0];
					}

					$tag  = $match[1];
					$body = $match[2];

					// Never touch a block that already carries a link: it keeps the
					// regex honest and avoids nesting anchors.
					if ( false !== stripos( $body, '<a ' ) ) {
						return $match[0];
					}

					$pattern = '/(?<![\w-])(' . preg_quote( $anchor, '/' ) . ')(?![\w-])/iu';
					if ( ! preg_match( $pattern, $body ) ) {
						return $match[0];
					}

					$attributes = 'href="' . esc_url( $url ) . '"';
					if ( '' !== $title ) {
						$attributes .= ' title="' . esc_attr( $title ) . '"';
					}

					$replaced = preg_replace(
						$pattern,
						'<a ' . $attributes . '>$1</a>',
						$body,
						1
					);

					$done = true;

					return '<' . $tag . '>' . $replaced . '</' . $tag . '>';
				},
				$content
			);

			if ( $done ) {
				$injected[] = $anchor;
			} else {
				$missed[] = $anchor;
			}
		}

		return array( $content, $injected, $missed );
	}

	/**
	 * Add title attributes (tooltips) where they are missing.
	 *
	 * Images fall back to their alt text. Links only get a tooltip when the
	 * payload supplies one, keyed by URL, because an invented link tooltip
	 * reads worse than none at all.
	 *
	 * @param string $content
	 * @param array  $tooltips url => tooltip text
	 * @param string $image_prefix Optional call-to-action prefix for image tooltips.
	 */
	public static function apply_tooltips( $content, array $tooltips = array(), $image_prefix = '' ) {
		$normalized = array();
		foreach ( $tooltips as $url => $text ) {
			$normalized[ esc_url_raw( $url ) ] = sanitize_text_field( $text );
		}

		$content = preg_replace_callback(
			'/<img\s[^>]*>/i',
			static function ( $match ) use ( $normalized, $image_prefix ) {
				$img = $match[0];

				if ( preg_match( '/\stitle=["\'][^"\']*["\']/i', $img ) ) {
					return $img;
				}

				$title = '';

				if ( preg_match( '/\ssrc=["\']([^"\']+)["\']/i', $img, $src ) && isset( $normalized[ $src[1] ] ) ) {
					$title = $normalized[ $src[1] ];
				} elseif ( preg_match( '/\salt=["\']([^"\']+)["\']/i', $img, $alt ) ) {
					$title = ( '' !== $image_prefix ? $image_prefix . ' ' : '' ) . $alt[1];
				}

				if ( '' === $title ) {
					return $img;
				}

				return rtrim( $img, '/>' ) . ' title="' . esc_attr( $title ) . '"' . ( '/>' === substr( $img, -2 ) ? '/>' : '>' );
			},
			$content
		);

		if ( ! $normalized ) {
			return $content;
		}

		return preg_replace_callback(
			'/<a\s[^>]*>/i',
			static function ( $match ) use ( $normalized ) {
				$tag = $match[0];

				if ( preg_match( '/\stitle=["\'][^"\']*["\']/i', $tag ) ) {
					return $tag;
				}

				if ( ! preg_match( '/\shref=["\']([^"\']+)["\']/i', $tag, $href ) || ! isset( $normalized[ $href[1] ] ) ) {
					return $tag;
				}

				return rtrim( $tag, '>' ) . ' title="' . esc_attr( $normalized[ $href[1] ] ) . '">';
			},
			$content
		);
	}

	/**
	 * Prepend a visible "last updated" line. Freshness is a ranking signal and
	 * a visible date also reassures the reader, as long as it stays current.
	 */
	public static function prepend_freshness_stamp( $content, $label, $date ) {
		$line = sprintf(
			'<!-- wp:paragraph {"className":"abb-freshness"} -->' . "\n" . '<p class="abb-freshness"><em>%s %s</em></p>' . "\n" . '<!-- /wp:paragraph -->',
			esc_html( $label ),
			esc_html( $date )
		);

		return $line . "\n\n" . $content;
	}

	/**
	 * A visible byline with an optional link to the author's professional
	 * profile. Themes usually print the WordPress login name, which is rarely
	 * the name the author wants attached to their work, and never links
	 * anywhere useful. A real name that resolves to a real profile is an
	 * E-E-A-T signal as well as a courtesy to the reader.
	 */
	public static function prepend_byline( $content, array $byline ) {
		$name = isset( $byline['name'] ) ? sanitize_text_field( $byline['name'] ) : '';

		if ( '' === $name ) {
			return $content;
		}

		$url   = isset( $byline['url'] ) ? esc_url_raw( $byline['url'] ) : '';
		$label = isset( $byline['label'] ) ? sanitize_text_field( $byline['label'] ) : ABB_Text::label( 'by' );

		if ( '' !== $url ) {
			$who = sprintf(
				'<a href="%s" rel="author noopener" target="_blank">%s</a>',
				esc_url( $url ),
				esc_html( $name )
			);
		} else {
			$who = esc_html( $name );
		}

		$html = sprintf(
			'<!-- wp:paragraph {"className":"abb-byline"} -->' . "
" . '<p class="abb-byline">%s %s</p>' . "
" . '<!-- /wp:paragraph -->',
			esc_html( $label ),
			$who
		);

		return $html . "

" . $content;
	}

	/**
	 * Mark a post as cornerstone / pillar content in whichever SEO plugin is active.
	 */
	public static function set_cornerstone( $post_id, $is_cornerstone ) {
		$value = $is_cornerstone ? '1' : '0';

		if ( ABB_SEO::has_yoast() ) {
			update_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', $value );
		}

		if ( ABB_SEO::has_rank_math() ) {
			update_post_meta( $post_id, 'rank_math_pillar_content', $is_cornerstone ? 'on' : 'off' );
		}

		update_post_meta( $post_id, '_abb_cornerstone', $value );
	}

	/**
	 * Deep link into the Search Console URL inspection tool, so a new post can
	 * be submitted for indexing immediately instead of waiting for a crawl.
	 */
	public static function inspection_url( $permalink ) {
		$property = home_url( '/' );

		return add_query_arg(
			array(
				'resource_id' => rawurlencode( $property ),
				'id'          => rawurlencode( $permalink ),
			),
			'https://search.google.com/search-console/inspect'
		);
	}
}
