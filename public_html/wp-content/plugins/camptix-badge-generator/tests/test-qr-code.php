<?php

use CampTix\Badge_Generator\QR_Code;

defined( 'WPINC' ) || die();

/**
 * Tests for the QR code encoder.
 *
 * The fixtures were checked module for module against the `qrcode` npm library
 * (byte mode, ECC level M, same mask) and decode with jsQR.
 *
 * @group camptix-badge-generator
 */
class Test_QR_Code extends WP_UnitTestCase {
	/**
	 * Load a fixture as rows of 0/1 strings.
	 *
	 * @param string $name
	 *
	 * @return string[]
	 */
	protected function get_fixture( $name ) {
		return explode( "\n", trim( file_get_contents( __DIR__ . "/fixtures/$name.txt" ) ) );
	}

	/**
	 * Flatten a matrix to rows of 0/1 strings.
	 *
	 * @param array $matrix
	 *
	 * @return string[]
	 */
	protected function to_rows( $matrix ) {
		return array_map(
			function ( $row ) {
				return implode( '', array_map( 'intval', $row ) );
			},
			$matrix
		);
	}

	/**
	 * Data provider for test_matrix_matches_reference().
	 *
	 * @return array
	 */
	public function data_matrix_matches_reference() {
		return array(
			'version 2' => array( 'https://w.org/@bor0', 'qr-wporg-profile-v2' ),
			'version 4' => array( 'https://gravatar.com/9dd4e461268c8034f5c8564e155c67a6', 'qr-gravatar-profile-v4' ),
			'version 7, with version information' => array( 'https://example.org/' . str_repeat( '0', 100 ), 'qr-long-url-v7' ),
		);
	}

	/**
	 * @covers \CampTix\Badge_Generator\QR_Code::get_matrix
	 * @dataProvider data_matrix_matches_reference
	 *
	 * @param string $text
	 * @param string $fixture
	 */
	public function test_matrix_matches_reference( $text, $fixture ) {
		$this->assertSame( $this->get_fixture( $fixture ), $this->to_rows( QR_Code::get_matrix( $text ) ) );
	}

	/**
	 * @covers \CampTix\Badge_Generator\QR_Code::get_matrix
	 * @covers \CampTix\Badge_Generator\QR_Code::get_svg
	 */
	public function test_text_too_long_returns_nothing() {
		$text = str_repeat( 'a', 214 );

		$this->assertSame( array(), QR_Code::get_matrix( $text ) );
		$this->assertSame( '', QR_Code::get_svg( $text ) );
	}

	/**
	 * The SVG is printed unescaped on the badges, so it must never carry the input.
	 *
	 * @covers \CampTix\Badge_Generator\QR_Code::get_svg
	 */
	public function test_svg_does_not_contain_input() {
		$text = 'https://w.org/@"><script>alert(1)</script>';
		$svg  = QR_Code::get_svg( $text );

		$this->assertStringStartsWith( '<svg ', $svg );
		$this->assertStringNotContainsString( 'script', $svg );
		$this->assertMatchesRegularExpression( '/ d="[Mhvz0-9, -]+"/', $svg );
	}

	/**
	 * The PNG's pixels match the matrix, with the quiet zone around it.
	 *
	 * @covers \CampTix\Badge_Generator\QR_Code::get_png
	 */
	public function test_png_matches_matrix() {
		$text   = 'https://w.org/@bor0';
		$matrix = QR_Code::get_matrix( $text );
		$png    = QR_Code::get_png( $text, QR_Code::ECC_M, 4, 3 );
		$size   = getimagesizefromstring( $png );

		$this->assertSame( IMAGETYPE_PNG, $size[2] );
		$this->assertSame( ( count( $matrix ) + 8 ) * 3, $size[0] );
		$this->assertSame( $size[0], $size[1] );

		// Skip the signature and IHDR chunk, then read the IDAT chunk.
		$idat_length = unpack( 'N', substr( $png, 33, 4 ) )[1];
		$pixels      = gzuncompress( substr( $png, 41, $idat_length ) );
		$stride      = $size[0] + 1;

		foreach ( array( -4, 0, 3, 10, count( $matrix ) - 1, count( $matrix ) + 3 ) as $r ) {
			foreach ( array( -4, 0, 5, 12, count( $matrix ) - 1, count( $matrix ) + 3 ) as $c ) {
				$y        = ( $r + 4 ) * 3 + 1;
				$x        = ( $c + 4 ) * 3 + 1;
				$expected = ! empty( $matrix[ $r ][ $c ] ) ? "\x00" : "\xFF";

				$this->assertSame( "\x00", $pixels[ $y * $stride ], "Filter byte on row $y." );
				$this->assertSame( $expected, $pixels[ $y * $stride + 1 + $x ], "Module $r, $c." );
			}
		}
	}

	/**
	 * @covers \CampTix\Badge_Generator\QR_Code::get_png
	 */
	public function test_png_empty_when_text_too_long() {
		$this->assertSame( '', QR_Code::get_png( str_repeat( 'a', 5000 ) ) );
	}
}
