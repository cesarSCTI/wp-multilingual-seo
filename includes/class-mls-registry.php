<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registro central de lo que terceros (temas, plugins) pueden declarar como
 * traducible:
 *
 *   - Campos personalizados de posts   -> mls_register_translatable_field()
 *   - Tipos de recurso enrutables      -> mls_register_resource_type()
 *
 * El plugin nunca adivina qué postmeta traducir: solo toca las claves
 * registradas aquí, y solo en el frontend traducido.
 */
class MLS_Registry {

	/** @var array<string,array> meta_key => args */
	private static $fields = array();

	/** @var array<string,array> type => args */
	private static $resource_types = array();

	/**
	 * @param string $meta_key
	 * @param array  $args {
	 *   @type string[] $post_types  Tipos de post donde aplica (vacío = todos).
	 *   @type string   $format      'text' | 'html'. Por defecto 'text'.
	 *   @type string   $label       Etiqueta para el editor de traducciones.
	 *   @type bool     $single      Si el meta es single (por defecto true).
	 * }
	 */
	public static function register_field( $meta_key, array $args = array() ) {
		$meta_key = sanitize_key( $meta_key );
		if ( '' === $meta_key ) {
			return;
		}
		self::$fields[ $meta_key ] = wp_parse_args( $args, array(
			'post_types' => array(),
			'format'     => 'text',
			'label'      => $meta_key,
			'single'     => true,
		) );
	}

	/**
	 * @return array<string,array>
	 */
	public static function fields() {
		return self::$fields;
	}

	/**
	 * @param string $meta_key
	 * @param string $post_type
	 * @return array|null
	 */
	public static function field( $meta_key, $post_type = '' ) {
		$meta_key = sanitize_key( $meta_key );
		if ( ! isset( self::$fields[ $meta_key ] ) ) {
			return null;
		}
		$def = self::$fields[ $meta_key ];
		if ( $post_type && ! empty( $def['post_types'] ) && ! in_array( $post_type, $def['post_types'], true ) ) {
			return null;
		}
		return $def;
	}

	/**
	 * Claves de meta traducibles para un tipo de post concreto.
	 *
	 * @param string $post_type
	 * @return array<string,array>
	 */
	public static function fields_for_post_type( $post_type ) {
		$out = array();
		foreach ( self::$fields as $key => $def ) {
			if ( empty( $def['post_types'] ) || in_array( $post_type, $def['post_types'], true ) ) {
				$out[ $key ] = $def;
			}
		}
		return $out;
	}

	/**
	 * @param string $type
	 * @param array  $args
	 */
	public static function register_resource_type( $type, array $args = array() ) {
		$type = sanitize_key( $type );
		if ( '' === $type ) {
			return;
		}
		self::$resource_types[ $type ] = $args;
	}

	/**
	 * @return array<string,array>
	 */
	public static function resource_types() {
		return self::$resource_types;
	}

	/**
	 * Registra los campos que el propio plugin traduce de serie.
	 * Se llama en `init` para dar tiempo a que terceros se enganchen antes.
	 */
	public static function register_defaults() {
		// Texto alternativo de las imágenes de la biblioteca de medios.
		self::register_field( '_wp_attachment_image_alt', array(
			'post_types' => array( 'attachment' ),
			'format'     => 'text',
			'label'      => __( 'Texto alternativo', 'mls' ),
		) );

		/**
		 * Punto de enganche para que temas y plugins registren sus campos.
		 */
		do_action( 'mls_register_translatable_fields' );
		do_action( 'mls_register_resource_types' );
	}
}

/**
 * API pública: registrar un campo personalizado como traducible.
 *
 * @param string $meta_key
 * @param array  $args
 */
function mls_register_translatable_field( $meta_key, array $args = array() ) {
	MLS_Registry::register_field( $meta_key, $args );
}

/**
 * API pública: registrar un tipo de recurso enrutable.
 *
 * @param string $type
 * @param array  $args
 */
function mls_register_resource_type( $type, array $args = array() ) {
	MLS_Registry::register_resource_type( $type, $args );
}
