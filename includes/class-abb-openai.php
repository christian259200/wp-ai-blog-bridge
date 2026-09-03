<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Narrow OpenAI client: meta titles and meta descriptions only.
 *
 * Full article generation deliberately does not live here. It needs research,
 * source verification, images and review passes, none of which fit inside a
 * single PHP request on shared hosting. This class exists for the one job that
 * does fit: rewriting a title or description in a few seconds while somebody
 * is editing a post by hand.
 */
class ABB_OpenAI {

	const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * The key is read from wp-config.php first. Storing it in the database is
	 * supported as a fallback, but the constant keeps it out of any backup,
	 * export or compromised-plugin read of wp_options.
	 */
	public static function key() {
		if ( defined( 'ABB_OPENAI_KEY' ) && ABB_OPENAI_KEY ) {
			return (string) ABB_OPENAI_KEY;
		}

		return (string) ABB_Settings::get( 'openai_key', '' );
	}

	public static function key_source() {
		if ( defined( 'ABB_OPENAI_KEY' ) && ABB_OPENAI_KEY ) {
			return 'wp-config';
		}
		if ( ABB_Settings::get( 'openai_key' ) ) {
			return 'database';
		}
		return 'none';
	}

	public static function available() {
		return '' !== self::key();
	}

	public static function model() {
		$model = trim( (string) ABB_Settings::get( 'openai_model', '' ) );
		return $model ?: 'gpt-4o-mini';
	}

	/**
	 * Generate meta title or meta description variants for a post.
	 *
	 * @param int    $post_id
	 * @param string $type    'title' or 'description'
	 * @param string $keyword Focus keyword; falls back to the stored one.
	 * @return array|WP_Error List of strings.
	 */
	public static function generate_meta( $post_id, $type, $keyword = '' ) {
		if ( ! self::available() ) {
			return new WP_Error( 'abb_no_key', __( 'No OpenAI key configured.', 'ai-blog-bridge' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'abb_no_post', __( 'Post not found.', 'ai-blog-bridge' ) );
		}

		$type    = 'title' === $type ? 'title' : 'description';
		$keyword = $keyword ?: self::stored_keyword( $post_id );
		$excerpt = self::excerpt( $post );

		$response = self::request( self::messages( $type, $post->post_title, $keyword, $excerpt ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::parse_variants( $response, $type );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * The prompt encodes the on-page rules this plugin already audits against,
	 * so generated meta passes its own audit instead of fighting it.
	 */
	private static function messages( $type, $title, $keyword, $excerpt ) {
		$language = self::language_name();

		if ( 'title' === $type ) {
			$rules = implode(
				"\n",
				array(
					'- Hard limit: 70 characters. Aim for 55-65.',
					'- The focus keyword must appear, as early as possible.',
					'- Lead with a benefit, not a feature.',
					'- Numbers, a year, or a concrete promise raise the click-through rate.',
					'- No clickbait, no ALL CAPS, no emoji, no quotation marks around the whole title.',
					'- It must read like a human wrote it, not like keywords glued together.',
				)
			);
			$shape = 'meta title tags';
		} else {
			$rules = implode(
				"\n",
				array(
					'- Length: between 140 and 160 characters. Never under 130 or over 160.',
					'- The focus keyword must appear, plus two or three long-tail variations of it.',
					'- Say what the reader gets. Speak to them directly.',
					'- One complete idea that reads naturally; it is a sentence, not a keyword list.',
					'- No emoji, no ALL CAPS, no truncation with an ellipsis.',
				)
			);
			$shape = 'meta description tags';
		}

		$system = sprintf(
			'You are an SEO copywriter. You write %s in %s that rank and get clicked. You follow character limits exactly and you never pad text to reach them. Reply only with JSON.',
			$shape,
			$language
		);

		$user = sprintf(
			"Write 3 different %s for this page.\n\nPage title: %s\nFocus keyword: %s\nPage content (start): %s\n\nRules:\n%s\n\nReturn strictly this JSON shape, with no commentary:\n{\"variants\": [\"...\", \"...\", \"...\"]}",
			$shape,
			$title,
			$keyword ?: '(not specified, infer it from the title)',
			$excerpt,
			$rules
		);

		return array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	private static function request( array $messages ) {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::key(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => self::model(),
						'messages'        => $messages,
						'temperature'     => 0.8,
						'max_tokens'      => 500,
						'response_format' => array( 'type' => 'json_object' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $code ) {
			$decoded = json_decode( $body, true );
			$message = $decoded['error']['message'] ?? substr( $body, 0, 200 );

			return new WP_Error(
				'abb_openai_http_' . $code,
				sprintf(
					/* translators: 1: HTTP status, 2: error message from OpenAI */
					__( 'OpenAI returned %1$d: %2$s', 'ai-blog-bridge' ),
					$code,
					$message
				)
			);
		}

		$decoded = json_decode( $body, true );
		$content = $decoded['choices'][0]['message']['content'] ?? '';

		if ( '' === $content ) {
			return new WP_Error( 'abb_openai_empty', __( 'OpenAI returned an empty response.', 'ai-blog-bridge' ) );
		}

		return $content;
	}

	/**
	 * Tolerant parsing: the model is asked for JSON, but a stray sentence
	 * around it should not lose the work.
	 */
	private static function parse_variants( $content, $type ) {
		$variants = array();
		$decoded  = json_decode( $content, true );

		if ( ! is_array( $decoded ) && preg_match( '/\{.*\}/s', $content, $match ) ) {
			$decoded = json_decode( $match[0], true );
		}

		if ( is_array( $decoded ) ) {
			$list = $decoded['variants'] ?? $decoded;
			foreach ( (array) $list as $item ) {
				if ( is_string( $item ) && '' !== trim( $item ) ) {
					$variants[] = trim( $item );
				}
			}
		}

		if ( ! $variants ) {
			return new WP_Error( 'abb_openai_parse', __( 'Could not read the generated variants.', 'ai-blog-bridge' ) );
		}

		$limit = 'title' === $type ? 70 : 160;

		return array_map(
			static function ( $variant ) use ( $limit ) {
				$variant = wp_strip_all_tags( $variant );
				$length  = ABB_Text::length( $variant );

				return array(
					'text'      => $variant,
					'length'    => $length,
					'within'    => $length <= $limit,
					'limit'     => $limit,
				);
			},
			array_slice( $variants, 0, 3 )
		);
	}

	private static function stored_keyword( $post_id ) {
		foreach ( array( '_abb_focus_keyword', 'rank_math_focus_keyword', '_yoast_wpseo_focuskw', '_seopress_analysis_target_kw' ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( $value ) {
				// Rank Math stores a comma separated list; the first one is primary.
				$parts = explode( ',', (string) $value );
				return trim( $parts[0] );
			}
		}

		return '';
	}

	private static function excerpt( $post ) {
		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return ABB_Text::substr( $text, 0, 1200 );
	}

	private static function language_name() {
		$locale = get_locale();

		$names = array(
			'es' => 'Spanish',
			'en' => 'English',
			'pt' => 'Portuguese',
			'fr' => 'French',
			'de' => 'German',
			'it' => 'Italian',
		);

		$code = strtolower( substr( $locale, 0, 2 ) );

		return $names[ $code ] ?? 'the same language as the page content';
	}
}
