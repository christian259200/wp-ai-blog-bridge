<?php
/**
 * Shared harness: stubs the slice of WordPress the content pipeline touches
 * so the plugin logic can be exercised from the command line.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ABB_NAMESPACE', 'ai-blog/v1' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['abb_test_meta']  = array();
$GLOBALS['abb_test_posts'] = array();

/* --- Escaping and sanitising ------------------------------------------- */

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return str_replace( array( '"', "'", '<', '>' ), '', (string) $url );
}
function esc_url_raw( $url ) {
	return (string) $url;
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function wp_kses_post( $text ) {
	return (string) $text;
}
function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function sanitize_title( $title ) {
	$title = strtolower( wp_strip_all_tags( $title ) );
	$title = strtr(
		$title,
		array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u', '¿' => '', '?' => '', '¡' => '', '!' => '' )
	);
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
	return trim( $title, '-' );
}
function wp_slash( $value ) {
	return $value;
}
function wp_json_encode( $value ) {
	return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/* --- URLs and options --------------------------------------------------- */

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function home_url( $path = '/' ) {
	return 'https://example.com' . $path;
}
function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}
function get_option( $name, $default = false ) {
	if ( isset( $GLOBALS['abb_test_options'][ $name ] ) ) {
		return $GLOBALS['abb_test_options'][ $name ];
	}

	$options = array(
		'date_format' => 'j F, Y',
		'gmt_offset'  => 0,
	);
	return $options[ $name ] ?? $default;
}
function date_i18n( $format, $timestamp = null ) {
	return gmdate( $format, $timestamp ?: time() );
}
function current_time( $type ) {
	return gmdate( 'Y-m-d H:i:s' );
}
function __( $text, $domain = null ) {
	return $text;
}

/* --- Errors ------------------------------------------------------------- */

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/* --- Posts and meta ----------------------------------------------------- */

function abb_test_register_post( $post_id, array $data ) {
	$GLOBALS['abb_test_posts'][ $post_id ] = $data;
}
function get_the_title( $post_id ) {
	return $GLOBALS['abb_test_posts'][ $post_id ]['title'] ?? '';
}
function get_permalink( $post_id ) {
	return $GLOBALS['abb_test_posts'][ $post_id ]['permalink'] ?? home_url( '/post/' );
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['abb_test_meta'][ $post_id ][ $key ] = $value;
	return true;
}
function get_post_meta( $post_id, $key, $single = false ) {
	return $GLOBALS['abb_test_meta'][ $post_id ][ $key ] ?? '';
}
function has_post_thumbnail( $post_id = 0 ) {
	return ! empty( $GLOBALS['abb_test_posts'][ $post_id ]['thumbnail'] );
}
function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['abb_test_meta'][ $post_id ][ $key ] );
	return true;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, array_filter( (array) $args, static function ( $v ) { return null !== $v; } ) );
}
function get_locale() {
	return $GLOBALS['abb_test_locale'] ?? 'es_ES';
}
function strip_shortcodes( $text ) {
	return $text;
}
function get_post( $post_id ) {
	if ( ! isset( $GLOBALS['abb_test_posts'][ $post_id ] ) ) {
		return null;
	}
	return (object) array(
		'ID'           => $post_id,
		'post_title'   => $GLOBALS['abb_test_posts'][ $post_id ]['title'] ?? '',
		'post_content' => $GLOBALS['abb_test_posts'][ $post_id ]['content'] ?? '',
		'post_author'  => 1,
	);
}

require_once __DIR__ . '/../includes/class-abb-text.php';
require_once __DIR__ . '/../includes/class-abb-openai.php';

require_once __DIR__ . '/../includes/class-abb-markdown.php';
require_once __DIR__ . '/../includes/class-abb-settings.php';
require_once __DIR__ . '/../includes/class-abb-seo.php';
require_once __DIR__ . '/../includes/class-abb-enhance.php';
require_once __DIR__ . '/../includes/class-abb-audit.php';
require_once __DIR__ . '/../includes/class-abb-media.php';
require_once __DIR__ . '/../includes/class-abb-logger.php';
require_once __DIR__ . '/../includes/class-abb-post-builder.php';

/* --- Assertions --------------------------------------------------------- */

$GLOBALS['abb_passed'] = 0;
$GLOBALS['abb_failed'] = 0;

function check( $label, $condition ) {
	if ( $condition ) {
		$GLOBALS['abb_passed']++;
		echo "  ok    {$label}\n";
	} else {
		$GLOBALS['abb_failed']++;
		echo "  FAIL  {$label}\n";
	}
}

function contains( $haystack, $needle ) {
	return false !== strpos( $haystack, $needle );
}

/**
 * Call a private static method for testing.
 */
function call_private( $class, $method, array $args ) {
	$reflection = new ReflectionMethod( $class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
}

function report() {
	echo "\n----------------------------------------\n";
	echo "Pasaron: {$GLOBALS['abb_passed']}   Fallaron: {$GLOBALS['abb_failed']}\n";
	return $GLOBALS['abb_failed'] > 0 ? 1 : 0;
}
