<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a request payload into a WordPress post.
 */
class ABB_Post_Builder {

	const META_EXTERNAL_ID = '_abb_external_id';
	const META_SOURCE      = '_abb_source';
	const META_PAYLOAD_HASH = '_abb_payload_hash';

	/**
	 * @param array $payload
	 * @return array|WP_Error Result data.
	 */
	public static function upsert( array $payload ) {
		$title = isset( $payload['title'] ) ? sanitize_text_field( $payload['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'abb_missing_title', 'A title is required.', array( 'status' => 400 ) );
		}

		$external_id = isset( $payload['external_id'] ) ? sanitize_text_field( $payload['external_id'] ) : '';
		$post_type   = isset( $payload['post_type'] ) ? sanitize_key( $payload['post_type'] ) : ABB_Settings::get( 'default_post_type' );

		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'abb_bad_post_type', 'Unknown post type: ' . $post_type, array( 'status' => 400 ) );
		}

		$existing_id = 0;
		if ( ! empty( $payload['post_id'] ) ) {
			$existing_id = absint( $payload['post_id'] );
		} elseif ( $external_id ) {
			$existing_id = self::find_by_external_id( $external_id, $post_type );
		}

		if ( $existing_id && isset( $payload['update_existing'] ) && ! $payload['update_existing'] ) {
			return new WP_Error(
				'abb_duplicate',
				'A post with this external_id already exists.',
				array(
					'status'  => 409,
					'post_id' => $existing_id,
				)
			);
		}

		$action = $existing_id ? 'update' : 'create';

		// --- Content ---------------------------------------------------------
		$format  = isset( $payload['content_format'] ) ? sanitize_key( $payload['content_format'] ) : 'markdown';
		$raw     = (string) ( $payload['content'] ?? '' );
		$content = self::render_content( $raw, $format );

		$content = self::prepend_key_takeaways( $content, $payload );
		$content = self::maybe_prepend_toc( $content, $payload, $raw, $format );
		$content = self::append_faq( $content, $payload );
		$content = self::append_sources( $content, $payload );

		// --- On-page enhancements --------------------------------------------
		$links_injected = array();
		$links_missed   = array();

		if ( ! empty( $payload['internal_links'] ) ) {
			list( $content, $links_injected, $links_missed ) = ABB_Enhance::inject_internal_links(
				$content,
				(array) $payload['internal_links']
			);
		}

		$content = ABB_Enhance::apply_tooltips(
			$content,
			(array) ( $payload['tooltips'] ?? array() ),
			isset( $payload['image_tooltip_prefix'] ) ? sanitize_text_field( $payload['image_tooltip_prefix'] ) : ''
		);

		if ( ! empty( $payload['byline'] ) && is_array( $payload['byline'] ) ) {
			$content = ABB_Enhance::prepend_byline( $content, $payload['byline'] );
		}

		if ( ! empty( $payload['show_updated_date'] ) ) {
			$content = ABB_Enhance::prepend_freshness_stamp(
				$content,
				isset( $payload['updated_label'] ) ? sanitize_text_field( $payload['updated_label'] ) : ABB_Text::label( 'updated' ),
				date_i18n( get_option( 'date_format' ) )
			);
		}

		// --- Core post fields ------------------------------------------------
		if ( isset( $payload['status'] ) ) {
			$status = sanitize_key( $payload['status'] );
		} elseif ( $existing_id ) {
			// Never silently unpublish a live post just because the payload
			// omitted a status. Updating content keeps whatever state it had.
			$status = get_post_status( $existing_id );
		} else {
			$status = ABB_Settings::get( 'default_status' );
		}

		if ( ! in_array( $status, array( 'draft', 'publish', 'pending', 'future', 'private' ), true ) ) {
			$status = $existing_id ? 'draft' : ABB_Settings::get( 'default_status' );
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_excerpt' => isset( $payload['excerpt'] ) ? sanitize_textarea_field( $payload['excerpt'] ) : '',
		);

