<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media sideloading: pull remote images into the library so posts never
 * hotlink to Pixabay, Unsplash or a temporary generation URL.
 */
class ABB_Media {

	/**
	 * Download a remote image and attach it to a post.
	 *
	 * @param string $url
	 * @param int    $post_id
	 * @param array  $meta    alt, caption, title, filename
	 * @return int|WP_Error Attachment ID.
	 */
	public static function sideload( $url, $post_id = 0, array $meta = array() ) {
		$url = esc_url_raw( $url );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'abb_bad_image_url', 'Invalid image URL: ' . $url );
		}

		$existing = self::find_by_source( $url );
		if ( $existing ) {
			self::apply_meta( $existing, $meta );
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$name = ! empty( $meta['filename'] ) ? sanitize_file_name( $meta['filename'] ) : self::filename_from_url( $url );

		$file = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, $post_id, $meta['title'] ?? null );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_abb_source_url', $url );
		self::apply_meta( $attachment_id, $meta );

		return $attachment_id;
	}

	/**
	 * Replace remote <img src> values inside block markup with library URLs.
	 *
	 * @param string $content
	 * @param int    $post_id
	 * @param array  $alt_map url => alt text
	 * @return string
	 */
	public static function localize_content_images( $content, $post_id, array $alt_map = array() ) {
		if ( ! preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			return $content;
		}

		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( array_unique( $matches[1] ) as $src ) {
			$host = wp_parse_url( $src, PHP_URL_HOST );
			if ( ! $host || $host === $home ) {
				continue; // already local
			}

			$attachment_id = self::sideload(
				$src,
				$post_id,
				array( 'alt' => $alt_map[ $src ] ?? '' )
			);

			if ( is_wp_error( $attachment_id ) ) {
				ABB_Logger::log(
					array(
						'post_id' => $post_id,
						'action'  => 'sideload',
						'status'  => 'warning',
						'message' => $attachment_id->get_error_message(),
					)
				);
				continue;
			}

			$local = wp_get_attachment_url( $attachment_id );
			if ( $local ) {
				$content = str_replace( $src, $local, $content );
				// Give the image block the attachment id so the editor recognises it.
				$content = str_replace(
					'<!-- wp:image {"sizeSlug":"large"} -->',
					'<!-- wp:image {"id":' . (int) $attachment_id . ',"sizeSlug":"large"} -->',
					$content
				);
			}
		}

		return $content;
	}

	private static function apply_meta( $attachment_id, array $meta ) {
		if ( ! empty( $meta['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $meta['alt'] ) );
		}
		if ( ! empty( $meta['caption'] ) || ! empty( $meta['title'] ) ) {
			$update = array( 'ID' => $attachment_id );
			if ( ! empty( $meta['caption'] ) ) {
				$update['post_excerpt'] = sanitize_text_field( $meta['caption'] );
			}
			if ( ! empty( $meta['title'] ) ) {
				$update['post_title'] = sanitize_text_field( $meta['title'] );
			}
			wp_update_post( $update );
		}
	}

	private static function find_by_source( $url ) {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_abb_source_url',
				'meta_value'     => $url,
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	private static function filename_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$name = $path ? basename( $path ) : '';

		if ( ! $name || ! preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $name ) ) {
			$name = 'ai-blog-' . substr( md5( $url ), 0, 10 ) . '.jpg';
		}

		return sanitize_file_name( $name );
	}
}
