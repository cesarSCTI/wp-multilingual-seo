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
}
