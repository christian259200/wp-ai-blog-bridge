<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST surface consumed by the local AI pipeline.
 */
class ABB_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			ABB_NAMESPACE,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);

		register_rest_route(
			ABB_NAMESPACE,
			'/posts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_post' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);

		register_rest_route(
			ABB_NAMESPACE,
			'/posts/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_batch' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);

		register_rest_route(
			ABB_NAMESPACE,
			'/stale',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'stale_posts' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
				'args'                => array(
					'days'  => array(
						'default'           => 365,
						'sanitize_callback' => 'absint',
					),
					'limit' => array(
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			ABB_NAMESPACE,
			'/audit/(?P<post_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_audit' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);

		register_rest_route(
			ABB_NAMESPACE,
			'/posts/(?P<external_id>[A-Za-z0-9_\-\.]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_by_external_id' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
				'args'                => array(
					'external_id' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * Application Password (or cookie) auth plus the optional shared token.
	 */
	public static function authorize( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			self::authenticate_with_token( $request );
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'abb_not_authenticated',
				'Authentication required. Send the API token in X-ABB-Token, or an Application Password over HTTP Basic auth.',
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error(
				'abb_forbidden',
				'This account cannot publish posts.',
				array( 'status' => 403 )
			);
		}

		$expected = (string) ABB_Settings::get( 'shared_token' );
		if ( '' !== $expected && ! self::$token_authenticated ) {
			$provided = (string) $request->get_header( 'x_abb_token' );
			if ( ! hash_equals( $expected, $provided ) ) {
				return new WP_Error(
					'abb_bad_token',
					'Missing or invalid X-ABB-Token header.',
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/** @var bool Set when the request authenticated with the API token. */
	private static $token_authenticated = false;

	/**
	 * Token authentication, for sites where a security plugin disables
	 * Application Passwords. The token is a 48 character random string kept in
	 * the options table and compared in constant time; it authenticates as one
	 * configured user, whose capabilities still gate everything afterwards.
	 */
	private static function authenticate_with_token( WP_REST_Request $request ) {
		$token = (string) $request->get_header( 'x_abb_token' );
		if ( '' === $token ) {
			return;
		}

		$expected = (string) ABB_Settings::get( 'api_token' );
		$user_id  = absint( ABB_Settings::get( 'api_token_user' ) );

		if ( '' === $expected || ! $user_id || strlen( $expected ) < 32 ) {
			return; // token auth not configured
		}

		if ( ! hash_equals( $expected, $token ) ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		wp_set_current_user( $user_id );
		self::$token_authenticated = true;
	}

	public static function ping( WP_REST_Request $request ) {
		$user = wp_get_current_user();

		return rest_ensure_response(
			array(
				'ok'          => true,
				'plugin'      => 'ai-blog-bridge',
				'version'     => ABB_VERSION,
				'site'        => get_bloginfo( 'name' ),
				'home_url'    => home_url( '/' ),
				'user'        => $user->user_login,
				'auth'        => self::$token_authenticated ? 'api-token' : 'application-password',
				'can_publish' => current_user_can( 'publish_posts' ),
				'seo_plugin'  => ABB_SEO::active_seo_plugin(),
				'post_types'  => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
				'categories'  => array_map(
					static function ( $term ) {
						return array(
							'id'   => $term->term_id,
							'name' => $term->name,
							'slug' => $term->slug,
						);
					},
					get_terms(
						array(
							'taxonomy'   => 'category',
							'hide_empty' => false,
							'number'     => 100,
						)
					)
				),
			)
		);
	}

	public static function create_post( WP_REST_Request $request ) {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) || ! $payload ) {
			return new WP_Error( 'abb_bad_payload', 'Send a JSON object body.', array( 'status' => 400 ) );
		}

		$result = ABB_Post_Builder::upsert( $payload );

		if ( is_wp_error( $result ) ) {
			ABB_Logger::log(
				array(
					'external_id' => sanitize_text_field( $payload['external_id'] ?? '' ),
					'action'      => 'upsert',
					'status'      => 'error',
					'message'     => $result->get_error_message(),
				)
			);
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public static function create_batch( WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$items = isset( $body['posts'] ) ? (array) $body['posts'] : (array) $body;

		if ( ! $items ) {
			return new WP_Error( 'abb_bad_payload', 'Send { "posts": [ ... ] }.', array( 'status' => 400 ) );
		}

		$results = array();
		foreach ( $items as $index => $payload ) {
			if ( ! is_array( $payload ) ) {
				$results[] = array(
					'index' => $index,
					'ok'    => false,
					'error' => 'Item is not an object.',
				);
				continue;
			}

			$result = ABB_Post_Builder::upsert( $payload );

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'index'       => $index,
					'ok'          => false,
					'external_id' => $payload['external_id'] ?? '',
					'error'       => $result->get_error_message(),
					'code'        => $result->get_error_code(),
				);
				continue;
			}

			$results[] = array_merge( array( 'index' => $index, 'ok' => true ), $result );
		}

		return rest_ensure_response(
			array(
				'count'   => count( $results ),
				'failed'  => count(
					array_filter(
						$results,
						static function ( $row ) {
							return empty( $row['ok'] );
						}
					)
				),
				'results' => $results,
			)
		);
	}

	/**
	 * Posts that have not been touched in a while. Freshness is a ranking
	 * signal most competitors ignore, so this is the update queue.
	 */
	public static function stale_posts( WP_REST_Request $request ) {
		$days  = max( 1, (int) $request->get_param( 'days' ) );
		$limit = max( 1, (int) $request->get_param( 'limit' ) );

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'ASC',
				'date_query'     => array(
					array(
						'column' => 'post_modified_gmt',
						'before' => $days . ' days ago',
					),
				),
			)
		);

		$rows = array();
		foreach ( $posts as $post ) {
			$audit = get_post_meta( $post->ID, '_abb_audit', true );

			$rows[] = array(
				'post_id'       => $post->ID,
				'title'         => get_the_title( $post ),
				'permalink'     => get_permalink( $post ),
				'edit_link'     => get_edit_post_link( $post->ID, 'raw' ),
				'external_id'   => get_post_meta( $post->ID, ABB_Post_Builder::META_EXTERNAL_ID, true ),
				'modified_gmt'  => $post->post_modified_gmt,
				'days_stale'    => (int) floor( ( time() - strtotime( $post->post_modified_gmt . ' GMT' ) ) / DAY_IN_SECONDS ),
				'cornerstone'   => '1' === get_post_meta( $post->ID, '_abb_cornerstone', true ),
				'audit_summary' => $audit ? ABB_Audit::summarize( (array) json_decode( $audit, true ) ) : null,
			);
		}

		return rest_ensure_response(
			array(
				'days'  => $days,
				'count' => count( $rows ),
				'posts' => $rows,
			)
		);
	}

	/**
	 * The stored on-page audit for one post.
	 */
	public static function get_audit( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'abb_not_found', 'No such post.', array( 'status' => 404 ) );
		}

		$stored   = get_post_meta( $post_id, '_abb_audit', true );
		$findings = $stored ? (array) json_decode( $stored, true ) : array();

		return rest_ensure_response(
			array(
				'post_id'   => $post_id,
				'title'     => get_the_title( $post_id ),
				'permalink' => get_permalink( $post_id ),
				'reviewed'  => get_post_meta( $post_id, '_abb_last_reviewed', true ),
				'counts'    => ABB_Audit::summarize( $findings ),
				'findings'  => $findings,
			)
		);
	}

	public static function get_by_external_id( WP_REST_Request $request ) {
		$external_id = $request->get_param( 'external_id' );
		$post_id     = ABB_Post_Builder::find_by_external_id( $external_id );

		if ( ! $post_id ) {
			return new WP_Error( 'abb_not_found', 'No post with that external_id.', array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'post_id'     => $post_id,
				'external_id' => $external_id,
				'status'      => get_post_status( $post_id ),
				'title'       => get_the_title( $post_id ),
				'permalink'   => get_permalink( $post_id ),
				'modified'    => get_post_modified_time( 'c', true, $post_id ),
			)
		);
	}
}