		if ( ! empty( $payload['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $payload['slug'] );
		}

		if ( ! empty( $payload['date'] ) ) {
			$timestamp = strtotime( $payload['date'] );
			if ( $timestamp ) {
				$postarr['post_date']     = gmdate( 'Y-m-d H:i:s', $timestamp + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
				$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
				if ( 'future' === $status && $timestamp <= time() ) {
					$postarr['post_status'] = 'publish';
				}
			}
		}

		$author_id = self::resolve_author( $payload );
		if ( $author_id ) {
			$postarr['post_author'] = $author_id;
		}

		if ( isset( $payload['comment_status'] ) ) {
			$postarr['comment_status'] = 'open' === $payload['comment_status'] ? 'open' : 'closed';
		}

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// --- Media -----------------------------------------------------------
		$sideload = isset( $payload['sideload_images'] )
			? (bool) $payload['sideload_images']
			: (bool) ABB_Settings::get( 'sideload_images' );

		if ( $sideload ) {
			$alt_map = array();
			foreach ( (array) ( $payload['images'] ?? array() ) as $image ) {
				if ( ! empty( $image['url'] ) ) {
					$alt_map[ $image['url'] ] = $image['alt'] ?? '';
				}
			}

			$localized = ABB_Media::localize_content_images( $content, $post_id, $alt_map );
			if ( $localized !== $content ) {
				$content = $localized;
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_slash( $content ),
					)
				);
			}
		}

		$featured_notice = self::set_featured_image( $post_id, $payload, $sideload );

		// --- Taxonomies ------------------------------------------------------
		self::apply_taxonomies( $post_id, $payload, $post_type );

		// --- Meta ------------------------------------------------------------
		if ( $external_id ) {
			update_post_meta( $post_id, self::META_EXTERNAL_ID, $external_id );
		}
		update_post_meta( $post_id, self::META_SOURCE, sanitize_text_field( $payload['source'] ?? 'ai-blog-bridge' ) );
		update_post_meta( $post_id, self::META_PAYLOAD_HASH, md5( wp_json_encode( $payload ) ) );

		foreach ( (array) ( $payload['meta'] ?? array() ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || '_' === $key[0] ) {
				continue; // protected meta stays off limits
			}
			update_post_meta( $post_id, $key, is_scalar( $value ) ? sanitize_text_field( $value ) : wp_json_encode( $value ) );
		}

		if ( isset( $payload['sticky'] ) ) {
			$payload['sticky'] ? stick_post( $post_id ) : unstick_post( $post_id );
		}

		ABB_SEO::save( $post_id, (array) ( $payload['seo'] ?? array() ) );
		ABB_SEO::save_schema( $post_id, $payload );

		if ( isset( $payload['cornerstone'] ) ) {
			ABB_Enhance::set_cornerstone( $post_id, (bool) $payload['cornerstone'] );
		}

		update_post_meta( $post_id, '_abb_last_reviewed', current_time( 'mysql' ) );

		$audit = ABB_Audit::run( $post_id, $payload, $content );

		$result = array(
			'post_id'        => $post_id,
			'action'         => $action,
			'status'         => get_post_status( $post_id ),
			'permalink'      => get_permalink( $post_id ),
			'edit_link'      => get_edit_post_link( $post_id, 'raw' ),
			'inspect_link'   => ABB_Enhance::inspection_url( get_permalink( $post_id ) ),
			'external_id'    => $external_id,
			'seo_plugin'     => ABB_SEO::active_seo_plugin(),
			'warnings'       => array_values( array_filter( array( $featured_notice ) ) ),
			'internal_links' => array(
				'injected' => $links_injected,
				'missed'   => $links_missed,
			),
			'audit'          => array(
				'counts'   => ABB_Audit::summarize( $audit ),
				'findings' => $audit,
			),
		);

		if ( $links_missed ) {
			$result['warnings'][] = sprintf(
				'Internal links not injected because the anchor text does not appear in the body: %s',
				implode( ', ', $links_missed )
			);
		}

		ABB_Logger::log(
			array(
				'external_id' => $external_id,
				'post_id'     => $post_id,
				'action'      => $action,
				'status'      => 'ok',
				'message'     => $title,
			)
		);

		return $result;
	}

	/* --------------------------------------------------------------------- */

	private static function render_content( $raw, $format ) {
		switch ( $format ) {
			case 'blocks':
			case 'gutenberg':
				return $raw;
			case 'html':
				return self::html_to_blocks( $raw );
			case 'markdown':
			case 'md':
			default:
				return ABB_Markdown::to_blocks( $raw );
		}
	}

	/**
	 * Wrap plain HTML so the block editor keeps it in one classic-free block.
	 */
	private static function html_to_blocks( $html ) {
		if ( false !== strpos( $html, '<!-- wp:' ) ) {
			return $html;
		}
		return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
	}

