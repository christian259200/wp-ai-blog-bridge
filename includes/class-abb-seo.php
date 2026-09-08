<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO meta and JSON-LD.
 *
 * Writes into whichever SEO plugin is active (Yoast, Rank Math, SEOPress) and
 * always keeps its own copy so nothing is lost if the plugin changes.
 */
class ABB_SEO {

	const META_TITLE       = '_abb_seo_title';
	const META_DESCRIPTION = '_abb_seo_description';
	const META_KEYWORD     = '_abb_focus_keyword';
	const META_CANONICAL   = '_abb_canonical';
	const META_OG_IMAGE    = '_abb_og_image';
	const META_SCHEMA      = '_abb_schema';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'render_head' ), 5 );
	}

	/**
	 * @param int   $post_id
	 * @param array $seo title, description, focus_keyword, canonical, og_image
	 */
	public static function save( $post_id, array $seo ) {
		$title       = isset( $seo['title'] ) ? sanitize_text_field( $seo['title'] ) : '';
		$description = isset( $seo['description'] ) ? sanitize_text_field( $seo['description'] ) : '';
		$keyword     = isset( $seo['focus_keyword'] ) ? sanitize_text_field( $seo['focus_keyword'] ) : '';
		$canonical   = isset( $seo['canonical'] ) ? esc_url_raw( $seo['canonical'] ) : '';
		$og_image    = isset( $seo['og_image'] ) ? esc_url_raw( $seo['og_image'] ) : '';

		$map = array(
			self::META_TITLE       => $title,
			self::META_DESCRIPTION => $description,
			self::META_KEYWORD     => $keyword,
			self::META_CANONICAL   => $canonical,
			self::META_OG_IMAGE    => $og_image,
		);
		foreach ( $map as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		if ( self::has_yoast() ) {
			self::set_meta( $post_id, '_yoast_wpseo_title', $title );
			self::set_meta( $post_id, '_yoast_wpseo_metadesc', $description );
			self::set_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
			self::set_meta( $post_id, '_yoast_wpseo_canonical', $canonical );
			self::set_meta( $post_id, '_yoast_wpseo_opengraph-image', $og_image );
		}

		if ( self::has_rank_math() ) {
			self::set_meta( $post_id, 'rank_math_title', $title );
			self::set_meta( $post_id, 'rank_math_description', $description );
			self::set_meta( $post_id, 'rank_math_focus_keyword', $keyword );
			self::set_meta( $post_id, 'rank_math_canonical_url', $canonical );
			self::set_meta( $post_id, 'rank_math_facebook_image', $og_image );
		}

		if ( self::has_seopress() ) {
			self::set_meta( $post_id, '_seopress_titles_title', $title );
			self::set_meta( $post_id, '_seopress_titles_desc', $description );
			self::set_meta( $post_id, '_seopress_analysis_target_kw', $keyword );
			self::set_meta( $post_id, '_seopress_robots_canonical', $canonical );
			self::set_meta( $post_id, '_seopress_social_fb_img', $og_image );
		}
	}

	/**
	 * Persist JSON-LD graph nodes supplied by the pipeline, merged with
	 * generated BlogPosting and FAQPage nodes.
	 *
	 * @param int   $post_id
	 * @param array $payload Full request payload.
	 */
	public static function save_schema( $post_id, array $payload ) {
		$graph = array();

		$custom = $payload['schema'] ?? array();
		if ( $custom && isset( $custom['@graph'] ) ) {
			$graph = array_merge( $graph, (array) $custom['@graph'] );
		} elseif ( $custom && isset( $custom[0] ) ) {
			$graph = array_merge( $graph, (array) $custom );
		} elseif ( $custom ) {
			$graph[] = $custom;
		}

		if ( self::should_emit_blogposting( $payload ) ) {
			$graph[] = self::blogposting_node( $post_id, $payload );
		}

		$faq = self::faq_node( $payload['faq'] ?? array() );
		if ( $faq ) {
			$graph[] = $faq;
		}

		if ( empty( $graph ) ) {
			delete_post_meta( $post_id, self::META_SCHEMA );
			return;
		}

		update_post_meta(
			$post_id,
			self::META_SCHEMA,
			wp_slash(
				wp_json_encode(
					array(
						'@context' => 'https://schema.org',
						'@graph'   => array_values( $graph ),
					)
				)
			)
		);
	}

	/**
	 * Should this plugin publish its own BlogPosting node?
	 *
	 * Yoast, Rank Math and SEOPress all emit an Article or BlogPosting node of
	 * their own. Emitting a second one for the same URL is not additive: each
	 * node carries its own author, dates and images, and when they disagree a
	 * search engine picks one without telling you which. Two nodes is a defect,
	 * not extra coverage.
	 *
	 * So the default is to stand down when one of those plugins is active and
	 * publish only what it does not cover, which is the FAQPage node.
	 *
	 * Three ways to override, most specific first:
	 *   1. "schema_blogposting" in the request payload: always | never | auto
	 *   2. "schema_skip_blogposting" in the payload, kept for older clients
	 *   3. The "blogposting_schema" setting on the settings screen
	 *
	 * @param array $payload Full request payload.
	 * @return bool
	 */
	private static function should_emit_blogposting( array $payload ) {
		// Older clients sent a plain skip flag. Still honoured.
		if ( ! empty( $payload['schema_skip_blogposting'] ) ) {
			return false;
		}

		$mode = $payload['schema_blogposting'] ?? ABB_Settings::get( 'blogposting_schema', 'auto' );
		$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : 'auto';

		if ( 'always' === $mode ) {
			return true;
		}
		if ( 'never' === $mode ) {
			return false;
		}

		return ! self::has_seo_plugin();
	}

	private static function blogposting_node( $post_id, array $payload ) {
		$post   = get_post( $post_id );
		$author = get_userdata( $post->post_author );

		$node = array(
			'@type'            => 'BlogPosting',
			'@id'              => get_permalink( $post_id ) . '#blogposting',
			'headline'         => wp_strip_all_tags( $post->post_title ),
			'description'      => get_post_meta( $post_id, self::META_DESCRIPTION, true ) ?: wp_strip_all_tags( $post->post_excerpt ),
			'url'              => get_permalink( $post_id ),
			'datePublished'    => get_the_date( 'c', $post_id ),
			'dateModified'     => get_the_modified_date( 'c', $post_id ),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post_id ),
			),
			'publisher'        => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
		);

		if ( $author ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => $author->display_name,
				'url'   => get_author_posts_url( $author->ID ),
			);
		}

		if ( ! empty( $payload['author_meta']['name'] ) ) {
			$node['author'] = array_filter(
				array(
					'@type'       => 'Person',
					'name'        => sanitize_text_field( $payload['author_meta']['name'] ),
					'jobTitle'    => isset( $payload['author_meta']['job_title'] ) ? sanitize_text_field( $payload['author_meta']['job_title'] ) : '',
					'url'         => isset( $payload['author_meta']['url'] ) ? esc_url_raw( $payload['author_meta']['url'] ) : '',
					'description' => isset( $payload['author_meta']['bio'] ) ? sanitize_text_field( $payload['author_meta']['bio'] ) : '',
				)
			);
		}

		$thumb = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $thumb ) {
			$node['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $thumb,
			);
		}

		return $node;
	}

	private static function faq_node( $faq ) {
		$entities = array();

		foreach ( (array) $faq as $item ) {
			$question = isset( $item['question'] ) ? wp_strip_all_tags( $item['question'] ) : '';
			$answer   = isset( $item['answer'] ) ? wp_kses_post( $item['answer'] ) : '';
			if ( ! $question || ! $answer ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);
		}

		if ( ! $entities ) {
			return null;
		}

		return array(
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
	}

	/**
	 * Print schema and, when no SEO plugin owns them, meta/OG tags.
	 */
	public static function render_head() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		if ( ABB_Settings::get( 'emit_schema' ) ) {
			$schema = get_post_meta( $post_id, self::META_SCHEMA, true );
			if ( $schema ) {
				echo "\n<script type=\"application/ld+json\">" . $schema . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}

		if ( ! ABB_Settings::get( 'emit_og' ) || self::has_seo_plugin() ) {
			return;
		}

		$description = get_post_meta( $post_id, self::META_DESCRIPTION, true );
		$canonical   = get_post_meta( $post_id, self::META_CANONICAL, true );
		$og_image    = get_post_meta( $post_id, self::META_OG_IMAGE, true );
		if ( ! $og_image ) {
			$og_image = get_the_post_thumbnail_url( $post_id, 'full' );
		}

		if ( $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
			echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		if ( $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}

		echo '<meta property="og:type" content="article" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( get_the_title( $post_id ) ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( get_permalink( $post_id ) ) . '" />' . "\n";
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( get_the_title( $post_id ) ) . '" />' . "\n";

		if ( $og_image ) {
			echo '<meta property="og:image" content="' . esc_url( $og_image ) . '" />' . "\n";
			echo '<meta name="twitter:image" content="' . esc_url( $og_image ) . '" />' . "\n";
		}
	}

	private static function set_meta( $post_id, $key, $value ) {
		if ( '' === $value ) {
			return;
		}
		update_post_meta( $post_id, $key, $value );
	}

	public static function has_yoast() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}

	public static function has_rank_math() {
		return class_exists( 'RankMath' );
	}

	public static function has_seopress() {
		return defined( 'SEOPRESS_VERSION' );
	}

	public static function has_seo_plugin() {
		return self::has_yoast() || self::has_rank_math() || self::has_seopress();
	}

	public static function active_seo_plugin() {
		if ( self::has_yoast() ) {
			return 'yoast';
		}
		if ( self::has_rank_math() ) {
			return 'rank-math';
		}
		if ( self::has_seopress() ) {
			return 'seopress';
		}
		return 'none';
	}
}
