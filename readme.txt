=== Multilingual SEO Translator (Google API) ===
Contributors: tu-sitio
Tags: traducción, multilenguaje, seo, hreflang, google translate
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 3.2.1
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
8. Incluye un selector de idioma como shortcode [mls_language_switcher] y como widget de Elementor ("Selector de idioma (MLS)").

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
10. Coloca el selector de idioma para que los visitantes puedan cambiar de idioma:
    - Con Elementor: arrastra el widget "Selector de idioma (MLS)" (categoría
      "Multilingual SEO") donde quieras — cabecera, menú, pie…
    - O con el shortcode [mls_language_switcher] en un widget de texto o en el
      contenido. Atributos: display="dropdown|horizontal|vertical" (por defecto
      dropdown), label="code|native|code_native" (por defecto code → ES/EN/PT),
      hide_current="1", class="mi-clase".

== Administración ==

* **Traducción Multilenguaje > Traducciones**: tabla con el estado (Pendiente / Auto / Manual) de cada post y cada idioma. "Traducir ahora" traduce un solo elemento al instante; "Sincronizar ahora" procesa todos los pendientes uno por uno mostrando una barra de progreso; "Forzar retraducción de todo" reemplaza también las traducciones manuales (pide confirmación).
* **Editar traducción**: desde la tabla de Traducciones o desde el meta box "Estado de traducción" en el editor de cada post, puedes corregir a mano el título, contenido, extracto y SEO de cualquier traducción. Al guardar queda marcada como "manual" y ya no se sobrescribe con traducciones automáticas futuras, salvo que uses "Forzar retraducción".
* Todas estas acciones se ejecutan de inmediato vía AJAX desde el navegador del administrador — no dependen de que WP-Cron se dispare con una visita al sitio.

== Notas importantes ==

* La traducción se ejecuta en segundo plano vía WP-Cron. En sitios con poco tráfico, considera configurar un cron real del servidor apuntando a wp-cron.php para que se ejecute puntualmente.
* Este plugin traduce el contenido de posts/páginas (título, cuerpo, excerpt) y las etiquetas de los menús de navegación ("Traducción Multilenguaje → Menús"). No traduce otros textos fijos del tema (botones, widgets); para eso necesitarías internacionalizar el tema con .po/.mo o usar un plugin de traducción de interfaz.
* Los shortcodes se protegen automáticamente antes de enviarse a traducir para no romper su sintaxis, pero revisa el resultado en contenido con HTML complejo.
* La API de Google Cloud Translation es de pago por volumen de caracteres; revisa la cuota y el costo en tu consola de Google Cloud.

== Changelog ==

= 3.2.1 =
* **Corregido el selector de idioma en páginas traducidas.** Estando en `/en/`,
  la opción del idioma fuente ("ES") apuntaba de vuelta a la propia página en
  inglés (`/en/...`) en lugar de a la URL original. La causa: `get_permalink()`
  pasa por los filtros que mantienen los enlaces dentro del idioma de la URL.
  Ahora el selector suspende esos filtros mientras calcula sus enlaces. La
  misma corrección se aplica a las etiquetas `hreflang` del idioma fuente y
  `x-default` en páginas traducidas.

= 3.2.0 =
* **Selector de idioma como widget de Elementor.** Nuevo widget "Selector de
  idioma (MLS)" (categoría "Multilingual SEO") que se arrastra como cualquier
  otro elemento — pensado para colocarlo junto al menú de la cabecera. Por
  defecto muestra la nomenclatura corta del idioma (ES, EN, PT…) y funciona
  como desplegable; también admite lista horizontal o vertical. La etiqueta
  puede ser el código, el nombre nativo ("Español") o ambos.
* **Herencia de estilos del menú.** El widget renderiza con las mismas clases
  que el widget de menú de Elementor (`elementor-item`,
  `elementor-nav-menu--dropdown`…) y tiene un campo "Selector CSS del menú"
  donde se pega el selector del menú ya estilado (p. ej.
  `.elementor-element-XXXX` a partir de un CSS ID) para que el selector de
  idioma se vea igual. Además incluye ajustes propios (tipografía, color de
  texto / hover / idioma actual, relleno, separación, alineación y estilo del
  panel del desplegable).
