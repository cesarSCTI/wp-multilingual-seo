<?php

namespace MLS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MLS_DB::sanitize_path
 */
final class DbSanitizePathTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_title' )->alias(
			static function ( $title ) {
				$title = strtolower( remove_accents_stub( (string) $title ) );
				$title = preg_replace( '/[^a-z0-9\s\-]/', '', $title );
				$title = preg_replace( '/[\s\-]+/', '-', $title );
				return trim( $title, '-' );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_conserva_las_barras_y_sanea_cada_segmento(): void {
		$this->assertSame(
			'acerca-de/nuestro-equipo',
			\MLS_DB::sanitize_path( 'Acerca de/Nuestro Equipo' )
		);
	}

	public function test_colapsa_barras_repetidas_y_recorta_los_extremos(): void {
		$this->assertSame(
			'a/b/c',
			\MLS_DB::sanitize_path( '/a//b/c/' )
		);
	}

	public function test_cadena_vacia_devuelve_vacio(): void {
		$this->assertSame( '', \MLS_DB::sanitize_path( '///' ) );
	}
}

function remove_accents_stub( string $s ): string {
	return strtr(
		$s,
		array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n' )
	);
}
