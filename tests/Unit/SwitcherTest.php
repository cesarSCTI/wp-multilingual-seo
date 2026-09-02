<?php

namespace MLS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MLS_Switcher
 */
final class SwitcherTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_locale' )->justReturn( 'es_ES' );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '/' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			static function ( $s ) {
				return rtrim( (string) $s, '/' ) . '/';
			}
		);

		\MLS_Language_Registry::flush_cache();
		\MLS_Language_Context::reset();
	}

	protected function tearDown(): void {
		\MLS_Language_Registry::flush_cache();
		\MLS_Language_Context::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function withLangs( string $source, array $targets ): void {
		Functions\when( 'mls_get_settings' )->justReturn(
			array( 'source_lang' => $source, 'target_langs' => $targets )
		);
	}

	public function test_format_label_admite_los_tres_formatos(): void {
		$link = array( 'code' => 'es', 'name' => 'Español', 'short' => 'ES' );

		$this->assertSame( 'ES', \MLS_Switcher::format_label( $link, 'code' ) );
		$this->assertSame( 'Español', \MLS_Switcher::format_label( $link, 'native' ) );
		$this->assertSame( 'ES · Español', \MLS_Switcher::format_label( $link, 'code_native' ) );
		// Formato desconocido -> código.
		$this->assertSame( 'ES', \MLS_Switcher::format_label( $link, 'loquesea' ) );
	}

	public function test_get_links_devuelve_codigo_en_mayusculas_y_marca_el_actual(): void {
		$this->withLangs( 'es', array( 'en', 'pt' ) );

		$links = \MLS_Switcher::get_links();

		$this->assertCount( 3, $links );

		$by_code = array();
		foreach ( $links as $l ) {
			$by_code[ $l['code'] ] = $l;
		}

		$this->assertSame( 'ES', $by_code['es']['short'] );
		$this->assertSame( 'EN', $by_code['en']['short'] );
		$this->assertTrue( $by_code['es']['is_current'], 'sin contexto traducido, el idioma fuente es el actual' );
		$this->assertFalse( $by_code['en']['is_current'] );
		$this->assertStringContainsString( '/en', $by_code['en']['url'] );
	}

	public function test_get_links_vacio_con_un_solo_idioma(): void {
		$this->withLangs( 'es', array() );

		$this->assertSame( array(), \MLS_Switcher::get_links() );
	}

	/**
	 * En una página /en/, el enlace al idioma fuente debe apuntar a la URL
	 * fuente real, no a la versión /en/. Para conseguirlo, get_links() suspende
	 * los filtros de MLS_Links mientras calcula las URLs.
	 */
	public function test_enlace_al_idioma_fuente_no_conserva_el_prefijo_traducido(): void {
		$this->withLangs( 'es', array( 'en' ) );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 123 );

		// Simula el filtro de MLS_Links: sin suspender, la URL fuente sale con
		// prefijo /en/; suspendido, sale limpia.
		Functions\when( 'mls_get_translated_url' )->alias(
			static function ( $post_id, $lang ) {
				if ( 'es' === $lang ) {
					return \MLS_Links::$suspended
						? 'https://example.test/contactanos/'
						: 'https://example.test/en/contactanos/';
				}
				return "https://example.test/{$lang}/contact-us/";
			}
		);

		\MLS_Language_Context::set_translation_context( 'en', 123 );

		$links   = \MLS_Switcher::get_links();
		$by_code = array();
		foreach ( $links as $l ) {
			$by_code[ $l['code'] ] = $l;
		}

		$this->assertSame( 'https://example.test/contactanos/', $by_code['es']['url'] );
		$this->assertTrue( $by_code['en']['is_current'] );
		$this->assertFalse( \MLS_Links::$suspended, 'la suspensión se restaura al terminar' );
	}
}
