<?php
/**
 * Content pipeline: markdown -> Gutenberg blocks, plus the key-takeaways,
 * table-of-contents, FAQ and sources sections.
 *
 * Usage: php tests/test-blocks.php
 */

require_once __DIR__ . '/bootstrap.php';

/* --- Fixture ----------------------------------------------------------- */

$markdown = <<<'MD'
La mayor parte del tiempo se va copiando texto al editor.

## Qué necesita el pipeline

Tres piezas, con **énfasis** y un `comando` inline y un [enlace externo](https://developer.wordpress.org/rest-api/).

- Generador de contenido
- Transporte autenticado
- Receptor en WordPress

### Detalle anidado

1. Primero
2. Segundo

## Cómo se estructura

| Markdown | Bloque |
| --- | --- |
| `## Título` | core/heading |
| `- item` | core/list |

![Escritorio con planos](https://cdn.pixabay.com/photo/plans.jpg "Foto: Pixabay")

> Reenviar el mismo archivo diez veces deja un solo post.

## Control de duplicados

```bash
python publish.py post.md
```

---

<svg viewBox="0 0 10 10"><rect width="10" height="10"/></svg>
MD;

echo "\n== Conversión markdown -> bloques ==\n";
$blocks = ABB_Markdown::to_blocks( $markdown );

check( 'párrafo', contains( $blocks, '<!-- wp:paragraph -->' ) );
check( 'heading H2 con ancla', contains( $blocks, '<!-- wp:heading {"level":2,"anchor":"que-necesita-el-pipeline"} -->' ) );
check( 'heading H3', contains( $blocks, '"level":3' ) );
check( 'id en la etiqueta h2', contains( $blocks, '<h2 class="wp-block-heading" id="que-necesita-el-pipeline">' ) );
check( 'lista no ordenada', contains( $blocks, '<!-- wp:list -->' ) && contains( $blocks, '<!-- wp:list-item -->' ) );
check( 'lista ordenada', contains( $blocks, '<!-- wp:list {"ordered":true} -->' ) && contains( $blocks, '<ol class="wp-block-list">' ) );
check( 'negrita', contains( $blocks, '<strong>énfasis</strong>' ) );
check( 'código inline', contains( $blocks, '<code>comando</code>' ) );
check( 'enlace externo con rel', contains( $blocks, 'rel="noopener noreferrer"' ) );
check( 'tabla', contains( $blocks, '<!-- wp:table -->' ) && contains( $blocks, '<th>Markdown</th>' ) );
check( 'tabla sin fila de alineación', ! contains( $blocks, '<td>---</td>' ) );
check( 'imagen', contains( $blocks, '<!-- wp:image' ) && contains( $blocks, 'alt="Escritorio con planos"' ) );
check( 'caption de imagen', contains( $blocks, '<figcaption class="wp-element-caption">Foto: Pixabay</figcaption>' ) );
check( 'cita', contains( $blocks, '<!-- wp:quote -->' ) );
check( 'bloque de código', contains( $blocks, '<!-- wp:code -->' ) && contains( $blocks, 'python publish.py' ) );
check( 'separador', contains( $blocks, '<!-- wp:separator -->' ) );
check( 'SVG en bloque html', contains( $blocks, '<!-- wp:html -->' ) && contains( $blocks, '<svg viewBox' ) );
check( 'sin markdown crudo residual', ! preg_match( '/^\s*##\s/m', $blocks ) );
check( 'headings registrados', count( ABB_Markdown::last_headings() ) === 4 );

echo "\n== Caja de puntos clave ==\n";
$payload = array(
	'key_takeaways' => array( 'Punto uno', 'Punto dos: con dos puntos' ),
);
$with_box = call_private( 'ABB_Post_Builder', 'prepend_key_takeaways', array( $blocks, $payload ) );
check( 'grupo insertado', contains( $with_box, 'wp-block-group abb-key-takeaways' ) );
check( 'va al principio', 0 === strpos( $with_box, '<!-- wp:group' ) );
check( 'items presentes', contains( $with_box, '<li>Punto dos: con dos puntos</li>' ) );

echo "\n== Índice (TOC) ==\n";
$with_toc = call_private( 'ABB_Post_Builder', 'maybe_prepend_toc', array( $blocks, array( 'toc' => true ), $markdown, 'markdown' ) );
check( 'toc insertado', contains( $with_toc, 'wp-block-group abb-toc' ) );
check( 'enlaza anclas reales', contains( $with_toc, 'href="#que-necesita-el-pipeline"' ) );
check( 'solo H2 en el índice', ! contains( $with_toc, 'href="#detalle-anidado"' ) );

$short   = ABB_Markdown::to_blocks( "## Uno\n\ntexto\n\n## Dos\n\ntexto\n" );
$no_toc  = call_private( 'ABB_Post_Builder', 'maybe_prepend_toc', array( $short, array( 'toc' => true ), '', 'blocks' ) );
check( 'sin índice con menos de 3 H2', ! contains( $no_toc, 'abb-toc' ) );

echo "\n== FAQ ==\n";
$faq_payload = array(
	'faq' => array(
		array( 'question' => '¿Necesito Yoast?', 'answer' => 'No. Detecta el que tengas.' ),
	),
);
$with_faq = call_private( 'ABB_Post_Builder', 'append_faq', array( $blocks, $faq_payload ) );
check( 'encabezado FAQ', contains( $with_faq, 'id="faq"' ) );
check( 'pregunta como H3 con ancla', contains( $with_faq, 'id="necesito-yoast"' ) );
check( 'respuesta como párrafo', contains( $with_faq, '<p>No. Detecta el que tengas.</p>' ) );

$schema_only = call_private( 'ABB_Post_Builder', 'append_faq', array( $blocks, $faq_payload + array( 'faq_schema_only' => true ) ) );
check( 'faq_schema_only omite el bloque visible', ! contains( $schema_only, 'id="faq"' ) );

echo "\n== Fuentes ==\n";
$sources = array(
	'sources' => array(
		array( 'title' => 'WordPress REST API Handbook', 'url' => 'https://developer.wordpress.org/rest-api/', 'date' => '2026-01' ),
		'Fuente sin enlace',
	),
);
$with_sources = call_private( 'ABB_Post_Builder', 'append_sources', array( $blocks, $sources ) );
check( 'sección de fuentes', contains( $with_sources, 'id="sources"' ) );
check( 'fuente enlazada', contains( $with_sources, 'href="https://developer.wordpress.org/rest-api/"' ) );
check( 'fecha de la fuente', contains( $with_sources, 'abb-source-date' ) );
check( 'fuente en texto plano', contains( $with_sources, 'Fuente sin enlace' ) );

echo "\n== Casos límite ==\n";
check( 'markdown vacío no rompe', '' === ABB_Markdown::to_blocks( '' ) );
check( 'H1 se degrada a H2', contains( ABB_Markdown::to_blocks( '# Titulo' ), '"level":2' ) );
check( 'bloques ya serializados pasan intactos', contains( ABB_Markdown::to_blocks( "<!-- wp:spacer -->\n<div></div>\n<!-- /wp:spacer -->" ), '<!-- wp:spacer -->' ) );
check( 'guion medio no se vuelve cursiva', ! contains( ABB_Markdown::to_blocks( 'costo * cantidad * margen' ), '<em>' ) );

exit( report() );
