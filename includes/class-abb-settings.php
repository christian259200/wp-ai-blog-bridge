<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen and option accessors.
 */
class ABB_Settings {

	const OPTION = 'abb_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function defaults() {
		return array(
			'shared_token'      => '',
			'default_status'    => 'draft',
			'default_post_type' => 'post',
			'default_author'    => 0,
			'default_category'  => 0,
			'sideload_images'   => 1,
			'emit_schema'       => 1,
			'blogposting_schema' => 'auto',
			'emit_og'           => 1,
			'log_retention'     => 200,
			'openai_key'        => '',
			'openai_model'      => 'gpt-4o-mini',
			'force_h1'          => 1,
			'api_token'         => '',
			'api_token_user'    => 0,
		);
	}

	/**
	 * Read one setting, or the whole array when $key is null.
	 */
	public static function get( $key = null, $fallback = null ) {
		$options = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $options;
		}
		return isset( $options[ $key ] ) ? $options[ $key ] : $fallback;
	}

	public static function add_menu() {
		add_options_page(
			__( 'AI Blog Bridge', 'ai-blog-bridge' ),
			__( 'AI Blog Bridge', 'ai-blog-bridge' ),
			'manage_options',
			'ai-blog-bridge',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'abb_settings_group',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function sanitize( $input ) {
		$input = (array) $input;
		$clean = self::defaults();

		$status = isset( $input['default_status'] ) ? $input['default_status'] : 'draft';

		$clean['shared_token']      = isset( $input['shared_token'] ) ? sanitize_text_field( $input['shared_token'] ) : '';
		$clean['default_status']    = in_array( $status, array( 'draft', 'publish', 'pending', 'future', 'private' ), true ) ? $status : 'draft';
		$clean['default_post_type'] = isset( $input['default_post_type'] ) ? sanitize_key( $input['default_post_type'] ) : 'post';
		$clean['default_author']    = isset( $input['default_author'] ) ? absint( $input['default_author'] ) : 0;
		$clean['default_category']  = isset( $input['default_category'] ) ? absint( $input['default_category'] ) : 0;
		$clean['sideload_images']   = empty( $input['sideload_images'] ) ? 0 : 1;
		$clean['emit_schema']       = empty( $input['emit_schema'] ) ? 0 : 1;
		$mode                       = isset( $input['blogposting_schema'] ) ? sanitize_key( $input['blogposting_schema'] ) : 'auto';
		$clean['blogposting_schema'] = in_array( $mode, array( 'auto', 'always', 'never' ), true ) ? $mode : 'auto';
		$clean['emit_og']           = empty( $input['emit_og'] ) ? 0 : 1;
		$clean['log_retention']     = isset( $input['log_retention'] ) ? max( 10, absint( $input['log_retention'] ) ) : 200;
		$clean['openai_key']        = isset( $input['openai_key'] ) ? trim( sanitize_text_field( $input['openai_key'] ) ) : '';
		$clean['openai_model']      = isset( $input['openai_model'] ) ? trim( sanitize_text_field( $input['openai_model'] ) ) : 'gpt-4o-mini';
		$clean['force_h1']          = empty( $input['force_h1'] ) ? 0 : 1;
		$clean['api_token_user']    = isset( $input['api_token_user'] ) ? absint( $input['api_token_user'] ) : 0;

		// Keep the existing token unless a regeneration was explicitly requested.
		$current = (string) self::get( 'api_token', '' );

		if ( ! empty( $input['regenerate_api_token'] ) || '' === $current ) {
			$clean['api_token'] = self::generate_token();
		} else {
			$clean['api_token'] = $current;
		}

		return $clean;
	}

	/**
	 * A token long enough that guessing it is not a threat model.
	 */
	public static function generate_token() {
		return wp_generate_password( 48, false, false );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Blog Bridge', 'ai-blog-bridge' ); ?></h1>

			<h2><?php esc_html_e( 'Endpoints', 'ai-blog-bridge' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
				<tr>
					<td><code>POST</code></td>
					<td><code><?php echo esc_html( rest_url( ABB_NAMESPACE . '/posts' ) ); ?></code></td>
					<td><?php esc_html_e( 'Create or update a post', 'ai-blog-bridge' ); ?></td>
				</tr>
				<tr>
					<td><code>GET</code></td>
					<td><code><?php echo esc_html( rest_url( ABB_NAMESPACE . '/ping' ) ); ?></code></td>
					<td><?php esc_html_e( 'Auth and connectivity check', 'ai-blog-bridge' ); ?></td>
				</tr>
				<tr>
					<td><code>GET</code></td>
					<td><code><?php echo esc_html( rest_url( ABB_NAMESPACE . '/posts/{external_id}' ) ); ?></code></td>
					<td><?php esc_html_e( 'Look up a post by external id', 'ai-blog-bridge' ); ?></td>
				</tr>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'Two ways to authenticate: the API token below, or a WordPress Application Password over HTTP Basic auth. Use the token when a security plugin disables application passwords.', 'ai-blog-bridge' ); ?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( 'abb_settings_group' ); ?>

				<h2><?php esc_html_e( 'API token', 'ai-blog-bridge' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="abb_api_token"><?php esc_html_e( 'Token', 'ai-blog-bridge' ); ?></label></th>
						<td>
							<input type="text" class="large-text code" id="abb_api_token" value="<?php echo esc_attr( $o['api_token'] ); ?>" readonly onclick="this.select();" />
							<p class="description">
								<?php esc_html_e( 'Copy this into ABB_TOKEN in the client .env. Requests send it in the X-ABB-Token header. Click to select.', 'ai-blog-bridge' ); ?>
							</p>
							<p>
								<label>
									<input type="checkbox" name="abb_settings[regenerate_api_token]" value="1" />
									<?php esc_html_e( 'Generate a new token when saving (the old one stops working immediately)', 'ai-blog-bridge' ); ?>
								</label>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Token acts as', 'ai-blog-bridge' ); ?></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'              => 'abb_settings[api_token_user]',
									'selected'          => $o['api_token_user'],
									'show_option_none'  => __( 'Disabled — token authentication off', 'ai-blog-bridge' ),
									'option_none_value' => 0,
									'role__in'          => array( 'administrator', 'editor', 'author' ),
								)
							);
							?>
							<p class="description">
								<?php esc_html_e( 'Posts arrive as this user. Leave on "Disabled" to require an Application Password instead.', 'ai-blog-bridge' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Publishing defaults', 'ai-blog-bridge' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="abb_shared_token"><?php esc_html_e( 'Extra shared token', 'ai-blog-bridge' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="abb_shared_token" name="abb_settings[shared_token]" value="<?php echo esc_attr( $o['shared_token'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional extra layer for Application Password auth. Leave empty when using the API token above.', 'ai-blog-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abb_default_status"><?php esc_html_e( 'Default status', 'ai-blog-bridge' ); ?></label></th>
						<td>
							<select id="abb_default_status" name="abb_settings[default_status]">
								<?php foreach ( array( 'draft', 'pending', 'publish', 'future', 'private' ) as $status ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $o['default_status'], $status ); ?>><?php echo esc_html( $status ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used when the payload omits a status. Keep it on draft until the pipeline is trusted.', 'ai-blog-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abb_default_post_type"><?php esc_html_e( 'Default post type', 'ai-blog-bridge' ); ?></label></th>
						<td><input type="text" id="abb_default_post_type" name="abb_settings[default_post_type]" value="<?php echo esc_attr( $o['default_post_type'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default author', 'ai-blog-bridge' ); ?></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'              => 'abb_settings[default_author]',
									'selected'          => $o['default_author'],
									'show_option_none'  => __( 'Use the requesting user', 'ai-blog-bridge' ),
									'option_none_value' => 0,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Fallback category', 'ai-blog-bridge' ); ?></th>
						<td>
							<?php
							wp_dropdown_categories(
								array(
									'name'              => 'abb_settings[default_category]',
									'selected'          => $o['default_category'],
									'show_option_none'  => __( 'None', 'ai-blog-bridge' ),
									'option_none_value' => 0,
									'hide_empty'        => 0,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Behaviour', 'ai-blog-bridge' ); ?></th>
						<td>
							<label><input type="checkbox" name="abb_settings[sideload_images]" value="1" <?php checked( $o['sideload_images'], 1 ); ?> /> <?php esc_html_e( 'Download remote images into the media library', 'ai-blog-bridge' ); ?></label><br />
							<label><input type="checkbox" name="abb_settings[emit_schema]" value="1" <?php checked( $o['emit_schema'], 1 ); ?> /> <?php esc_html_e( 'Print JSON-LD schema in the page head', 'ai-blog-bridge' ); ?></label><br />
							<label><input type="checkbox" name="abb_settings[emit_og]" value="1" <?php checked( $o['emit_og'], 1 ); ?> /> <?php esc_html_e( 'Print Open Graph and Twitter tags when no SEO plugin is active', 'ai-blog-bridge' ); ?></label><br />
						<label><input type="checkbox" name="abb_settings[force_h1]" value="1" <?php checked( $o['force_h1'], 1 ); ?> /> <?php esc_html_e( 'Promote the post title to H1 when the theme leaves the page without one', 'ai-blog-bridge' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abb_blogposting_schema"><?php esc_html_e( 'BlogPosting schema', 'ai-blog-bridge' ); ?></label></th>
						<td>
							<select id="abb_blogposting_schema" name="abb_settings[blogposting_schema]">
								<option value="auto" <?php selected( $o['blogposting_schema'], 'auto' ); ?>><?php esc_html_e( 'Auto: skip it when an SEO plugin already emits one', 'ai-blog-bridge' ); ?></option>
								<option value="always" <?php selected( $o['blogposting_schema'], 'always' ); ?>><?php esc_html_e( 'Always emit', 'ai-blog-bridge' ); ?></option>
								<option value="never" <?php selected( $o['blogposting_schema'], 'never' ); ?>><?php esc_html_e( 'Never emit', 'ai-blog-bridge' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Yoast, Rank Math and SEOPress publish their own Article node. A second one for the same URL is a defect, not extra coverage: each carries its own author and dates, and a search engine picks one without telling you which. The FAQPage node is always published, because no SEO plugin builds it from this payload.', 'ai-blog-bridge' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abb_log_retention"><?php esc_html_e( 'Log rows to keep', 'ai-blog-bridge' ); ?></label></th>
						<td><input type="number" min="10" id="abb_log_retention" name="abb_settings[log_retention]" value="<?php echo esc_attr( $o['log_retention'] ); ?>" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Meta title and description generator', 'ai-blog-bridge' ); ?></h2>
				<p class="description" style="max-width:900px">
					<?php esc_html_e( 'Optional. Adds a panel to the post editor that rewrites the meta title and meta description on demand. It does not write articles: full content generation needs research, source checks and images, none of which fit in a single PHP request.', 'ai-blog-bridge' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'API key', 'ai-blog-bridge' ); ?></th>
						<td>
							<?php $source = ABB_OpenAI::key_source(); ?>
							<?php if ( 'wp-config' === $source ) : ?>
								<p><strong style="color:#00753a"><?php esc_html_e( 'Detected in wp-config.php.', 'ai-blog-bridge' ); ?></strong> <?php esc_html_e( 'This is the recommended place: the key stays out of the database, out of backups and out of any plugin that can read options.', 'ai-blog-bridge' ); ?></p>
							<?php else : ?>
								<p><?php esc_html_e( 'Recommended: add this line to wp-config.php, above the "That\'s all, stop editing" comment.', 'ai-blog-bridge' ); ?></p>
								<p><code>define( 'ABB_OPENAI_KEY', 'sk-...' );</code></p>
								<p style="margin-top:12px">
									<label for="abb_openai_key"><?php esc_html_e( 'Or store it here (less secure, it lives in the database):', 'ai-blog-bridge' ); ?></label><br />
									<input type="password" class="regular-text" id="abb_openai_key" name="abb_settings[openai_key]" value="<?php echo esc_attr( $o['openai_key'] ); ?>" autocomplete="off" />
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abb_openai_model"><?php esc_html_e( 'Model', 'ai-blog-bridge' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="abb_openai_model" name="abb_settings[openai_model]" value="<?php echo esc_attr( $o['openai_model'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Any chat model your account can reach. A small, cheap model is plenty for meta tags.', 'ai-blog-bridge' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Recent activity', 'ai-blog-bridge' ); ?></h2>
			<?php ABB_Logger::render_table(); ?>
		</div>
		<?php
	}
}
