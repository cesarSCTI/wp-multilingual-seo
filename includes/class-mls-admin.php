<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Página de ajustes del plugin en el escritorio de WordPress.
 */
class MLS_Admin {

	private $settings_hook;
	private $translations_hook;
	private $edit_hook;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_menu', array( $this, 'add_edit_translation_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_mls_save_translation', array( $this, 'handle_save_translation' ) );
		add_action( 'admin_post_mls_retranslate_now', array( $this, 'handle_retranslate_now' ) );
		add_action( 'admin_post_mls_force_flush', array( $this, 'handle_force_flush' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_translation_status_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Endpoints AJAX: disparan la traducción al instante desde el navegador
		// del administrador, sin depender de que WP-Cron se active con visitas.
		add_action( 'wp_ajax_mls_get_pending_jobs', array( $this, 'ajax_get_pending_jobs' ) );
		add_action( 'wp_ajax_mls_translate_one', array( $this, 'ajax_translate_one' ) );
	}

	/**
	 * Comprueba, contra las reglas de URL realmente guardadas por WordPress
	 * (no las que el plugin cree que registró), si cada idioma destino
	 * tiene su ruta activa. Esto permite mostrar un diagnóstico claro en
	 * vez de que el usuario tenga que adivinar por qué /en/ no funciona.
	 */
	private function get_rewrite_diagnostics() {
		$settings    = mls_get_settings();
		$rules       = get_option( 'rewrite_rules' );
		$diagnostics = array();

		foreach ( (array) $settings['target_langs'] as $lang ) {
			$lang = sanitize_key( $lang );
			if ( ! $lang ) {
				continue;
			}
			$found = false;
			if ( is_array( $rules ) ) {
				foreach ( $rules as $target ) {
					if ( false !== strpos( $target, 'mls_lang=' . $lang ) ) {
						$found = true;
						break;
					}
				}
			}
			$diagnostics[ $lang ] = $found;
		}

		return $diagnostics;
	}

	/**
	 * Diagnóstico de cachés externas que pueden interactuar con el
	 * multilenguaje: LiteSpeed, un caché de objetos persistente, y el
	 * "Element Cache" de Elementor (verificado en su código fuente:
	 * guarda su TTL en la opción `elementor_element_cache_ttl` — vacío o
	 * "disabled" significa apagado, un número significa horas de TTL
	 * activo). Esto es informativo, no bloquea nada.
	 */
	private function render_cache_diagnostics() {
		$litespeed_active = class_exists( 'LiteSpeed\Core' ) || defined( 'LSCWP_V' );
		$object_cache      = wp_using_ext_object_cache();

		$elementor_active = class_exists( '\Elementor\Plugin' );
		$element_cache_ttl = get_option( 'elementor_element_cache_ttl', '' );
		$element_cache_on  = $elementor_active && '' !== $element_cache_ttl && 'disabled' !== $element_cache_ttl && is_numeric( $element_cache_ttl );
		?>
		<hr style="margin:16px 0;border-color:var(--mls-border,#dcdcde);" />
		<h3 class="mls-diagnostics-title" style="font-size:13px;"><?php esc_html_e( 'Cachés detectadas', 'mls' ); ?></h3>
		<ul class="mls-diagnostics-list">
			<li><span class="mls-diag-dot <?php echo $litespeed_active ? 'mls-diag-ok' : 'mls-diag-fail'; ?>"></span> LiteSpeed Cache: <?php echo $litespeed_active ? esc_html__( 'detectado', 'mls' ) : esc_html__( 'no detectado', 'mls' ); ?></li>
			<li><span class="mls-diag-dot <?php echo $object_cache ? 'mls-diag-ok' : 'mls-diag-fail'; ?>"></span> <?php esc_html_e( 'Object cache persistente (Redis/Memcached)', 'mls' ); ?>: <?php echo $object_cache ? esc_html__( 'sí', 'mls' ) : esc_html__( 'no', 'mls' ); ?></li>
			<?php if ( $elementor_active ) : ?>
				<li>
					<span class="mls-diag-dot <?php echo $element_cache_on ? 'mls-diag-fail' : 'mls-diag-ok'; ?>"></span>
					Elementor Element Cache: <?php echo $element_cache_on ? esc_html__( 'ACTIVO', 'mls' ) . ' (TTL: ' . esc_html( $element_cache_ttl ) . 'h)' : esc_html__( 'desactivado', 'mls' ); ?>
				</li>
			<?php endif; ?>
		</ul>
		<?php if ( $element_cache_on ) : ?>
			<p class="mls-field-hint" style="color:var(--mls-warning-text,#8a4b00);">
				⚠ <?php esc_html_e( 'El Element Cache de Elementor está activo. Según la documentación de Elementor, este caché aplica a la resolución de dynamic tags, no al texto estático que este plugin traduce — así que no debería mezclar idiomas. Si sospechas que sí lo hace, puedes desactivarlo en Elementor > Ajustes > Rendimiento para descartarlo mientras pruebas.', 'mls' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Regenera las URLs de inmediato. A diferencia del flush automático al
	 * guardar ajustes (que puede usar configuración desactualizada por el
	 * orden en que WordPress procesa la petición), este botón se ejecuta
	 * en su propia petición separada, así que siempre usa la configuración
	 * ya guardada — es la forma más confiable de corregir /en/ si no navega.
	 */
	public function handle_force_flush() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'mls' ) );
		}
		check_admin_referer( 'mls_force_flush_action', 'mls_force_flush_nonce' );

		flush_rewrite_rules();