* **El shortcode `[mls_language_switcher]` admite atributos.** `display`
  (`dropdown` por defecto, `horizontal`, `vertical`), `label` (`code` por
  defecto, `native`, `code_native`), `hide_current` y `class`. El marcado pasó
  de un `<select>` nativo a un desplegable accesible propio (con `aria-expanded`,
  cierre con Esc y clic fuera, `aria-current` en el idioma activo y `hreflang`
  en cada enlace).

= 3.1.0 =
* **Traducción de las etiquetas de los menús.** Nueva pantalla "Traducción
  Multilenguaje → Menús": lista cada opción de cada menú con un campo por
  idioma para el texto visible, el atributo `title` (tooltip) y la descripción.
  Cubre enlaces personalizados, etiquetas editadas a mano y opciones que
  apuntan a contenido sin traducir — lo que antes no se traducía. Botón
  "Rellenar vacíos con Google" y traducción automática al guardar un menú si
  "Traducir automáticamente" está activo. Almacenamiento propio
  (`mls_menu_translations`); el menú original no se toca. Se aplica solo en las
  URLs `/idioma/`.
* **El editor manual de traducciones ya no aplana la estructura.** Antes, editar
  a mano una traducción clásica o de Gutenberg pasaba el contenido por un
  modelo de bloques simplificado y al reconstruir se perdían los contenedores,
  las clases y las alineaciones (`alignwide`, `has-text-align-center`,
  columnas, grupos, estilos de botón…); en Gutenberg además quedaba markup de
  bloques inválido. Ahora se edita unidad-por-unidad y se reinyecta cada texto
  sobre la estructura original con el adaptador correspondiente
  (`serialize_blocks()` para Gutenberg, DOM para clásico), conservando el 100 %
  del marcado. Elementor ya funcionaba así.
  Nota: el selector de "cambiar imagen" por idioma del editor manual solo
  queda disponible para contenido clásico/Gutenberg a través del texto
  alternativo; el reemplazo de la imagen en sí se hace desde el editor normal.
* El `<label>` accesible del selector de idioma usa una clase propia
  (`.mls-language-switcher__label`) en vez de `.screen-reader-text`, para no
  competir con la definición del tema.

= 3.0.6 =
* La caché de HTML documental de Elementor (`_elementor_element_cache`) se
  desactiva en TODO el frontend mientras haya idiomas destino configurados,
  no solo en las URLs `/idioma/`. Esa caché es por post_id y sin idioma, así
  que también dejaba la página FUENTE con contenido incompleto o mezclado
  (típicamente los widgets "Editor de texto" no se renderizaban). Filtro
  `mls_disable_elementor_document_cache` para revertir.
* La lectura de `_elementor_element_cache` se intercepta en cualquier URL del
  frontend (no solo traducida) y devuelve vacío para forzar render fresco.

= 3.0.5 =
* **Caché de HTML documental de Elementor envenenada.** Elementor guarda el
  HTML ya renderizado en el postmeta `_elementor_element_cache`, por post_id y
  sin distinguir idioma. Una versión anterior hizo que se guardara el render
  en inglés (e incompleto) bajo el mismo post_id que la página fuente. Se
  añade: (1) purga única versionada al desplegar; (2) en `/idioma/` se
  devuelve "sin caché" al leer esa meta; (3) se cancela la ESCRITURA de esa
  meta en `/idioma/`; (4) al traducir se borra para ese post.
* `translate_elementor` valida "sin texto traducible" antes de llamar al
  proveedor. Se saltan los kits de Elementor (ajustes globales).
* `get_request_relative_path()` compartido para instalaciones en subdirectorio;
  `the_content` con respaldo de traducción para clásico/Gutenberg.

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
