<?php
/**
 * Shapes Arabic text for DomPDF.
 *
 * DomPDF draws each character on its own and left to right, so Arabic comes out as disconnected
 * letters in reverse order. This replaces each letter with the presentation form for its position
 * in the word (isolated, final, initial or medial), joins lam-alef into its ligature, and reverses
 * each Arabic run into visual order. The Persian letters missing from the Arabic alphabet are
 * covered too. There's no full bidi: numbers inside an Arabic run keep their place and direction.
 */
class WordCamp_Docs_Arabic_Shaper {

	/**
	 * Presentation forms by letter: isolated, final, initial, medial. Letters with two forms only
	 * join the letter before them.
	 */
	const FORMS = array(
		0x0621 => array( 0xFE80 ),
		0x0622 => array( 0xFE81, 0xFE82 ),
		0x0623 => array( 0xFE83, 0xFE84 ),
		0x0624 => array( 0xFE85, 0xFE86 ),
		0x0625 => array( 0xFE87, 0xFE88 ),
		0x0626 => array( 0xFE89, 0xFE8A, 0xFE8B, 0xFE8C ),
		0x0627 => array( 0xFE8D, 0xFE8E ),
		0x0628 => array( 0xFE8F, 0xFE90, 0xFE91, 0xFE92 ),
		0x0629 => array( 0xFE93, 0xFE94 ),
		0x062A => array( 0xFE95, 0xFE96, 0xFE97, 0xFE98 ),
		0x062B => array( 0xFE99, 0xFE9A, 0xFE9B, 0xFE9C ),
		0x062C => array( 0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0 ),
		0x062D => array( 0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4 ),
		0x062E => array( 0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8 ),
		0x062F => array( 0xFEA9, 0xFEAA ),
		0x0630 => array( 0xFEAB, 0xFEAC ),
		0x0631 => array( 0xFEAD, 0xFEAE ),
		0x0632 => array( 0xFEAF, 0xFEB0 ),
		0x0633 => array( 0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4 ),
		0x0634 => array( 0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8 ),
		0x0635 => array( 0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC ),
		0x0636 => array( 0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0 ),
		0x0637 => array( 0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4 ),
		0x0638 => array( 0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8 ),
		0x0639 => array( 0xFEC9, 0xFECA, 0xFECB, 0xFECC ),
		0x063A => array( 0xFECD, 0xFECE, 0xFECF, 0xFED0 ),
		0x0641 => array( 0xFED1, 0xFED2, 0xFED3, 0xFED4 ),
		0x0642 => array( 0xFED5, 0xFED6, 0xFED7, 0xFED8 ),
		0x0643 => array( 0xFED9, 0xFEDA, 0xFEDB, 0xFEDC ),
		0x0644 => array( 0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0 ),
		0x0645 => array( 0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4 ),
		0x0646 => array( 0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8 ),
		0x0647 => array( 0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC ),
		0x0648 => array( 0xFEED, 0xFEEE ),
		0x0649 => array( 0xFEEF, 0xFEF0 ),
		0x064A => array( 0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4 ),
		// Persian.
		0x067E => array( 0xFB56, 0xFB57, 0xFB58, 0xFB59 ),
		0x0686 => array( 0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D ),
		0x0698 => array( 0xFB8A, 0xFB8B ),
		0x06A9 => array( 0xFB8E, 0xFB8F, 0xFB90, 0xFB91 ),
		0x06AF => array( 0xFB92, 0xFB93, 0xFB94, 0xFB95 ),
		0x06CC => array( 0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF ),
	);

	/**
	 * Lam followed by one of these alefs becomes a ligature: isolated, final.
	 */
	const LAM_ALEF = array(
		0x0622 => array( 0xFEF5, 0xFEF6 ),
		0x0623 => array( 0xFEF7, 0xFEF8 ),
		0x0625 => array( 0xFEF9, 0xFEFA ),
		0x0627 => array( 0xFEFB, 0xFEFC ),
	);

	const LAM     = 0x0644;
	const TATWEEL = 0x0640;

	/**
	 * A run of Arabic letters and marks, with the spaces between its words. Arabic-Indic digits
	 * are left out so numbers keep reading left to right.
	 */
	const RUN = '/[\x{0621}-\x{065F}\x{0670}-\x{06EF}\x{06FA}-\x{06FF}](?:[\x{0621}-\x{065F}\x{0670}-\x{06EF}\x{06FA}-\x{06FF} \x{00A0}]*[\x{0621}-\x{065F}\x{0670}-\x{06EF}\x{06FA}-\x{06FF}])?/u';

