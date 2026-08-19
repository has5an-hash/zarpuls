<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** محافظ سبک برای اطلاعات حساس منابع قیمت. */
class ZG_Crypto {
	public static function encrypt( $value ) {
		if ( '' === (string) $value ) { return ''; }
		if ( ! function_exists( 'openssl_encrypt' ) ) { return base64_encode( 'plain:' . $value ); }
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
		$encrypted = openssl_encrypt( (string) $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $encrypted ? '' : base64_encode( $encrypted );
	}

	public static function decrypt( $value ) {
		if ( '' === (string) $value ) { return ''; }
		$decoded = base64_decode( (string) $value, true );
		if ( false === $decoded ) { return ''; }
		if ( 0 === strpos( $decoded, 'plain:' ) ) { return substr( $decoded, 6 ); }
		if ( ! function_exists( 'openssl_decrypt' ) ) { return ''; }
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
		$plain = openssl_decrypt( $decoded, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}
}
