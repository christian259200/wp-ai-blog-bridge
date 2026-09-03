<?php
/**
 * On-page audit rules and content enhancements.
 *
 * Usage: php tests/test-audit.php
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * @return string[] Finding codes returned by the audit.
 */
function audit_codes( $post_id, array $payload, $content ) {
	$findings = ABB_Audit::run( $post_id, $payload, $content );
	return array_column( $findings, 'code' );
}

/* --- A deliberately bad post -------------------------------------------- */

echo "\n== Post malo: el caso serigrafía ==\n";

abb_test_register_post(
	1,
	array(
		'title'     => '¿Qué es serigrafía? Guía completa para artículos promocionales',
		'permalink' => 'https://example.com/blog/blog/que-es-serigrafia/',
	)
);

// Reproduces the real problem: paragraphs only, no headings, no links, no images.
$bad_content = '';
for ( $i = 0; $i < 4; $i++ ) {
	$bad_content .= "<!-- wp:paragraph -->\n<p>La técnica de personalización es antigua y efectiva para uniformes corporativos y bolsos con logo de empresa en Nicaragua.</p>\n<!-- /wp:paragraph -->\n\n";
}

$bad_payload = array(
	'seo' => array(
		'title'         => '¿Qué es serigrafía? Guía completa para artículos promocionales en Nicaragua y toda Centroamérica',
		'description'   => 'Guía de serigrafía.',
		'focus_keyword' => 'que es serigrafia',
	),
);

$codes = audit_codes( 1, $bad_payload, $bad_content );

check( 'detecta meta title largo', in_array( 'title_too_long', $codes, true ) );
check( 'detecta meta description corta', in_array( 'description_too_short', $codes, true ) );
check( 'detecta ausencia de H2', in_array( 'no_h2', $codes, true ) );
check( 'detecta contenido delgado', in_array( 'thin_content', $codes, true ) );
check( 'detecta cero enlaces internos', in_array( 'no_internal_links', $codes, true ) );
check( 'detecta cero citas externas', in_array( 'no_citations', $codes, true ) );
check( 'detecta ausencia de imágenes', in_array( 'no_images', $codes, true ) );
check( 'detecta ausencia de encabezados pregunta', in_array( 'no_question_headings', $codes, true ) );
check( 'detecta ausencia de listas o tablas', in_array( 'no_lists_or_tables', $codes, true ) );
check( 'detecta URL con directorio repetido', in_array( 'url_too_deep', $codes, true ) === false ); // 3 segmentos, aún aceptable

/* --- A good post --------------------------------------------------------- */

echo "\n== Post bueno ==\n";

abb_test_register_post(
	2,
	array(
		'title'     => 'Qué es la serigrafía y cuándo conviene usarla',
		'permalink' => 'https://example.com/blog/que-es-serigrafia/',
	)
);

$body = "<!-- wp:paragraph -->\n<p>La serigrafía es una técnica de impresión por malla. En resumen, conviene cuando imprimes muchas piezas con pocos colores.</p>\n<!-- /wp:paragraph -->\n\n";
$body .= "<!-- wp:heading {\"level\":2,\"anchor\":\"como-funciona\"} -->\n<h2 class=\"wp-block-heading\" id=\"como-funciona\">¿Cómo funciona la serigrafía paso a paso?</h2>\n<!-- /wp:heading -->\n\n";
$body .= "<!-- wp:paragraph -->\n<p>Paso 1: se prepara el diseño y se separa en capas de color. Consulta nuestro <a href=\"https://example.com/catalogo/\" title=\"Ver el catálogo\">catálogo de textiles</a> y el <a href=\"https://example.com/productos/\">listado de productos</a> para elegir la prenda.</p>\n<!-- /wp:paragraph -->\n\n";
$body .= "<!-- wp:list -->\n<ul class=\"wp-block-list\"><li>Malla tensada</li><li>Emulsión fotosensible</li><li>Tinta plastisol</li></ul>\n<!-- /wp:list -->\n\n";
$body .= "<!-- wp:heading {\"level\":2,\"anchor\":\"cuanto-cuesta\"} -->\n<h2 class=\"wp-block-heading\" id=\"cuanto-cuesta\">¿Cuánto cuesta la serigrafía en Nicaragua?</h2>\n<!-- /wp:heading -->\n\n";
$body .= "<!-- wp:paragraph -->\n<p>El costo baja con el volumen. Según la <a href=\"https://www.printing.org/\" target=\"_blank\" rel=\"noopener noreferrer\" title=\"Printing United Alliance\">Printing United Alliance</a>, el punto de equilibrio ronda las 50 piezas.</p>\n<!-- /wp:paragraph -->\n\n";
$body .= "<!-- wp:heading {\"level\":2,\"anchor\":\"serigrafia-vs-dtf\"} -->\n<h2 class=\"wp-block-heading\" id=\"serigrafia-vs-dtf\">¿Serigrafía o DTF para artículos promocionales?</h2>\n<!-- /wp:heading -->\n\n";
$body .= "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/img/taller.jpg\" alt=\"Taller de serigrafía\" title=\"Conoce nuestro taller de serigrafía\"/></figure>\n<!-- /wp:image -->\n\n";
$body .= "<!-- wp:paragraph -->\n<p>" . str_repeat( 'La serigrafía rinde mejor en tirajes largos y la impresión digital en tirajes cortos con muchos colores. ', 30 ) . "</p>\n<!-- /wp:paragraph -->\n";

