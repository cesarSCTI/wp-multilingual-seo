<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Herramientas de diagnóstico en tiempo real, solo para administradores
 * y solo cuando "Debug mode" está activo en los ajustes:
 *
 *  - Barra visual al pie de página mostrando el contexto de idioma real
 *    con el que se sirvió ESA petición concreta (para comprobar de un
 *    vistazo, sin adivinar, si /pagina/ y /en/pagina/ se resolvieron
 *    como se esperaba).
 *  - `?mls_debug=1`: fuerza cabeceras no-cache para poder comparar el
 *    HTML real sin que ninguna caché de página se interponga.
 */
class MLS_Debug {

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_bypass_cache' ), 0 );
		add_action( 'wp', array( $this, 'log_resolved_context' ) );
		add_action( 'wp_footer', array( $this, 'render_debug_bar' ) );
	}

	private function is_enabled() {
		$settings = mls_get_settings();
		return ! empty( $settings['debug_mode'] ) && current_user_can( 'manage_options' );
	}

	/**
	 * `?mls_debug=1`: envía cabeceras no-cache para que se pueda
	 * comparar el HTML real de /pagina/ vs /en/pagina/ sin depender de
	 * purgar manualmente LiteSpeed/WP Rocket/Cloudflare en cada prueba.
	 * No cambia ninguna configuración de forma permanente.
	 */
	public function maybe_bypass_cache() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( empty( $_GET['mls_debug'] ) ) {
			return;
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/**
	 * Registra en el log de depuración el contexto ya completamente
	 * resuelto para esta petición (idioma, post, builder, si hubo
	 * traducción, si Elementor recibió datos traducidos). Es el mismo
	 * dato que muestra la barra visual, pero queda también en el log
	 * del servidor para revisar después.
	 */
	public function log_resolved_context() {
		if ( is_admin() ) {
			return;
		}
		$settings = mls_get_settings();
		if ( empty( $settings['debug_mode'] ) ) {
			return; // Evita trabajo innecesario en producción cuando el modo debug está apagado.
		}
		$this->collect_context();
	}

	private function collect_context() {
		$settings   = mls_get_settings();
		$is_source  = MLS_Language_Context::is_source_request();
		$lang       = MLS_Language_Context::get_current_language();
		$post_id    = is_singular() ? get_queried_object_id() : MLS_Language_Context::get_requested_post_id();
		$post_type  = $post_id ? get_post_type( $post_id ) : '';
		$builder    = $post_id ? MLS_Content_Resolver::detect_builder( $post_id ) : '';
		$lang_for_lookup = $is_source ? $settings['source_lang'] : $lang;
		$translation     = ( $post_id && ! $is_source ) ? MLS_DB::get_translation( $post_id, $lang_for_lookup ) : null;

		$data = array(
			'request_uri'   => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'source_lang'   => $settings['source_lang'],
			'current_lang'  => $lang,
			'is_source'     => $is_source,
			'is_translation' => ! $is_source,
			'post_id'       => $post_id,
			'post_type'     => $post_type,
			'builder'       => $builder,
			'translation_found' => (bool) $translation,
			'builder_data_used' => ( 'elementor' === $builder ) ? ( $is_source ? 'original' : ( $translation ? 'current source structure + translated units' : 'original (sin traducción de Elementor aún)' ) ) : 'n/a',
		);

		mls_debug_log(
			sprintf(
				"REQUEST %s\n  source_lang=%s current_lang=%s is_source=%s is_translation=%s\n  post_id=%s post_type=%s builder=%s\n  translation_found=%s builder_data_used=%s",
				$data['request_uri'],
				$data['source_lang'],
				$data['current_lang'],
				$data['is_source'] ? 'true' : 'false',
				$data['is_translation'] ? 'true' : 'false',
				$data['post_id'] ? $data['post_id'] : '(n/a)',
				$data['post_type'] ? $data['post_type'] : '(n/a)',
				$data['builder'] ? $data['builder'] : '(n/a)',
				$data['translation_found'] ? 'true' : 'false',
				$data['builder_data_used']
			)
		);

		return $data;
	}

	/**
	 * Barra discreta al pie de página, SOLO visible para administradores
	 * con Debug mode activo — muestra el contexto real con el que se
	 * sirvió esta petición concreta, para comprobar de un vistazo si
	 * /pagina/ realmente se resolvió como idioma fuente o no.
	 */
	public function render_debug_bar() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$data = $this->collect_context();
		?>
		<div style="position:fixed;bottom:0;left:0;right:0;z-index:999999;background:#1d2327;color:#f0f0f1;font:12px/1.5 Consolas,Monaco,monospace;padding:8px 16px;display:flex;flex-wrap:wrap;gap:16px;align-items:center;">
			<strong style="color:#72aee6;">MLS DEBUG</strong>
			<span>URL: <?php echo esc_html( $data['request_uri'] ); ?></span>
			<span>Context: <strong style="color:<?php echo $data['is_source'] ? '#68de7c' : '#f0b849'; ?>;"><?php echo $data['is_source'] ? 'SOURCE' : 'TRANSLATION'; ?></strong></span>
			<span>Language: <?php echo esc_html( strtoupper( $data['current_lang'] ) ); ?></span>
			<span>Post: <?php echo esc_html( $data['post_id'] ? $data['post_id'] : '—' ); ?></span>
			<span>Builder: <?php echo esc_html( $data['builder'] ? $data['builder'] : '—' ); ?></span>
			<span>Data: <?php echo esc_html( $data['builder_data_used'] ); ?></span>
			<span>Interception: <?php echo esc_html( ( 'elementor' === $data['builder'] && ! $data['is_source'] && false !== strpos( $data['builder_data_used'], 'translated units' ) ) ? 'YES' : 'NO' ); ?></span>
		</div>
		<?php
	}
}
