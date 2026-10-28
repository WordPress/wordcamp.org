<?php

namespace CampTix\Badge_Generator;

defined( 'WPINC' ) || die();

/**
 * Standalone, dependency-free QR Code generator for badge printing and InDesign merge.
 *
 * Implements ISO/IEC 18004:2006 QR Code standard in Byte Mode (UTF-8 / 8-bit).
 */
class QR_Code {

	public const ECC_L = 0; // 7% error correction.
	public const ECC_M = 1; // 15% error correction.
	public const ECC_Q = 2; // 25% error correction.
	public const ECC_H = 3; // 30% error correction.

	/**
	 * Capacity table for Byte mode (Version 1 to 10) [L, M, Q, H].
	 */
	private static array $capacity = array(
		1  => array( 17, 14, 11, 7 ),
		2  => array( 32, 26, 20, 14 ),
		3  => array( 53, 42, 32, 24 ),
		4  => array( 78, 62, 46, 34 ),
		5  => array( 106, 84, 60, 44 ),
		6  => array( 134, 106, 74, 58 ),
		7  => array( 154, 122, 86, 64 ),
		8  => array( 192, 152, 108, 84 ),
		9  => array( 230, 180, 130, 98 ),
		10 => array( 271, 213, 151, 119 ),
	);

	/**
	 * Total data codewords per version & ECC level [total_codewords, ec_codewords_per_block, num_blocks_g1, data_cw_g1, num_blocks_g2, data_cw_g2].
	 */
	private static array $ecc_table = array(
		1  => array(
			self::ECC_L => array( 26, 7, 1, 19, 0, 0 ),
			self::ECC_M => array( 26, 10, 1, 16, 0, 0 ),
			self::ECC_Q => array( 26, 13, 1, 13, 0, 0 ),
			self::ECC_H => array( 26, 17, 1, 9, 0, 0 ),
		),
		2  => array(
			self::ECC_L => array( 44, 10, 1, 34, 0, 0 ),
			self::ECC_M => array( 44, 16, 1, 28, 0, 0 ),
			self::ECC_Q => array( 44, 22, 1, 22, 0, 0 ),
			self::ECC_H => array( 44, 28, 1, 16, 0, 0 ),
		),
		3  => array(
			self::ECC_L => array( 70, 15, 1, 55, 0, 0 ),
			self::ECC_M => array( 70, 26, 1, 44, 0, 0 ),
			self::ECC_Q => array( 70, 18, 2, 17, 0, 0 ),
			self::ECC_H => array( 70, 22, 2, 13, 0, 0 ),
		),
		4  => array(
			self::ECC_L => array( 100, 20, 1, 80, 0, 0 ),
			self::ECC_M => array( 100, 18, 2, 32, 0, 0 ),
			self::ECC_Q => array( 100, 26, 2, 24, 0, 0 ),
			self::ECC_H => array( 100, 16, 4, 9, 0, 0 ),
		),
		5  => array(
			self::ECC_L => array( 134, 26, 1, 108, 0, 0 ),
			self::ECC_M => array( 134, 24, 2, 43, 0, 0 ),
			self::ECC_Q => array( 134, 18, 2, 15, 2, 16 ),
			self::ECC_H => array( 134, 22, 2, 11, 2, 12 ),
		),
		6  => array(
			self::ECC_L => array( 172, 18, 2, 68, 0, 0 ),
			self::ECC_M => array( 172, 16, 4, 27, 0, 0 ),
			self::ECC_Q => array( 172, 24, 4, 19, 0, 0 ),
			self::ECC_H => array( 172, 28, 4, 15, 0, 0 ),
		),
		7  => array(
			self::ECC_L => array( 196, 20, 2, 78, 0, 0 ),
			self::ECC_M => array( 196, 18, 4, 31, 0, 0 ),
			self::ECC_Q => array( 196, 18, 2, 14, 4, 15 ),
			self::ECC_H => array( 196, 26, 4, 13, 1, 14 ),
		),
		8  => array(
			self::ECC_L => array( 242, 24, 2, 97, 0, 0 ),
			self::ECC_M => array( 242, 22, 2, 38, 2, 39 ),
			self::ECC_Q => array( 242, 22, 4, 18, 2, 19 ),
			self::ECC_H => array( 242, 26, 4, 14, 2, 15 ),
		),
		9  => array(
			self::ECC_L => array( 292, 30, 2, 116, 0, 0 ),
			self::ECC_M => array( 292, 22, 3, 36, 2, 37 ),
			self::ECC_Q => array( 292, 20, 4, 16, 4, 17 ),
			self::ECC_H => array( 292, 24, 4, 12, 4, 13 ),
		),
		10 => array(
			self::ECC_L => array( 346, 18, 2, 68, 2, 69 ),
			self::ECC_M => array( 346, 26, 4, 43, 1, 44 ),
			self::ECC_Q => array( 346, 24, 6, 19, 2, 20 ),
			self::ECC_H => array( 346, 28, 6, 15, 2, 16 ),
		),
	);