	private static function prepend_key_takeaways( $content, array $payload ) {
		$items = array_filter( array_map( 'trim', (array) ( $payload['key_takeaways'] ?? array() ) ) );
		if ( ! $items ) {
			return $content;
		}

		$label = isset( $payload['key_takeaways_label'] )
			? sanitize_text_field( $payload['key_takeaways_label'] )
			: ABB_Text::label( 'key_takeaways' );

		$box  = "<!-- wp:group {\"className\":\"abb-key-takeaways\",\"layout\":{\"type\":\"constrained\"}} -->\n";
		$box .= '<div class="wp-block-group abb-key-takeaways">' . "\n";
		$box .= "<!-- wp:heading {\"level\":2,\"anchor\":\"key-takeaways\"} -->\n";
		$box .= '<h2 class="wp-block-heading" id="key-takeaways">' . esc_html( $label ) . "</h2>\n";
		$box .= "<!-- /wp:heading -->\n";
		$box .= "<!-- wp:list -->\n<ul class=\"wp-block-list\">";
		foreach ( $items as $item ) {
			$box .= "\n<!-- wp:list-item -->\n<li>" . wp_kses_post( $item ) . "</li>\n<!-- /wp:list-item -->";
		}
		$box .= "\n</ul>\n<!-- /wp:list -->\n</div>\n<!-- /wp:group -->";

		return $box . "\n\n" . $content;
	}

	private static function maybe_prepend_toc( $content, array $payload, $raw, $format ) {
		if ( empty( $payload['toc'] ) ) {
			return $content;
		}

		$headings = ABB_Markdown::last_headings();
		if ( ! $headings && 'markdown' === $format ) {
			ABB_Markdown::to_blocks( $raw );
			$headings = ABB_Markdown::last_headings();
		}

		$h2 = array_filter(
			$headings,
			static function ( $heading ) {
				return 2 === (int) $heading['level'];
			}
		);

		if ( count( $h2 ) < 3 ) {
			return $content; // a table of contents for two sections is noise
		}

		$label = isset( $payload['toc_label'] ) ? sanitize_text_field( $payload['toc_label'] ) : ABB_Text::label( 'toc' );

		$toc  = "<!-- wp:group {\"className\":\"abb-toc\",\"layout\":{\"type\":\"constrained\"}} -->\n";
		$toc .= '<div class="wp-block-group abb-toc">' . "\n";
		$toc .= "<!-- wp:heading {\"level\":2,\"anchor\":\"on-this-page\"} -->\n";
		$toc .= '<h2 class="wp-block-heading" id="on-this-page">' . esc_html( $label ) . "</h2>\n";
		$toc .= "<!-- /wp:heading -->\n";
		$toc .= "<!-- wp:list -->\n<ul class=\"wp-block-list\">";
		foreach ( $h2 as $heading ) {
			$toc .= "\n<!-- wp:list-item -->\n<li><a href=\"#" . esc_attr( $heading['anchor'] ) . '">' . esc_html( $heading['text'] ) . "</a></li>\n<!-- /wp:list-item -->";
		}
		$toc .= "\n</ul>\n<!-- /wp:list -->\n</div>\n<!-- /wp:group -->";

		return $toc . "\n\n" . $content;
	}

	private static function append_faq( $content, array $payload ) {
		$faq = (array) ( $payload['faq'] ?? array() );
		if ( ! $faq || ! empty( $payload['faq_schema_only'] ) ) {
			return $content;
		}

		$label = isset( $payload['faq_label'] ) ? sanitize_text_field( $payload['faq_label'] ) : ABB_Text::label( 'faq' );

		$html  = "<!-- wp:heading {\"level\":2,\"anchor\":\"faq\"} -->\n";
		$html .= '<h2 class="wp-block-heading" id="faq">' . esc_html( $label ) . "</h2>\n";
		$html .= "<!-- /wp:heading -->";

		foreach ( $faq as $item ) {
			$question = isset( $item['question'] ) ? trim( wp_strip_all_tags( $item['question'] ) ) : '';
			$answer   = isset( $item['answer'] ) ? trim( $item['answer'] ) : '';
			if ( ! $question || ! $answer ) {
				continue;
			}
			$anchor = sanitize_title( $question );

			$html .= "\n\n<!-- wp:heading {\"level\":3,\"anchor\":\"" . esc_attr( $anchor ) . "\"} -->\n";
			$html .= '<h3 class="wp-block-heading" id="' . esc_attr( $anchor ) . '">' . esc_html( $question ) . "</h3>\n";
			$html .= "<!-- /wp:heading -->\n\n";
			$html .= "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $answer ) . "</p>\n<!-- /wp:paragraph -->";
		}

		return $content . "\n\n" . $html;
	}

