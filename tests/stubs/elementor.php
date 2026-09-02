<?php
/**
 * Stubs mínimos de Elementor para el análisis estático (PHPStan). No se
 * cargan en producción ni en las pruebas; solo describen la superficie de
 * API que el plugin toca cuando Elementor está presente.
 */

namespace Elementor;

class Plugin {
	/** @var self|null */
	public static $instance;

	/** @var object */
	public $files_manager;

	/** @var Widgets_Manager */
	public $widgets_manager;
}

class Controls_Manager {
	const TAB_CONTENT = 'content';
	const TAB_STYLE   = 'style';
	const TEXT        = 'text';
	const SELECT      = 'select';
	const SWITCHER    = 'switcher';
	const SLIDER      = 'slider';
	const DIMENSIONS  = 'dimensions';
	const CHOOSE      = 'choose';
	const COLOR       = 'color';
	const HEADING     = 'heading';
}

class Widgets_Manager {
	/** @param object $widget */
	public function register( $widget ) {}

	/** @param object $widget */
	public function register_widget_type( $widget ) {}
}

class Elements_Manager {
	/**
	 * @param string $id
	 * @param array<string,mixed> $args
	 */
	public function add_category( $id, $args ) {}
}

abstract class Group_Control_Base {
	/** @return string */
	public static function get_type() {
		return '';
	}
}

class Group_Control_Typography extends Group_Control_Base {}
class Group_Control_Border extends Group_Control_Base {}

abstract class Widget_Base {

	/**
	 * @param string $id
	 * @param array<string,mixed> $args
	 */
	protected function start_controls_section( $id, $args = array() ) {}

	protected function end_controls_section() {}

	/**
	 * @param string $id
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $options
	 */
	protected function add_control( $id, $args = array(), $options = array() ) {}

	/**
	 * @param string $id
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $options
	 */
	protected function add_responsive_control( $id, $args = array(), $options = array() ) {}

	/**
	 * @param string $type
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $options
	 */
	protected function add_group_control( $type, $args = array(), $options = array() ) {}

	/** @return array<string,mixed> */
	public function get_settings_for_display( $setting_key = null ) {
		return array();
	}
}
