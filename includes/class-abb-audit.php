<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On-page audit run on every publish.
 *
 * Thresholds come from the SEO course transcript the plugin owner supplied,
 * so the numbers here are deliberate rather than folklore:
 *
 * - meta title under 70 characters, focus keyword present and near the front
 * - meta description around 140-160 characters, carrying the long-tail keywords
 * - focus keyword inside the first paragraph, where weight is highest
 * - exactly one H1 (the post title), 3-10 H2 sections, 5 being the sweet spot
 * - 5-10 internal links per page, inline in the body, with varied anchor text
 * - at least one outbound citation to an authoritative source (GEO)
 * - title attributes (tooltips) on images and links for conversion + keywords
 * - question-form headings and semantic cues so LLMs can parse the page
 * - URLs under 100 characters with the keyword and no more than two directories
 */
class ABB_Audit {

	const SEVERITY_ERROR   = 'error';
	const SEVERITY_WARNING = 'warning';
	const SEVERITY_INFO    = 'info';

	/** Semantic cues that tell an LLM what a passage is. */
	private static $semantic_cues = array(
		'in summary', 'in short', 'the most important', 'key takeaway', 'step 1', 'step one',
		'common mistakes', 'for example', 'in conclusion', 'bottom line',
		'en resumen', 'en resumidas cuentas', 'lo más importante', 'punto clave', 'paso 1',
		'errores comunes', 'por ejemplo', 'en conclusión', 'en pocas palabras',
	);

	/**
	 * @param int    $post_id
	 * @param array  $payload
	 * @param string $content Rendered block markup.
	 * @return array List of findings: severity, code, message.
	 */
	public static function run( $post_id, array $payload, $content ) {
		$findings = array();

		$seo     = (array) ( $payload['seo'] ?? array() );
		$keyword = trim( (string) ( $seo['focus_keyword'] ?? '' ) );
		$title   = (string) get_the_title( $post_id );
		$text    = self::plain_text( $content );

		$meta_title       = trim( (string) ( $seo['title'] ?? '' ) ) ?: $title;
		$meta_description = trim( (string) ( $seo['description'] ?? '' ) );

		self::check_keyword( $findings, $keyword );
		self::check_meta_title( $findings, $meta_title, $keyword );
		self::check_meta_description( $findings, $meta_description, $keyword );
		self::check_first_paragraph( $findings, $content, $keyword );
		self::check_headings( $findings, $content, $keyword );
		self::check_length( $findings, $text );
		self::check_links( $findings, $content );
		self::check_images( $findings, $content, $post_id );
		self::check_geo( $findings, $content, $text, $payload );
		self::check_url( $findings, $post_id, $keyword );

		update_post_meta( $post_id, '_abb_audit', wp_slash( wp_json_encode( $findings ) ) );

		return $findings;
	}

	/* --------------------------------------------------------------------- */

	private static function add( array &$findings, $severity, $code, $message ) {
		$findings[] = array(
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
		);
	}

