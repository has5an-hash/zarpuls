<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZG_Pricing {
	public static function formulas() {
		$formulas = get_option( 'zg_formulas', ZG_Activator::default_formulas() );
		return is_array( $formulas ) ? $formulas : array();
	}

	public static function get_formula( $id ) {
		$formulas = self::formulas();
		return isset( $formulas[ $id ] ) ? $formulas[ $id ] : false;
	}

	public static function product_config( $product_id, $posted = array() ) {
		$keys = array( 'enabled', 'product_type', 'weight', 'karat', 'stone', 'wage', 'wage_per_gram', 'wage_percent', 'profit_percent', 'profit_fixed', 'extra', 'packing', 'shipping', 'insurance', 'manufacturing', 'tax_percent', 'formula_id' );
		$config = array();
		foreach ( $keys as $key ) {
			$meta_key = '_zg_' . $key;
			$config[ $key ] = array_key_exists( $key, $posted ) ? $posted[ $key ] : get_post_meta( $product_id, $meta_key, true );
		}
		$config['enabled'] = ! empty( $config['enabled'] );
		$config['product_type'] = sanitize_key( $config['product_type'] ? $config['product_type'] : 'custom' );
		foreach ( array_diff( $keys, array( 'enabled', 'product_type', 'formula_id' ) ) as $key ) { $config[ $key ] = is_numeric( $config[ $key ] ) ? (float) $config[ $key ] : 0; }
		$config['formula_id'] = sanitize_key( $config['formula_id'] );

		// پروفایل دسته فقط در صورت خالی بودن تنظیم محصول نقش پیش‌فرض دارد.
		if ( empty( $config['formula_id'] ) || ! metadata_exists( 'post', $product_id, '_zg_profit_percent' ) ) {
			$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
			$category_ids = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
			foreach ( (array) $category_ids as $cat_id ) {
				if ( empty( $settings['category_profiles'][ $cat_id ] ) ) { continue; }
				$profile = $settings['category_profiles'][ $cat_id ];
				if ( empty( $config['formula_id'] ) && ! empty( $profile['formula_id'] ) ) { $config['formula_id'] = sanitize_key( $profile['formula_id'] ); }
				if ( ! metadata_exists( 'post', $product_id, '_zg_profit_percent' ) && isset( $profile['profit_percent'] ) ) { $config['profit_percent'] = (float) $profile['profit_percent']; }
				break;
			}
		}
		return $config;
	}

	public static function calculate( $product_id, $posted = array(), $snapshot = null ) {
		$config = self::product_config( $product_id, $posted );
		if ( empty( $config['enabled'] ) ) { throw new Exception( 'قیمت‌گذاری هوشمند برای این محصول فعال نشده است.' ); }
		$formula = self::get_formula( $config['formula_id'] );
		if ( ! $formula || empty( $formula['active'] ) ) { throw new Exception( 'فرمول انتخاب‌شده وجود ندارد یا غیرفعال است.' ); }
		$snapshot = null === $snapshot ? ZG_Sources::snapshot() : $snapshot;
		$prices = isset( $snapshot['prices'] ) ? $snapshot['prices'] : array();
		$vars = array(
			'weight' => $config['weight'], 'base_18k' => isset( $prices['18k'] ) ? $prices['18k'] : 0, 'base_24k' => isset( $prices['24k'] ) ? $prices['24k'] : 0,
			'coin' => isset( $prices['coin'] ) ? $prices['coin'] : 0, 'ounce' => isset( $prices['ounce'] ) ? $prices['ounce'] : 0,
			'exchange' => isset( $prices['exchange'] ) ? $prices['exchange'] : 0, 'karat_factor' => $config['karat'] > 0 ? $config['karat'] / 18 : 1,
			'wage' => $config['wage'], 'wage_per_gram' => $config['wage_per_gram'], 'wage_percent' => $config['wage_percent'],
			'stone' => $config['stone'], 'extra' => $config['extra'], 'packing' => $config['packing'], 
			'shipping' => $config['shipping'], 'insurance' => $config['insurance'], 'manufacturing' => $config['manufacturing'],
			'profit_percent' => $config['profit_percent'], 'profit_fixed' => $config['profit_fixed'], 'tax_percent' => $config['tax_percent'],
		);
		$value = ZG_Formula_Engine::evaluate( $formula['expression'], $vars );
		$final_price = ZG_Sources::maybe_round_thousand( $value );
		return array( 'price' => $final_price, 'raw_price' => $value, 'vars' => $vars, 'config' => $config, 'formula' => $formula, 'snapshot' => $snapshot );
	}

	/** برآورد سهم سود تعریف‌شده در خروجی فرمول جاری (تفاوت با حالت سود صفر). */
	public static function profit_impact( $product_id ) {
		try {
			$current = self::calculate( $product_id );
			$config = self::product_config( $product_id );
			$config['profit_percent'] = 0;
			$config['profit_fixed'] = 0;
			$without_profit = self::calculate( $product_id, $config );
			return max( 0, $current['raw_price'] - $without_profit['raw_price'] );
		} catch ( Exception $e ) {
			return 0;
		}
	}

	public static function sync_product( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) { return new WP_Error( 'zg_no_wc', 'ووکامرس فعال نیست.' ); }
		$product = wc_get_product( $product_id );
		if ( ! $product ) { return new WP_Error( 'zg_no_product', 'محصول پیدا نشد.' ); }
		try { $calculation = self::calculate( $product_id ); } catch ( Exception $e ) { self::set_product_error( $product_id, $e->getMessage() ); return new WP_Error( 'zg_formula', $e->getMessage() ); }
		if ( $calculation['price'] <= 0 ) { self::set_product_error( $product_id, 'قیمت نهایی صفر است؛ قیمت پایه یا فرمول را بررسی کنید.' ); return new WP_Error( 'zg_zero', 'قیمت نهایی صفر است.' ); }
		$product->set_regular_price( wc_format_decimal( $calculation['price'] ) );
		$product->set_sale_price( '' );
		$product->set_price( wc_format_decimal( $calculation['price'] ) );
		$product->save();
		update_post_meta( $product_id, '_zg_last_price', $calculation['price'] );
		update_post_meta( $product_id, '_zg_last_sync', current_time( 'mysql', true ) );
		delete_post_meta( $product_id, '_zg_last_error' );
		return $calculation;
	}

	public static function sync_all( $limit = 0 ) {
		if ( get_transient( 'zg_sync_lock' ) ) { return new WP_Error( 'zg_locked', 'یک بروزرسانی دیگر در حال اجرا است.' ); }
		set_transient( 'zg_sync_lock', 1, 90 );
		try {
			$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
			$limit = $limit ? absint( $limit ) : max( 1, absint( $settings['batch_size'] ) );
			$args = array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => array( 'publish', 'private' ), 'posts_per_page' => $limit, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_zg_enabled', 'value' => 'yes' ) ), 'no_found_rows' => false, 'orderby' => 'ID', 'order' => 'ASC' );
			$result = array( 'updated' => 0, 'errors' => array(), 'checked' => 0 );
			// پردازش صفحه‌ای باعث می‌شود محدودیت batch فقط مصرف حافظه را کنترل کند، نه تعداد کل محصولات بروزرسانی‌شده.
			$page = 1;
			do {
				$args['paged'] = $page;
				$query = new WP_Query( $args );
				$result['checked'] += count( $query->posts );
				foreach ( $query->posts as $product_id ) {
					$sync = self::sync_product( $product_id );
					if ( is_wp_error( $sync ) ) { $result['errors'][ $product_id ] = $sync->get_error_message(); } else { $result['updated']++; }
				}
				$max_pages = max( 1, (int) $query->max_num_pages );
				$page++;
			} while ( $page <= $max_pages );
			ZG_Activator::log( 'product_sync', sprintf( '%d محصول بروزرسانی و %d خطا ثبت شد.', $result['updated'], count( $result['errors'] ) ), empty( $result['errors'] ) ? 'info' : 'warning', $result );
			return $result;
		} finally {
			delete_transient( 'zg_sync_lock' );
		}
	}

	private static function set_product_error( $product_id, $message ) {
		update_post_meta( $product_id, '_zg_last_error', sanitize_text_field( $message ) );
	}
}
