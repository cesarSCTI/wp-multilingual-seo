<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Widget de Elementor "Selector de idioma (MLS)".
 *
 * Renderiza el mismo HTML que el shortcode [mls_language_switcher] (ver
 * MLS_Switcher::render_html), con:
 *
 *  - Controles de contenido: tipo de visualización (dropdown / lista
 *    horizontal / vertical), formato de la etiqueta (ES / Español / ES ·
 *    Español), ocultar idioma actual, flecha del dropdown.
 *  - Herencia de estilos: un campo donde se pega el selector CSS del widget de
 *    menú de Elementor ya estilado (`.elementor-element-XXXX` o una clase
 *    propia); ese token se añade al wrapper para que el CSS por-elemento de ese
 *    menú cascadee sobre el selector (ambos comparten las clases
 *    `elementor-item`, `elementor-nav-menu--dropdown`, etc.).
 *  - Ajustes de estilo propios (tipografía, colores, padding, separación,
 *    alineación, panel del dropdown) para afinar lo que no venga del menú.
 */
class MLS_Elementor_Widget_Language_Switcher extends \Elementor\Widget_Base {

	public function get_name() {
		return 'mls-language-switcher';
	}

	public function get_title() {
		return __( 'Selector de idioma (MLS)', 'mls' );
	}

	public function get_icon() {
		return 'eicon-globe';
	}

	public function get_categories() {
		return array( 'mls' );
	}

	public function get_keywords() {
		return array( 'idioma', 'language', 'switcher', 'selector', 'multilingual', 'hreflang', 'mls' );
	}

	public function get_script_depends() {
		return array( 'mls-switcher' );
	}

	public function get_style_depends() {
		return array( 'mls-style', 'mls-switcher' );
	}