$good_payload = array(
	'seo' => array(
		'title'         => 'Qué es la serigrafía y cuándo conviene usarla',
		'description'   => 'Qué es la serigrafía, cómo funciona paso a paso, cuánto cuesta en Nicaragua y cuándo conviene frente al DTF para artículos promocionales.',
		'focus_keyword' => 'serigrafía',
	),
);

$codes = audit_codes( 2, $good_payload, $body );

check( 'sin error de título', ! in_array( 'title_too_long', $codes, true ) && ! in_array( 'title_missing_keyword', $codes, true ) );
check( 'sin error de descripción', ! in_array( 'description_too_short', $codes, true ) && ! in_array( 'description_too_long', $codes, true ) );
check( 'keyword en el primer párrafo', ! in_array( 'keyword_not_in_intro', $codes, true ) );
check( 'keyword en algún H2', ! in_array( 'keyword_not_in_h2', $codes, true ) );
check( 'contenido suficiente', ! in_array( 'thin_content', $codes, true ) );
check( 'reconoce enlaces internos', ! in_array( 'no_internal_links', $codes, true ) );
check( 'reconoce cita externa', ! in_array( 'no_citations', $codes, true ) );
check( 'reconoce encabezados en forma de pregunta', ! in_array( 'no_question_headings', $codes, true ) );
check( 'reconoce señales semánticas', ! in_array( 'no_semantic_cues', $codes, true ) );
check( 'reconoce listas', ! in_array( 'no_lists_or_tables', $codes, true ) );
check( 'imagen con alt y tooltip pasa limpia', ! in_array( 'images_without_alt', $codes, true ) && ! in_array( 'images_without_tooltip', $codes, true ) );
check( 'avisa pocos enlaces internos (2 de 5-10)', in_array( 'few_internal_links', $codes, true ) );

/* --- Specific rules ------------------------------------------------------ */

echo "\n== Reglas puntuales ==\n";

abb_test_register_post( 3, array( 'title' => 'X', 'permalink' => 'https://example.com/x/' ) );

$h1_content = "<!-- wp:paragraph -->\n<p>Texto.</p>\n<!-- /wp:paragraph -->\n<h1>Título duplicado</h1>";
check( 'detecta H1 en el cuerpo', in_array( 'h1_in_body', audit_codes( 3, array(), $h1_content ), true ) );

$repeated = '';
for ( $i = 0; $i < 4; $i++ ) {
	$repeated .= "<!-- wp:paragraph -->\n<p>Ver <a href=\"https://example.com/p{$i}/\">artículos promocionales</a> aquí.</p>\n<!-- /wp:paragraph -->\n";
}
check( 'detecta anchor text repetido', in_array( 'repeated_anchor_text', audit_codes( 3, array(), $repeated ), true ) );

$many_h2 = '';
for ( $i = 0; $i < 12; $i++ ) {
	$many_h2 .= "<h2>Sección {$i}</h2>\n<p>Texto de la sección {$i}.</p>\n";
}
check( 'avisa más de 10 H2', in_array( 'many_h2', audit_codes( 3, array(), $many_h2 ), true ) );

$no_alt = "<!-- wp:image -->\n<figure><img src=\"https://x.test/a.jpg\"/></figure>\n<!-- /wp:image -->";
check( 'detecta imagen sin alt', in_array( 'images_without_alt', audit_codes( 3, array(), $no_alt ), true ) );

