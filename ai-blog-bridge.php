<?php
/**
 * Plugin Name:       AI Blog Bridge
 * Plugin URI:        https://github.com/christian259200/wp-ai-blog-bridge
 * Description:       Receives structured blog posts from a local AI pipeline over the REST API and publishes them with images, taxonomies, SEO meta and JSON-LD schema.
 * Version:           2.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Christian Monge
 * Author URI:        https://github.com/christian259200
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-blog-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABB_VERSION', '2.2.0' );
define( 'ABB_FILE', __FILE__ );
define( 'ABB_PATH', plugin_dir_path( __FILE__ ) );
define( 'ABB_NAMESPACE', 'ai-blog/v1' );

require_once ABB_PATH . 'includes/class-abb-text.php';
require_once ABB_PATH . 'includes/class-abb-settings.php';
require_once ABB_PATH . 'includes/class-abb-logger.php';
require_once ABB_PATH . 'includes/class-abb-markdown.php';
require_once ABB_PATH . 'includes/class-abb-media.php';
require_once ABB_PATH . 'includes/class-abb-seo.php';
require_once ABB_PATH . 'includes/class-abb-enhance.php';
require_once ABB_PATH . 'includes/class-abb-audit.php';
require_once ABB_PATH . 'includes/class-abb-post-builder.php';
require_once ABB_PATH . 'includes/class-abb-openai.php';
require_once ABB_PATH . 'includes/class-abb-metabox.php';
require_once ABB_PATH . 'includes/class-abb-frontend.php';
require_once ABB_PATH . 'includes/class-abb-rest.php';

/**
 * Boot the plugin once WordPress has loaded its own APIs.
 */
function abb_bootstrap() {
	ABB_Settings::init();
	ABB_Logger::init();
	ABB_SEO::init();
	ABB_Frontend::init();
	ABB_Metabox::init();
	ABB_REST::init();
}
add_action( 'plugins_loaded', 'abb_bootstrap' );

/**
 * Style the structural blocks (key takeaways, table of contents) the bridge injects.
 */
function abb_enqueue_styles() {
	// Single posts get the article styles; the blog index, category, tag and
	// search archives get the card grid. Checking only is_singular() left the
	// listing unstyled, because a posts page is an archive, not a singular.
	$wanted = is_singular()
		|| is_home()
		|| is_category()
		|| is_tag()
		|| is_search()
		|| is_archive();

	if ( ! $wanted ) {
		return;
	}

	wp_enqueue_style(
		'ai-blog-bridge',
		plugins_url( 'assets/abb-frontend.css', ABB_FILE ),
		array(),
		ABB_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'abb_enqueue_styles' );

/**
 * Create the log table on activation.
 */
function abb_activate() {
	ABB_Logger::install_table();

	$settings = wp_parse_args( (array) get_option( 'abb_settings', array() ), ABB_Settings::defaults() );

	if ( empty( $settings['api_token'] ) ) {
		$settings['api_token'] = ABB_Settings::generate_token();
	}

	update_option( 'abb_settings', $settings );
}
register_activation_hook( __FILE__, 'abb_activate' );
