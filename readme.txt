=== Multilingual SEO Translator (Google API) ===
Contributors: tu-sitio
Tags: traducción, multilenguaje, seo, hreflang, google translate
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 3.0.2
License: GPLv2 or later

Traduce automáticamente tu contenido con la API de Google Cloud Translation, publícalo en dominio.com/{idioma}/ y sigue buenas prácticas de SEO multilenguaje.

== Descripción ==

Este plugin:

1. Se conecta a la Google Cloud Translation API (necesitas tu propia API key).
2. Al publicar o actualizar un post/página, traduce título, contenido y excerpt a los idiomas que definas.
3. Guarda las traducciones en una tabla propia de la base de datos (no crea posts duplicados).
4. Publica cada idioma en una URL con prefijo: dominio.com/en/tu-post, dominio.com/fr/tu-post, etc.
5. Añade etiquetas hreflang y canonical correctas por idioma (evita contenido duplicado ante Google).
6. Genera un sitemap XML por idioma (dominio.com/mls-sitemap-en.xml) y lo referencia en robots.txt.
7. Opcionalmente redirige a cada visitante a la versión de idioma de su navegador (302, respetando bots/crawlers y con posibilidad de cambio manual).
8. Incluye un shortcode [mls_language_switcher] para el selector de idioma.

== Instalación ==

1. Sube la carpeta `wp-multilingual-seo-translator` completa a `/wp-content/plugins/`.
2. Activa el plugin desde el escritorio de WordPress ("Plugins").
3. Ve a "Traducción Multilenguaje" > Ajustes en el menú lateral.
4. Pega tu API key de Google (con la "Cloud Translation API" habilitada en Google Cloud Console y facturación activa).
5. Define el idioma de origen (ej. es) y los idiomas destino (ej. en, fr).
6. Marca "Traducir automáticamente" si quieres que se traduzca al publicar/actualizar.
7. Marca "Redirect automático" si quieres redirigir visitantes según su navegador.
8. Guarda cambios (esto regenera las URLs automáticamente; si aun así ves 404 en /en/, ve a Ajustes > Enlaces permanentes y guarda una vez).
9. Ve a "Traducción Multilenguaje" > Traducciones para ver el estado de cada post/página y usa "Sincronizar ahora" para traducir todo lo pendiente al instante, sin depender de WP-Cron.
10. Agrega el shortcode [mls_language_switcher] (aparece como un menú desplegable) a tu menú o widget para que los visitantes puedan cambiar de idioma.

== Administración ==

* **Traducción Multilenguaje > Traducciones**: tabla con el estado (Pendiente / Auto / Manual) de cada post y cada idioma. "Traducir ahora" traduce un solo elemento al instante; "Sincronizar ahora" procesa todos los pendientes uno por uno mostrando una barra de progreso; "Forzar retraducción de todo" reemplaza también las traducciones manuales (pide confirmación).
* **Editar traducción**: desde la tabla de Traducciones o desde el meta box "Estado de traducción" en el editor de cada post, puedes corregir a mano el título, contenido, extracto y SEO de cualquier traducción. Al guardar queda marcada como "manual" y ya no se sobrescribe con traducciones automáticas futuras, salvo que uses "Forzar retraducción".
* Todas estas acciones se ejecutan de inmediato vía AJAX desde el navegador del administrador — no dependen de que WP-Cron se dispare con una visita al sitio.

== Notas importantes ==

* La traducción se ejecuta en segundo plano vía WP-Cron. En sitios con poco tráfico, considera configurar un cron real del servidor apuntando a wp-cron.php para que se ejecute puntualmente.
* Este plugin traduce el contenido de posts/páginas (título, cuerpo, excerpt). No traduce automáticamente textos fijos del tema (menús, botones, widgets); para eso necesitarías además internacionalizar el tema con .po/.mo o usar un plugin de traducción de interfaz.
* Los shortcodes se protegen automáticamente antes de enviarse a traducir para no romper su sintaxis, pero revisa el resultado en contenido con HTML complejo.
* La API de Google Cloud Translation es de pago por volumen de caracteres; revisa la cuota y el costo en tu consola de Google Cloud.

