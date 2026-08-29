<?php

namespace MLS\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * El adaptador clásico traduce solo texto y atributos seguros, preservando
 * estructura, scripts, URLs y clases.
 *
 * @covers \MLS_Classic_Adapter
 */
final class ClassicAdapterTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/../../includes/class-mls-classic-adapter.php';
	}

	/** Traduce cada unidad a MAYÚSCULAS para comprobar dónde se aplicó. */
	private function upper( string $html ): string {
		$units = \MLS_Classic_Adapter::extract_units( $html );
		$tr    = array_map(
			static function ( $u ) {
				$u['text'] = mb_strtoupper( $u['text'] );
				return $u;
			},
			$units
		);
		return \MLS_Classic_Adapter::apply_translations( $html, $tr );
	}

	public function test_extrae_texto_y_atributos_seguros(): void {
		$units = \MLS_Classic_Adapter::extract_units(
			'<p>Hola <a href="/x" title="Ir aquí">enlace</a></p><img src="/a.png" alt="Un gato">'
		);
		$texts = array_map( static fn( $u ) => trim( $u['text'] ), $units );

		$this->assertContains( 'Hola', $texts );
		$this->assertContains( 'enlace', $texts );
		$this->assertContains( 'Ir aquí', $texts );
		$this->assertContains( 'Un gato', $texts );
	}

	public function test_no_toca_scripts_ni_urls_ni_estructura(): void {
		$html   = '<p class="lead">Texto</p><script>var x = "no traducir";</script><a href="/ruta">ir</a>';
		$result = $this->upper( $html );

		$this->assertStringContainsString( 'var x = "no traducir";', $result );
		$this->assertStringContainsString( 'href="/ruta"', $result );
		$this->assertStringContainsString( 'class="lead"', $result );
		$this->assertStringContainsString( '<strong>NO</strong>', $this->upper( '<p>a<strong>no</strong>b</p>' ) );
	}

	public function test_respeta_notranslate(): void {
		$result = $this->upper( '<p>traducir <span class="notranslate">MarcaFija</span></p>' );
		$this->assertStringContainsString( 'TRADUCIR', $result );
		$this->assertStringContainsString( 'MarcaFija', $result );
		$this->assertStringNotContainsString( 'MARCAFIJA', $result );
	}

	public function test_conserva_espacios_entre_palabras_y_etiquetas(): void {
		$result = $this->upper( '<p>uno <em>dos</em> tres</p>' );
		$this->assertStringContainsString( 'UNO <em>DOS</em> TRES', $result );
	}
}
