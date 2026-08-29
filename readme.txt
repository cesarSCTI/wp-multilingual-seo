=== Multilingual SEO Translator (Google API) ===
Contributors: tu-sitio
Tags: traducción, multilenguaje, seo, hreflang, google translate
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 2.3.0
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
