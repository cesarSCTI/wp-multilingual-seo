<?php

namespace MLS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * El batch de traducción debe mandar a Google `format=html` para las
 * unidades con marcado y `format=text` para el texto plano; si no, Google
 * escapa las etiquetas (bug corregido en 2.4.0).
 *
 * @covers \MLS_Translator::unit_format
 */
final class TranslatorUnitFormatTest extends TestCase {

	/** @var ReflectionMethod */
	private $method;

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/../../includes/class-mls-translator.php';
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $text ) {
				return trim( preg_replace( '/<[^>]*>/', '', (string) $text ) );
			}
		);

		$this->method = new ReflectionMethod( \MLS_Translator::class, 'unit_format' );
		$this->method->setAccessible( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function format( array $unit ): string {
		return $this->method->invoke( null, $unit );
	}

	public function test_tipo_html_explicito(): void {
		$this->assertSame( 'html', $this->format( array( 'type' => 'html', 'text' => 'Hola' ) ) );
	}

	public function test_detecta_marcado_sin_tipo(): void {
		$this->assertSame( 'html', $this->format( array( 'text' => 'Texto con <strong>negrita</strong>' ) ) );
	}

	public function test_detecta_entidades_html(): void {
		$this->assertSame( 'html', $this->format( array( 'text' => 'Ver m&aacute;s' ) ) );
	}

	public function test_texto_plano_es_text(): void {
		$this->assertSame( 'text', $this->format( array( 'text' => 'Simplemente texto, con coma & signo' ) ) );
		$this->assertSame( 'text', $this->format( array( 'type' => 'attr', 'text' => 'Enviar' ) ) );
	}
}
