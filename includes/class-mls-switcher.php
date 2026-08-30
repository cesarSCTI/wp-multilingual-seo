<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selector de idioma: [mls_language_switcher]
 * También disponible como función de plantilla: mls_render_language_switcher()
 */
class MLS_Switcher {

	public function __construct() {
		add_shortcode( 'mls_language_switcher', array( $this, 'render' ) );
	}

	public function render() {
		$settings = mls_get_settings();
		$post_id  = is_singular() ? get_queried_object_id() : 0;
		$current  = MLS_Language_Context::get_current_language();

		$langs = array_merge( array( $settings['source_lang'] ), array_map( 'sanitize_key', (array) $settings['target_langs'] ) );
		$langs = array_values( array_unique( array_filter( $langs ) ) );

		if ( count( $langs ) < 2 ) {
			return '';
		}

		// Nombres legibles para los idiomas más comunes; cualquier otro código
		// se muestra simplemente en mayúsculas (ej. "PT").
		$names = array(
			'es' => 'Español',
			'en' => 'English',
			'fr' => 'Français',
			'de' => 'Deutsch',
			'it' => 'Italiano',
			'pt' => 'Português',
			'nl' => 'Nederlands',
			'ru' => 'Русский',
			'ja' => '日本語',
			'zh' => '中文',
			'ar' => 'العربية',
			'ko' => '한국어',
		);

		$field_id = 'mls-switcher-' . wp_rand( 1000, 9999 );

		ob_start();
		?>
		<div class="mls-language-switcher">
			<label for="<?php echo esc_attr( $field_id ); ?>" class="mls-language-switcher__label">
				<?php esc_html_e( 'Elegir idioma', 'mls' ); ?>
			</label>
			<select id="<?php echo esc_attr( $field_id ); ?>" class="mls-language-switcher-select" onchange="if(this.value){window.location.href=this.value;}">
				<?php foreach ( $langs as $lang ) :
					$url   = $post_id ? mls_get_translated_url( $post_id, $lang ) : trailingslashit( home_url( $lang === $settings['source_lang'] ? '/' : '/' . $lang ) );
					$label = isset( $names[ $lang ] ) ? $names[ $lang ] : strtoupper( $lang );
					?>
					<option value="<?php echo esc_url( $url ); ?>" <?php selected( $lang, $current ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
		return ob_get_clean();
	}
}

/**
 * Función de conveniencia para usar directamente en archivos de tema.
 */
function mls_render_language_switcher() {
	echo do_shortcode( '[mls_language_switcher]' );
}