	/**
	 * Shape every Arabic run in a string. Markup is left alone, since tags and attribute names
	 * are never Arabic.
	 *
	 * @param string $text Text or HTML.
	 *
	 * @return string
	 */
	public static function shape( $text ) {
		if ( ! preg_match( '/[\x{0600}-\x{06FF}]/u', $text ) ) {
			return $text;
		}

		return preg_replace_callback(
			self::RUN,
			function ( $run ) {
				return self::shape_run( $run[0] );
			},
			$text
		);
	}

	/**
	 * Shape one run, then reverse it into visual order.
	 *
	 * @param string $run Arabic text in logical order.
	 *
	 * @return string
	 */
	protected static function shape_run( $run ) {
		$chars    = array_map( 'mb_ord', mb_str_split( $run ) );
		$count    = count( $chars );
		$clusters = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$char = $chars[ $i ];

			// A harakah or other mark stays with the letter it sits on.
			if ( self::is_mark( $char ) && $clusters ) {
				$clusters[ count( $clusters ) - 1 ] .= mb_chr( $char );
				continue;
			}

			$joins_before = self::joins_forward( self::neighbour( $chars, $i, -1 ), $chars );
			$next         = self::neighbour( $chars, $i, 1 );

			if ( self::LAM === $char && null !== $next && isset( self::LAM_ALEF[ $chars[ $next ] ] ) ) {
				$clusters[] = mb_chr( self::LAM_ALEF[ $chars[ $next ] ][ $joins_before ? 1 : 0 ] );
				$i          = $next;
				continue;
			}

			if ( ! isset( self::FORMS[ $char ] ) ) {
				$clusters[] = mb_chr( $char );
				continue;
			}

			$forms       = self::FORMS[ $char ];
			$joins_after = 4 === count( $forms ) && self::joins_backward( $next, $chars );

			if ( 1 === count( $forms ) ) {
				$form = $forms[0];
			} elseif ( $joins_before && $joins_after ) {
				$form = $forms[3];
			} elseif ( $joins_after ) {
				$form = $forms[2];
			} elseif ( $joins_before ) {
				$form = $forms[1];
			} else {
				$form = $forms[0];
			}

			$clusters[] = mb_chr( $form );
		}

		return implode( '', array_reverse( $clusters ) );
	}

	/**
	 * The index of the nearest letter before or after $i, skipping marks.
	 *
	 * @param int[] $chars     Code points.
	 * @param int   $i         Position.
	 * @param int   $direction -1 for before, 1 for after.
	 *
	 * @return int|null
	 */
	protected static function neighbour( $chars, $i, $direction ) {
		for ( $j = $i + $direction; isset( $chars[ $j ] ); $j += $direction ) {
			if ( ! self::is_mark( $chars[ $j ] ) ) {
				return $j;
			}
		}

		return null;
	}

	/**
	 * Whether the letter at an index connects to the letter after it.
	 *
	 * @param int|null $index Index into $chars, from neighbour().
	 * @param int[]    $chars Code points.
	 *
	 * @return bool
	 */
	protected static function joins_forward( $index, $chars ) {
		if ( null === $index ) {
			return false;
		}

		$char = $chars[ $index ];

		return self::TATWEEL === $char || ( isset( self::FORMS[ $char ] ) && 4 === count( self::FORMS[ $char ] ) );
	}

	/**
	 * Whether the letter at an index connects to the letter before it.
	 *
	 * @param int|null $index Index into $chars.
	 * @param int[]    $chars Code points.
	 *
	 * @return bool
	 */
	protected static function joins_backward( $index, $chars ) {
		if ( null === $index ) {
			return false;
		}

		$char = $chars[ $index ];

		return self::TATWEEL === $char || ( isset( self::FORMS[ $char ] ) && count( self::FORMS[ $char ] ) > 1 );
	}

	/**
	 * Whether a code point is a combining mark, which doesn't affect joining.
	 *
	 * @param int $char Code point.
	 *
	 * @return bool
	 */
	protected static function is_mark( $char ) {
		return ( $char >= 0x064B && $char <= 0x065F ) || 0x0670 === $char || ( $char >= 0x06D6 && $char <= 0x06ED );
	}
}
