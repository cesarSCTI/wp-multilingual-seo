<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selector de idioma.
 *
 * Tres formas de usarlo, todas comparten la misma lógica de datos y de render:
 *
 *   - Shortcode:  [mls_language_switcher display="dropdown" label="code"]
 *   - Plantilla:  mls_render_language_switcher()
 *   - Widget de Elementor: "Selector de idioma (MLS)" (ver
 *     MLS_Elementor_Widget_Language_Switcher), que llama a
 *     MLS_Switcher::render_html().
 *
 * La lista de idiomas sale de MLS_Language_Registry y el idioma actual de
 * MLS_Language_Context; nunca se releen `mls_settings` a mano aquí.
 */
class MLS_Switcher {

	public function __construct() {
		add_shortcode( 'mls_language_switcher', array( $this, 'render' ) );
	}

	/**
	 * Shortcode: [mls_language_switcher].
	 *
	 * @param array|string $atts
	 * @return string
	 */
	public function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'display'      => 'dropdown', // dropdown | horizontal | vertical
				'label'        => 'code',     // code | native | code_native
				'hide_current' => '0',
				'show_arrow'   => '1',
				'class'        => '',
			),
			$atts,
			'mls_language_switcher'
		);

		return self::render_html(
			array(
				'display'      => $atts['display'],
				'label'        => $atts['label'],
				'hide_current' => ! empty( $atts['hide_current'] ) && '0' !== $atts['hide_current'],
				'show_arrow'   => ! empty( $atts['show_arrow'] ) && '0' !== $atts['show_arrow'],
				'extra_class'  => (string) $atts['class'],
			)
		);
	}

	/**
	 * Lista de idiomas disponibles para el contexto actual.
	 *
	 * @return array<int,array{code:string,name:string,short:string,url:string,is_current:bool}>
	 *               Vacío si hay menos de dos idiomas.
	 */
	public static function get_links() {
		$languages = MLS_Language_Registry::all();
		if ( count( $languages ) < 2 ) {
			return array();
		}

		$current = MLS_Language_Context::get_current_language();
		$post_id = self::current_post_id();

		$links = array();
		foreach ( $languages as $code => $lang ) {
			if ( isset( $lang['active'] ) && ! $lang['active'] ) {
				continue;
			}

			if ( $post_id ) {
				$url = mls_get_translated_url( $post_id, $code );
			} else {
				$url = MLS_Url::home( $code );
			}

			$links[] = array(
				'code'       => $code,
				'name'       => MLS_Language_Registry::label( $code ),
				'short'      => strtoupper( $code ),
				'url'        => $url,
				'is_current' => ( $code === $current ),
			);
		}

		return count( $links ) < 2 ? array() : $links;
	}

	/**
	 * Post al que se refiere la petición actual, si lo hay.
	 *
	 * @return int
	 */
	private static function current_post_id() {
		if ( class_exists( 'MLS_Language_Context' ) ) {
			$requested = MLS_Language_Context::get_requested_post_id();
			if ( $requested ) {
				return (int) $requested;
			}
		}
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/**
	 * Texto visible de un idioma según el formato elegido.
	 *
	 * @param array  $link   Un elemento de get_links().
	 * @param string $format code | native | code_native
	 * @return string
	 */
	public static function format_label( array $link, $format ) {
		$short = isset( $link['short'] ) ? $link['short'] : strtoupper( isset( $link['code'] ) ? $link['code'] : '' );
		$name  = isset( $link['name'] ) ? $link['name'] : $short;

		switch ( $format ) {
			case 'native':
				return $name;
			case 'code_native':
				return $short . ' · ' . $name;
			case 'code':
			default:
				return $short;
		}
	}

	/**
	 * HTML del selector. Compartido por el shortcode y el widget de Elementor.
	 *
	 * El marcado lleva un doble juego de clases: las propias (`mls-lang-switch*`,
	 * para el CSS base y el JS) y las del widget "Nav Menu" de Elementor
	 * (`elementor-nav-menu`, `elementor-item`, `elementor-sub-item`,
	 * `elementor-nav-menu--dropdown`...), de modo que el CSS por-elemento que
	 * Elementor generó para un menú estilado pueda cascadear sobre el selector
	 * cuando se le añade el selector de ese menú vía `extra_class`.
	 *
	 * @param array $args {
	 *     @type string $display      dropdown | horizontal | vertical
	 *     @type string $label        code | native | code_native
	 *     @type bool   $hide_current Ocultar el idioma actual del listado.
	 *     @type bool   $show_arrow   Mostrar la flecha del toggle (solo dropdown).
	 *     @type string $extra_class  Clases extra para el wrapper.
	 *     @type string $aria_label   Etiqueta accesible del <nav>.
	 * }
	 * @return string
	 */
	public static function render_html( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'display'      => 'dropdown',
				'label'        => 'code',
				'hide_current' => false,
				'show_arrow'   => true,
				'extra_class'  => '',
				'aria_label'   => __( 'Selector de idioma', 'mls' ),
			)
		);

		$display = in_array( $args['display'], array( 'dropdown', 'horizontal', 'vertical' ), true ) ? $args['display'] : 'dropdown';
		$links   = self::get_links();
		if ( empty( $links ) ) {
			return '';
		}

		$current = null;
		foreach ( $links as $link ) {
			if ( $link['is_current'] ) {
				$current = $link;
				break;
			}
		}

		$layout_map    = array(
			'dropdown'   => 'dropdown',
			'horizontal' => 'horizontal',
			'vertical'   => 'vertical',
		);
		$wrapper_class = array(
			'mls-lang-switch',
			'mls-lang-switch--' . $display,
			'elementor-nav-menu__container',
			'elementor-nav-menu--layout-' . $layout_map[ $display ],
		);
		$extra = preg_split( '/\s+/', trim( (string) $args['extra_class'] ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( ( is_array( $extra ) ? $extra : array() ) as $cls ) {
			$wrapper_class[] = sanitize_html_class( $cls );
		}

		$uid       = 'mls-lang-' . wp_rand( 1000, 99999 );
		$is_drop   = 'dropdown' === $display;
		$list_id   = $uid . '-list';

		ob_start();
		?>
		<nav class="<?php echo esc_attr( implode( ' ', array_filter( $wrapper_class ) ) ); ?>" aria-label="<?php echo esc_attr( $args['aria_label'] ); ?>">
			<?php if ( $is_drop ) : ?>
				<button type="button" class="mls-lang-switch__toggle elementor-item" aria-expanded="false" aria-controls="<?php echo esc_attr( $list_id ); ?>">
					<span class="mls-lang-switch__current"><?php echo esc_html( self::format_label( $current ? $current : $links[0], $args['label'] ) ); ?></span>
					<?php if ( $args['show_arrow'] ) : ?>
						<span class="mls-lang-switch__arrow" aria-hidden="true"></span>
					<?php endif; ?>
				</button>
			<?php endif; ?>

			<ul
				id="<?php echo esc_attr( $list_id ); ?>"
				class="<?php echo esc_attr( $is_drop ? 'mls-lang-switch__panel elementor-nav-menu--dropdown' : 'mls-lang-switch__list elementor-nav-menu' ); ?>"
				<?php echo $is_drop ? 'hidden' : ''; ?>
			>
				<?php
				foreach ( $links as $link ) :
					if ( $link['is_current'] && ( $args['hide_current'] || $is_drop ) ) {
						continue;
					}
					$item_class = 'mls-lang-switch__item elementor-menu-item';
					$link_class = ( $is_drop ? 'mls-lang-switch__link elementor-sub-item' : 'mls-lang-switch__link elementor-item' );
					if ( $link['is_current'] ) {
						$link_class .= ' is-current elementor-item-active';
					}
					?>
					<li class="<?php echo esc_attr( $item_class ); ?>">
						<a
							class="<?php echo esc_attr( $link_class ); ?>"
							href="<?php echo esc_url( $link['url'] ); ?>"
							hreflang="<?php echo esc_attr( MLS_Language_Registry::hreflang( $link['code'] ) ); ?>"
							lang="<?php echo esc_attr( MLS_Language_Registry::hreflang( $link['code'] ) ); ?>"
							<?php echo $link['is_current'] ? ' aria-current="true"' : ''; ?>
						>
							<?php echo esc_html( self::format_label( $link, $args['label'] ) ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
		return trim( ob_get_clean() );
	}
}

/**
 * Función de conveniencia para usar directamente en archivos de tema.
 *
 * @param array $args Igual que MLS_Switcher::render_html().
 */
function mls_render_language_switcher( $args = array() ) {
	echo MLS_Switcher::render_html( is_array( $args ) ? $args : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_html ya escapa.
}