== Changelog ==

= 3.0.2 =
* **Render de Elementor reescrito.** En vez de depender del filtro `elementor/frontend/builder_content_data` (poco fiable según la versión de Elementor), ahora se intercepta `get_post_metadata` para `_elementor_data` y se devuelve el JSON ya traducido — la misma técnica de WPML/Polylang. Funciona con página, entrada, **cabecera, pie, plantillas del Theme Builder y popups**, y respeta la caché de Elementor. El `_elementor_data` original nunca se modifica.
* **Cabecera y pie traducibles.** Los documentos de Elementor (`elementor_library`, plantillas del Theme Builder...) ahora aparecen en "Traducciones" y se traducen aunque su tipo no sea público.
* Al traducir una plantilla del Theme Builder se purga la caché de página de LiteSpeed y el render de Elementor (operación poco frecuente; filtrable con `mls_purge_page_cache_on_template_translation`).
* La barra de depuración muestra ahora cuántos documentos/unidades de Elementor se aplicaron realmente en la petición.
* El filtro `mls_elementor_document_types` permite añadir tipos de documento de Elementor de terceros.

= 3.0.1 =
* Guarda contra doble carga: si hay dos copias del plugin instaladas (carpetas distintas), la segunda se detiene en silencio en vez de provocar un fatal por redeclaración, y se muestra un aviso en el escritorio.
* Sitemap nativo: se comprueba `wp_sitemaps_add_provider()` (la función exacta que se usa) antes de llamarla, y se envuelve en try/catch — en sitios con Yoast/Rank Math o con los sitemaps del core deshabilitados ya no hay fatal; se usa el XML propio por idioma.
* El XML propio por idioma se añade al índice de sitemaps de Yoast (`wpseo_sitemap_index`) cuando Yoast está activo.
* Elementor: la extracción de texto era demasiado restrictiva (solo una lista blanca de claves), por lo que en el frontend traducido solo cambiaban los títulos. Ahora también se traduce cualquier valor que sea claramente una frase (contiene espacios o puntuación) en una clave no técnica, cubriendo widgets de terceros y claves poco comunes. Requiere re-traducir las páginas ya traducidas con versiones anteriores.

= 3.0.0 =

Reescritura profunda alrededor de un principio: **con el plugin activo y sin
prefijo de idioma en la URL, el sitio se comporta igual que sin instalarlo.**
Toda la funcionalidad multilenguaje vive bajo URLs `/{idioma}/` y en tablas
propias del plugin.

**Contrato de no intervención**
* Se elimina toda purga global de cachés de terceros (opción `elementor_element_cache_unique_id`, caché global de Elementor, `litespeed_purge_all`). El aislamiento del Element Cache por idioma se mantiene con el filtro público de Elementor.
* Nunca se quita `rel_canonical` del core; el canonical por idioma se aplica con el filtro `get_canonical_url` y los de Yoast/Rank Math/AIOSEO.
* `before_delete_post` limpia las filas propias al borrar contenido.
* Nueva opción "Borrar datos al desinstalar" (desactivada por defecto): desinstalar ya no destruye las traducciones.
* La API key de Google viaja en cabecera (`X-goog-api-key`), no en el query string.

**Núcleo, router y URLs**
* Registro normalizado de idiomas (`MLS_Language_Registry`): código URL ↔ locale ↔ hreflang; variantes regionales vía filtro `mls_languages`.
* `switch_to_locale()` en el frontend traducido: los textos `.mo`/`.json` del tema y los plugins salen en el idioma de la URL (nunca en admin/REST/AJAX/cron).
* Router tipado por recurso (home, paginación, búsqueda, feeds, contenido) en vez del catch-all `/{idioma}/(.+)`.
* Rutas jerárquicas: `translated_path` completo; `/en/seccion/subpagina/` funciona y se recalcula al cambiar el slug del padre.
* Enlaces internos localizados: permalinks, `term_link`, menús (URL y etiqueta), paginación y enlaces incrustados en el contenido.
* Una URL `/{idioma}/` sin traducción publicada devuelve 404 (nunca sirve el original mezclado).