		wp_safe_redirect( add_query_arg( array( 'page' => 'mls-settings', 'mls_flushed' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Carga el JS/CSS del admin solo en nuestras pantallas y en el editor
	 * de posts/páginas (donde vive el meta box de estado de traducción).
	 */
	public function enqueue_admin_assets( $hook ) {
		$our_pages      = array_filter( array( $this->settings_hook, $this->translations_hook, $this->edit_hook ) );
		$is_post_editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		if ( ! in_array( $hook, $our_pages, true ) && ! $is_post_editor ) {
			return;
		}

		if ( $this->edit_hook && $hook === $this->edit_hook ) {
			wp_enqueue_media();
		}

		wp_enqueue_style( 'mls-admin', MLS_PLUGIN_URL . 'assets/admin.css', array(), MLS_VERSION );
		wp_enqueue_script( 'mls-admin', MLS_PLUGIN_URL . 'assets/admin.js', array(), MLS_VERSION, true );
		wp_localize_script( 'mls-admin', 'mlsAdmin', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'mls_bulk_sync' ),
			'i18nTranslating'  => __( 'Traduciendo…', 'mls' ),
			'i18nDone'         => __( 'Listo ✓', 'mls' ),
			'i18nError'        => __( 'Error, reintentar', 'mls' ),
			'i18nPending'      => __( 'Pendiente', 'mls' ),
			'i18nManual'       => __( 'Editado a mano', 'mls' ),
			'i18nAuto'         => __( 'Traducido (auto)', 'mls' ),
			'i18nLoadingList'  => __( 'Buscando contenido pendiente…', 'mls' ),
			'i18nListError'    => __( 'No se pudo obtener la lista de pendientes.', 'mls' ),
			'i18nNothingPending' => __( 'No hay nada pendiente por traducir.', 'mls' ),
			'i18nProcessed'    => __( 'procesados', 'mls' ),
			'i18nWithErrors'   => __( 'con error', 'mls' ),
			'i18nUnknownError' => __( 'error desconocido', 'mls' ),
			'i18nConfirmForce' => __( 'Esto va a volver a traducir TODO con Google, incluyendo traducciones editadas a mano, reemplazándolas. ¿Continuar?', 'mls' ),
			'i18nChooseImage'  => __( 'Elegir o subir imagen', 'mls' ),
			'i18nUseImage'     => __( 'Usar esta imagen', 'mls' ),
		) );
	}

	/**
	 * Página oculta (no aparece en el menú) para editar una traducción puntual.
	 * Se accede desde el enlace "Editar" del meta box del editor de posts.
	 */
	public function add_edit_translation_page() {
		$this->edit_hook = add_submenu_page(
			null,
			__( 'Editar traducción', 'mls' ),
			__( 'Editar traducción', 'mls' ),
			'manage_options',
			'mls-edit-translation',
			array( $this, 'render_edit_translation_page' )
		);
	}

	/**
	 * Menú propio con dos pantallas: Ajustes y Traducciones.
	 */
	public function add_settings_page() {
		add_menu_page(
			__( 'Traducción Multilenguaje', 'mls' ),
			__( 'Traducción Multilenguaje', 'mls' ),
			'manage_options',
			'mls-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-translation',
			80
		);
		$this->settings_hook = add_submenu_page(
			'mls-settings',
			__( 'Ajustes', 'mls' ),
			__( 'Ajustes', 'mls' ),
			'manage_options',
			'mls-settings',
			array( $this, 'render_settings_page' )
		);
		$this->translations_hook = add_submenu_page(
			'mls-settings',
			__( 'Traducciones', 'mls' ),
			__( 'Traducciones', 'mls' ),
			'manage_options',
			'mls-translations',
			array( $this, 'render_translations_page' )
		);
	}

	public function register_settings() {
		register_setting( 'mls_settings_group', 'mls_settings', array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$current = mls_get_settings();

		$output                    = array();
		$output['api_key']        = isset( $input['api_key'] ) ? sanitize_text_field( trim( $input['api_key'] ) ) : $current['api_key'];
		$output['source_lang']    = isset( $input['source_lang'] ) ? sanitize_key( $input['source_lang'] ) : $current['source_lang'];
		$output['auto_translate'] = ! empty( $input['auto_translate'] ) ? 1 : 0;
		$output['auto_redirect']  = ! empty( $input['auto_redirect'] ) ? 1 : 0;
		$output['ignore_redirect_admins'] = ! empty( $input['ignore_redirect_admins'] ) ? 1 : 0;
		$output['debug_mode']     = ! empty( $input['debug_mode'] ) ? 1 : 0;

		$raw_targets = isset( $input['target_langs'] ) ? $input['target_langs'] : array();
		$targets = is_array( $raw_targets ) ? $raw_targets : explode( ',', (string) $raw_targets );
		$targets = array_map( 'sanitize_key', array_map( 'trim', $targets ) );
		$targets = array_values( array_unique( array_filter( $targets, function ( $l ) use ( $output ) {
			return $l && $l !== $output['source_lang'] && preg_match( '/^[a-z]{2}$/', $l );
		} ) ) );
		$output['target_langs'] = $targets;

		$post_types = isset( $input['post_types'] ) ? (array) $input['post_types'] : array( 'post', 'page' );
		$output['post_types'] = array_map( 'sanitize_key', $post_types );

		// El flush real se aplaza al siguiente 'init' (ver MLS_Rewrite::maybe_flush_rules),
		// para que use la configuración recién guardada y no la anterior.
		update_option( 'mls_flush_rewrite_rules', 1 );

		return $output;
	}

	public function maybe_show_notices() {
		$settings = mls_get_settings();
		if ( empty( $settings['api_key'] ) && isset( $_GET['page'] ) && 'mls-settings' === $_GET['page'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Introduce tu API key de Google Cloud Translation para activar la traducción automática.', 'mls' ) . '</p></div>';
		}
		if ( isset( $_GET['mls_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Traducción guardada. Ya no se sobrescribirá automáticamente.', 'mls' ) . '</p></div>';
		}
		if ( isset( $_GET['mls_retranslated'] ) ) {
			$ok = '1' === $_GET['mls_retranslated'];
			$class = $ok ? 'notice-success' : 'notice-error';
			$msg   = $ok ? __( 'Se volvió a traducir automáticamente con Google.', 'mls' ) : __( 'Ocurrió un error al traducir. Revisa tu API key y cuota en Google Cloud.', 'mls' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( isset( $_GET['mls_flushed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'URLs regeneradas. Prueba de nuevo el enlace /en/... — si sigues viendo contenido viejo, es la caché de LiteSpeed.', 'mls' ) . '</p></div>';
		}
	}

	/**
	 * AJAX: lista los pares post+idioma que faltan por traducir
	 * (o todos, si $force viene marcado) para alimentar la barra de progreso.
	 */
	public function ajax_get_pending_jobs() {
		check_ajax_referer( 'mls_bulk_sync', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos suficientes.', 'mls' ) ), 403 );
		}

		$force    = ! empty( $_POST['force'] );
		$settings = mls_get_settings();
		$targets  = array_filter( array_map( 'sanitize_key', (array) $settings['target_langs'] ) );

		$posts = get_posts( array(
			'post_type'      => (array) $settings['post_types'],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$jobs = array();
		foreach ( $posts as $post_id ) {
			foreach ( $targets as $lang ) {
				if ( $lang === $settings['source_lang'] ) {
					continue;
				}
				$existing = MLS_DB::get_translation( $post_id, $lang );
				if ( $existing && ! $force ) {
					continue; // Ya traducido: solo se incluye si se pide forzar.
				}
				$jobs[] = array(
					'post_id' => $post_id,
					'lang'    => $lang,
					'title'   => get_the_title( $post_id ),
				);
			}
		}

		wp_send_json_success( array( 'jobs' => $jobs ) );
	}

	/**
	 * AJAX: traduce un único post+idioma de inmediato (llamando directo a
	 * la API de Google desde esta misma petición, sin pasar por WP-Cron).
	 */
	public function ajax_translate_one() {
		check_ajax_referer( 'mls_bulk_sync', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos suficientes.', 'mls' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( $_POST['lang'] ) : '';
		$force   = ! empty( $_POST['force'] );

		if ( ! $post_id || ! $lang ) {
			wp_send_json_error( array( 'message' => __( 'Datos incompletos.', 'mls' ) ) );
		}

		$result = MLS_Translator::translate_and_save( $post_id, $lang, $force );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * Pantalla para editar a mano una traducción, bloque por bloque:
	 * cada encabezado, párrafo, imagen y elemento de lista tiene su
	 * propio campo, en vez de un único editor con todo mezclado.
	 */
	public function render_edit_translation_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$lang    = isset( $_GET['lang'] ) ? sanitize_key( $_GET['lang'] ) : '';
		$post    = get_post( $post_id );

		if ( ! $post || ! $lang ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Falta el post o el idioma indicado.', 'mls' ) . '</p></div>';
			return;
		}

		$translation = MLS_DB::get_translation( $post_id, $lang );
		$title       = $translation ? $translation->post_title : '';
		$excerpt     = $translation ? $translation->post_excerpt : '';
		$meta_title  = $translation ? $translation->meta_title : '';
		$meta_desc   = $translation ? $translation->meta_description : '';
		$status      = $translation ? $translation->status : '';
		$builder     = MLS_Content_Resolver::detect_builder( $post_id );
		$is_outdated = MLS_DB::is_outdated( $translation );

		$blocks = array();
		$elementor_pairs = array();

		if ( MLS_Content_Resolver::BUILDER_ELEMENTOR === $builder ) {
			// Elementor no usa post_content, así que el "original" y el
			// "editable" se arman a partir de las unidades de texto de
			// _elementor_data, emparejadas por su unit_id estable (con el
			// path como respaldo para traducciones guardadas antes de que
			// existiera unit_id).
			$raw_json           = get_post_meta( $post_id, '_elementor_data', true );
			$original_units     = MLS_Elementor_Adapter::extract_units( $raw_json );
			$translated_by_unit = array();
			$translated_by_path = array();

			if ( $translation && ! empty( $translation->translation_units ) ) {
				$decoded = json_decode( $translation->translation_units, true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $u ) {
						$text = isset( $u['text'] ) ? $u['text'] : '';
						if ( ! empty( $u['unit_id'] ) ) {
							$translated_by_unit[ $u['unit_id'] ] = $text;
						}
						if ( ! empty( $u['path'] ) ) {
							$translated_by_path[ $u['path'] ] = $text;
						}
					}
				}
			}

			foreach ( $original_units as $ou ) {
				$translated_text = '';
				if ( isset( $translated_by_unit[ $ou['unit_id'] ] ) ) {
					$translated_text = $translated_by_unit[ $ou['unit_id'] ];
				} elseif ( isset( $translated_by_path[ $ou['path'] ] ) ) {
					$translated_text = $translated_by_path[ $ou['path'] ];
				}
				$elementor_pairs[] = array(
					'unit_id'    => $ou['unit_id'],
					'path'       => $ou['path'],
					'label'      => $this->humanize_elementor_key( isset( $ou['key'] ) ? $ou['key'] : '' ),
					'original'   => $ou['text'],
					'translated' => $translated_text,
				);
			}
		} else {
			$blocks = MLS_DB::get_blocks_for_translation( $translation );
			if ( empty( $blocks ) ) {
				$blocks = array( array( 'type' => 'paragraph', 'text' => '' ) );
			}
		}

		$settings    = mls_get_settings();
		$source_lang = strtoupper( $settings['source_lang'] );
		$target_lang = strtoupper( $lang );
		$slug        = $translation ? $translation->post_slug : '';
		?>
		<div class="wrap mls-edit-wrap mls-admin">
			<div class="mls-editor-header">
				<div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mls-translations' ) ); ?>" class="mls-editor-back">&larr; <?php esc_html_e( 'Traducciones', 'mls' ); ?></a>
					<h1 class="mls-editor-title">
						<?php echo esc_html( $post->post_title ); ?>
						<span class="mls-editor-lang-arrow"><?php echo esc_html( $source_lang ); ?> → <?php echo esc_html( $target_lang ); ?></span>
					</h1>
				</div>
				<div class="mls-editor-header-badges">
					<span class="mls-badge mls-badge--neutral"><?php echo esc_html( MLS_Content_Resolver::label( $builder ) ); ?></span>
					<?php if ( $status ) : ?>
						<span class="mls-badge mls-badge--<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( 'manual' === $status ? __( 'Manual', 'mls' ) : __( 'Auto', 'mls' ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $is_outdated ) : ?>
						<span class="mls-badge mls-badge--outdated"><?php esc_html_e( 'Desactualizada', 'mls' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="mls-editor-preview-links">
				<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener" class="mls-button mls-button--secondary">
					<?php esc_html_e( 'Ver original', 'mls' ); ?> ↗
				</a>
				<a href="<?php echo esc_url( mls_get_translated_url( $post_id, $lang ) ); ?>" target="_blank" rel="noopener" class="mls-button mls-button--secondary">
					<?php esc_html_e( 'Ver traducción', 'mls' ); ?> ↗
				</a>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mls-editor-form">
				<input type="hidden" name="action" value="mls_save_translation" />
				<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
				<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
				<input type="hidden" name="mls_builder" value="<?php echo esc_attr( $builder ); ?>" />
				<?php wp_nonce_field( 'mls_save_translation_' . $post_id . '_' . $lang, 'mls_save_translation_nonce' ); ?>

				<?php
				$this->render_translation_unit( array(
					'label'        => __( 'Título', 'mls' ),
					'context'      => MLS_Content_Resolver::label( $builder ),
					'source_lang'  => $source_lang,
					'target_lang'  => $target_lang,
					'original_html' => esc_html( $post->post_title ),
					'field_html'   => '<textarea class="mls-tu__textarea mls-autosize" name="post_title" rows="1">' . esc_textarea( $title ) . '</textarea>',
				) );
				?>

				<div class="mls-card mls-slug-card">
					<label class="mls-tu__col-label" for="mls_slug"><?php esc_html_e( 'Slug traducido (URL)', 'mls' ); ?></label>
					<div class="mls-slug-row">
						<span class="mls-slug-prefix"><?php echo esc_html( trailingslashit( home_url( '/' . $lang . '/' ) ) ); ?></span>
						<input type="text" id="mls_slug" name="post_slug" value="<?php echo esc_attr( $slug ); ?>" class="mls-input" placeholder="<?php esc_attr_e( '(se genera del título si lo dejas vacío)', 'mls' ); ?>" />
					</div>
					<p class="mls-field-hint"><?php esc_html_e( 'Si ya tiene un slug, cambiar el título no lo modifica solo — así no se te rompen enlaces ya compartidos. Bórralo si quieres que se regenere del título.', 'mls' ); ?></p>
				</div>

				<?php if ( MLS_Content_Resolver::BUILDER_ELEMENTOR === $builder ) : ?>
					<?php if ( empty( $elementor_pairs ) ) : ?>
						<p class="description"><?php esc_html_e( 'No se detectó texto traducible en esta página de Elementor todavía. Guárdala al menos una vez desde el editor de Elementor y vuelve aquí.', 'mls' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $elementor_pairs as $i => $pair ) :
						$this->render_translation_unit( array(
							'label'        => $pair['label'],
							'context'      => 'Elementor',
							'technical'    => $pair['path'],
							'source_lang'  => $source_lang,
							'target_lang'  => $target_lang,
							'original_html' => esc_html( $pair['original'] ),
							'field_html'   => '<input type="hidden" name="units[' . (int) $i . '][unit_id]" value="' . esc_attr( $pair['unit_id'] ) . '" />'
								. '<input type="hidden" name="units[' . (int) $i . '][path]" value="' . esc_attr( $pair['path'] ) . '" />'
								. '<textarea class="mls-tu__textarea mls-autosize" name="units[' . (int) $i . '][text]" rows="2">' . esc_textarea( $pair['translated'] ) . '</textarea>',
						) );
					endforeach; ?>
				<?php else : ?>
					<?php
					$original_content_blocks = MLS_Content_Blocks::parse_html_to_blocks( $post->post_content );
					foreach ( $blocks as $i => $block ) :
						$type     = isset( $block['type'] ) ? $block['type'] : 'paragraph';
						$original = isset( $original_content_blocks[ $i ] ) ? $original_content_blocks[ $i ] : null;

						$type_labels = array(
							'heading'    => __( 'Encabezado', 'mls' ),
							'paragraph'  => __( 'Párrafo', 'mls' ),
							'blockquote' => __( 'Cita', 'mls' ),
							'image'      => __( 'Imagen', 'mls' ),
							'list_item'  => __( 'Elemento de lista', 'mls' ),
							'raw'        => __( 'HTML sin clasificar', 'mls' ),
						);
						$unit_label = isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : __( 'Texto', 'mls' );
						if ( 'heading' === $type ) {
							/* translators: %d: nivel de encabezado */
							$unit_label = sprintf( __( 'Encabezado H%d', 'mls' ), (int) $block['level'] );
						}

						$original_html = '';
						$original_is_image = false;
						$original_image_src = '';
						if ( $original ) {
							if ( 'image' === $original['type'] && ! empty( $original['src'] ) ) {
								$original_is_image  = true;
								$original_image_src = $original['src'];
							} elseif ( isset( $original['text'] ) ) {
								$original_html = wp_kses_post( $original['text'] );
							} elseif ( isset( $original['html'] ) ) {
								$original_html = esc_html( wp_strip_all_tags( $original['html'] ) );
							}
						}

						if ( 'heading' === $type ) {
							$field_html = '<input type="hidden" name="blocks[' . (int) $i . '][type]" value="heading" />'
								. '<input type="hidden" name="blocks[' . (int) $i . '][level]" value="' . esc_attr( $block['level'] ) . '" />'
								. '<textarea class="mls-tu__textarea mls-autosize mls-tu__textarea--heading" name="blocks[' . (int) $i . '][text]" rows="1">' . esc_textarea( $block['text'] ) . '</textarea>';
						} elseif ( 'image' === $type ) {
							$preview = ! empty( $block['src'] )
								? '<img src="' . esc_url( $block['src'] ) . '" alt="" class="mls-image-preview" />'
								: '<div class="mls-image-preview mls-image-preview-empty"></div>';
							$field_html = '<input type="hidden" name="blocks[' . (int) $i . '][type]" value="image" />'
								. '<div class="mls-image-row">' . $preview
								. '<input type="hidden" name="blocks[' . (int) $i . '][src]" value="' . esc_url( isset( $block['src'] ) ? $block['src'] : '' ) . '" class="mls-image-src-input" />'
								. '<div class="mls-image-fields"><button type="button" class="mls-button mls-button--secondary mls-choose-image">' . esc_html__( 'Cambiar imagen', 'mls' ) . '</button>'
								. '<input type="text" name="blocks[' . (int) $i . '][alt]" value="' . esc_attr( isset( $block['alt'] ) ? $block['alt'] : '' ) . '" class="mls-input" placeholder="' . esc_attr__( 'Texto alternativo', 'mls' ) . '" /></div></div>';
						} elseif ( 'list_item' === $type ) {
							$field_html = '<input type="hidden" name="blocks[' . (int) $i . '][type]" value="list_item" />'
								. '<input type="hidden" name="blocks[' . (int) $i . '][list_id]" value="' . esc_attr( isset( $block['list_id'] ) ? $block['list_id'] : '' ) . '" />'
								. '<input type="hidden" name="blocks[' . (int) $i . '][ordered]" value="' . ( ! empty( $block['ordered'] ) ? '1' : '0' ) . '" />'
								. '<textarea class="mls-tu__textarea mls-autosize" name="blocks[' . (int) $i . '][text]" rows="2">' . esc_textarea( $block['text'] ) . '</textarea>';
						} elseif ( 'raw' === $type ) {
							$field_html = '<input type="hidden" name="blocks[' . (int) $i . '][type]" value="raw" />'
								. '<textarea class="mls-tu__textarea mls-tu__textarea--code" name="blocks[' . (int) $i . '][html]" rows="4">' . esc_textarea( $block['html'] ) . '</textarea>';
						} else {
							$field_html = '<input type="hidden" name="blocks[' . (int) $i . '][type]" value="' . esc_attr( $type ) . '" />'
								. '<textarea class="mls-tu__textarea mls-autosize" name="blocks[' . (int) $i . '][text]" rows="2">' . esc_textarea( $block['text'] ) . '</textarea>';
						}

						$this->render_translation_unit( array(
							'label'               => $unit_label,
							'context'             => MLS_Content_Resolver::label( $builder ),
							'source_lang'         => $source_lang,
							'target_lang'         => $target_lang,
							'original_html'       => $original_html,
							'original_is_image'   => $original_is_image,
							'original_image_src'  => $original_image_src,
							'field_html'          => $field_html,
						) );
					endforeach;
					?>
				<?php endif; ?>

				<?php
				$this->render_translation_unit( array(
					'label'        => __( 'Extracto', 'mls' ),
					'context'      => __( 'SEO / resumen', 'mls' ),
					'source_lang'  => $source_lang,
					'target_lang'  => $target_lang,
					'original_html' => esc_html( $post->post_excerpt ),
					'field_html'   => '<textarea class="mls-tu__textarea mls-autosize" name="post_excerpt" rows="2">' . esc_textarea( $excerpt ) . '</textarea>',
				) );
				?>

				<div class="mls-card">
					<div class="mls-field">
						<label class="mls-label" for="mls_meta_title"><?php esc_html_e( 'Meta título (SEO)', 'mls' ); ?></label>
						<input type="text" id="mls_meta_title" name="meta_title" value="<?php echo esc_attr( $meta_title ); ?>" class="mls-input" />
					</div>
					<div class="mls-field">
						<label class="mls-label" for="mls_meta_desc"><?php esc_html_e( 'Meta descripción (SEO)', 'mls' ); ?></label>
						<textarea id="mls_meta_desc" name="meta_description" rows="2" class="mls-textarea" maxlength="160"><?php echo esc_textarea( $meta_desc ); ?></textarea>
						<p class="mls-field-hint"><?php esc_html_e( 'Máximo recomendado: 155-160 caracteres.', 'mls' ); ?></p>
					</div>
				</div>

				<div class="mls-editor-actions">
					<?php submit_button( __( 'Guardar traducción', 'mls' ), 'primary mls-button mls-button--primary', 'submit', false ); ?>
				</div>
			</form>

			<?php if ( ! empty( $translation ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mls-retranslate-form" onsubmit="return confirm('<?php echo esc_js( __( 'Esto reemplazará el texto actual con una nueva traducción automática de Google (se pierde lo editado a mano). ¿Continuar?', 'mls' ) ); ?>');">
					<input type="hidden" name="action" value="mls_retranslate_now" />
					<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
					<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
					<?php wp_nonce_field( 'mls_retranslate_now_' . $post_id . '_' . $lang, 'mls_retranslate_now_nonce' ); ?>
					<?php submit_button( __( 'Volver a traducir automáticamente (reemplaza lo actual)', 'mls' ), 'secondary mls-button mls-button--secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<p><a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>">&larr; <?php esc_html_e( 'Volver al editor del post', 'mls' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Renderiza UNA tarjeta de "unidad de traducción": original y campo
	 * editable en la MISMA fila, para que la correspondencia sea
	 * inequívoca sin importar cuánto contenido haya arriba o abajo.
	 * Se usa tanto para el título, como para cada unidad de Elementor,
	 * cada bloque clásico/Gutenberg, y el extracto.
	 *
	 * @param array $args {
	 *     @type string $label               Nombre del campo (ej. "Encabezado H1").
	 *     @type string $context             Contexto (ej. "Elementor", "Gutenberg").
	 *     @type string $technical           Ruta técnica opcional, se muestra en "Detalles técnicos".
	 *     @type string $source_lang         Código de idioma origen en mayúsculas.
	 *     @type string $target_lang         Código de idioma destino en mayúsculas.
	 *     @type string $original_html       HTML ya seguro (escapado/kses) del texto original.
	 *     @type bool   $original_is_image   Si el original es una imagen en vez de texto.
	 *     @type string $original_image_src  URL de la imagen original, si aplica.
	 *     @type string $field_html          HTML ya armado del campo editable (input/textarea/etc).
	 * }
	 */
	private function render_translation_unit( array $args ) {
		$args = wp_parse_args( $args, array(
			'label'              => '',
			'context'            => '',
			'technical'          => '',
			'source_lang'        => '',
			'target_lang'        => '',
			'original_html'      => '',
			'original_is_image'  => false,
			'original_image_src' => '',
			'field_html'         => '',
		) );
		?>
		<div class="mls-tu">
			<div class="mls-tu__header">
				<span class="mls-tu__label"><?php echo esc_html( $args['label'] ); ?></span>
				<?php if ( $args['context'] ) : ?><span class="mls-tu__context">· <?php echo esc_html( $args['context'] ); ?></span><?php endif; ?>
			</div>
			<div class="mls-tu__grid">
				<div class="mls-tu__source">
					<label class="mls-tu__col-label"><?php echo esc_html( $args['source_lang'] ); ?> · <?php esc_html_e( 'Original', 'mls' ); ?></label>
					<?php if ( $args['original_is_image'] && $args['original_image_src'] ) : ?>
						<img src="<?php echo esc_url( $args['original_image_src'] ); ?>" alt="" class="mls-image-preview" />
					<?php else : ?>
						<div class="mls-tu__source-value"><?php echo $args['original_html'] ? $args['original_html'] : '<span class="mls-tu__empty">—</span>'; ?></div>
					<?php endif; ?>
				</div>
				<div class="mls-tu__target">
					<label class="mls-tu__col-label"><?php echo esc_html( $args['target_lang'] ); ?> · <?php esc_html_e( 'Traducción', 'mls' ); ?></label>
					<?php echo $args['field_html']; ?>
				</div>
			</div>
			<?php if ( $args['technical'] ) : ?>
				<button type="button" class="mls-tu__details-toggle"><?php esc_html_e( 'Detalles técnicos', 'mls' ); ?></button>
				<div class="mls-tu__details" hidden><?php echo esc_html( $args['context'] . ' · ' . $args['technical'] ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Convierte una clave técnica de Elementor (ej. "button_text",
	 * "tab_title") en una etiqueta legible para el editor.
	 */
	private function humanize_elementor_key( $key ) {
		if ( '' === $key ) {
			return __( 'Texto', 'mls' );
		}
		$labels = array(
			'title'        => __( 'Título', 'mls' ),
			'sub_title'    => __( 'Subtítulo', 'mls' ),
			'subtitle'     => __( 'Subtítulo', 'mls' ),
			'description'  => __( 'Descripción', 'mls' ),
			'text'         => __( 'Texto', 'mls' ),
			'editor'       => __( 'Contenido', 'mls' ),
			'content'      => __( 'Contenido', 'mls' ),
			'button_text'  => __( 'Texto del botón', 'mls' ),
			'tab_title'    => __( 'Título de pestaña', 'mls' ),
			'tab_content'  => __( 'Contenido de pestaña', 'mls' ),
			'question'     => __( 'Pregunta', 'mls' ),
			'answer'       => __( 'Respuesta', 'mls' ),
			'caption'      => __( 'Leyenda', 'mls' ),
		);
		if ( isset( $labels[ $key ] ) ) {
			return $labels[ $key ];
		}
		return ucfirst( str_replace( '_', ' ', $key ) );
	}

	/**
	 * Guarda los cambios manuales de una traducción, reconstruyendo el
	 * HTML completo a partir de los bloques enviados. Queda marcada
	 * como "manual" para que la traducción automática no la vuelva a pisar.
	 */
	public function handle_save_translation() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'mls' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( $_POST['lang'] ) : '';
		$builder = isset( $_POST['mls_builder'] ) ? sanitize_key( $_POST['mls_builder'] ) : 'classic';

		check_admin_referer( 'mls_save_translation_' . $post_id . '_' . $lang, 'mls_save_translation_nonce' );

		if ( ! $post_id || ! $lang ) {
			wp_die( esc_html__( 'Datos incompletos.', 'mls' ) );
		}

		$title = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';

		// Slug independiente del título (sección 23): si el admin escribió
		// un slug a mano, se respeta; si lo dejó vacío, se genera del
		// título. Cambiar el título después NO regenera un slug existente.
		$submitted_slug = isset( $_POST['post_slug'] ) ? sanitize_title( wp_unslash( $_POST['post_slug'] ) ) : '';
		$slug_base      = '' !== $submitted_slug ? $submitted_slug : $title;
		$final_slug     = MLS_DB::generate_unique_slug( $slug_base, $lang, $post_id );

		$row = array(
			'post_id'          => $post_id,
			'language'         => $lang,
			'post_title'       => $title,
			'post_slug'        => $final_slug,
			'post_excerpt'     => isset( $_POST['post_excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['post_excerpt'] ) ) : '',
			'meta_title'       => isset( $_POST['meta_title'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_title'] ) ) : '',
			'meta_description' => isset( $_POST['meta_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meta_description'] ) ) : '',
			'status'           => 'manual',
			'builder'          => $builder,
			'source_hash'      => MLS_DB::compute_source_hash( $post_id ),
		);

		if ( MLS_Content_Resolver::BUILDER_ELEMENTOR === $builder ) {
			$raw_json    = get_post_meta( $post_id, '_elementor_data', true );
			$raw_units   = isset( $_POST['units'] ) && is_array( $_POST['units'] ) ? wp_unslash( $_POST['units'] ) : array();
			$clean_units = array();

			foreach ( $raw_units as $u ) {
				if ( ! isset( $u['unit_id'] ) && ! isset( $u['path'] ) ) {
					continue;
				}
				$clean_units[] = array(
					'unit_id' => isset( $u['unit_id'] ) ? sanitize_text_field( $u['unit_id'] ) : '',
					'path'    => isset( $u['path'] ) ? sanitize_text_field( $u['path'] ) : '',
					'text'    => wp_kses_post( isset( $u['text'] ) ? $u['text'] : '' ),
				);
			}

			$row['post_content']      = '';
			$row['builder_data']      = MLS_Elementor_Adapter::apply_translations( $raw_json, $clean_units );
			$row['translation_units'] = wp_json_encode( $clean_units );

			MLS_DB::save_translation( $row );
			MLS_Elementor_Adapter::clear_elementor_render_cache();
		} else {
			$clean_blocks          = $this->sanitize_submitted_blocks( isset( $_POST['blocks'] ) && is_array( $_POST['blocks'] ) ? wp_unslash( $_POST['blocks'] ) : array() );
			$row['post_content']   = MLS_Content_Blocks::blocks_to_html( $clean_blocks );
			$row['content_blocks'] = wp_json_encode( $clean_blocks );

			MLS_DB::save_translation( $row );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'mls-edit-translation', 'post_id' => $post_id, 'lang' => $lang, 'mls_saved' => 1 ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Valida y limpia los bloques enviados desde el formulario según su
	 * tipo, antes de reconstruir el HTML y guardarlos.
	 */
	private function sanitize_submitted_blocks( array $raw_blocks ) {
		$allowed_types = array( 'heading', 'paragraph', 'blockquote', 'image', 'list_item', 'raw' );
		$clean         = array();

		foreach ( $raw_blocks as $b ) {
			$type  = ( isset( $b['type'] ) && in_array( $b['type'], $allowed_types, true ) ) ? $b['type'] : 'paragraph';
			$block = array( 'type' => $type );

			switch ( $type ) {
				case 'heading':
					$block['level'] = isset( $b['level'] ) ? max( 1, min( 6, (int) $b['level'] ) ) : 2;
					$block['text']  = sanitize_text_field( isset( $b['text'] ) ? $b['text'] : '' );
					break;
				case 'image':
					$block['src'] = isset( $b['src'] ) ? esc_url_raw( $b['src'] ) : '';
					$block['alt'] = sanitize_text_field( isset( $b['alt'] ) ? $b['alt'] : '' );
					break;
				case 'list_item':
					$block['list_id'] = isset( $b['list_id'] ) ? sanitize_key( $b['list_id'] ) : 'list_0';
					$block['ordered'] = ! empty( $b['ordered'] );
					$block['text']    = wp_kses_post( isset( $b['text'] ) ? $b['text'] : '' );
					break;
				case 'raw':
					$block['html'] = wp_kses_post( isset( $b['html'] ) ? $b['html'] : '' );
					break;
				case 'blockquote':
				case 'paragraph':
				default:
					$block['text'] = wp_kses_post( isset( $b['text'] ) ? $b['text'] : '' );
					break;
			}

			$clean[] = $block;
		}

		return $clean;
	}

	/**
	 * Fuerza una nueva traducción automática, reemplazando la actual
	 * (incluida una edición manual previa).
	 */
	public function handle_retranslate_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'mls' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( $_POST['lang'] ) : '';

		check_admin_referer( 'mls_retranslate_now_' . $post_id . '_' . $lang, 'mls_retranslate_now_nonce' );

		$result = MLS_Translator::translate_and_save( $post_id, $lang, true );

		wp_safe_redirect( add_query_arg(
			array(
				'page'              => 'mls-edit-translation',
				'post_id'           => $post_id,
				'lang'              => $lang,
				'mls_retranslated'  => is_wp_error( $result ) ? 0 : 1,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	private function language_options() {
		return array(
			'es' => 'Español', 'en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch',
			'it' => 'Italiano', 'pt' => 'Português', 'nl' => 'Nederlands', 'pl' => 'Polski',
			'ca' => 'Català', 'ja' => '日本語', 'ko' => '한국어', 'zh' => '中文',
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings    = mls_get_settings();
		$languages   = $this->language_options();
		$diagnostics = $this->get_rewrite_diagnostics();
		?>
		<div class="wrap mls-admin mls-settings-page">
			<div class="mls-page-header">
				<div>
					<h1><?php esc_html_e( 'Multilingual Translator', 'mls' ); ?></h1>
					<p><?php esc_html_e( 'Configura idiomas, automatización, URLs y comportamiento del sitio multilenguaje.', 'mls' ); ?></p>
				</div>
				<a class="mls-button mls-button--secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mls-translations' ) ); ?>"><?php esc_html_e( 'Ver traducciones', 'mls' ); ?></a>
			</div>

			<div class="mls-stat-grid">
				<div class="mls-stat-card"><span><?php esc_html_e( 'Idioma fuente', 'mls' ); ?></span><strong><?php echo esc_html( strtoupper( $settings['source_lang'] ) ); ?></strong><small><?php esc_html_e( 'Sin prefijo en URL', 'mls' ); ?></small></div>
				<div class="mls-stat-card"><span><?php esc_html_e( 'Idiomas destino', 'mls' ); ?></span><strong><?php echo esc_html( count( (array) $settings['target_langs'] ) ); ?></strong><small><?php echo esc_html( implode( ', ', array_map( 'strtoupper', (array) $settings['target_langs'] ) ) ); ?></small></div>
				<div class="mls-stat-card"><span><?php esc_html_e( 'URL Routing', 'mls' ); ?></span><strong><?php echo ! in_array( false, $diagnostics, true ) ? 'Healthy' : 'Check'; ?></strong><small><?php esc_html_e( 'Prefijos registrados', 'mls' ); ?></small></div>
				<div class="mls-stat-card"><span><?php esc_html_e( 'Google Translation', 'mls' ); ?></span><strong><?php echo ! empty( $settings['api_key'] ) ? 'Connected' : 'Not configured'; ?></strong><small><?php esc_html_e( 'Proveedor automático', 'mls' ); ?></small></div>
			</div>

			<form method="post" action="options.php" class="mls-settings-form">
				<?php settings_fields( 'mls_settings_group' ); ?>

				<div class="mls-settings-grid">
					<section class="mls-card mls-settings-card">
						<div class="mls-card__header"><div><h2><?php esc_html_e( 'Idiomas', 'mls' ); ?></h2><p><?php esc_html_e( 'Define el idioma original y los idiomas que se publicarán con prefijo.', 'mls' ); ?></p></div></div>
						<div class="mls-card__body">
							<div class="mls-field"><label class="mls-label" for="mls_source_lang"><?php esc_html_e( 'Idioma de origen', 'mls' ); ?></label><select class="mls-select" id="mls_source_lang" name="mls_settings[source_lang]">
							<?php foreach ( $languages as $code => $label ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['source_lang'], $code ); ?>><?php echo esc_html( $label . ' — ' . $code ); ?></option><?php endforeach; ?>
							</select><p class="mls-field-hint"><?php esc_html_e( 'Se sirve sin prefijo: dominio.com/pagina/', 'mls' ); ?></p></div>
							<div class="mls-field"><span class="mls-label"><?php esc_html_e( 'Idiomas destino', 'mls' ); ?></span><div class="mls-language-grid">
							<?php foreach ( $languages as $code => $label ) : if ( $code === $settings['source_lang'] ) continue; ?><label class="mls-check-card"><input type="checkbox" name="mls_settings[target_langs][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, (array) $settings['target_langs'], true ) ); ?>><span><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( '/' . $code . '/' ); ?></small></span></label><?php endforeach; ?>
							</div></div>
						</div>
					</section>

					<section class="mls-card mls-settings-card">
						<div class="mls-card__header"><div><h2><?php esc_html_e( 'Google Cloud Translation', 'mls' ); ?></h2><p><?php esc_html_e( 'Credencial utilizada para traducción automática.', 'mls' ); ?></p></div><span class="mls-badge <?php echo ! empty( $settings['api_key'] ) ? 'mls-badge--manual' : 'mls-badge--pending'; ?>"><?php echo ! empty( $settings['api_key'] ) ? esc_html__( 'Configured', 'mls' ) : esc_html__( 'Pending', 'mls' ); ?></span></div>
						<div class="mls-card__body"><div class="mls-field"><label class="mls-label" for="mls_api_key"><?php esc_html_e( 'API Key', 'mls' ); ?></label><input class="mls-input" type="password" id="mls_api_key" name="mls_settings[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off"><p class="mls-field-hint"><?php esc_html_e( 'Cloud Translation API v2 debe estar habilitada.', 'mls' ); ?></p></div></div>
					</section>

					<section class="mls-card mls-settings-card mls-settings-card--wide">
						<div class="mls-card__header"><div><h2><?php esc_html_e( 'Contenido', 'mls' ); ?></h2><p><?php esc_html_e( 'Selecciona qué tipos de contenido forman parte de la capa multilenguaje.', 'mls' ); ?></p></div></div>
						<div class="mls-card__body"><div class="mls-content-types">
						<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : ?><label class="mls-check-card"><input type="checkbox" name="mls_settings[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $settings['post_types'], true ) ); ?>><span><strong><?php echo esc_html( $pt->labels->name ); ?></strong><small><?php echo esc_html( $pt->name ); ?></small></span></label><?php endforeach; ?>
						</div></div>
					</section>

					<section class="mls-card mls-settings-card">
						<div class="mls-card__header"><div><h2><?php esc_html_e( 'Automatización', 'mls' ); ?></h2><p><?php esc_html_e( 'Controla traducción y detección del navegador.', 'mls' ); ?></p></div></div>
						<div class="mls-card__body mls-toggle-list">
							<label class="mls-toggle-row"><span><strong><?php esc_html_e( 'Traducción automática', 'mls' ); ?></strong><small><?php esc_html_e( 'Traducir al publicar o actualizar.', 'mls' ); ?></small></span><input type="checkbox" name="mls_settings[auto_translate]" value="1" <?php checked( $settings['auto_translate'], 1 ); ?>></label>
							<label class="mls-toggle-row"><span><strong><?php esc_html_e( 'Redirect por navegador', 'mls' ); ?></strong><small><?php esc_html_e( 'Solo redirige; nunca cambia el idioma dentro de la misma URL.', 'mls' ); ?></small></span><input type="checkbox" name="mls_settings[auto_redirect]" value="1" <?php checked( $settings['auto_redirect'], 1 ); ?>></label>
							<label class="mls-toggle-row"><span><strong><?php esc_html_e( 'Ignorar administradores', 'mls' ); ?></strong><small><?php esc_html_e( 'Recomendado para probar URLs manualmente.', 'mls' ); ?></small></span><input type="checkbox" name="mls_settings[ignore_redirect_admins]" value="1" <?php checked( $settings['ignore_redirect_admins'], 1 ); ?>></label>
							<label class="mls-toggle-row"><span><strong><?php esc_html_e( 'Debug', 'mls' ); ?></strong><small><?php esc_html_e( 'Muestra contexto de idioma solo a administradores.', 'mls' ); ?></small></span><input type="checkbox" name="mls_settings[debug_mode]" value="1" <?php checked( $settings['debug_mode'], 1 ); ?>></label>
						</div>
					</section>

					<section class="mls-card mls-settings-card">
						<div class="mls-card__header"><div><h2><?php esc_html_e( 'URL Routing', 'mls' ); ?></h2><p><?php esc_html_e( 'Una URL corresponde siempre a un único idioma.', 'mls' ); ?></p></div></div>
						<div class="mls-card__body"><div class="mls-route-list"><div><span class="mls-diag-dot mls-diag-ok"></span><code>/</code><span><?php echo esc_html( strtoupper( $settings['source_lang'] ) ); ?> · Source</span></div><?php foreach ( $diagnostics as $lang => $ok ) : ?><div><span class="mls-diag-dot <?php echo $ok ? 'mls-diag-ok' : 'mls-diag-fail'; ?>"></span><code>/<?php echo esc_html( $lang ); ?>/</code><span><?php echo $ok ? esc_html__( 'Active', 'mls' ) : esc_html__( 'Needs regeneration', 'mls' ); ?></span></div><?php endforeach; ?></div>
							<a class="mls-button mls-button--secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mls_force_flush' ), 'mls_force_flush_action', 'mls_force_flush_nonce' ) ); ?>"><?php esc_html_e( 'Regenerar rutas', 'mls' ); ?></a>
							<p class="mls-field-hint"><?php esc_html_e( 'Úsalo si un prefijo devuelve 404. La caché se invalida automáticamente al actualizar esta versión y al guardar traducciones.', 'mls' ); ?></p>
						</div>
					</section>
				</div>

				<div class="mls-save-bar"><span><?php esc_html_e( 'Guarda los cambios para regenerar las rutas con la configuración actual.', 'mls' ); ?></span><button type="submit" class="mls-button mls-button--primary"><?php esc_html_e( 'Guardar cambios', 'mls' ); ?></button></div>
			</form>
		</div>
		<?php
	}

	/**
	 * Pantalla central: tabla con el estado de traducción de cada
	 * post/página en cada idioma, con acciones para traducir uno a uno
	 * o sincronizar todo de golpe — todo vía AJAX, sin depender de WP-Cron.
	 */
	public function render_translations_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = mls_get_settings();
		$targets  = array_filter( array_map( 'sanitize_key', (array) $settings['target_langs'] ) );
		if ( empty( $targets ) ) {
			echo '<div class="wrap mls-admin"><div class="mls-empty-state"><h1>' . esc_html__( 'No hay idiomas destino', 'mls' ) . '</h1><p>' . esc_html__( 'Configura al menos un idioma destino antes de crear traducciones.', 'mls' ) . '</p><a class="mls-button mls-button--primary" href="' . esc_url( admin_url( 'admin.php?page=mls-settings' ) ) . '">' . esc_html__( 'Configurar idiomas', 'mls' ) . '</a></div></div>';
			return;
		}

		$paged     = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$per_page  = 20;
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$lang_filter = isset( $_GET['lang'] ) ? sanitize_key( $_GET['lang'] ) : '';
		$type_filter = isset( $_GET['content_type'] ) ? sanitize_key( $_GET['content_type'] ) : '';
		$query_targets = ( $lang_filter && in_array( $lang_filter, $targets, true ) ) ? array( $lang_filter ) : $targets;
		$post_types = $type_filter && in_array( $type_filter, (array) $settings['post_types'], true ) ? array( $type_filter ) : (array) $settings['post_types'];

		$q = new WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			's'              => $search,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$total_pairs = (int) $q->found_posts * count( $query_targets );
		$translated_count = 0; $outdated_count = 0; $pending_count = 0;
		foreach ( $q->posts as $p ) foreach ( $query_targets as $lang ) { $t = MLS_DB::get_translation( $p->ID, $lang ); if ( ! $t ) $pending_count++; elseif ( MLS_DB::is_outdated( $t ) ) $outdated_count++; else $translated_count++; }
		?>
		<div class="wrap mls-admin mls-translations-page">
			<div class="mls-page-header"><div><h1><?php esc_html_e( 'Traducciones', 'mls' ); ?></h1><p><?php esc_html_e( 'Gestiona el contenido por idioma, revisa estados y sincroniza traducciones.', 'mls' ); ?></p></div><button type="button" class="mls-button mls-button--primary" id="mls-sync-now"><?php esc_html_e( 'Traducir pendientes', 'mls' ); ?></button></div>
			<div class="mls-stat-grid mls-stat-grid--compact"><div class="mls-stat-card"><span><?php esc_html_e( 'Resultados', 'mls' ); ?></span><strong><?php echo esc_html( $total_pairs ); ?></strong></div><div class="mls-stat-card"><span><?php esc_html_e( 'Traducidos', 'mls' ); ?></span><strong><?php echo esc_html( $translated_count ); ?></strong></div><div class="mls-stat-card"><span><?php esc_html_e( 'Desactualizados', 'mls' ); ?></span><strong><?php echo esc_html( $outdated_count ); ?></strong></div><div class="mls-stat-card"><span><?php esc_html_e( 'Pendientes', 'mls' ); ?></span><strong><?php echo esc_html( $pending_count ); ?></strong></div></div>

			<div class="mls-card mls-toolbar-card"><form method="get" class="mls-toolbar"><input type="hidden" name="page" value="mls-translations"><input class="mls-input mls-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Buscar contenido…', 'mls' ); ?>"><select class="mls-select" name="lang"><option value=""><?php esc_html_e( 'Todos los idiomas', 'mls' ); ?></option><?php foreach ( $targets as $lang ) : ?><option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $lang_filter, $lang ); ?>><?php echo esc_html( strtoupper( $lang ) ); ?></option><?php endforeach; ?></select><select class="mls-select" name="content_type"><option value=""><?php esc_html_e( 'Todos los tipos', 'mls' ); ?></option><?php foreach ( (array) $settings['post_types'] as $pt ) : $obj=get_post_type_object($pt); ?><option value="<?php echo esc_attr($pt); ?>" <?php selected($type_filter,$pt); ?>><?php echo esc_html($obj ? $obj->labels->name : $pt); ?></option><?php endforeach; ?></select><button class="mls-button mls-button--secondary" type="submit"><?php esc_html_e( 'Filtrar', 'mls' ); ?></button><button type="button" class="mls-button mls-button--ghost" id="mls-force-sync-now"><?php esc_html_e( 'Retraducir todo', 'mls' ); ?></button></form></div>

			<div id="mls-sync-progress" class="mls-sync-message is-hidden"></div><div id="mls-sync-bar-wrap" class="is-hidden"><div id="mls-sync-bar"></div></div><div id="mls-sync-log"></div>

			<div class="mls-card mls-table-card">
			<?php if ( empty( $q->posts ) ) : ?><div class="mls-empty-state"><h2><?php esc_html_e( 'Sin resultados', 'mls' ); ?></h2><p><?php esc_html_e( 'No encontramos contenido con estos filtros.', 'mls' ); ?></p></div><?php else : ?>
			<table class="mls-table"><thead><tr><th><?php esc_html_e( 'Contenido', 'mls' ); ?></th><th><?php esc_html_e( 'Tipo', 'mls' ); ?></th><th><?php esc_html_e( 'Constructor', 'mls' ); ?></th><th><?php esc_html_e( 'Idioma', 'mls' ); ?></th><th><?php esc_html_e( 'Estado', 'mls' ); ?></th><th><?php esc_html_e( 'Actualizado', 'mls' ); ?></th><th><?php esc_html_e( 'Acciones', 'mls' ); ?></th></tr></thead><tbody>
			<?php foreach ( $q->posts as $p ) : $builder=MLS_Content_Resolver::detect_builder($p->ID); foreach ( $query_targets as $lang ) : $translation=MLS_DB::get_translation($p->ID,$lang); $outdated=$translation&&MLS_DB::is_outdated($translation); $status=!$translation?'pending':($outdated?'outdated':('manual'===$translation->status?'manual':'auto')); $labels=array('pending'=>__('Pendiente','mls'),'outdated'=>__('Desactualizada','mls'),'manual'=>__('Manual','mls'),'auto'=>__('Traducida','mls')); $edit_url=add_query_arg(array('page'=>'mls-edit-translation','post_id'=>$p->ID,'lang'=>$lang),admin_url('admin.php')); ?>
			<tr class="mls-status-cell" data-post-id="<?php echo esc_attr($p->ID); ?>" data-lang="<?php echo esc_attr($lang); ?>"><td data-label="Contenido"><strong><?php echo esc_html($p->post_title ?: __('(sin título)','mls')); ?></strong><small class="mls-row-slug"><?php echo esc_html('/'.$p->post_name.'/'); ?></small></td><td data-label="Tipo"><?php $pto=get_post_type_object($p->post_type); echo esc_html($pto ? $pto->labels->singular_name : $p->post_type); ?></td><td data-label="Constructor"><span class="mls-badge mls-badge--neutral"><?php echo esc_html(MLS_Content_Resolver::label($builder)); ?></span></td><td data-label="Idioma"><strong><?php echo esc_html(strtoupper($lang)); ?></strong></td><td data-label="Estado"><span class="mls-status-label mls-status-pill mls-status-<?php echo esc_attr($status); ?>"><?php echo esc_html($labels[$status]); ?></span><?php if($translation): ?><small class="mls-row-meta"><?php echo 'manual'===$translation->status ? esc_html__('Editada manualmente','mls') : esc_html__('Automática','mls'); ?></small><?php endif; ?></td><td data-label="Actualizado"><?php echo $translation ? esc_html(mysql2date(get_option('date_format'),$translation->updated_at)) : '—'; ?></td><td data-label="Acciones"><div class="mls-row-actions"><?php if($translation): ?><a class="mls-button mls-button--small mls-button--secondary" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Editar','mls'); ?></a><a class="mls-button mls-button--small mls-button--ghost" target="_blank" rel="noopener" href="<?php echo esc_url(mls_get_translated_url($p->ID,$lang)); ?>"><?php esc_html_e('Ver','mls'); ?></a><?php endif; ?><a href="#" class="mls-translate-now" data-post-id="<?php echo esc_attr($p->ID); ?>" data-lang="<?php echo esc_attr($lang); ?>"><?php esc_html_e('Traducir','mls'); ?></a></div></td></tr>
			<?php endforeach; endforeach; ?></tbody></table><?php endif; ?></div>
			<?php if ( $q->max_num_pages > 1 ) : ?><div class="mls-pagination"><?php echo wp_kses_post( paginate_links( array( 'total'=>$q->max_num_pages, 'current'=>$paged, 'format'=>'?paged=%#%', 'add_args'=>array_filter(array('page'=>'mls-translations','s'=>$search,'lang'=>$lang_filter,'content_type'=>$type_filter)) ) ) ); ?></div><?php endif; ?>
		</div>
		<?php wp_reset_postdata();
	}

	/**
	 * Meta box en el editor mostrando el estado de traducción de cada idioma.
	 */
	public function add_translation_status_metabox() {
		$settings = mls_get_settings();
		foreach ( (array) $settings['post_types'] as $post_type ) {
			add_meta_box( 'mls_translation_status', __( 'Estado de traducción', 'mls' ), array( $this, 'render_status_metabox' ), $post_type, 'side' );
		}
	}

	public function render_status_metabox( $post ) {
		$settings = mls_get_settings();
		if ( empty( $settings['target_langs'] ) ) {
			echo '<p>' . esc_html__( 'No hay idiomas destino configurados.', 'mls' ) . '</p>';
			return;
		}
		echo '<ul class="mls-status-list">';
		foreach ( (array) $settings['target_langs'] as $lang ) {
			$lang        = sanitize_key( $lang );
			$translation = MLS_DB::get_translation( $post->ID, $lang );
			$edit_url    = add_query_arg(
				array( 'page' => 'mls-edit-translation', 'post_id' => $post->ID, 'lang' => $lang ),
				admin_url( 'admin.php' )
			);

			$status = 'pending';
			if ( $translation ) {
				$status = ( 'manual' === $translation->status ) ? 'manual' : 'auto';
			}
			$status_label = array(
				'pending' => __( 'Pendiente', 'mls' ),
				'manual'  => __( 'Editado a mano', 'mls' ),
				'auto'    => __( 'Traducido (auto)', 'mls' ),
			);

			printf(
				'<li class="mls-status-cell" data-post-id="%1$d" data-lang="%2$s"><strong>%3$s:</strong> <span class="mls-status-label mls-status-pill mls-status-%7$s">%4$s</span><br />%5$s<a href="#" class="mls-translate-now" data-post-id="%1$d" data-lang="%2$s">%6$s</a></li>',
				$post->ID,
				esc_attr( $lang ),
				esc_html( strtoupper( $lang ) ),
				esc_html( $status_label[ $status ] ),
				$translation ? '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Editar', 'mls' ) . '</a> · ' : '',
				esc_html__( 'Traducir ahora', 'mls' ),
				esc_attr( $status )
			);
		}
		echo '</ul>';
	}
}
