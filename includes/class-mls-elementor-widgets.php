<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registro del/los widget(s) de Elementor del plugin.
 *
 * El constructor solo engancha hooks de Elementor: si Elementor no está
 * activo, ninguno se dispara y la clase es inerte.
 */
class MLS_Elementor_Widgets {

	/** @var bool Evita registrar el widget dos veces si ambos hooks se disparan. */
	private $registered = false;

	public function __construct() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );

		// Elementor >= 3.5 usa `register`; las versiones previas, `widgets_registered` + `register_widget_type`.
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_widgets' ) );

		// El editor (iframe de preview) necesita el CSS/JS del selector.
		add_action( 'elementor/preview/enqueue_styles', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'elementor/preview/enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * @param \Elementor\Elements_Manager $elements_manager
	 */
	public function register_category( $elements_manager ) {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}
		$elements_manager->add_category(
			'mls',
			array(
				'title' => __( 'Multilingual SEO', 'mls' ),
				'icon'  => 'eicon-globe',
			)
		);
	}

	/**
	 * @param \Elementor\Widgets_Manager|null $widgets_manager
	 */
	public function register_widgets( $widgets_manager = null ) {
		if ( $this->registered || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		$this->registered = true;

		require_once MLS_PLUGIN_DIR . 'includes/class-mls-elementor-widget.php';

		if ( ! class_exists( 'MLS_Elementor_Widget_Language_Switcher' ) ) {
			return;
		}

		$widget = new MLS_Elementor_Widget_Language_Switcher();

		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( $widget );
			return;
		}

		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register_widget_type' ) ) {
			$widgets_manager->register_widget_type( $widget );
			return;
		}

		// Elementor < 2.0: manager global.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
			\Elementor\Plugin::$instance->widgets_manager->register_widget_type( $widget );
		}
	}

	public function enqueue_editor_assets() {
		if ( function_exists( 'mls_register_frontend_assets' ) ) {
			mls_register_frontend_assets();
		}
		wp_enqueue_style( 'mls-switcher' );
		wp_enqueue_script( 'mls-switcher' );
	}
}
