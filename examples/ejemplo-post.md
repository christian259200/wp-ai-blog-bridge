---
title: "Cómo automatizar la publicación de blogs en WordPress"
slug: automatizar-publicacion-blogs-wordpress
external_id: automatizar-publicacion-2026-08
description: "Guía práctica para conectar un pipeline de IA local con WordPress usando la REST API y publicar con estructura, imágenes y schema."
focus_keyword: "automatizar blogs wordpress"
categories: [Automatización, WordPress]
tags: [wordpress, rest api, ia, contenido]
status: draft
toc: true
featured_image:
  url: https://cdn.pixabay.com/photo/2016/11/29/06/15/plans-1867745_1280.jpg
  alt: Escritorio con planos y portátil
  caption: "Foto: Pixabay"
key_takeaways:
  - La REST API de WordPress acepta contenido estructurado sin tocar el editor.
  - Las Application Passwords evitan guardar la contraseña real en la máquina local.
  - "Un external_id hace la publicación idempotente: reenviar actualiza en vez de duplicar."
cornerstone: true
show_updated_date: true
updated_label: "Última actualización:"
image_tooltip_prefix: "Mira"
internal_links:
  - anchor: "REST API"
    url: https://tusitio.com/servicios/desarrollo-wordpress/
    title: "Ver nuestro servicio de desarrollo WordPress"
  - anchor: "Application Password"
    url: https://tusitio.com/blog/seguridad-wordpress/
tooltips:
  https://developer.wordpress.org/rest-api/: "Documentación oficial de la REST API"
sources:
  - title: WordPress REST API Handbook
    url: https://developer.wordpress.org/rest-api/
    date: 2026-01
---

# Cómo automatizar la publicación de blogs en WordPress

La mayor parte del tiempo de publicación no se va escribiendo, sino copiando texto al editor, subiendo imágenes y rellenando campos SEO. Ese trabajo es mecánico y se puede mover a un script.

## Qué necesita el pipeline

Tres piezas: un generador de contenido local, un transporte autenticado y un receptor en WordPress que sepa convertir markdown a bloques.

- **Generador**: cualquier flujo que produzca markdown con frontmatter.
- **Transporte**: HTTP Basic con Application Password.
- **Receptor**: este plugin, escuchando en `/wp-json/ai-blog/v1/posts`.

## Cómo se estructura el artículo

El plugin convierte cada elemento markdown en su bloque nativo de Gutenberg, de modo que el artículo sigue siendo editable a mano:

| Markdown | Bloque resultante |
| --- | --- |
| `## Título` | `core/heading` con ancla |
| `- item` | `core/list` |
| `![alt](url)` | `core/image` con la imagen ya en la biblioteca |
| Tabla pipe | `core/table` |

## Control de duplicados

Cada envío lleva un `external_id`. Si ya existe un post con ese identificador, el plugin lo actualiza en lugar de crear uno nuevo.

> Reenviar el mismo archivo diez veces deja un solo post, con la última versión.

## FAQ

### ¿Necesito Yoast o Rank Math?

No. Si alguno está activo, el plugin escribe en sus campos; si no hay ninguno, imprime sus propias etiquetas meta y Open Graph.

### ¿Puedo publicar directamente sin revisar?

Sí, enviando `status: publish`, pero el ajuste por defecto es `draft` a propósito: conviene revisar las primeras tandas antes de abrir el grifo.

### ¿Qué pasa con las imágenes remotas?

Se descargan a la biblioteca de medios y se reescriben las URLs del contenido, así que el post no depende de un CDN externo.