	private static function check_keyword( array &$findings, $keyword ) {
		if ( '' === $keyword ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'no_focus_keyword',
				'No focus keyword supplied, so keyword placement could not be checked. Send seo.focus_keyword.'
			);
		}
	}

	private static function check_meta_title( array &$findings, $meta_title, $keyword ) {
		$length = self::length( $meta_title );

		if ( $length > 70 ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'title_too_long',
				sprintf( 'Meta title is %d characters; Google truncates past about 70.', $length )
			);
		} elseif ( $length < 30 ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'title_too_short',
				sprintf( 'Meta title is only %d characters. There is room for more keywords and a benefit.', $length )
			);
		}

		if ( '' === $keyword ) {
			return;
		}

		$position = self::position( $meta_title, $keyword );

		if ( false === $position ) {
			self::add(
				$findings,
				self::SEVERITY_ERROR,
				'title_missing_keyword',
				sprintf( 'The focus keyword "%s" does not appear in the meta title. This is the single most important placement.', $keyword )
			);
		} elseif ( $position > 40 ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'title_keyword_late',
				sprintf( 'The focus keyword appears %d characters into the meta title. Earlier carries more weight.', $position )
			);
		}
	}

	private static function check_meta_description( array &$findings, $description, $keyword ) {
		$length = self::length( $description );

		if ( 0 === $length ) {
			self::add(
				$findings,
				self::SEVERITY_ERROR,
				'no_meta_description',
				'No meta description. Google will invent one from the page and the click-through rate suffers.'
			);
			return;
		}

		if ( $length < 120 ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'description_too_short',
				sprintf( 'Meta description is %d characters. Aim for 140-160 and use the space for long-tail keywords.', $length )
			);
		} elseif ( $length > 160 ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'description_too_long',
				sprintf( 'Meta description is %d characters; it will be cut off past about 160.', $length )
			);
		}

		if ( '' !== $keyword && false === self::position( $description, $keyword ) ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'description_missing_keyword',
				sprintf( 'The focus keyword "%s" does not appear in the meta description.', $keyword )
			);
		}
	}

	private static function check_first_paragraph( array &$findings, $content, $keyword ) {
		if ( '' === $keyword ) {
			return;
		}

		if ( ! preg_match( '/<p>(.*?)<\/p>/s', $content, $match ) ) {
			return;
		}

		if ( false === self::position( wp_strip_all_tags( $match[1] ), $keyword ) ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'keyword_not_in_intro',
				sprintf( 'The focus keyword "%s" is missing from the first paragraph, where keyword weight is highest.', $keyword )
			);
		}
	}

	private static function check_headings( array &$findings, $content, $keyword ) {
		preg_match_all( '/<h([2-6])[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER );

		$h2 = array();
		foreach ( $matches as $match ) {
			if ( '2' === $match[1] ) {
				$h2[] = wp_strip_all_tags( $match[2] );
			}
		}

		if ( preg_match( '/<h1[^>]*>/i', $content ) ) {
			self::add(
				$findings,
				self::SEVERITY_ERROR,
				'h1_in_body',
				'The body contains an H1. The post title is already the H1, and a second one splits the signal.'
			);
		}

		$count = count( $h2 );

		if ( 0 === $count ) {
			self::add(
				$findings,
				self::SEVERITY_ERROR,
				'no_h2',
				'No H2 sections. A wall of paragraphs has no hierarchy for readers, Google or LLMs.'
			);
		} elseif ( $count < 3 ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'few_h2',
				sprintf( 'Only %d H2 sections. Around 5 is the sweet spot for a normal article.', $count )
			);
		} elseif ( $count > 10 ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'many_h2',
				sprintf( '%d H2 sections. Past about 10, the ones near the bottom carry little weight unless this is cornerstone content.', $count )
			);
		}

		if ( '' === $keyword || ! $h2 ) {
			return;
		}

		foreach ( $h2 as $heading ) {
			if ( false !== self::position( $heading, $keyword ) ) {
				return;
			}
		}

		self::add(
			$findings,
			self::SEVERITY_WARNING,
			'keyword_not_in_h2',
			sprintf( 'No H2 contains the focus keyword "%s" or part of it.', $keyword )
		);
	}

	private static function check_length( array &$findings, $text ) {
		$words = self::word_count( $text );

		if ( $words < 300 ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'thin_content',
				sprintf( 'Only %d words. Under 300 rarely ranks; 300-500 is a floor and competitive topics want 1000+.', $words )
			);
		}
	}

	private static function check_links( array &$findings, $content ) {
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER );

		$home     = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal = array();
		$external = 0;
		$anchors  = array();
		$no_title = 0;

		foreach ( $matches as $match ) {
			$href = $match[1];

			if ( 0 === strpos( $href, '#' ) ) {
				continue; // jump link, counted with the table of contents
			}

			$host = wp_parse_url( $href, PHP_URL_HOST );

			if ( ! $host || $host === $home ) {
				$internal[] = $href;
				$anchors[]  = strtolower( trim( wp_strip_all_tags( $match[2] ) ) );
			} else {
				$external++;
			}

			if ( ! preg_match( '/\stitle=["\']/i', $match[0] ) ) {
				$no_title++;
			}
		}

		$count = count( $internal );

		if ( 0 === $count ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'no_internal_links',
				'No internal links. Interlinking is the cheapest ranking boost available and it is entirely under your control. Aim for 5-10.'
			);
		} elseif ( $count < 3 ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'few_internal_links',
				sprintf( 'Only %d internal links. The working range is 5-10 per page.', $count )
			);
		} elseif ( $count > 10 ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'many_internal_links',
				sprintf( '%d internal links. Past 10 it starts to look manipulative.', $count )
			);
		}

		$repeated = array_filter( array_count_values( array_filter( $anchors ) ), static function ( $n ) {
			return $n > 2;
		} );

		if ( $repeated ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'repeated_anchor_text',
				sprintf( 'Anchor text repeated more than twice: %s. Vary it so the linking looks natural.', implode( ', ', array_keys( $repeated ) ) )
			);
		}

		if ( 0 === $external ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'no_citations',
				'No outbound links. Citing authoritative sources is a core signal for AI citation and for E-E-A-T.'
			);
		}

		if ( $no_title > 0 ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'links_without_tooltip',
				sprintf( '%d links have no title attribute. A keyword-rich tooltip that invites the next step lifts both conversion and SEO.', $no_title )
			);
		}
	}

	private static function check_images( array &$findings, $content, $post_id = 0 ) {
		preg_match_all( '/<img\s[^>]*>/i', $content, $matches );

		if ( empty( $matches[0] ) ) {
			// A featured image is real rich media even though it lives outside
			// the body, so only complain when there is no image at all.
			if ( $post_id && has_post_thumbnail( $post_id ) ) {
				self::add(
					$findings,
					self::SEVERITY_INFO,
					'only_featured_image',
					'The only image is the featured one. Images inside the body raise dwell time further.'
				);
				return;
			}

			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'no_images',
				'No images. Rich media raises dwell time, which now feeds back into ranking.'
			);
			return;
		}

		$missing_alt   = 0;
		$missing_title = 0;

		foreach ( $matches[0] as $img ) {
			if ( ! preg_match( '/\salt=["\'][^"\']+["\']/i', $img ) ) {
				$missing_alt++;
			}
			if ( ! preg_match( '/\stitle=["\'][^"\']+["\']/i', $img ) ) {
				$missing_title++;
			}
		}

		if ( $missing_alt ) {
			self::add(
				$findings,
				self::SEVERITY_ERROR,
				'images_without_alt',
				sprintf( '%d images have no alt text.', $missing_alt )
			);
		}

		if ( $missing_title ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'images_without_tooltip',
				sprintf( '%d images have no title attribute (hover tooltip).', $missing_title )
			);
		}
	}

	private static function check_geo( array &$findings, $content, $text, array $payload ) {
		preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $content, $matches );

		$questions = 0;
		foreach ( $matches[1] ?? array() as $heading ) {
			if ( preg_match( '/\?|^\s*(how|what|why|when|where|which|who|can|should|is|does|do)\b|^\s*(cómo|qué|por qué|cuándo|dónde|cuál|quién|puedo|debo|es|sirve)\b/iu', wp_strip_all_tags( $heading ) ) ) {
				$questions++;
			}
		}

		if ( 0 === $questions && empty( $payload['faq'] ) ) {
			self::add(
				$findings,
				self::SEVERITY_WARNING,
				'no_question_headings',
				'No question-form headings and no FAQ. Question headings with direct answers underneath are what AI engines quote.'
			);
		}

		$lower = ABB_Text::fold( $text );
		$found = false;
		foreach ( self::$semantic_cues as $cue ) {
			if ( false !== strpos( $lower, ABB_Text::fold( $cue ) ) ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'no_semantic_cues',
				'No semantic cues found ("in summary", "the most important", "step 1", "common mistakes"). They tell an LLM which passage to lift.'
			);
		}

		$has_structure = preg_match( '/<(ul|ol|table)\b/i', $content );
		if ( ! $has_structure ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'no_lists_or_tables',
				'No lists or tables. Structured formatting is what AI engines parse most reliably.'
			);
		}
	}

	private static function check_url( array &$findings, $post_id, $keyword ) {
		$permalink = get_permalink( $post_id );
		$path      = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		$length    = strlen( $permalink );

		if ( $length > 100 ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'url_too_long',
				sprintf( 'The URL is %d characters. Keep it under about 100.', $length )
			);
		}

		$depth = count( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		if ( $depth > 3 ) {
			self::add(
				$findings,
				self::SEVERITY_INFO,
				'url_too_deep',
				sprintf( 'The URL has %d segments. One or two directories above the slug is plenty.', $depth )
			);
		}

		if ( '' !== $keyword ) {
			$slug_words = sanitize_title( $keyword );
			$first      = explode( '-', $slug_words );
			if ( $first && false === strpos( $path, $first[0] ) ) {
				self::add(
					$findings,
					self::SEVERITY_INFO,
					'url_missing_keyword',
					'The URL does not contain the focus keyword.'
				);
			}
		}
	}

	/* --------------------------------------------------------------------- */

	private static function plain_text( $content ) {
		$content = preg_replace( '/<!--.*?-->/s', '', $content );
		return trim( wp_strip_all_tags( $content ) );
	}

	private static function word_count( $text ) {
		return preg_match_all( '/[\p{L}\p{N}]+/u', $text );
	}

	private static function length( $text ) {
		return ABB_Text::length( $text );
	}

	private static function lower( $text ) {
		return ABB_Text::lower( $text );
	}

	/**
	 * Case-insensitive position of $needle in $haystack, false when absent.
	 */
	private static function position( $haystack, $needle ) {
		$haystack = ABB_Text::fold( $haystack );
		$needle   = ABB_Text::fold( trim( $needle ) );

		if ( '' === $needle ) {
			return false;
		}

		$position = strpos( $haystack, $needle );
		if ( false !== $position ) {
			return $position;
		}

		// Fall back to a loose match: every word of the keyword present somewhere.
		$words = preg_split( '/\s+/', $needle );
		if ( count( $words ) < 2 ) {
			return false;
		}

		$earliest = false;
		foreach ( $words as $word ) {
			if ( self::length( $word ) < 3 ) {
				continue; // skip articles and prepositions
			}
			$at = strpos( $haystack, $word );
			if ( false === $at ) {
				return false;
			}
			$earliest = ( false === $earliest ) ? $at : min( $earliest, $at );
		}

		return $earliest;
	}

	/**
	 * Compact one-line summary used in the REST response and the log.
	 */
	public static function summarize( array $findings ) {
		$counts = array(
			self::SEVERITY_ERROR   => 0,
			self::SEVERITY_WARNING => 0,
			self::SEVERITY_INFO    => 0,
		);

		foreach ( $findings as $finding ) {
			$severity = $finding['severity'];
			if ( isset( $counts[ $severity ] ) ) {
				$counts[ $severity ]++;
			}
		}

		return $counts;
	}
}
