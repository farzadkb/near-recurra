<?php
/**
 * Exact integer-string arithmetic for token amounts. Never uses floats.
 *
 * All amounts are non-negative integers in base units, as decimal strings
 * (e.g. "12500000" = 12.5 USDC with 6 decimals). The helpers below are plain
 * string algorithms, so they work with or without bcmath and for any size.
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integer-string math helpers.
 */
final class Recurra_Amount {

	/**
	 * Whether $value is a canonical-or-padded unsigned integer string.
	 *
	 * @param mixed $value Value.
	 */
	public static function is_uint( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\d+$/', $value );
	}

	/**
	 * Removes leading zeros ("007" => "7", "000" => "0").
	 *
	 * @param string $value Unsigned integer string.
	 */
	public static function normalize( string $value ): string {
		$v = ltrim( $value, '0' );
		return '' === $v ? '0' : $v;
	}

	/**
	 * Converts a decimal string ("12.50") to base units ("12500000" for 6 decimals).
	 * Refuses (throws) if precision would be lost — money is never rounded silently.
	 *
	 * @param string $decimal  Non-negative decimal string, "." as separator.
	 * @param int    $decimals Token decimals.
	 * @throws InvalidArgumentException On malformed input or precision loss.
	 */
	public static function decimal_to_base( string $decimal, int $decimals ): string {
		$decimal = trim( $decimal );
		if ( 1 !== preg_match( '/^(\d+)(?:\.(\d+))?$/', $decimal, $m ) ) {
			throw new InvalidArgumentException( 'Invalid decimal amount: ' . esc_html( $decimal ) );
		}
		$int  = $m[1];
		$frac = isset( $m[2] ) ? rtrim( $m[2], '0' ) : '';
		if ( strlen( $frac ) > $decimals ) {
			throw new InvalidArgumentException( 'Amount has more precision than the token supports.' );
		}
		return self::normalize( $int . str_pad( $frac, $decimals, '0' ) );
	}

	/**
	 * Formats base units as a decimal string ("12500000", 6 => "12.5").
	 *
	 * @param string $base     Unsigned integer string.
	 * @param int    $decimals Token decimals.
	 */
	public static function base_to_decimal( string $base, int $decimals ): string {
		$base = self::normalize( $base );
		if ( 0 === $decimals ) {
			return $base;
		}
		$base = str_pad( $base, $decimals + 1, '0', STR_PAD_LEFT );
		$int  = substr( $base, 0, -$decimals );
		$frac = rtrim( substr( $base, -$decimals ), '0' );
		return '' === $frac ? $int : $int . '.' . $frac;
	}

	/**
	 * Compares two unsigned integer strings. Returns -1, 0 or 1.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 */
	public static function cmp( string $a, string $b ): int {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) < strlen( $b ) ? -1 : 1;
		}
		return $a <=> $b;
	}

	/**
	 * Multiplies an integer string by a non-negative int.
	 *
	 * @param string $a Unsigned integer string.
	 * @param int    $k Multiplier >= 0.

	 * @throws InvalidArgumentException On out-of-range arguments.
	 */
	public static function mul_small( string $a, int $k ): string {
		if ( $k < 0 ) {
			throw new InvalidArgumentException( 'Multiplier out of range.' );
		}
		return self::mul( $a, (string) $k );
	}

	/**
	 * Divides an integer string by a positive int, rounding up if $ceil.
	 *
	 * @param string $a    Unsigned integer string.
	 * @param int    $d    Divisor, 0 < d < 2^53.
	 * @param bool   $ceil Round up instead of down.

	 * @throws InvalidArgumentException On out-of-range arguments.
	 */
	public static function div_small( string $a, int $d, bool $ceil = false ): string {
		if ( $d <= 0 || $d >= 9007199254740992 ) {
			throw new InvalidArgumentException( 'Divisor out of range.' );
		}
		$out = '';
		$rem = 0;
		$len = strlen( $a );
		for ( $i = 0; $i < $len; $i++ ) {
			// $rem < $d < 2^53, so $rem * 10 + 9 stays below PHP_INT_MAX.
			$cur  = $rem * 10 + (int) $a[ $i ];
			$out .= (string) intdiv( $cur, $d );
			$rem  = $cur % $d;
		}
		$q = self::normalize( $out );
		if ( $ceil && $rem > 0 ) {
			$q = self::add( $q, '1' );
		}
		return $q;
	}

	/**
	 * Adds two unsigned integer strings.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 */
	public static function add( string $a, string $b ): string {
		$a     = strrev( $a );
		$b     = strrev( $b );
		$n     = max( strlen( $a ), strlen( $b ) );
		$out   = '';
		$carry = 0;
		for ( $i = 0; $i < $n; $i++ ) {
			$s     = ( isset( $a[ $i ] ) ? (int) $a[ $i ] : 0 ) + ( isset( $b[ $i ] ) ? (int) $b[ $i ] : 0 ) + $carry;
			$out  .= (string) ( $s % 10 );
			$carry = intdiv( $s, 10 );
		}
		if ( $carry ) {
			$out .= '1';
		}
		return self::normalize( strrev( $out ) );
	}

	/**
	 * Multiplies two unsigned integer strings (schoolbook).
	 *
	 * @param string $a A.
	 * @param string $b B.
	 */
	public static function mul( string $a, string $b ): string {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( '0' === $a || '0' === $b ) {
			return '0';
		}
		$la  = strlen( $a );
		$lb  = strlen( $b );
		$res = array_fill( 0, $la + $lb, 0 );
		for ( $i = $la - 1; $i >= 0; $i-- ) {
			for ( $j = $lb - 1; $j >= 0; $j-- ) {
				$sum                = $res[ $i + $j + 1 ] + ( (int) $a[ $i ] ) * ( (int) $b[ $j ] );
				$res[ $i + $j + 1 ] = $sum % 10;
				$res[ $i + $j ]    += intdiv( $sum, 10 );
			}
		}
		return self::normalize( implode( '', $res ) );
	}

	/**
	 * Divides by 10^$n, rounding down (or up if $ceil).
	 *
	 * @param string $a    Unsigned integer string.
	 * @param int    $n    Exponent >= 0.
	 * @param bool   $ceil Round up.
	 */
	public static function shift_down( string $a, int $n, bool $ceil = false ): string {
		$a = self::normalize( $a );
		if ( $n <= 0 ) {
			return $a;
		}
		$a    = str_pad( $a, $n + 1, '0', STR_PAD_LEFT );
		$q    = self::normalize( substr( $a, 0, -$n ) );
		$rest = trim( substr( $a, -$n ), '0' );
		return ( $ceil && '' !== $rest ) ? self::add( $q, '1' ) : $q;
	}

	/**
	 * Multiplies by 10^$n.
	 *
	 * @param string $a Unsigned integer string.
	 * @param int    $n Exponent >= 0.
	 */
	public static function shift( string $a, int $n ): string {
		return self::normalize( $a . str_repeat( '0', max( 0, $n ) ) );
	}
}
