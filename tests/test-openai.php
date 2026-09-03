<?php
/**
 * Meta generator: prompt construction, response parsing and key handling.
 * No network calls: only the pure parts are exercised.
 *
 * Usage: php tests/test-openai.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "\n== Configuración ==\n";

check( 'sin key configurada, no está disponible', ! ABB_OpenAI::available() );
check( 'origen de la key: ninguno', 'none' === ABB_OpenAI::key_source() );
check( 'modelo por defecto', 'gpt-4o-mini' === ABB_OpenAI::model() );

$GLOBALS['abb_test_options']['abb_settings'] = array(
	'openai_key'   => 'sk-test-key',
	'openai_model' => 'gpt-4.1-mini',
);

check( 'con key en la base de datos, está disponible', ABB_OpenAI::available() );
check( 'origen de la key: base de datos', 'database' === ABB_OpenAI::key_source() );
check( 'modelo configurable', 'gpt-4.1-mini' === ABB_OpenAI::model() );

echo "\n== Prompt ==\n";

$messages = call_private(
	'ABB_OpenAI',
	'messages',
	array( 'title', '¿Qué es serigrafía?', 'serigrafía en Nicaragua', 'La serigrafía es una técnica de impresión por malla.' )
);

$system = $messages[0]['content'];
$user   = $messages[1]['content'];

check( 'rol de sistema primero', 'system' === $messages[0]['role'] && 'user' === $messages[1]['role'] );
check( 'idioma tomado del locale', contains( $system, 'Spanish' ) );
check( 'límite de 70 caracteres en el título', contains( $user, '70 characters' ) );
check( 'exige la keyword lo antes posible', contains( $user, 'as early as possible' ) );
check( 'pasa la keyword real', contains( $user, 'serigrafía en Nicaragua' ) );
check( 'pasa el contexto de la página', contains( $user, 'técnica de impresión por malla' ) );
check( 'pide JSON', contains( $user, '"variants"' ) );
check( 'prohíbe clickbait', contains( $user, 'No clickbait' ) );

$desc_messages = call_private(
	'ABB_OpenAI',
	'messages',
	array( 'description', 'Título', 'serigrafía', 'Contenido de la página.' )
);

check( 'rango 140-160 en la description', contains( $desc_messages[1]['content'], 'between 140 and 160 characters' ) );
check( 'pide variantes long-tail', contains( $desc_messages[1]['content'], 'long-tail variations' ) );

$GLOBALS['abb_test_locale'] = 'en_US';
$english = call_private( 'ABB_OpenAI', 'messages', array( 'title', 'T', 'k', 'c' ) );
check( 'cambia de idioma con el locale', contains( $english[0]['content'], 'English' ) );
$GLOBALS['abb_test_locale'] = 'es_ES';

echo "\n== Parseo de la respuesta ==\n";

$clean = json_encode(
	array(
		'variants' => array(
			'Serigrafía en Nicaragua: guía completa para empresas',
			'Qué es la serigrafía y cuándo conviene usarla en 2026',
			'Serigrafía para artículos promocionales: costos y proceso',
		),
	)
);

$variants = call_private( 'ABB_OpenAI', 'parse_variants', array( $clean, 'title' ) );

check( 'devuelve tres variantes', is_array( $variants ) && 3 === count( $variants ) );
check( 'incluye el texto', 'Serigrafía en Nicaragua: guía completa para empresas' === $variants[0]['text'] );
check( 'cuenta caracteres en multibyte, no bytes', 52 === $variants[0]['length'] && strlen( $variants[0]['text'] ) > $variants[0]['length'] );
check( 'marca el límite del título', 70 === $variants[0]['limit'] && true === $variants[0]['within'] );

$wrapped  = "Claro, aquí tienes:\n```json\n" . $clean . "\n```\nEspero que sirvan.";
$unwrapped = call_private( 'ABB_OpenAI', 'parse_variants', array( $wrapped, 'title' ) );
check( 'rescata el JSON envuelto en prosa', is_array( $unwrapped ) && 3 === count( $unwrapped ) );

$long = json_encode( array( 'variants' => array( str_repeat( 'a', 200 ) ) ) );
$over = call_private( 'ABB_OpenAI', 'parse_variants', array( $long, 'description' ) );
check( 'marca la variante que excede el límite', false === $over[0]['within'] && 160 === $over[0]['limit'] );

$many = json_encode( array( 'variants' => array( 'a', 'b', 'c', 'd', 'e' ) ) );
check( 'corta a tres variantes', 3 === count( call_private( 'ABB_OpenAI', 'parse_variants', array( $many, 'title' ) ) ) );

$bare = json_encode( array( 'Uno', 'Dos' ) );
check( 'acepta un array plano', 2 === count( call_private( 'ABB_OpenAI', 'parse_variants', array( $bare, 'title' ) ) ) );

$tagged = json_encode( array( 'variants' => array( '<b>Con etiquetas</b>' ) ) );
check( 'limpia etiquetas HTML', 'Con etiquetas' === call_private( 'ABB_OpenAI', 'parse_variants', array( $tagged, 'title' ) )[0]['text'] );

$garbage = call_private( 'ABB_OpenAI', 'parse_variants', array( 'no hay json aquí', 'title' ) );
check( 'error legible si no hay JSON', is_wp_error( $garbage ) );

$empty = call_private( 'ABB_OpenAI', 'parse_variants', array( json_encode( array( 'variants' => array( '', '  ' ) ) ), 'title' ) );
check( 'error si las variantes vienen vacías', is_wp_error( $empty ) );

echo "\n== Keyword almacenada ==\n";

abb_test_register_post( 20, array( 'title' => 'Post', 'content' => 'Texto' ) );
update_post_meta( 20, 'rank_math_focus_keyword', 'serigrafía nicaragua,serigrafía managua' );

check(
	'toma la keyword primaria de Rank Math',
	'serigrafía nicaragua' === call_private( 'ABB_OpenAI', 'stored_keyword', array( 20 ) )
);

update_post_meta( 21, '_abb_focus_keyword', 'bordado corporativo' );
check(
	'prefiere la keyword propia del plugin',
	'bordado corporativo' === call_private( 'ABB_OpenAI', 'stored_keyword', array( 21 ) )
);

check( 'sin keyword devuelve cadena vacía', '' === call_private( 'ABB_OpenAI', 'stored_keyword', array( 99 ) ) );

echo "\n== Extracto del contenido ==\n";

abb_test_register_post( 22, array( 'title' => 'Post', 'content' => '<p>Uno</p>   <p>Dos</p>' . str_repeat( ' relleno', 500 ) ) );
$excerpt = call_private( 'ABB_OpenAI', 'excerpt', array( get_post( 22 ) ) );

check( 'quita el HTML', ! contains( $excerpt, '<p>' ) );
check( 'colapsa espacios', ! contains( $excerpt, '   ' ) );
check( 'trunca a 1200 caracteres', ABB_Text::length( $excerpt ) <= 1200 );

exit( report() );
