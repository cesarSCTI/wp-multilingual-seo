<?php

namespace MLS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MLS_Language_Registry
 */
final class LanguageRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'get_locale' )->justReturn( 'es_ES' );
		// apply_filters( $tag, $value, ... ) -> devuelve $value sin tocar.
		Functions\when( 'apply_filters' )->returnArg( 2 );

		\MLS_Language_Registry::flush_cache();
	}

	protected function tearDown(): void {
		\MLS_Language_Registry::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function withSettings( array $settings ): void {
		Functions\when( 'mls_get_settings' )->justReturn(
			array_merge(
				array( 'source_lang' => 'es', 'target_langs' => array() ),
				$settings
			)
		);
	}

	public function test_normaliza_locale_y_hreflang_de_idiomas_conocidos(): void {
		$this->withSettings( array( 'source_lang' => 'es', 'target_langs' => array( 'en', 'fr' ) ) );

		$all = \MLS_Language_Registry::all();

		$this->assertSame( 'en_US', $all['en']['locale'] );
		// Sin región conocida, el hreflang por defecto es el código de 2
		// letras (BCP-47 válido). La variante regional se define con el
		// filtro `mls_languages`.
		$this->assertSame( 'en', $all['en']['hreflang'] );
		$this->assertSame( 'fr_FR', $all['fr']['locale'] );
		$this->assertTrue( $all['es']['is_source'] );
		$this->assertFalse( $all['en']['is_source'] );
	}

	public function test_idioma_desconocido_cae_a_valores_derivados(): void {
		$this->withSettings( array( 'source_lang' => 'es', 'target_langs' => array( 'xy' ) ) );

		$this->assertSame( 'xy_XY', \MLS_Language_Registry::locale( 'xy' ) );
		$this->assertSame( 'xy', \MLS_Language_Registry::hreflang( 'xy' ) );
	}

	public function test_targets_excluye_el_idioma_fuente(): void {
		$this->withSettings( array( 'source_lang' => 'es', 'target_langs' => array( 'en', 'es' ) ) );

		$targets = \MLS_Language_Registry::targets();

		$this->assertArrayHasKey( 'en', $targets );
		$this->assertArrayNotHasKey( 'es', $targets );
		$this->assertTrue( \MLS_Language_Registry::is_target( 'en' ) );
		$this->assertFalse( \MLS_Language_Registry::is_target( 'es' ) );
		$this->assertFalse( \MLS_Language_Registry::is_target( 'de' ) );
	}

	public function test_el_filtro_mls_languages_puede_desactivar_un_idioma(): void {
		$this->withSettings( array( 'source_lang' => 'es', 'target_langs' => array( 'en', 'fr' ) ) );

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'mls_languages' === $tag ) {
					$value['fr']['active']   = false;
					$value['en']['hreflang'] = 'en-GB';
				}
				return $value;
			}
		);
		\MLS_Language_Registry::flush_cache();

		$targets = \MLS_Language_Registry::targets();
		$this->assertArrayHasKey( 'en', $targets );
		$this->assertArrayNotHasKey( 'fr', $targets, 'fr desactivado por el filtro no debe ser destino' );
		$this->assertSame( 'en-GB', \MLS_Language_Registry::hreflang( 'en' ) );
	}
}