	private static function append_sources( $content, array $payload ) {
		$sources = (array) ( $payload['sources'] ?? array() );
		if ( ! $sources ) {
			return $content;
		}

		$label = isset( $payload['sources_label'] ) ? sanitize_text_field( $payload['sources_label'] ) : ABB_Text::label( 'sources' );

		$html  = "<!-- wp:heading {\"level\":2,\"anchor\":\"sources\"} -->\n";
		$html .= '<h2 class="wp-block-heading" id="sources">' . esc_html( $label ) . "</h2>\n";
		$html .= "<!-- /wp:heading -->\n\n";
		$html .= "<!-- wp:list -->\n<ul class=\"wp-block-list\">";

		foreach ( $sources as $source ) {
			if ( is_string( $source ) ) {
				$source = array( 'title' => $source );
			}
			$title = isset( $source['title'] ) ? wp_strip_all_tags( $source['title'] ) : '';
			$url   = isset( $source['url'] ) ? esc_url_raw( $source['url'] ) : '';
			$date  = isset( $source['date'] ) ? sanitize_text_field( $source['date'] ) : '';
			if ( ! $title && ! $url ) {
				continue;
			}
			$text  = $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ?: $url ) . '</a>' : esc_html( $title );
			$text .= $date ? ' <span class="abb-source-date">(' . esc_html( $date ) . ')</span>' : '';
			$html .= "\n<!-- wp:list-item -->\n<li>" . $text . "</li>\n<!-- /wp:list-item -->";
		}

		$html .= "\n</ul>\n<!-- /wp:list -->";

		return $content . "\n\n" . $html;
	}

	private static function set_featured_image( $post_id, array $payload, $sideload ) {
		$featured = $payload['featured_image'] ?? null;
		if ( ! $featured ) {
			return '';
		}

		if ( is_string( $featured ) ) {
			$featured = array( 'url' => $featured );
		}

		if ( ! empty( $featured['attachment_id'] ) ) {
			set_post_thumbnail( $post_id, absint( $featured['attachment_id'] ) );
			return '';
		}

		if ( empty( $featured['url'] ) ) {
			return '';
		}

		if ( ! $sideload ) {
			return 'Featured image skipped: image sideloading is disabled.';
		}

		$attachment_id = ABB_Media::sideload(
			$featured['url'],
			$post_id,
			array(
				'alt'     => $featured['alt'] ?? '',
				'caption' => $featured['caption'] ?? '',
				'title'   => $featured['title'] ?? '',
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			ABB_Logger::log(
				array(
					'post_id' => $post_id,
					'action'  => 'featured',
					'status'  => 'warning',
					'message' => $attachment_id->get_error_message(),
				)
			);
			return 'Featured image failed: ' . $attachment_id->get_error_message();
		}

		set_post_thumbnail( $post_id, $attachment_id );
		return '';
	}

	private static function apply_taxonomies( $post_id, array $payload, $post_type ) {
		$map = array();

		if ( isset( $payload['categories'] ) ) {
			$map['category'] = (array) $payload['categories'];
		}
		if ( isset( $payload['tags'] ) ) {
			$map['post_tag'] = (array) $payload['tags'];
		}
		foreach ( (array) ( $payload['taxonomies'] ?? array() ) as $taxonomy => $terms ) {
			$map[ sanitize_key( $taxonomy ) ] = (array) $terms;
		}

		foreach ( $map as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_ids = array();
			foreach ( $terms as $term ) {
				$term = is_scalar( $term ) ? trim( (string) $term ) : '';
				if ( '' === $term ) {
					continue;
				}

				$existing = term_exists( $term, $taxonomy );
				if ( ! $existing ) {
					$existing = wp_insert_term( $term, $taxonomy );
				}
				if ( ! is_wp_error( $existing ) ) {
					$term_ids[] = (int) $existing['term_id'];
				}
			}

			if ( 'category' === $taxonomy && ! $term_ids ) {
				$fallback = absint( ABB_Settings::get( 'default_category' ) );
				if ( $fallback ) {
					$term_ids[] = $fallback;
				}
			}

			if ( $term_ids ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}

		// Never leave a post in Uncategorized when a fallback is configured.
		if ( 'post' === $post_type && empty( $map['category'] ) ) {
			$fallback = absint( ABB_Settings::get( 'default_category' ) );
			if ( $fallback && ! has_category( '', $post_id ) ) {
				wp_set_object_terms( $post_id, array( $fallback ), 'category', false );
			}
		}
	}

	private static function resolve_author( array $payload ) {
		$author = $payload['author'] ?? '';

		if ( $author ) {
			if ( is_numeric( $author ) ) {
				$user = get_user_by( 'id', absint( $author ) );
			} elseif ( is_email( $author ) ) {
				$user = get_user_by( 'email', $author );
			} else {
				$user = get_user_by( 'login', $author );
				if ( ! $user ) {
					$user = get_user_by( 'slug', sanitize_title( $author ) );
				}
			}
			if ( $user ) {
				return (int) $user->ID;
			}
		}

		$default = absint( ABB_Settings::get( 'default_author' ) );
		if ( $default ) {
			return $default;
		}

		return get_current_user_id();
	}

	public static function find_by_external_id( $external_id, $post_type = 'any' ) {
		$found = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_EXTERNAL_ID,
				'meta_value'     => $external_id,
			)
		);

		return $found ? (int) $found[0] : 0;
	}
}
