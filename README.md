# Multilingual SEO Translator

Traduce contenido de WordPress con la API de Google Cloud Translation y lo publica
bajo URLs con prefijo de idioma (`dominio.com/en/...`), con hreflang, canonical y
sitemap por idioma.

## Principio de diseño

**Con el plugin activo y sin prefijo de idioma en la URL, el sitio se comporta
exactamente igual que sin el plugin.** Toda la funcionalidad multilenguaje vive
bajo URLs localizadas (`/en/`, `/fr/`...) y en datos propios del plugin.

El plugin **solo escribe** en:

- Sus tablas propias: `{prefix}_mls_translations`, `{prefix}_mls_meta_translations`,
  `{prefix}_mls_term_translations`, `{prefix}_mls_menu_translations`.
- Opciones cuyo nombre empieza por `mls_` (`mls_settings`, `mls_db_version`,
  `mls_runtime_version`, `mls_flush_rewrite_rules`).

Nunca modifica `wp_posts`, `wp_postmeta`, términos, ni opciones o cachés de otros
plugins/temas.

## Qué cubre hoy

- Entradas y páginas (editor clásico con parseo DOM, Gutenberg, Elementor).
- Título, contenido, extracto, meta title y meta description.
- Home / front page por idioma, con paginación y búsqueda dentro del idioma.
- Páginas jerárquicas: rutas padre/hijo traducidas (`/en/seccion/subpagina/`).
- Enlaces internos localizados: permalinks, menús (URL y etiqueta), paginación
  y enlaces incrustados en el contenido se mantienen dentro del idioma.
- Etiquetas de los menús de navegación traducibles (texto visible, atributo
  `title` y descripción), incluidos enlaces personalizados y etiquetas editadas
  a mano, desde "Traducción Multilenguaje → Menús". Tabla propia
  `{prefix}_mls_menu_translations`.
- Cambio real de locale en el frontend traducido (`.mo`/`.json` del tema y
  plugins salen en el idioma de la URL).
- Cola de traducción con estados (`pending/translating/published/failed/
  outdated`), locks, reintentos con backoff. Nunca publica traducciones
  incompletas. Proveedor intercambiable (`mls_translation_provider`).
- Custom fields traducibles vía `mls_register_translatable_field()`
  (`alt` de imágenes de serie).
- Términos (categorías, etiquetas, taxonomías): nombre, descripción, archivo
  de término enrutado (`/en/category/…`) y `term_link` localizado.
- WooCommerce: productos y taxonomías vía la maquinaria general + módulo
  propio (se activa solo si WC está presente).
- hreflang recíproco (con variantes regionales) + canonical por idioma
  (sin desregistrar el canonical del core).
- Sitemap nativo `WP_Sitemaps` con subtipo por idioma; XML propio de respaldo.
- title / meta description / Open Graph / Twitter traducidos (core, Yoast,
  Rank Math, AIOSEO).
- Selector de idioma (`[mls_language_switcher]`).
- Redirección opcional por `Accept-Language` (302, respeta bots y elección manual).

## Qué NO cubre todavía

- Archivos de autor / fecha traducidos (los de término sí).
- Site Editor / template parts, widgets clásicos.
- Cadenas del tema no internacionalizadas (sin `.mo`).
- Variaciones de producto WooCommerce con datos propios más allá del extracto.
- Formularios de plugins complejos.

Ver `readme.txt` (changelog) y el plan por fases.

## API para integraciones

- `mls_get_localized_url( $post_id, $lang )` — URL localizada de un post.
- `mls_register_translatable_field( $meta_key, $args )` — declarar un
  postmeta como traducible (`post_types`, `format`, `label`).
- `mls_register_resource_type( $type, $args )` — declarar un recurso enrutable.
- Acciones `mls_register_translatable_fields`, `mls_register_resource_types`,
  `mls_translation_saved`.
- Filtros: `mls_languages` (locale/hreflang/label, variantes regionales,
  desactivar idiomas), `mls_translation_provider`, `mls_translation_max_attempts`,
  `mls_use_native_sitemaps`, `mls_index_outdated_translations`,
  `mls_clear_elementor_cache_on_save`.

## Configuración

Ajustes en **Traducción Multilenguaje → Ajustes**: API key de Google (Cloud
Translation API v2 habilitada), idioma de origen, idiomas destino, tipos de
contenido, y toggles de automatización. Si `/en/` devuelve 404, guardar una vez
en **Ajustes → Enlaces permanentes**.

## Desarrollo

```bash
composer install
composer lint      # php -l en todos los archivos
composer phpcs      # WordPress Coding Standards
composer phpstan    # análisis estático (nivel 5)
composer test       # PHPUnit (pruebas unitarias, sin WordPress)
composer check      # todo lo anterior
```

Las pruebas unitarias (`tests/Unit`) usan Brain Monkey y no cargan WordPress:
cubren la lógica pura (registro de idiomas, saneado de paths, elección de
formato de traducción). Las pruebas de integración con la WordPress test
suite (routing real, `parse_blocks`, Elementor) están pendientes.
CI en `.github/workflows/ci.yml`.
