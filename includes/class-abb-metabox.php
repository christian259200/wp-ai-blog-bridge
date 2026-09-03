<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editor panel: regenerate the meta title and meta description on demand.
 *
 * Variants are shown for the editor to pick from and copy. Applying writes
 * straight to the SEO plugin's post meta, which is why the panel asks for a
 * reload afterwards rather than pretending to sync with the block editor's
 * in-memory state.
 */
class ABB_Metabox {

	const NONCE = 'abb_meta_nonce';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_abb_generate_meta', array( __CLASS__, 'ajax_generate' ) );
		add_action( 'wp_ajax_abb_apply_meta', array( __CLASS__, 'ajax_apply' ) );
	}

	public static function register() {
		if ( ! ABB_OpenAI::available() ) {
			return;
		}

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}

			add_meta_box(
				'abb-meta-generator',
				__( 'AI Blog Bridge · SEO meta', 'ai-blog-bridge' ),
				array( __CLASS__, 'render' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	public static function render( $post ) {
		$keyword = get_post_meta( $post->ID, ABB_SEO::META_KEYWORD, true );
		if ( ! $keyword ) {
			$keyword = get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
			$keyword = $keyword ? trim( explode( ',', $keyword )[0] ) : '';
		}
		?>
		<div class="abb-meta-panel">
			<?php wp_nonce_field( self::NONCE, 'abb_meta_nonce_field' ); ?>

			<p>
				<label for="abb-keyword"><strong><?php esc_html_e( 'Focus keyword', 'ai-blog-bridge' ); ?></strong></label>
				<input type="text" id="abb-keyword" class="widefat" value="<?php echo esc_attr( $keyword ); ?>" placeholder="<?php esc_attr_e( 'e.g. serigrafía en Nicaragua', 'ai-blog-bridge' ); ?>" />
			</p>

			<p>
				<button type="button" class="button" data-abb-generate="title"><?php esc_html_e( 'Generate title', 'ai-blog-bridge' ); ?></button>
				<button type="button" class="button" data-abb-generate="description"><?php esc_html_e( 'Generate description', 'ai-blog-bridge' ); ?></button>
			</p>

			<div class="abb-meta-status" aria-live="polite"></div>
			<div class="abb-meta-results"></div>

			<p class="description">
				<?php esc_html_e( 'Titles are capped at 70 characters, descriptions at 140-160. Applying writes to your SEO plugin and needs a page reload to show up in its fields.', 'ai-blog-bridge' ); ?>
			</p>
		</div>

		<style>
			.abb-meta-panel .abb-variant { border: 1px solid #dcdcde; border-radius: 4px; padding: 8px; margin-bottom: 8px; }
			.abb-meta-panel .abb-variant p { margin: 0 0 6px; }
			.abb-meta-panel .abb-count { font-size: 11px; color: #646970; }
			.abb-meta-panel .abb-count.over { color: #d63638; font-weight: 600; }
			.abb-meta-panel .abb-meta-status { margin: 6px 0; font-style: italic; color: #646970; }
			.abb-meta-panel .abb-meta-status.error { color: #d63638; font-style: normal; }
		</style>

		<script>
		( function () {
			var panel = document.querySelector( '.abb-meta-panel' );
			if ( ! panel ) { return; }

			var postId  = <?php echo (int) $post->ID; ?>;
			var nonce   = panel.querySelector( '#abb_meta_nonce_field' ).value;
			var status  = panel.querySelector( '.abb-meta-status' );
			var results = panel.querySelector( '.abb-meta-results' );
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			function say( message, isError ) {
				status.textContent = message || '';
				status.className = 'abb-meta-status' + ( isError ? ' error' : '' );
			}

			function post( action, extra ) {
				var body = new URLSearchParams();
				body.append( 'action', action );
				body.append( 'post_id', postId );
				body.append( 'nonce', nonce );
				Object.keys( extra ).forEach( function ( key ) { body.append( key, extra[ key ] ); } );

				return fetch( ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} ).then( function ( response ) { return response.json(); } );
			}

			function render( type, variants ) {
				results.innerHTML = '';

				variants.forEach( function ( variant ) {
					var box = document.createElement( 'div' );
					box.className = 'abb-variant';

					var text = document.createElement( 'p' );
					text.textContent = variant.text;
					box.appendChild( text );

					var count = document.createElement( 'span' );
					count.className = 'abb-count' + ( variant.within ? '' : ' over' );
					count.textContent = variant.length + ' / ' + variant.limit;
					box.appendChild( count );

					var apply = document.createElement( 'button' );
					apply.type = 'button';
					apply.className = 'button button-small';
					apply.style.marginLeft = '8px';
					apply.textContent = <?php echo wp_json_encode( __( 'Apply', 'ai-blog-bridge' ) ); ?>;
					apply.addEventListener( 'click', function () {
						apply.disabled = true;
						say( <?php echo wp_json_encode( __( 'Saving…', 'ai-blog-bridge' ) ); ?> );

						post( 'abb_apply_meta', { type: type, value: variant.text } ).then( function ( json ) {
							apply.disabled = false;
							if ( json.success ) {
								say( json.data.message );
							} else {
								say( json.data.message, true );
							}
						} ).catch( function () {
							apply.disabled = false;
							say( <?php echo wp_json_encode( __( 'Network error.', 'ai-blog-bridge' ) ); ?>, true );
						} );
					} );
					box.appendChild( apply );

					var copy = document.createElement( 'button' );
					copy.type = 'button';
					copy.className = 'button button-small';
					copy.style.marginLeft = '4px';
					copy.textContent = <?php echo wp_json_encode( __( 'Copy', 'ai-blog-bridge' ) ); ?>;
					copy.addEventListener( 'click', function () {
						navigator.clipboard.writeText( variant.text ).then( function () {
							say( <?php echo wp_json_encode( __( 'Copied.', 'ai-blog-bridge' ) ); ?> );
						} );
					} );
					box.appendChild( copy );

					results.appendChild( box );
				} );
			}

			panel.querySelectorAll( '[data-abb-generate]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var type = button.getAttribute( 'data-abb-generate' );

					panel.querySelectorAll( '[data-abb-generate]' ).forEach( function ( b ) { b.disabled = true; } );
					results.innerHTML = '';
					say( <?php echo wp_json_encode( __( 'Generating…', 'ai-blog-bridge' ) ); ?> );

					post( 'abb_generate_meta', {
						type: type,
						keyword: panel.querySelector( '#abb-keyword' ).value
					} ).then( function ( json ) {
						panel.querySelectorAll( '[data-abb-generate]' ).forEach( function ( b ) { b.disabled = false; } );

						if ( json.success ) {
							say( '' );
							render( type, json.data.variants );
						} else {
							say( json.data.message, true );
						}
					} ).catch( function () {
						panel.querySelectorAll( '[data-abb-generate]' ).forEach( function ( b ) { b.disabled = false; } );
						say( <?php echo wp_json_encode( __( 'Network error.', 'ai-blog-bridge' ) ); ?>, true );
					} );
				} );
			} );
		}() );
		</script>
		<?php
	}

	/* --------------------------------------------------------------------- */

	private static function guard() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $post_id || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_send_json_error( array( 'message' => __( 'Expired session. Reload the page.', 'ai-blog-bridge' ) ), 403 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot edit this post.', 'ai-blog-bridge' ) ), 403 );
		}

		return $post_id;
	}

	public static function ajax_generate() {
		$post_id = self::guard();

		$type    = isset( $_POST['type'] ) && 'title' === $_POST['type'] ? 'title' : 'description';
		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';

		$variants = ABB_OpenAI::generate_meta( $post_id, $type, $keyword );

		if ( is_wp_error( $variants ) ) {
			ABB_Logger::log(
				array(
					'post_id' => $post_id,
					'action'  => 'openai',
					'status'  => 'error',
					'message' => $variants->get_error_message(),
				)
			);

			wp_send_json_error( array( 'message' => $variants->get_error_message() ) );
		}

		ABB_Logger::log(
			array(
				'post_id' => $post_id,
				'action'  => 'openai',
				'status'  => 'ok',
				'message' => sprintf( 'meta %s x%d', $type, count( $variants ) ),
			)
		);

		wp_send_json_success( array( 'variants' => $variants ) );
	}

	public static function ajax_apply() {
		$post_id = self::guard();

		$type  = isset( $_POST['type'] ) && 'title' === $_POST['type'] ? 'title' : 'description';
		$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

		if ( '' === $value ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to save.', 'ai-blog-bridge' ) ) );
		}

		$existing = array(
			'title'         => get_post_meta( $post_id, ABB_SEO::META_TITLE, true ),
			'description'   => get_post_meta( $post_id, ABB_SEO::META_DESCRIPTION, true ),
			'focus_keyword' => get_post_meta( $post_id, ABB_SEO::META_KEYWORD, true ),
			'canonical'     => get_post_meta( $post_id, ABB_SEO::META_CANONICAL, true ),
			'og_image'      => get_post_meta( $post_id, ABB_SEO::META_OG_IMAGE, true ),
		);

		$existing[ $type ] = $value;

		if ( isset( $_POST['keyword'] ) ) {
			$keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ) );
			if ( $keyword ) {
				$existing['focus_keyword'] = $keyword;
			}
		}

		ABB_SEO::save( $post_id, $existing );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: the SEO plugin in use */
					__( 'Saved to %s. Reload the editor to see it in its fields.', 'ai-blog-bridge' ),
					ABB_SEO::active_seo_plugin()
				),
			)
		);
	}
}
