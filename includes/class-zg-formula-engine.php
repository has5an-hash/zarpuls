<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** موتور محاسبه‌ی بدون eval؛ تنها عملگرهای پایه و متغیرهای مجاز پذیرفته می‌شوند. */
class ZG_Formula_Engine {
	public static function variables() {
		return array(
			'weight' => 'وزن (گرم)', 'base_18k' => 'قیمت هر گرم ۱۸ عیار', 'base_24k' => 'قیمت هر گرم ۲۴ عیار',
			'coin' => 'قیمت سکه', 'ounce' => 'اونس جهانی', 'exchange' => 'نرخ ارز', 'karat_factor' => 'ضریب عیار',
			'wage' => 'اجرت ثابت کل', 'wage_per_gram' => 'اجرت هر گرم', 'wage_percent' => 'اجرت درصدی',
			'stone' => 'هزینه سنگ', 'extra' => 'هزینه اضافی', 'packing' => 'بسته‌بندی',
			'shipping' => 'ارسال', 'insurance' => 'بیمه', 'manufacturing' => 'هزینه ساخت',
			'profit_percent' => 'سود درصدی', 'profit_fixed' => 'سود ثابت', 'tax_percent' => 'مالیات درصدی',
		);
	}

	public static function evaluate( $expression, $variables ) {
		$tokens = self::tokenize( $expression );
		$postfix = self::to_postfix( $tokens );
		$stack = array();
		$allowed = self::variables();
		foreach ( $postfix as $token ) {
			if ( is_numeric( $token ) ) {
				$stack[] = (float) $token;
				continue;
			}
			if ( isset( $allowed[ $token ] ) ) {
				$stack[] = isset( $variables[ $token ] ) ? (float) $variables[ $token ] : 0;
				continue;
			}
			if ( in_array( $token, array( '+', '-', '*', '/' ), true ) ) {
				if ( count( $stack ) < 2 ) { throw new Exception( 'ساختار فرمول کامل نیست.' ); }
				$b = array_pop( $stack ); $a = array_pop( $stack );
				switch ( $token ) {
					case '+': $stack[] = $a + $b; break;
					case '-': $stack[] = $a - $b; break;
					case '*': $stack[] = $a * $b; break;
					case '/':
						if ( abs( $b ) < 0.00000001 ) { throw new Exception( 'تقسیم بر صفر در فرمول مجاز نیست.' ); }
						$stack[] = $a / $b; break;
				}
				continue;
			}
			throw new Exception( 'توکن نامعتبر در فرمول.' );
		}
		if ( 1 !== count( $stack ) || ! is_finite( $stack[0] ) ) { throw new Exception( 'خروجی فرمول معتبر نیست.' ); }
		return max( 0, (float) $stack[0] );
	}

	private static function tokenize( $expression ) {
		$expression = trim( (string) $expression );
		if ( '' === $expression || strlen( $expression ) > 1200 ) { throw new Exception( 'فرمول خالی یا بیش از حد طولانی است.' ); }
		$offset = 0; $tokens = array();
		while ( $offset < strlen( $expression ) ) {
			if ( ! preg_match( '/\G\s*([0-9]+(?:\.[0-9]+)?|[a-z_][a-z0-9_]*|[()+\-*\/])\s*/Ai', $expression, $match, 0, $offset ) ) {
				throw new Exception( 'فرمول فقط از عدد، متغیرهای مجاز، پرانتز و عملگرهای + - * / تشکیل می‌شود.' );
			}
			$tokens[] = strtolower( $match[1] );
			$offset += strlen( $match[0] );
		}
		return $tokens;
	}

	private static function to_postfix( $tokens ) {
		$output = array(); $operators = array(); $precedence = array( '+' => 1, '-' => 1, '*' => 2, '/' => 2 );
		$expect_value = true;
		foreach ( $tokens as $token ) {
			if ( is_numeric( $token ) || isset( self::variables()[ $token ] ) ) {
				$output[] = $token; $expect_value = false; continue;
			}
			if ( '(' === $token ) { $operators[] = $token; $expect_value = true; continue; }
			if ( ')' === $token ) {
				$found = false;
				while ( ! empty( $operators ) ) { $op = array_pop( $operators ); if ( '(' === $op ) { $found = true; break; } $output[] = $op; }
				if ( ! $found ) { throw new Exception( 'پرانتزهای فرمول با هم مطابقت ندارند.' ); }
				$expect_value = false; continue;
			}
			if ( isset( $precedence[ $token ] ) ) {
				if ( $expect_value ) { throw new Exception( 'عملگر در جای نادرست قرار گرفته است.' ); }
				while ( ! empty( $operators ) && '(' !== end( $operators ) && $precedence[ end( $operators ) ] >= $precedence[ $token ] ) { $output[] = array_pop( $operators ); }
				$operators[] = $token; $expect_value = true; continue;
			}
			throw new Exception( 'متغیر ناشناخته در فرمول.' );
		}
		if ( $expect_value ) { throw new Exception( 'فرمول با یک مقدار پایان نیافته است.' ); }
		while ( ! empty( $operators ) ) { $op = array_pop( $operators ); if ( '(' === $op ) { throw new Exception( 'پرانتزهای فرمول با هم مطابقت ندارند.' ); } $output[] = $op; }
		return $output;
	}
}
