<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contrato de un proveedor de traducción automática.
 *
 * El plugin nunca llama a Google directamente: pide el proveedor activo con
 * `MLS_Translator::provider()` (filtrable con `mls_translation_provider`), de
 * modo que se pueda sustituir por DeepL, un servicio propio o un mock en las
 * pruebas sin tocar el resto del código.
 */
interface MLS_Translation_Provider {

	/**
	 * Traduce un lote de textos, preservando el orden.
	 *
	 * @param string[] $texts  Textos de origen.
	 * @param string   $source Código de idioma origen (2 letras).
	 * @param string   $target Código de idioma destino (2 letras).
	 * @param string   $format 'text' | 'html'.
	 * @return string[]|WP_Error  Traducciones en el mismo orden que $texts.
	 */
	public function translate_batch( array $texts, $source, $target, $format );

	/**
	 * @return string Identificador legible ("google", "deepl"...).
	 */
	public function get_name();

	/**
	 * @return int Máximo de caracteres por petición (para dividir lotes).
	 */
	public function max_chars_per_request();

	/**
	 * @return int Máximo de segmentos (textos) por petición.
	 */
	public function max_items_per_request();
}

/**
 * Proveedor por defecto: Google Cloud Translation API v2 (REST).
 */
class MLS_Google_Provider implements MLS_Translation_Provider {

	const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

	public function get_name() {
		return 'google';
	}

	public function max_chars_per_request() {
		// Google v2 admite ~30 000 caracteres por petición; se deja margen.
		return 28000;
	}

	public function max_items_per_request() {
		return 100;
	}

	/**
	 * @param string[] $texts
	 * @param string   $source
	 * @param string   $target
	 * @param string   $format
	 * @return string[]|WP_Error
	 */
	public function translate_batch( array $texts, $source, $target, $format ) {
		if ( empty( $texts ) ) {
			return array();
		}

		$settings = mls_get_settings();
		$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		if ( '' === $api_key ) {
			return new WP_Error( 'mls_no_key', __( 'Falta configurar la API key de Google en los ajustes del plugin.', 'mls' ) );
		}

		$format = ( 'html' === $format ) ? 'html' : 'text';

		$pairs = array();
		foreach ( $texts as $text ) {
			$pairs[] = 'q=' . rawurlencode( (string) $text );
		}
		$pairs[] = 'source=' . rawurlencode( $source );
		$pairs[] = 'target=' . rawurlencode( $target );
		$pairs[] = 'format=' . $format;

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 60,
			'headers' => array(
				// La API key viaja en cabecera: no queda en logs de acceso.
				'X-goog-api-key' => $api_key,
				'Content-Type'   => 'application/x-www-form-urlencoded',
			),
			'body'    => implode( '&', $pairs ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['data']['translations'] ) ) {
			$message = isset( $body['error']['message'] )
				? $body['error']['message']
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Error %d al llamar a la API de Google Translate.', 'mls' ),
					$code
				);
			return new WP_Error( 'mls_api_error', $message, array( 'status' => $code ) );
		}

		$out = array();
		foreach ( $body['data']['translations'] as $t ) {
			$out[] = isset( $t['translatedText'] ) ? $t['translatedText'] : '';
		}

		if ( count( $out ) !== count( $texts ) ) {
			return new WP_Error(
				'mls_api_partial',
				__( 'La API devolvió menos traducciones de las esperadas (respuesta parcial).', 'mls' )
			);
		}

		return $out;
	}
}
