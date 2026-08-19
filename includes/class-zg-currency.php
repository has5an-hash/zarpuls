<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZG_Currency {
	public static function store_unit() {
		$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		$mode = isset( $settings['currency_unit'] ) ? sanitize_key( $settings['currency_unit'] ) : 'auto';
		if ( in_array( $mode, array( 'rial', 'toman' ), true ) ) { return $mode; }
		$code = function_exists( 'get_woocommerce_currency' ) ? strtoupper( get_woocommerce_currency() ) : strtoupper( get_option( 'woocommerce_currency', 'IRR' ) );
		if ( 'IRR' === $code ) { return 'rial'; }
		if ( in_array( $code, array( 'IRT', 'TMN', 'TOMAN', 'IRTOMAN' ), true ) ) { return 'toman'; }
		$label = isset( $settings['currency_label'] ) ? $settings['currency_label'] : '';
		if ( false !== strpos( $label, 'تومان' ) || false !== stripos( $label, 'toman' ) ) { return 'toman'; }
		return 'rial';
	}

	public static function label() {
		$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		$mode = isset( $settings['currency_unit'] ) ? sanitize_key( $settings['currency_unit'] ) : 'auto';
		if ( 'manual' === $mode && ! empty( $settings['currency_label'] ) ) { return $settings['currency_label']; }
		return 'rial' === self::store_unit() ? 'ریال' : 'تومان';
	}

	public static function normalize_to_store( $value, $source_unit, $price_key = '' ) {
		$value = (float) $value;
		$source_unit = sanitize_key( $source_unit ? $source_unit : 'store' );
		if ( 'ounce' === $price_key || 'usd' === $source_unit ) { return $value; }
		$target = self::store_unit();
		if ( 'store' === $source_unit || $source_unit === $target ) { return $value; }
		if ( 'rial' === $source_unit && 'toman' === $target ) { return $value / 10; }
		if ( 'toman' === $source_unit && 'rial' === $target ) { return $value * 10; }
		return $value;
	}

	public static function source_unit( $source ) {
		$unit = isset( $source['unit'] ) ? sanitize_key( $source['unit'] ) : '';
		if ( in_array( $unit, array( 'rial', 'toman', 'store', 'usd' ), true ) ) { return $unit; }
		$type = isset( $source['type'] ) ? $source['type'] : '';
		if ( in_array( $type, array( 'tgju', 'auto_market' ), true ) ) { return 'rial'; }
		if ( in_array( $type, array( 'estjt', 'taline', 'moj3', 'kifpool', 'talasea', 'zariran' ), true ) ) { return 'toman'; }
		return 'store';
	}
}