	/**
	 * Alignment pattern center coordinates.
	 */
	private static array $alignment_patterns = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	/**
	 * Galois Field 256 tables for Reed-Solomon coding.
	 */
	private static array $gf_exp = array();
	private static array $gf_log = array();

	/**
	 * Initialize GF(256) tables.
	 */
	private static function init_gf(): void {
		if ( ! empty( self::$gf_exp ) ) {
			return;
		}

		self::$gf_exp = array_fill( 0, 512, 0 );
		self::$gf_log = array_fill( 0, 256, 0 );

		$x = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			self::$gf_exp[ $i ] = $x;
			self::$gf_log[ $x ] = $i;
			$x                <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11d; // Primitive polynomial x^8 + x^4 + x^3 + x^2 + 1.
			}
		}
		for ( $i = 255; $i < 512; $i++ ) {
			self::$gf_exp[ $i ] = self::$gf_exp[ $i - 255 ];
		}
	}

	/**
	 * Generate SVG string for given text.
	 *
	 * @param string $text      The URL or text to encode.
	 * @param int    $ecc_level Error correction level (default ECC_M).
	 * @param int    $margin    Quiet zone margin in modules (default 4).
	 *
	 * @return string SVG element markup.
	 */
	public static function get_svg( string $text, int $ecc_level = self::ECC_M, int $margin = 4 ): string {
		$matrix = self::get_matrix( $text, $ecc_level );
		if ( empty( $matrix ) ) {
			return '';
		}

		$size       = count( $matrix );
		$total_size = $size + ( $margin * 2 );
		$path_data  = '';

		for ( $r = 0; $r < $size; $r++ ) {
			for ( $c = 0; $c < $size; $c++ ) {
				if ( $matrix[ $r ][ $c ] ) {
					$x          = $c + $margin;
					$y          = $r + $margin;
					$path_data .= "M{$x},{$y}h1v1h-1z ";
				}
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="100%%" height="100%%" role="img" aria-hidden="true" shape-rendering="crispEdges"><path fill="currentColor" d="%2$s"/></svg>',
			$total_size,
			trim( $path_data )
		);
	}

	/**
	 * Generate 2D boolean matrix for the QR Code.
	 *
	 * @param string $text
	 * @param int    $ecc_level
	 *
	 * @return array<int, array<int, bool>>
	 */
	public static function get_matrix( string $text, int $ecc_level = self::ECC_M ): array {
		self::init_gf();

		$data_len = strlen( $text );
		$version  = self::get_best_version( $data_len, $ecc_level );
		if ( 0 === $version ) {
			return array();
		}

		$data_codewords = self::encode_data( $text, $version, $ecc_level );
		$final_bits     = self::interleave_blocks( $data_codewords, $version, $ecc_level );

		$size   = 17 + 4 * $version;
		$matrix = array_fill( 0, $size, array_fill( 0, $size, null ) );

		// Place function patterns.
		self::place_finder_pattern( $matrix, 0, 0 );
		self::place_finder_pattern( $matrix, $size - 7, 0 );
		self::place_finder_pattern( $matrix, 0, $size - 7 );

		self::place_separators( $matrix, $size );
		self::place_timing_patterns( $matrix, $size );
		self::place_alignment_patterns( $matrix, $version );
		self::place_dark_module( $matrix, $version );
		self::reserve_format_information( $matrix, $size );
		self::place_version_information( $matrix, $version, $size );

		// Place data modules and choose best mask.
		$best_mask    = 0;
		$best_penalty = PHP_INT_MAX;
		$best_matrix  = array();

		for ( $mask = 0; $mask < 8; $mask++ ) {
			$test_matrix = $matrix;
			self::place_data_bits( $test_matrix, $final_bits, $mask, $size );
			self::place_format_information( $test_matrix, $ecc_level, $mask, $size );

			$penalty = self::calculate_penalty( $test_matrix, $size );
			if ( $penalty < $best_penalty ) {
				$best_penalty = $penalty;
				$best_mask    = $mask;
				$best_matrix  = $test_matrix;
			}
		}

		return array_map(
			function ( $row ) {
				return array_map( 'boolval', $row );
			},
			$best_matrix
		);
	}

	/**
	 * Find the smallest version that fits the data at the given error correction level.
	 */
	private static function get_best_version( int $data_len, int $ecc_level ): int {
		foreach ( self::$capacity as $version => $limits ) {
			if ( $data_len <= $limits[ $ecc_level ] ) {
				return $version;
			}
		}
		return 0;
	}

	/**
	 * Encode the text as byte mode data codewords, padded to the version's capacity.
	 */
	private static function encode_data( string $text, int $version, int $ecc_level ): array {
		$bit_buffer = '';

		// Mode Indicator: Byte Mode (0100).
		$bit_buffer .= '0100';

		// Character Count Indicator (8 bits for v1-9, 16 bits for v10+).
		$count_bits  = ( $version <= 9 ) ? 8 : 16;
		$bit_buffer .= str_pad( decbin( strlen( $text ) ), $count_bits, '0', STR_PAD_LEFT );

		// Data bits.
		$len = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$bit_buffer .= str_pad( decbin( ord( $text[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}

		$ecc_info         = self::$ecc_table[ $version ][ $ecc_level ];
		$total_data_bytes = ( $ecc_info[2] * $ecc_info[3] ) + ( $ecc_info[4] * $ecc_info[5] );
		$total_data_bits  = $total_data_bytes * 8;

		// Terminator.
		$bit_buffer .= str_repeat( '0', min( 4, $total_data_bits - strlen( $bit_buffer ) ) );

		// Pad to byte.
		if ( 0 !== strlen( $bit_buffer ) % 8 ) {
			$bit_buffer .= str_repeat( '0', 8 - ( strlen( $bit_buffer ) % 8 ) );
		}

		// Pad bytes (0xEC, 0x11).
		$pad_bytes = array( '11101100', '00010001' );
		$pad_count = ( $total_data_bits - strlen( $bit_buffer ) ) / 8;
		for ( $p = 0; $p < $pad_count; $p++ ) {
			$bit_buffer .= $pad_bytes[ $p % 2 ];
		}

		return array_map( 'bindec', str_split( $bit_buffer, 8 ) );
	}

	/**
	 * Split the data into blocks, add error correction, and interleave them as a bit string.
	 */
	private static function interleave_blocks( array $data_codewords, int $version, int $ecc_level ): string {
		$info            = self::$ecc_table[ $version ][ $ecc_level ];
		$ec_cw_per_block = $info[1];
		$num_b1          = $info[2];
		$cw_b1           = $info[3];
		$num_b2          = $info[4];
		$cw_b2           = $info[5];

		$blocks    = array();
		$ec_blocks = array();
		$offset    = 0;

		for ( $i = 0; $i < $num_b1; $i++ ) {
			$block       = array_slice( $data_codewords, $offset, $cw_b1 );
			$offset     += $cw_b1;
			$blocks[]    = $block;
			$ec_blocks[] = self::generate_ec_codewords( $block, $ec_cw_per_block );
		}

		for ( $i = 0; $i < $num_b2; $i++ ) {
			$block       = array_slice( $data_codewords, $offset, $cw_b2 );
			$offset     += $cw_b2;
			$blocks[]    = $block;
			$ec_blocks[] = self::generate_ec_codewords( $block, $ec_cw_per_block );
		}

		$interleaved = '';
		$max_data_cw = max( $cw_b1, $cw_b2 );

		// Interleave data codewords.
		for ( $i = 0; $i < $max_data_cw; $i++ ) {
			foreach ( $blocks as $b ) {
				if ( isset( $b[ $i ] ) ) {
					$interleaved .= str_pad( decbin( $b[ $i ] ), 8, '0', STR_PAD_LEFT );
				}
			}
		}

		// Interleave EC codewords.
		for ( $i = 0; $i < $ec_cw_per_block; $i++ ) {
			foreach ( $ec_blocks as $eb ) {
				if ( isset( $eb[ $i ] ) ) {
					$interleaved .= str_pad( decbin( $eb[ $i ] ), 8, '0', STR_PAD_LEFT );
				}
			}
		}

		return $interleaved;
	}

	/**
	 * Generate the Reed-Solomon error correction codewords for one block.
	 */
	private static function generate_ec_codewords( array $data, int $num_ec ): array {
		$generator = self::get_generator_poly( $num_ec );
		$msg       = array_merge( $data, array_fill( 0, $num_ec, 0 ) );

		$data_count      = count( $data );
		$generator_count = count( $generator );

		for ( $i = 0; $i < $data_count; $i++ ) {
			$lead = $msg[ $i ];
			if ( 0 !== $lead ) {
				$lead_log = self::$gf_log[ $lead ];
				for ( $j = 0; $j < $generator_count; $j++ ) {
					$msg[ $i + $j ] ^= self::$gf_exp[ $lead_log + $generator[ $j ] ];
				}
			}
		}

		return array_slice( $msg, count( $data ), $num_ec );
	}

	/**
	 * Get the Reed-Solomon generator polynomial of the given degree, as logs.
	 */
	private static function get_generator_poly( int $degree ): array {
		// Coefficients of ( x - a^0 )( x - a^1 )...( x - a^(degree-1) ), highest power first.
		$poly = array( 1 );
		for ( $i = 0; $i < $degree; $i++ ) {
			$poly_count = count( $poly );
			$next       = array_fill( 0, $poly_count + 1, 0 );
			for ( $j = 0; $j < $poly_count; $j++ ) {
				$next[ $j ]     ^= $poly[ $j ];
				$next[ $j + 1 ] ^= self::$gf_exp[ self::$gf_log[ $poly[ $j ] ] + $i ];
			}
			$poly = $next;
		}

		// Return as logs, which is what generate_ec_codewords() multiplies with.
		return array_map(
			function ( $val ) {
				return self::$gf_log[ $val ];
			},
			$poly
		);
	}

	/**
	 * Place a 7x7 finder pattern with its top-left corner at the given position.
	 */
	private static function place_finder_pattern( array &$matrix, int $x, int $y ): void {
		for ( $r = 0; $r < 7; $r++ ) {
			for ( $c = 0; $c < 7; $c++ ) {
				if ( 0 === $r || 6 === $r || 0 === $c || 6 === $c || ( $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4 ) ) {
					$matrix[ $y + $r ][ $x + $c ] = 1;
				} else {
					$matrix[ $y + $r ][ $x + $c ] = 0;
				}
			}
		}
	}

	/**
	 * Place the light separators around the three finder patterns.
	 */
	private static function place_separators( array &$matrix, int $size ): void {
		for ( $i = 0; $i < 8; $i++ ) {
			$matrix[7][ $i ]             = 0;
			$matrix[ $i ][7]             = 0;
			$matrix[ $size - 8 ][ $i ]   = 0;
			$matrix[ $size - 1 - $i ][7] = 0;
			$matrix[7][ $size - 1 - $i ] = 0;
			$matrix[ $i ][ $size - 8 ]   = 0;
		}
	}

	/**
	 * Place the alternating timing patterns in row and column 6.
	 */
	private static function place_timing_patterns( array &$matrix, int $size ): void {
		for ( $i = 8; $i < $size - 8; $i++ ) {
			if ( null === $matrix[6][ $i ] ) {
				$matrix[6][ $i ] = ( 0 === $i % 2 ) ? 1 : 0;
			}
			if ( null === $matrix[ $i ][6] ) {
				$matrix[ $i ][6] = ( 0 === $i % 2 ) ? 1 : 0;
			}
		}
	}

	/**
	 * Place the 5x5 alignment patterns for the version.
	 */
	private static function place_alignment_patterns( array &$matrix, int $version ): void {
		$positions = self::$alignment_patterns[ $version ];
		$count     = count( $positions );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = 0; $j < $count; $j++ ) {
				$r = $positions[ $i ];
				$c = $positions[ $j ];

				// Skip if overlapping with finder patterns.
				if ( ( 6 === $r && 6 === $c ) ||
					( 6 === $r && $positions[ $count - 1 ] === $c && 0 === $i ) ||
					( $positions[ $count - 1 ] === $r && 6 === $c && 0 === $j ) ) {
					continue;
				}

				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						if ( abs( $dy ) === 2 || abs( $dx ) === 2 || ( 0 === $dy && 0 === $dx ) ) {
							$matrix[ $r + $dy ][ $c + $dx ] = 1;
						} else {
							$matrix[ $r + $dy ][ $c + $dx ] = 0;
						}
					}
				}
			}
		}
	}

	/**
	 * Place the module that is always dark.
	 */
	private static function place_dark_module( array &$matrix, int $version ): void {
		$matrix[ 4 * $version + 9 ][8] = 1;
	}

	/**
	 * Version 7 and up carry their version number in two 6x3 blocks, next to the
	 * top-right and bottom-left finders.
	 */
	private static function place_version_information( array &$matrix, int $version, int $size ): void {
		if ( $version < 7 ) {
			return;
		}

		// 12 BCH error correction bits, generator polynomial 0x1F25.
		$rem = $version;
		for ( $i = 0; $i < 12; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
		}
		$bits = ( $version << 12 ) | $rem;

		for ( $i = 0; $i < 18; $i++ ) {
			$bit = ( $bits >> $i ) & 1;
			$a   = $size - 11 + ( $i % 3 );
			$b   = intdiv( $i, 3 );

			$matrix[ $b ][ $a ] = $bit;
			$matrix[ $a ][ $b ] = $bit;
		}
	}

	/**
	 * Reserve the format information areas so data bits skip them.
	 */
	private static function reserve_format_information( array &$matrix, int $size ): void {
		for ( $i = 0; $i < 9; $i++ ) {
			if ( null === $matrix[8][ $i ] ) {
				$matrix[8][ $i ] = 0;
			}
			if ( null === $matrix[ $i ][8] ) {
				$matrix[ $i ][8] = 0;
			}
		}
		for ( $i = $size - 8; $i < $size; $i++ ) {
			if ( null === $matrix[8][ $i ] ) {
				$matrix[8][ $i ] = 0;
			}
			if ( null === $matrix[ $i ][8] ) {
				$matrix[ $i ][8] = 0;
			}
		}
	}

	/**
	 * Place the data bits in the zigzag order, applying the mask.
	 */
	private static function place_data_bits( array &$matrix, string $bits, int $mask, int $size ): void {
		$bit_idx = 0;
		$bit_len = strlen( $bits );

		$x   = $size - 1;
		$dir = -1; // Moving upwards.

		while ( $x > 0 ) {
			if ( 6 === $x ) {
				--$x; // Skip vertical timing pattern.
			}

			$y = ( -1 === $dir ) ? $size - 1 : 0;

			while ( $y >= 0 && $y < $size ) {
				for ( $c = 0; $c < 2; $c++ ) {
					$col = $x - $c;
					if ( null === $matrix[ $y ][ $col ] ) {
						$bit = ( $bit_idx < $bit_len ) ? (int) $bits[ $bit_idx ] : 0;
						++$bit_idx;

						if ( self::apply_mask( $mask, $y, $col ) ) {
							$bit ^= 1;
						}

						$matrix[ $y ][ $col ] = $bit;
					}
				}
				$y += $dir;
			}

			$dir = -$dir;
			$x  -= 2;
		}
	}

	/**
	 * Whether the given mask pattern flips the module at the given position.
	 */
	private static function apply_mask( int $mask, int $r, int $c ): bool {
		// Each pattern flips the module where its expression is zero.
		return 0 === match ( $mask ) {
			0 => ( $r + $c ) % 2,
			1 => $r % 2,
			2 => $c % 3,
			3 => ( $r + $c ) % 3,
			4 => ( intdiv( $r, 2 ) + intdiv( $c, 3 ) ) % 2,
			5 => ( $r * $c ) % 2 + ( $r * $c ) % 3,
			6 => ( ( $r * $c ) % 2 + ( $r * $c ) % 3 ) % 2,
			7 => ( ( $r + $c ) % 2 + ( $r * $c ) % 3 ) % 2,
			default => 1,
		};
	}

	/**
	 * Place both copies of the format information for the ECC level and mask.
	 */
	private static function place_format_information( array &$matrix, int $ecc_level, int $mask, int $size ): void {
		// Format string: ECC (2 bits) + Mask (3 bits) + 10 BCH error correction bits, XORed with 0x5412.
		$format_bits = array(
			self::ECC_L => array( 0x77c4, 0x72f3, 0x7daa, 0x789d, 0x662f, 0x6318, 0x6c41, 0x6976 ),
			self::ECC_M => array( 0x5412, 0x5125, 0x5e7c, 0x5b4b, 0x45f9, 0x40ce, 0x4f97, 0x4aa0 ),
			self::ECC_Q => array( 0x355f, 0x3068, 0x3f31, 0x3a06, 0x24b4, 0x2183, 0x2eda, 0x2bed ),
			self::ECC_H => array( 0x1689, 0x13be, 0x1ce7, 0x19d0, 0x0762, 0x0255, 0x0d0c, 0x083b ),
		);

		$val = $format_bits[ $ecc_level ][ $mask ];

		for ( $i = 0; $i < 15; $i++ ) {
			$bit = ( $val >> $i ) & 1;

			// Around the top-left finder, skipping the timing pattern in row and column 6.
			if ( $i < 6 ) {
				$matrix[ $i ][8] = $bit;
			} elseif ( $i < 8 ) {
				$matrix[ $i + 1 ][8] = $bit;
			} elseif ( 8 === $i ) {
				$matrix[8][7] = $bit;
			} else {
				$matrix[8][ 14 - $i ] = $bit;
			}

			// Split between the top-right and bottom-left finders.
			if ( $i < 8 ) {
				$matrix[8][ $size - 1 - $i ] = $bit;
			} else {
				$matrix[ $size - 15 + $i ][8] = $bit;
			}
		}
	}

	/**
	 * Score a masked matrix; the lowest score picks the mask.
	 */
	private static function calculate_penalty( array $matrix, int $size ): int {
		$penalty = 0;

		// Condition 1: Runs of same color >= 5.
		for ( $r = 0; $r < $size; $r++ ) {
			$run_len = 1;
			for ( $c = 1; $c < $size; $c++ ) {
				if ( $matrix[ $r ][ $c ] === $matrix[ $r ][ $c - 1 ] ) {
					++$run_len;
				} else {
					if ( $run_len >= 5 ) {
						$penalty += 3 + ( $run_len - 5 );
					}
					$run_len = 1;
				}
			}
			if ( $run_len >= 5 ) {
				$penalty += 3 + ( $run_len - 5 );
			}
		}

		for ( $c = 0; $c < $size; $c++ ) {
			$run_len = 1;
			for ( $r = 1; $r < $size; $r++ ) {
				if ( $matrix[ $r ][ $c ] === $matrix[ $r - 1 ][ $c ] ) {
					++$run_len;
				} else {
					if ( $run_len >= 5 ) {
						$penalty += 3 + ( $run_len - 5 );
					}
					$run_len = 1;
				}
			}
			if ( $run_len >= 5 ) {
				$penalty += 3 + ( $run_len - 5 );
			}
		}

		// Condition 2: 2x2 blocks.
		for ( $r = 0; $r < $size - 1; $r++ ) {
			for ( $c = 0; $c < $size - 1; $c++ ) {
				$val = $matrix[ $r ][ $c ];
				if ( $val === $matrix[ $r + 1 ][ $c ] &&
					$val === $matrix[ $r ][ $c + 1 ] &&
					$val === $matrix[ $r + 1 ][ $c + 1 ] ) {
					$penalty += 3;
				}
			}
		}

		return $penalty;
	}
}
