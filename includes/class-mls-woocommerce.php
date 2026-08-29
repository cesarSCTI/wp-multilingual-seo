<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Módulo WooCommerce (se instancia solo si WooCommerce está activo).
 *
 * La mayor parte de WooCommerce ya funciona con la maquinaria general del
 * plugin: los productos son un CPT (título/contenido/extracto traducidos por
 * el flujo normal), las categorías/etiquetas/atributos son taxonomías
 * (MLS_Terms) y las cadenas de la interfaz salen traducidas gracias a
 * `switch_to_locale()`.
 *
 * Aquí solo se cubren los huecos específicos de WooCommerce.
 */
class MLS_WooCommerce {

	public static function is_active() {
		return class_exists( 'WooCommerce' );
	}

	public function __construct() {
		add_action( 'mls_register_translatable_fields', array( $this, 'register_fields' ) );
	}

	public function register_fields() {
		// Nota de compra que se muestra tras el pedido.
		mls_register_translatable_field( '_purchase_note', array(
			'post_types' => array( 'product' ),
			'format'     => 'html',
			'label'      => __( 'Nota de compra (WooCommerce)', 'mls' ),
		) );
	}
}