	/**
	 * Back-compat: Elementor < 3.1 llama a `_register_controls()`.
	 */
	protected function _register_controls() { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->register_controls();
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Contenido', 'mls' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'display',
			array(
				'label'   => __( 'Visualización', 'mls' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'dropdown',
				'options' => array(
					'dropdown'   => __( 'Desplegable', 'mls' ),
					'horizontal' => __( 'Lista horizontal', 'mls' ),
					'vertical'   => __( 'Lista vertical', 'mls' ),
				),
			)
		);

		$this->add_control(
			'label_format',
			array(
				'label'   => __( 'Etiqueta', 'mls' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'code',
				'options' => array(
					'code'        => __( 'Código (ES)', 'mls' ),
					'native'      => __( 'Nombre (Español)', 'mls' ),
					'code_native' => __( 'Código + nombre (ES · Español)', 'mls' ),
				),
			)
		);

		$this->add_control(
			'hide_current',
			array(
				'label'        => __( 'Ocultar idioma actual', 'mls' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'description'  => __( 'En modo desplegable el idioma actual siempre se muestra en el botón.', 'mls' ),
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'show_arrow',
			array(
				'label'        => __( 'Mostrar flecha', 'mls' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'display' => 'dropdown' ),
			)
		);

		$this->end_controls_section();

		/* --- Heredar estilos del menú --- */
		$this->start_controls_section(
			'section_inherit',
			array(
				'label' => __( 'Heredar estilos del menú', 'mls' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'inherit_selector',
			array(
				'label'       => __( 'Selector CSS del menú', 'mls' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'ai'          => array( 'active' => false ),
				'placeholder' => '.elementor-element-abc123',
				'description' => __( 'Copia aquí el selector del widget de menú que ya tienes estilado para que el selector de idioma se vea igual. Consíguelo poniendo un CSS ID en el menú (Avanzado → CSS ID) y pegando aquí ".elementor-element-ID" o "#tu-css-id". También sirve una clase propia añadida al menú.', 'mls' ),
			)
		);

		$this->end_controls_section();

		/* --- Estilo: ajustes propios --- */
		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Ajustes', 'mls' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .mls-lang-switch__link, {{WRAPPER}} .mls-lang-switch__toggle',
			)
		);

		$this->add_control(
			'color',
			array(
				'label'     => __( 'Color del texto', 'mls' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .mls-lang-switch__link, {{WRAPPER}} .mls-lang-switch__toggle' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'color_hover',
			array(
				'label'     => __( 'Color del texto (hover)', 'mls' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .mls-lang-switch__link:hover, {{WRAPPER}} .mls-lang-switch__link:focus, {{WRAPPER}} .mls-lang-switch__toggle:hover, {{WRAPPER}} .mls-lang-switch__toggle:focus' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'color_active',
			array(
				'label'     => __( 'Color del idioma actual', 'mls' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .mls-lang-switch__link.is-current' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'item_padding',
			array(
				'label'      => __( 'Relleno de cada elemento', 'mls' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .mls-lang-switch__link, {{WRAPPER}} .mls-lang-switch__toggle' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'item_gap',
			array(
				'label'      => __( 'Separación entre elementos', 'mls' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array( 'min' => 0, 'max' => 60 ),
					'em' => array( 'min' => 0, 'max' => 5, 'step' => 0.1 ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .mls-lang-switch__list, {{WRAPPER}} .mls-lang-switch__panel' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alineación', 'mls' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Izquierda', 'mls' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'     => array(
						'title' => __( 'Centro', 'mls' ),
						'icon'  => 'eicon-text-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'Derecha', 'mls' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .mls-lang-switch__list' => 'align-items: {{VALUE}}; justify-content: {{VALUE}};',
					'{{WRAPPER}} .mls-lang-switch__panel' => 'align-items: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'panel_heading',
			array(
				'label'     => __( 'Panel del desplegable', 'mls' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array( 'display' => 'dropdown' ),
			)
		);

		$this->add_control(
			'panel_bg',
			array(
				'label'     => __( 'Fondo del panel', 'mls' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .mls-lang-switch__panel' => 'background-color: {{VALUE}};',
				),
				'condition' => array( 'display' => 'dropdown' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'panel_border',
				'selector'  => '{{WRAPPER}} .mls-lang-switch__panel',
				'condition' => array( 'display' => 'dropdown' ),
			)
		);

		$this->add_responsive_control(
			'panel_radius',
			array(
				'label'      => __( 'Radio del borde del panel', 'mls' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .mls-lang-switch__panel' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array( 'display' => 'dropdown' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Normaliza el selector CSS pegado por el usuario a una lista de clases
	 * utilizables en el atributo `class` del wrapper.
	 *
	 * `.elementor-element-abc123`  -> `elementor-element-abc123`
	 * `#mi-menu`                   -> `mi-menu`  (para que valga un CSS ID)
	 * `.a .b`                      -> `a b`
	 *
	 * @param string $raw
	 * @return string
	 */
	private function selector_to_classes( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		$classes = array();
		$tokens  = preg_split( '/[\s,>+~]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( ( is_array( $tokens ) ? $tokens : array() ) as $token ) {
			$token = trim( $token );
			if ( '' === $token ) {
				continue;
			}
			// Quita el prefijo `.` o `#` y cualquier pseudo/atributo.
			$token = preg_replace( '/[:\[].*$/', '', $token );
			$token = ltrim( $token, '.#' );
			$token = sanitize_html_class( $token );
			if ( '' === $token ) {
				continue;
			}
			// Las reglas que Elementor genera para un widget se anclan en
			// `.elementor-element.elementor-element-<ID>`; añadimos también la
			// clase base para que ese selector doble aplique al selector de idioma.
			if ( 0 === strpos( $token, 'elementor-element-' ) ) {
				$classes[] = 'elementor-element';
			}
			$classes[] = $token;
		}

		return implode( ' ', array_unique( $classes ) );
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		echo MLS_Switcher::render_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_html escapa su salida.
			array(
				'display'      => isset( $settings['display'] ) ? $settings['display'] : 'dropdown',
				'label'        => isset( $settings['label_format'] ) ? $settings['label_format'] : 'code',
				'hide_current' => ! empty( $settings['hide_current'] ),
				'show_arrow'   => ! isset( $settings['show_arrow'] ) || 'yes' === $settings['show_arrow'],
				'extra_class'  => $this->selector_to_classes( isset( $settings['inherit_selector'] ) ? $settings['inherit_selector'] : '' ),
			)
		);
	}
}