**Cola, estados y proveedor**
* Estados: `pending → translating → published`, más `manual`, `failed`, `outdated`. Una traducción automática incompleta NUNCA se publica; reintentos con backoff (60s/5m/15m, máx 3). Action Scheduler si está disponible; sin `spawn_cron()` por guardado.
* Locks por post+idioma contra traducciones simultáneas.
* Proveedor intercambiable (`mls_translation_provider`); lotes divididos por segmentos Y por caracteres; HTML y texto plano separados.

**Adaptadores**
* Clásico: parseo DOM — solo nodos de texto y atributos seguros (`alt`, `title`, `aria-label`, `placeholder`); respeta `translate="no"` y `.notranslate`.
* Gutenberg: se corrige la pérdida de traducciones en bloques anidados (quote+cite, columns…) y se valida el resultado con `parse_blocks()`.
* Elementor: listas de claves de texto filtrables (`mls_elementor_text_keys`, `mls_elementor_text_suffixes`, `mls_elementor_blacklist_keys`); la limpieza de caché de render es opt-in.

**Contenido de un sitio real**
* Custom fields traducibles (`mls_register_translatable_field()`); `get_post_metadata` se intercepta solo para las claves registradas. `alt` de imágenes de serie.
* Términos: nombre y descripción, edición manual ("Traducción Multilenguaje → Términos"), archivos enrutados (`/en/category/…`, jerarquías incluidas).
* Menús: etiquetas traducidas de ítems que apuntan a un post/término traducido.
* WooCommerce: módulo que se activa solo si WC está presente.
* API de extensión: `mls_register_translatable_field()`, `mls_register_resource_type()`, acciones `mls_register_translatable_fields` / `mls_register_resource_types` / `mls_translation_saved`.

**SEO**
* Proveedor nativo `WP_Sitemaps` con subtipo por idioma; el XML propio queda de respaldo.
* title / meta description / Open Graph / Twitter traducidos (core, Yoast, Rank Math, AIOSEO); `og:locale` y `og:locale:alternate`.
* hreflang y sitemap listan solo traducciones publicadas y contenido de origen público.

**Calidad**
* `composer.json`, PHPCS (WPCS), PHPStan (nivel 5), PHPUnit (pruebas unitarias con Brain Monkey), GitHub Actions.

= 2.3.0 =
* Elementor ya no se traduce interceptando get_post_metadata; se usa el filtro nativo builder_content_data.
* El render EN parte siempre de la estructura Elementor ORIGINAL ACTUAL y aplica translation_units 1:1; evita páginas incompletas cuando el original cambió después de traducir.
* Traducciones antiguas con builder_data se convierten en unidades en runtime y también se aplican sobre la estructura actual.
* Routing acepta tanto el slug traducido como el path/slug original bajo /{lang}/ cuando existe traducción real.
* Al actualizar se regeneran rewrite rules y se purgan una vez las cachés antiguas de Elementor/LiteSpeed.
* Se mantiene aislamiento de Element Cache por idioma.

= 2.2.0 =
* Aislamiento estricto del idioma por URL: una petición sin prefijo nunca puede consumir traducciones.
* El contexto de idioma se revalida contra REQUEST_URI antes de servir contenido traducido.
* Elementor Element Cache ahora incorpora el idioma mediante elementor/element_cache/unique_id para impedir mezcla ES/EN al reutilizar el mismo post ID.
* Purga de caché de migración una sola vez al actualizar y purga puntual de URLs LiteSpeed al guardar traducciones.
* La página configurada como front page usa / para source y /{lang}/ para traducciones.
* Settings rediseñado con cards, selectors de idioma, toggles y estado de routing.
* Traducciones rediseñado con una fila por contenido+idioma, buscador, filtros y paginación.
* Mejora responsive de las pantallas administrativas y manejo de errores AJAX más robusto.