abb_test_register_post( 4, array( 'title' => 'Y', 'permalink' => 'https://example.com/blog/categoria/subcategoria/otra/pagina-con-un-slug-verdaderamente-larguisimo-para-la-prueba/' ) );
$deep = audit_codes( 4, array(), '<p>Texto.</p>' );
check( 'detecta URL demasiado profunda', in_array( 'url_too_deep', $deep, true ) );
check( 'detecta URL demasiado larga', in_array( 'url_too_long', $deep, true ) );

$summary = ABB_Audit::summarize(
	array(
		array( 'severity' => 'error', 'code' => 'a', 'message' => '' ),
		array( 'severity' => 'error', 'code' => 'b', 'message' => '' ),
		array( 'severity' => 'info', 'code' => 'c', 'message' => '' ),
	)
);
check( 'resumen cuenta por severidad', 2 === $summary['error'] && 1 === $summary['info'] && 0 === $summary['warning'] );

/* --- Internal link injection --------------------------------------------- */

echo "\n== Inyección de enlaces internos ==\n";

$content = "<!-- wp:paragraph -->\n<p>Ofrecemos serigrafía textil y bordado para empresas.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>También hacemos serigrafía textil en volumen.</p>\n<!-- /wp:paragraph -->";

list( $linked, $injected, $missed ) = ABB_Enhance::inject_internal_links(
	$content,
	array(
		array( 'anchor' => 'serigrafía textil', 'url' => 'https://example.com/serigrafia/', 'title' => 'Ver servicio de serigrafía' ),
		array( 'anchor' => 'grabado láser', 'url' => 'https://example.com/laser/' ),
	)
);

check( 'enlaza la frase encontrada', contains( $linked, '<a href="https://example.com/serigrafia/"' ) );
check( 'añade el title del enlace', contains( $linked, 'title="Ver servicio de serigrafía"' ) );
check( 'enlaza una sola vez', 1 === substr_count( $linked, 'href="https://example.com/serigrafia/"' ) );
check( 'reporta el enlace inyectado', in_array( 'serigrafía textil', $injected, true ) );
check( 'reporta el anchor no encontrado', in_array( 'grabado láser', $missed, true ) );

list( $twice ) = ABB_Enhance::inject_internal_links(
	$linked,
	array( array( 'anchor' => 'serigrafía textil', 'url' => 'https://example.com/otra/' ) )
);
check( 'no anida enlaces en un párrafo ya enlazado', ! contains( $twice, '<a href="https://example.com/otra/"><a' ) );

/* --- Tooltips ------------------------------------------------------------- */

echo "\n== Tooltips (atributo title) ==\n";

$html    = '<figure><img src="https://x.test/taller.jpg" alt="Taller de serigrafía"/></figure><p><a href="https://x.test/catalogo/">catálogo</a></p>';
$tipped  = ABB_Enhance::apply_tooltips( $html, array( 'https://x.test/catalogo/' => 'Explora el catálogo completo' ), 'Mira' );

check( 'imagen recibe title desde el alt', contains( $tipped, 'title="Mira Taller de serigrafía"' ) );
check( 'enlace recibe title del payload', contains( $tipped, 'title="Explora el catálogo completo"' ) );
check( 'no duplica un title existente', 1 === substr_count( ABB_Enhance::apply_tooltips( $tipped, array(), 'Mira' ), 'title="Mira Taller de serigrafía"' ) );

$already = '<img src="https://x.test/a.jpg" alt="A" title="Original"/>';
check( 'respeta el title ya presente', contains( ABB_Enhance::apply_tooltips( $already, array(), 'Mira' ), 'title="Original"' ) );

/* --- Freshness and cornerstone ------------------------------------------- */

echo "\n== Frescura y cornerstone ==\n";

$stamped = ABB_Enhance::prepend_freshness_stamp( '<!-- wp:paragraph -->', 'Última actualización:', '1 septiembre, 2026' );
check( 'sello de frescura al inicio', 0 === strpos( $stamped, '<!-- wp:paragraph {"className":"abb-freshness"}' ) );
check( 'sello contiene la fecha', contains( $stamped, '1 septiembre, 2026' ) );

ABB_Enhance::set_cornerstone( 5, true );
check( 'marca cornerstone propio', '1' === get_post_meta( 5, '_abb_cornerstone', true ) );

check( 'enlace de inspección de Search Console', contains( ABB_Enhance::inspection_url( 'https://example.com/x/' ), 'search.google.com/search-console/inspect' ) );

exit( report() );
