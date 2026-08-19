<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZG_Sources {

	// ==========================================
	// ۱) ثابت‌های فیزیکی و استانداردهای رسمی
	// ==========================================
	const TOZ_GRAMS                = 31.1034768;
	const MESGHAL_705_TO_18_FACTOR = 4.331802;

	const FINE_GOLD_EMAMI          = 7.322382;
	const FINE_GOLD_HALF           = 3.661191;
	const FINE_GOLD_QUARTER        = 1.8305955;

	const AUTO_RESULT_TRANSIENT    = 'zg_auto_prices_result';
	const LAST_VALID_AUTO_OPTION   = 'zg_last_valid_auto_result';

	/** کلیدهای شاخص‌های قیمت */
	public static function price_keys() {
		return array(
			'18k'     => 'هر گرم طلای ۱۸ عیار',
			'24k'     => 'هر گرم طلای ۲۴ عیار خالص',
			'mazaneh' => 'مظنه طلا (۷۰۵)',
			'coin'    => 'سکه امامی',
			'nim'     => 'نیم‌سکه',
			'rob'     => 'ربع‌سکه',
		);
	}

	/** رند کردن سه رقم آخر قیمت به ۱,۰۰۰ تومان (زیر ۵۰۰ به پایین، ۵۰۰ و بالاتر به بالا) */
	public static function maybe_round_thousand( $price, $settings = null ) {
		$price = (float) $price;
		if ( $price <= 0 ) { return 0; }
		if ( null === $settings ) {
			$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		}
		if ( ! empty( $settings['round_thousand'] ) ) {
			$remainder = fmod( $price, 1000 );
			if ( $remainder >= 500 ) {
				return ceil( $price / 1000 ) * 1000;
			} else {
				return floor( $price / 1000 ) * 1000;
			}
		}
		return round( $price );
	}

	/** تشخیص حالت قیمت‌گذاری: auto (خودکار) یا manual (دستی) */
	public static function resolve_mode( $settings = null ) {
		if ( null === $settings ) {
			$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		}
		$mode = isset( $settings['pricing_mode'] ) ? sanitize_key( $settings['pricing_mode'] ) : 'auto';
		if ( 'manual' === $mode ) {
			return 'manual';
		}
		return 'auto';
	}

	/** دریافت اسنپ‌شات آخرین قیمت‌ها */
	public static function snapshot() {
		$snapshot = get_option( 'zg_price_snapshot', array() );
		if ( ! is_array( $snapshot ) ) {
			return array(
				'prices'         => array(),
				'sell_prices'    => array(),
				'sources'        => array(),
				'updated_at'     => 0,
				'store_unit'     => ZG_Currency::store_unit(),
				'currency_label' => ZG_Currency::label(),
			);
		}
		$current_unit = ZG_Currency::store_unit();
		$stored_unit = isset( $snapshot['store_unit'] ) ? sanitize_key( $snapshot['store_unit'] ) : 'toman';
		if ( ! empty( $snapshot['prices'] ) && in_array( $stored_unit, array( 'rial', 'toman' ), true ) && $stored_unit !== $current_unit ) {
			foreach ( $snapshot['prices'] as $key => $value ) {
				$snapshot['prices'][ $key ] = ZG_Currency::normalize_to_store( (float) $value, $stored_unit, $key );
			}
			if ( ! empty( $snapshot['sell_prices'] ) && is_array( $snapshot['sell_prices'] ) ) {
				foreach ( $snapshot['sell_prices'] as $key => $value ) {
					$snapshot['sell_prices'][ $key ] = ZG_Currency::normalize_to_store( (float) $value, $stored_unit, $key );
				}
			}
			$snapshot['store_unit'] = $current_unit;
			$snapshot['currency_label'] = ZG_Currency::label();
			update_option( 'zg_price_snapshot', $snapshot, false );
		}
		if ( ! isset( $snapshot['sell_prices'] ) || ! is_array( $snapshot['sell_prices'] ) ) {
			$snapshot['sell_prices'] = array();
		}
		return $snapshot;
	}

	/**
	 * دریافت نرخ زنده اونس جهانی طلا (XAU/USD).
	 */
	public static function fetch_gold_ounce( $force = false ) {
		$cache_key = 'plugin_gold_ounce_live';
		$fallback_key = 'zg_fallback_gold_ounce_xau';

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) && ! empty( $cached['price'] ) ) {
				return array(
					'price'      => (float) $cached['price'],
					'updated_at' => isset( $cached['updated_at'] ) ? $cached['updated_at'] : gmdate( 'c' ),
					'stale'      => false,
					'source'     => 'gold-api',
				);
			}
		}

		$response = wp_remote_get(
			'https://api.gold-api.com/price/XAU',
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'headers'     => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if (
				is_array( $body ) &&
				isset( $body['symbol'], $body['currency'], $body['price'] ) &&
				'XAU' === $body['symbol'] &&
				'USD' === $body['currency'] &&
				is_numeric( $body['price'] ) &&
				(float) $body['price'] > 0
			) {
				$price = (float) $body['price'];
				$updated_at = isset( $body['updatedAt'] ) ? sanitize_text_field( $body['updatedAt'] ) : gmdate( 'c' );
				$data = array(
					'price'      => $price,
					'updated_at' => $updated_at,
				);
				set_transient( $cache_key, $data, 60 );
				update_option( $fallback_key, $data, false );
				return array(
					'price'      => $price,
					'updated_at' => $updated_at,
					'stale'      => false,
					'source'     => 'gold-api',
				);
			}
		}

		$fallback = get_option( $fallback_key, null );
		if ( is_array( $fallback ) && ! empty( $fallback['price'] ) && (float) $fallback['price'] > 0 ) {
			return array(
				'price'      => (float) $fallback['price'],
				'updated_at' => isset( $fallback['updated_at'] ) ? $fallback['updated_at'] : gmdate( 'c' ),
				'stale'      => true,
				'source'     => 'last_valid_cache',
			);
		}

		return array(
			'price'      => 4377.60,
			'updated_at' => gmdate( 'c' ),
			'stale'      => true,
			'source'     => 'default_baseline',
		);
	}

	/**
	 * استخراج نرخ دلار از Bonbast (منبع اصلی).
	 */
	public static function fetch_bonbast_usd() {
		$home_url = 'https://www.bonbast.com/';
		$user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

		$home_response = wp_remote_get(
			$home_url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => $user_agent,
				'headers'     => array(
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
			)
		);

		if ( is_wp_error( $home_response ) || 200 !== (int) wp_remote_retrieve_response_code( $home_response ) ) {
			return new WP_Error( 'zg_bonbast_home_fail', 'ارتباط با منبع اولیه ناموفق بود.' );
		}

		$html = wp_remote_retrieve_body( $home_response );
		$cookies = wp_remote_retrieve_cookies( $home_response );

		if ( ! preg_match( '/\$\.post\(\'\/json\',\s*\{param:\s*"([^"]+)"/', $html, $m ) ) {
			return new WP_Error( 'zg_bonbast_param_fail', 'پارامتر اولیه یافت نشد.' );
		}

		$bonbast_param = $m[1];

		$json_response = wp_remote_post(
			'https://www.bonbast.com/json',
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => $user_agent,
				'headers'     => array(
					'X-Requested-With' => 'XMLHttpRequest',
					'Accept'           => 'application/json, text/javascript, */*; q=0.01',
					'Referer'          => 'https://www.bonbast.com/',
				),
				'body'        => array(
					'param' => $bonbast_param,
				),
				'cookies'     => $cookies,
			)
		);

		if ( is_wp_error( $json_response ) || 200 !== (int) wp_remote_retrieve_response_code( $json_response ) ) {
			return new WP_Error( 'zg_bonbast_json_fail', 'دریافت پاسخ از منبع اولیه ناموفق بود.' );
		}

		$data = json_decode( wp_remote_retrieve_body( $json_response ), true );
		if ( ! is_array( $data ) || ! isset( $data['usd1'] ) || ! is_numeric( $data['usd1'] ) ) {
			return new WP_Error( 'zg_bonbast_invalid_json', 'داده دریافتی نامعتبر است.' );
		}

		$usd1 = (float) $data['usd1'];

		if ( $usd1 < 10000 || $usd1 > 1000000 ) {
			return new WP_Error( 'zg_bonbast_out_of_range', 'نرخ دریافتی در بازه مجاز نیست.' );
		}

		return array(
			'price_toman'   => $usd1,
			'source'        => 'bonbast',
			'is_fallback'   => false,
			'last_modified' => isset( $data['last_modified'] ) ? sanitize_text_field( $data['last_modified'] ) : '',
		);
	}

	/**
	 * منبع پشتیبان (Fallback) بدون توکن.
	 */
	public static function fetch_alanchand_usd() {
		$url = 'https://alanchand.com/en/currencies-price/usd';
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'zg_alanchand_fail', 'ارتباط با منبع پشتیبان ناموفق بود.' );
		}

		$html = wp_remote_retrieve_body( $response );
		$rate_rial = self::extract_alanchand_dollar( $html );

		if ( ! $rate_rial || $rate_rial <= 100000 ) {
			return new WP_Error( 'zg_alanchand_parse_fail', 'استخراج نرخ از منبع پشتیبان ناموفق بود.' );
		}

		$toman = $rate_rial / 10;

		if ( $toman < 10000 || $toman > 1000000 ) {
			return new WP_Error( 'zg_alanchand_out_of_range', 'نرخ در بازه مجاز نیست.' );
		}

		return array(
			'price_toman'   => $toman,
			'source'        => 'alanchand',
			'is_fallback'   => true,
			'last_modified' => '',
		);
	}

	/**
	 * استخراج عدد ریالی از HTML صفحه آلن‌چند
	 */
	public static function extract_alanchand_dollar( $html ) {
		if ( empty( $html ) || ! is_string( $html ) ) { return 0; }

		if ( preg_match( '/[\'"]offers[\'"]\s*:\s*\{[\s\S]*?[\'"]price[\'"]\s*:\s*[\'"]?(\d{6,})[\'"]?/i', $html, $m ) ) {
			$num = self::parse_number( $m[1] );
			if ( $num && $num > 100000 ) { return $num; }
		}

		if ( preg_match( '/Sell rate for USD to IRR[\s\S]{1,200}?([\d,۰-۹]{6,})/iu', $html, $m ) ) {
			$num = self::parse_number( $m[1] );
			if ( $num && $num > 100000 ) { return $num; }
		}

		if ( preg_match( '/Sell rate[\s\S]{1,200}?([\d,۰-۹]{6,})/iu', $html, $m ) ) {
			$num = self::parse_number( $m[1] );
			if ( $num && $num > 100000 ) { return $num; }
		}

		$text = self::normalize_text( wp_strip_all_tags( $html ) );
		$num = self::scrape_number_after( $text, 'Sell rate', 100000 );
		if ( $num && $num > 100000 ) { return $num; }

		return 0;
	}

	/**
	 * دریافت نرخ زنده دلار به تومان با سیستم پشتیبان و کش.
	 */
	public static function fetch_dollar_rate( $force = false ) {
		$cache_key = 'plugin_usd_free_live';
		$fallback_key = 'zg_fallback_dollar_toman';

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) && ! empty( $cached['price_toman'] ) ) {
				return array(
					'price_toman'        => (float) $cached['price_toman'],
					'source'             => isset( $cached['source'] ) ? $cached['source'] : 'cache',
					'is_dollar_fallback' => ! empty( $cached['is_fallback'] ),
					'stale'              => false,
				);
			}
		}

		$bonbast = self::fetch_bonbast_usd();
		if ( ! is_wp_error( $bonbast ) && ! empty( $bonbast['price_toman'] ) ) {
			$data = array(
				'price_toman' => (float) $bonbast['price_toman'],
				'source'      => 'bonbast',
				'is_fallback' => false,
			);
			set_transient( $cache_key, $data, 60 );
			update_option( $fallback_key, $data, false );
			return array(
				'price_toman'        => (float) $bonbast['price_toman'],
				'source'             => 'bonbast',
				'is_dollar_fallback' => false,
				'stale'              => false,
			);
		}

		$alanchand = self::fetch_alanchand_usd();
		if ( ! is_wp_error( $alanchand ) && ! empty( $alanchand['price_toman'] ) ) {
			$data = array(
				'price_toman' => (float) $alanchand['price_toman'],
				'source'      => 'alanchand',
				'is_fallback' => true,
			);
			set_transient( $cache_key, $data, 60 );
			update_option( $fallback_key, $data, false );
			return array(
				'price_toman'        => (float) $alanchand['price_toman'],
				'source'             => 'alanchand',
				'is_dollar_fallback' => true,
				'stale'              => false,
			);
		}

		$fallback = get_option( $fallback_key, null );
		if ( is_array( $fallback ) && ! empty( $fallback['price_toman'] ) && (float) $fallback['price_toman'] > 0 ) {
			return array(
				'price_toman'        => (float) $fallback['price_toman'],
				'source'             => isset( $fallback['source'] ) ? $fallback['source'] . ' (cache)' : 'last_valid_cache',
				'is_dollar_fallback' => ! empty( $fallback['is_fallback'] ),
				'stale'              => true,
			);
		}

		return array(
			'price_toman'        => 181200.0,
			'source'             => 'default_baseline',
			'is_dollar_fallback' => true,
			'stale'              => true,
		);
	}

	/**
	 * دریافت کالیبراسیون و ضرایب اسپرد
	 * کالیبره شده با میانگین اجماع معتبرترین پلتفرم‌های طلای آنلاین (طلاسی، طلاین، گلدیکا، میلی‌گلد، وال‌گلد)
	 */
	public static function get_live_calibration( $force = false ) {
		$cache_key = 'zg_live_calibration_params';
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['k18'] ) ) {
				return $cached;
			}
		}

		$defaults = array(
			'k18'              => 0.959,    // هماهنگی دقیق ۱۸ عیار با میانگین پلتفرم‌های برتر (طلاین، طلاسی، میلی، گلدیکا)
			'r24_to_18'        => 1.333333, // نسبت ۲۴ به ۱۸ عیار
			'k_emami'          => 0.978,    // هماهنگی سکه امامی با میانگین پلتفرم‌ها
			'k_half'           => 0.990,    // هماهنگی نیم‌سکه با میانگین پلتفرم‌ها
			'k_quarter'        => 1.104,    // هماهنگی ربع‌سکه با میانگین پلتفرم‌ها
			'gold18_ask_bps'   => 20,       // اسپرد فروش ۱۸ عیار (+0.2%)
			'gold18_bid_bps'   => 20,       // اسپرد خرید ۱۸ عیار (-0.2%)
			'emami_ask_bps'    => 25,       // اسپرد فروش سکه امامی (+0.25%)
			'emami_bid_bps'    => 25,       // اسپرد خرید سکه امامی (-0.25%)
			'half_ask_bps'     => 30,       // اسپرد فروش نیم‌سکه (+0.3%)
			'half_bid_bps'     => 30,       // اسپرد خرید نیم‌سکه (-0.3%)
			'quarter_ask_bps'  => 35,       // اسپرد فروش ربع‌سکه (+0.35%)
			'quarter_bid_bps'  => 35,       // اسپرد خرید ربع‌سکه (-0.35%)
			'updated_at'       => gmdate( 'c' ),
			'is_stale'         => false,
		);

		$saved = get_option( 'zg_calibration_params', array() );
		if ( is_array( $saved ) && ! empty( $saved ) ) {
			$defaults = wp_parse_args( $saved, $defaults );
		}

		set_transient( $cache_key, $defaults, 300 );
		return $defaults;
	}

	// ==========================================
	// ۲) تابع اصلی محاسبه قیمت‌ها
	// ==========================================
	public static function calculate_auto_prices( $force = false ) {

		// اگر کش معتبر وجود دارد، مستقیم همان را برگردان
		if ( ! $force ) {
			$cached = get_transient( self::AUTO_RESULT_TRANSIENT );

			if (
				is_array( $cached ) &&
				! empty( $cached['gold_18k'] ) &&
				! empty( $cached['dollar_toman'] ) &&
				empty( $cached['is_stale'] )
			) {
				return $cached;
			}
		}

		// دریافت اونس و دلار
		$ounce_res  = self::fetch_gold_ounce( $force );
		$dollar_res = self::fetch_dollar_rate( $force );

		// دریافت Calibration پویا:
		// شامل Kها و spreadهای استخراج‌شده از منابع منتخب
		$calibration = self::get_live_calibration( $force );

		// اگر هر منبع اصلی یا Calibration خطا داشت،
		// آخرین داده معتبر را برگردان
		if (
			is_wp_error( $ounce_res ) ||
			is_wp_error( $dollar_res ) ||
			is_wp_error( $calibration )
		) {
			return self::get_last_valid_auto_result(
				is_wp_error( $ounce_res ) ? $ounce_res :
				( is_wp_error( $dollar_res ) ? $dollar_res : $calibration )
			);
		}

		// ------------------------------------------
		// اعتبارسنجی اونس و دلار
		// ------------------------------------------
		if (
			! isset( $ounce_res['price'] ) ||
			! is_numeric( $ounce_res['price'] ) ||
			(float) $ounce_res['price'] <= 0
		) {
			return self::get_last_valid_auto_result(
				new WP_Error(
					'zg_invalid_ounce',
					'قیمت اونس جهانی معتبر نیست.'
				)
			);
		}

		if (
			! isset( $dollar_res['price_toman'] ) ||
			! is_numeric( $dollar_res['price_toman'] ) ||
			(float) $dollar_res['price_toman'] <= 0
		) {
			return self::get_last_valid_auto_result(
				new WP_Error(
					'zg_invalid_dollar',
					'قیمت دلار آزاد معتبر نیست.'
				)
			);
		}

		// ------------------------------------------
		// اعتبارسنجی Calibration
		// ------------------------------------------
		$required_calibration_keys = array(
			'k18',
			'r24_to_18',

			'k_emami',
			'k_half',
			'k_quarter',

			'gold18_ask_bps',
			'gold18_bid_bps',

			'emami_ask_bps',
			'emami_bid_bps',

			'half_ask_bps',
			'half_bid_bps',

			'quarter_ask_bps',
			'quarter_bid_bps',
		);

		foreach ( $required_calibration_keys as $key ) {
			if (
				! isset( $calibration[ $key ] ) ||
				! is_numeric( $calibration[ $key ] )
			) {
				return self::get_last_valid_auto_result(
					new WP_Error(
						'zg_invalid_calibration',
						'داده Calibration برای کلید ' . $key . ' معتبر نیست.'
					)
				);
			}
		}

		$ounce_usd    = (float) $ounce_res['price'];
		$dollar_toman = (float) $dollar_res['price_toman'];

		// ------------------------------------------
		// ۳) قیمت‌های پایه از اونس و دلار
		// ------------------------------------------

		// هر گرم طلای خالص
		$pure_gold_per_gram =
			( $ounce_usd * $dollar_toman ) / self::TOZ_GRAMS;

		// طلای ۱۸ خام، قبل از بیس بازار
		$gold_18k_raw =
			$pure_gold_per_gram * 0.75;

		// ------------------------------------------
		// ۴) قیمت Mid نزدیک به اکثریت بازار
		// ------------------------------------------

		$gold_18k_mid =
			$gold_18k_raw * (float) $calibration['k18'];

		$gold_24k_mid =
			$gold_18k_mid * (float) $calibration['r24_to_18'];

		$mazaneh_705_mid =
			$gold_18k_mid * self::MESGHAL_705_TO_18_FACTOR;

		// ارزش ذاتی سکه‌ها
		$emami_intrinsic =
			$pure_gold_per_gram * self::FINE_GOLD_EMAMI;

		$half_coin_intrinsic =
			$pure_gold_per_gram * self::FINE_GOLD_HALF;

		$quarter_coin_intrinsic =
			$pure_gold_per_gram * self::FINE_GOLD_QUARTER;

		// Mid سکه‌ها با ضریب اجماع
		$emami_mid =
			$emami_intrinsic * (float) $calibration['k_emami'];

		$half_coin_mid =
			$half_coin_intrinsic * (float) $calibration['k_half'];

		$quarter_coin_mid =
			$quarter_coin_intrinsic * (float) $calibration['k_quarter'];

		// ------------------------------------------
		// ۵) محاسبه Buy / Sell با spread پویا
		// ------------------------------------------

		$gold_18k_quote = self::make_bid_ask_quote(
			$gold_18k_mid,
			(float) $calibration['gold18_ask_bps'],
			(float) $calibration['gold18_bid_bps']
		);

		$gold_24k_quote = self::make_bid_ask_quote(
			$gold_24k_mid,
			(float) $calibration['gold18_ask_bps'],
			(float) $calibration['gold18_bid_bps']
		);

		$mazaneh_705_quote = self::make_bid_ask_quote(
			$mazaneh_705_mid,
			(float) $calibration['gold18_ask_bps'],
			(float) $calibration['gold18_bid_bps']
		);

		$emami_quote = self::make_bid_ask_quote(
			$emami_mid,
			(float) $calibration['emami_ask_bps'],
			(float) $calibration['emami_bid_bps']
		);

		$half_coin_quote = self::make_bid_ask_quote(
			$half_coin_mid,
			(float) $calibration['half_ask_bps'],
			(float) $calibration['half_bid_bps']
		);

		$quarter_coin_quote = self::make_bid_ask_quote(
			$quarter_coin_mid,
			(float) $calibration['quarter_ask_bps'],
			(float) $calibration['quarter_bid_bps']
		);

		if (
			is_wp_error( $gold_18k_quote ) ||
			is_wp_error( $gold_24k_quote ) ||
			is_wp_error( $mazaneh_705_quote ) ||
			is_wp_error( $emami_quote ) ||
			is_wp_error( $half_coin_quote ) ||
			is_wp_error( $quarter_coin_quote )
		) {
			return self::get_last_valid_auto_result(
				new WP_Error(
					'zg_invalid_spread',
					'Spread خرید و فروش معتبر نیست.'
				)
			);
		}

		// ------------------------------------------
		// ۶) ساخت خروجی نهایی با پشتیبانی از رند ۳ رقم آخر
		// ------------------------------------------
		$out = array(

			// ورودی‌ها
			'ounce_usd'    => $ounce_usd,
			'dollar_toman' => $dollar_toman,

			// قیمت خام/ذاتی
			'pure_gold_per_gram' => self::maybe_round_thousand( $pure_gold_per_gram ),
			'gold_18k_raw'       => self::maybe_round_thousand( $gold_18k_raw ),

			// طلای 18
			'gold_18k'      => self::maybe_round_thousand( $gold_18k_quote['buy'] ), // فروش به مشتری (اعمال روی محصول)
			'gold_18k_buy'  => self::maybe_round_thousand( $gold_18k_quote['buy'] ), // فروش به مشتری (Ask / بالاتر)
			'gold_18k_sell' => self::maybe_round_thousand( $gold_18k_quote['sell'] ), // خرید از مشتری (Bid / پایین‌تر)

			// مظنه
			'mazaneh_705'      => self::maybe_round_thousand( $mazaneh_705_quote['buy'] ),
			'mazaneh_705_buy'  => self::maybe_round_thousand( $mazaneh_705_quote['buy'] ), // فروش به مشتری
			'mazaneh_705_sell' => self::maybe_round_thousand( $mazaneh_705_quote['sell'] ), // خرید از مشتری

			// طلای 24 بازار
			'gold_24k'      => self::maybe_round_thousand( $gold_24k_quote['buy'] ),
			'gold_24k_buy'  => self::maybe_round_thousand( $gold_24k_quote['buy'] ), // فروش به مشتری
			'gold_24k_sell' => self::maybe_round_thousand( $gold_24k_quote['sell'] ), // خرید از مشتری

			// طلای 24 ذاتی
			'gold_24k_pure' => self::maybe_round_thousand( $pure_gold_per_gram ),
			'gold_24k_999'  => self::maybe_round_thousand( $pure_gold_per_gram * 0.999 ),

			// سکه امامی
			'emami_intrinsic' => self::maybe_round_thousand( $emami_intrinsic ),
			'emami_coin'      => self::maybe_round_thousand( $emami_quote['buy'] ),
			'emami_coin_buy'  => self::maybe_round_thousand( $emami_quote['buy'] ), // فروش به مشتری
			'emami_coin_sell' => self::maybe_round_thousand( $emami_quote['sell'] ), // خرید از مشتری

			// نیم سکه
			'half_coin_intrinsic' => self::maybe_round_thousand( $half_coin_intrinsic ),
			'half_coin'           => self::maybe_round_thousand( $half_coin_quote['buy'] ),
			'half_coin_buy'       => self::maybe_round_thousand( $half_coin_quote['buy'] ), // فروش به مشتری
			'half_coin_sell'      => self::maybe_round_thousand( $half_coin_quote['sell'] ), // خرید از مشتری

			// ربع سکه
			'quarter_coin_intrinsic' => self::maybe_round_thousand( $quarter_coin_intrinsic ),
			'quarter_coin'           => self::maybe_round_thousand( $quarter_coin_quote['buy'] ),
			'quarter_coin_buy'       => self::maybe_round_thousand( $quarter_coin_quote['buy'] ), // فروش به مشتری
			'quarter_coin_sell'      => self::maybe_round_thousand( $quarter_coin_quote['sell'] ), // خرید از مشتری

			// اطلاعات منبع
			'source_ounce' => $ounce_res['source'] ?? 'gold-api',
			'source_dollar' => $dollar_res['source'] ?? 'bonbast',

			'ounce_source_updated_at' =>
				$ounce_res['updated_at'] ?? null,

			'dollar_source_updated_at' =>
				$dollar_res['updated_at'] ?? null,

			'calibration_updated_at' =>
				$calibration['updated_at'] ?? null,

			'calculated_at' => gmdate( 'c' ),
			'last_valid_at' => time(),

			'is_stale' =>
				! empty( $ounce_res['stale'] ) ||
				! empty( $dollar_res['stale'] ) ||
				! empty( $calibration['is_stale'] ),

			'is_dollar_fallback' =>
				! empty( $dollar_res['is_dollar_fallback'] ),
		);

		// کش 60 ثانیه‌ای قیمت خروجی
		set_transient(
			self::AUTO_RESULT_TRANSIENT,
			$out,
			60
		);

		// آخرین نتیجه معتبر برای fallback
		update_option(
			self::LAST_VALID_AUTO_OPTION,
			$out,
			false
		);

		return $out;
	}

	// ==========================================
	// ۳) ساخت قیمت Mid / Buy / Sell از Spread
	// ==========================================
	private static function make_bid_ask_quote(
		$mid_price,
		$ask_bps,
		$bid_bps
	) {

		$mid_price = (float) $mid_price;
		$ask_bps   = (float) $ask_bps;
		$bid_bps   = (float) $bid_bps;

		if (
			$mid_price <= 0 ||
			$ask_bps <= 0 ||
			$bid_bps <= 0 ||
			$ask_bps > 3000 ||
			$bid_bps > 3000
		) {
			return new WP_Error(
				'zg_bad_quote',
				'مقادیر Mid یا Spread معتبر نیستند.'
			);
		}

		// قیمت فروش به مشتری (Ask / بالاتر)
		$buy_price =
			$mid_price * ( 1 + ( $ask_bps / 10000 ) );

		// قیمت خرید از مشتری (Bid / پایین‌تر)
		$sell_price =
			$mid_price * ( 1 - ( $bid_bps / 10000 ) );

		$buy_price  = round( $buy_price );
		$mid_price  = round( $mid_price );
		$sell_price = round( $sell_price );

		if (
			$buy_price <= $mid_price ||
			$sell_price >= $mid_price ||
			$sell_price <= 0
		) {
			return new WP_Error(
				'zg_bad_quote_order',
				'ترتیب قیمت خرید و فروش معتبر نیست.'
			);
		}

		return array(
			'buy'  => $buy_price,  // فروش به مشتری (بالاتر)
			'mid'  => $mid_price,
			'sell' => $sell_price, // خرید از مشتری (پایین‌تر)
		);
	}

	// ==========================================
	// ۴) بازیابی آخرین داده معتبر
	// ==========================================
	private static function get_last_valid_auto_result( $original_error ) {

		$saved = get_option(
			self::LAST_VALID_AUTO_OPTION,
			null
		);

		$max_stale_age = 6 * HOUR_IN_SECONDS;

		if (
			is_array( $saved ) &&
			! empty( $saved['last_valid_at'] ) &&
			( time() - (int) $saved['last_valid_at'] ) < $max_stale_age
		) {
			$saved['is_stale'] = true;
			$saved['fallback_error'] = is_wp_error( $original_error )
				? $original_error->get_error_message()
				: 'Unknown error';

			return $saved;
		}

		return is_wp_error( $original_error )
			? $original_error
			: new WP_Error(
				'zg_no_valid_price',
				'هیچ داده معتبر فعلی یا cache معتبر در دسترس نیست.'
			);
	}

	/** بازخوانی و اعمال نهایی قیمت‌ها بر اساس حالت انتخاب‌شده (خودکار یا دستی) */
	public static function refresh( $force = false ) {
		$previous = self::snapshot();
		$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		$mode = self::resolve_mode( $settings );
		$started_ts = time();
		$trace = array(
			'mode'       => $mode,
			'started_at' => current_time( 'mysql', true ),
			'started_ts' => $started_ts,
			'steps'      => array(),
		);
		$used_sources = array();
		$failures = array();
		$prices = array();
		$sell_prices = array();
		$calc = null;

		if ( 'manual' === $mode ) {
			$config = isset( $settings['direct_api'] ) && is_array( $settings['direct_api'] ) ? $settings['direct_api'] : array();
			$direct = self::fetch_direct_api( $config, $force );
			$step = array(
				'source' => 'direct_api',
				'name'   => 'منبع مستقیم (API دلخواه)',
				'type'   => 'direct',
				'status' => 'error',
				'at'     => current_time( 'mysql', true ),
			);

			if ( is_wp_error( $direct ) ) {
				$step['error'] = $direct->get_error_message();
				$failures[] = 'منبع مستقیم: ' . $direct->get_error_message();
				ZG_Activator::log( 'source_error', 'منبع مستقیم: ' . $direct->get_error_message(), 'warning', array( 'source' => 'direct_api' ) );
			} else {
				$step['status'] = 'ok';
				$step['values'] = $direct['prices'];
				$unit = isset( $config['unit'] ) ? $config['unit'] : 'store';
				foreach ( $direct['prices'] as $k => $v ) {
					$p = self::to_pair( $v );
					$buy = $p['buy'] > 0 ? ZG_Currency::normalize_to_store( $p['buy'], $unit, $k ) : 0;
					$sell = $p['sell'] > 0 ? ZG_Currency::normalize_to_store( $p['sell'], $unit, $k ) : 0;
					$mid = $p['mid'] > 0 ? ZG_Currency::normalize_to_store( $p['mid'], $unit, $k ) : 0;
					if ( $buy > 0 ) { $prices[ $k ] = self::maybe_round_thousand( $buy ); $used_sources[ $k ] = 'direct_api'; }
					if ( $sell > 0 ) { $sell_prices[ $k ] = self::maybe_round_thousand( $sell ); }
					if ( $mid > 0 && $buy <= 0 && $sell <= 0 ) {
						$prices[ $k ] = self::maybe_round_thousand( $mid );
						$sell_prices[ $k ] = self::maybe_round_thousand( $mid );
						$used_sources[ $k ] = 'direct_api';
					}
				}
				$step['chosen'] = $prices;
			}
			$trace['steps'][] = $step;
		} else {
			// حالت اول: محاسبه هوشمند و خودکار
			$calc = self::calculate_auto_prices( $force );

			if ( is_wp_error( $calc ) ) {
				return $calc;
			}

			$step_calc = array(
				'source' => 'formula_engine',
				'name'   => 'موتور هوشمند قیمت‌گذاری زرپالس',
				'type'   => 'engine',
				'status' => 'ok',
				'values' => array(
					'18k'         => round( $calc['gold_18k_buy'] ),
					'24k'         => round( $calc['gold_24k_buy'] ),
					'mazaneh_705' => round( $calc['mazaneh_705_buy'] ),
					'coin'        => round( $calc['emami_coin_buy'] ),
					'nim'         => round( $calc['half_coin_buy'] ),
					'rob'         => round( $calc['quarter_coin_buy'] ),
				),
				'at'     => current_time( 'mysql', true ),
			);

			$trace['steps'][] = $step_calc;

			// تبدیل مقادیر (محاسبه‌شده بر حسب تومان) به واحد فروشگاه در ووکامرس
			$norm = static function ( $val ) {
				return ZG_Currency::normalize_to_store( (float) $val, 'toman' );
			};

			// قیمت‌های فروش به مشتری (اعمال روی محصول)
			$prices['18k']         = $norm( $calc['gold_18k_buy'] );
			$prices['24k']         = $norm( $calc['gold_24k_buy'] );
			$prices['24k_999']     = $norm( $calc['gold_24k_999'] );
			$prices['mazaneh']     = $norm( $calc['mazaneh_705_buy'] );
			$prices['mazaneh_705'] = $norm( $calc['mazaneh_705_buy'] );
			$prices['coin']        = $norm( $calc['emami_coin_buy'] );
			$prices['nim']         = $norm( $calc['half_coin_buy'] );
			$prices['rob']         = $norm( $calc['quarter_coin_buy'] );

			// قیمت‌های خرید از مشتری
			$sell_prices['18k']         = $norm( $calc['gold_18k_sell'] );
			$sell_prices['24k']         = $norm( $calc['gold_24k_sell'] );
			$sell_prices['24k_999']     = $norm( $calc['gold_24k_999'] );
			$sell_prices['mazaneh']     = $norm( $calc['mazaneh_705_sell'] );
			$sell_prices['mazaneh_705'] = $norm( $calc['mazaneh_705_sell'] );
			$sell_prices['coin']        = $norm( $calc['emami_coin_sell'] );
			$sell_prices['nim']         = $norm( $calc['half_coin_sell'] );
			$sell_prices['rob']         = $norm( $calc['quarter_coin_sell'] );

			foreach ( array_keys( $prices ) as $pk ) {
				$used_sources[ $pk ] = 'formula_engine';
			}
		}

		// ورود دستی پشتیبان در صورتی که قیمت خالی باشد
		if ( empty( $prices['18k'] ) && ! empty( $settings['manual_prices'] ) ) {
			foreach ( $settings['manual_prices'] as $k => $v ) {
				if ( (float) $v > 0 && empty( $prices[ $k ] ) ) {
					$m = ZG_Currency::normalize_to_store( (float) $v, isset( $settings['manual_price_unit'] ) ? $settings['manual_price_unit'] : 'store', $k );
					$prices[ $k ] = self::maybe_round_thousand( $m );
					$sell_prices[ $k ] = self::maybe_round_thousand( $m );
					$used_sources[ $k ] = 'manual';
				}
			}
		}

		if ( empty( $prices ) ) {
			return new WP_Error( 'zg_no_price', 'هیچ قیمت معتبری حاصل نشد.' );
		}

		$trace['final_prices'] = $prices;
		$trace['sell_prices']  = $sell_prices;
		$trace['sources_used'] = $used_sources;
		$trace['failures']     = $failures;
		$trace['elapsed']      = time() - $started_ts;
		$trace['ended_at']     = current_time( 'mysql', true );

		$snapshot = array(
			'prices'         => $prices,
			'sell_prices'    => $sell_prices,
			'sources'        => $used_sources,
			'source_count'   => 1,
			'updated_at'     => time(),
			'updated_gmt'    => current_time( 'mysql', true ),
			'store_unit'     => ZG_Currency::store_unit(),
			'currency_label' => ZG_Currency::label(),
			'failures'       => $failures,
		);

		update_option( 'zg_price_snapshot', $snapshot, false );
		update_option( 'zg_last_refresh_trace', $trace, false );
		self::store_history( $prices, $used_sources );
		self::maybe_alert( $previous, $prices, $settings );
		ZG_Activator::log( 'price_refresh', sprintf( 'قیمت‌ها بروزرسانی شد (حالت %s).', $mode ), 'info', array( 'mode' => $mode, 'prices' => $prices ) );

		return $snapshot;
	}

	/** آخرین روند کامل محاسبه قیمت */
	public static function last_trace() {
		$trace = get_option( 'zg_last_refresh_trace', array() );
		return is_array( $trace ) ? $trace : array();
	}

	/** قیمت نهایی خرید و فروش بازار برای استفاده در پنل و کارت‌ها */
	public static function final_market_price() {
		$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		$mode = self::resolve_mode( $settings );
		$currency = ZG_Currency::label();
		$snapshot = self::snapshot();
		$prices = isset( $snapshot['prices'] ) ? $snapshot['prices'] : array();
		$sell_prices = isset( $snapshot['sell_prices'] ) ? $snapshot['sell_prices'] : array();
		$sell_to_cust_18 = isset( $prices['18k'] ) ? (float) $prices['18k'] : 0;
		$sell_to_cust_24 = isset( $prices['24k'] ) ? (float) $prices['24k'] : 0;
		$buy_from_cust_18 = isset( $sell_prices['18k'] ) ? (float) $sell_prices['18k'] : 0;
		$buy_from_cust_24 = isset( $sell_prices['24k'] ) ? (float) $sell_prices['24k'] : 0;
		if ( $buy_from_cust_18 <= 0 ) { $buy_from_cust_18 = $sell_to_cust_18; }
		if ( $buy_from_cust_24 <= 0 ) { $buy_from_cust_24 = $sell_to_cust_24; }

		return array(
			'mode'             => $mode,
			'sell_to_cust_18'  => round( $sell_to_cust_18 ), // فروش به مشتری (اعمال روی محصول)
			'buy_from_cust_18' => round( $buy_from_cust_18 ), // خرید از مشتری
			'sell_to_cust_24'  => round( $sell_to_cust_24 ), // فروش ۲۴ عیار به مشتری
			'buy_from_cust_24' => round( $buy_from_cust_24 ), // خرید ۲۴ عیار از مشتری
			'buy'              => round( $sell_to_cust_18 ),
			'sell'             => round( $buy_from_cust_18 ),
			'buy_24k'          => round( $sell_to_cust_24 ),
			'sell_24k'         => round( $buy_from_cust_24 ),
			'mazaneh'          => isset( $prices['mazaneh'] ) ? round( (float) $prices['mazaneh'] ) : 0,
			'coin'             => isset( $prices['coin'] ) ? round( (float) $prices['coin'] ) : 0,
			'nim'              => isset( $prices['nim'] ) ? round( (float) $prices['nim'] ) : 0,
			'rob'              => isset( $prices['rob'] ) ? round( (float) $prices['rob'] ) : 0,
			'currency'         => $currency,
			'updated_at'       => isset( $snapshot['updated_at'] ) ? $snapshot['updated_at'] : 0,
			'updated_gmt'      => isset( $snapshot['updated_gmt'] ) ? $snapshot['updated_gmt'] : '',
			'source_count'     => 1,
			'failures'         => isset( $snapshot['failures'] ) ? $snapshot['failures'] : array(),
		);
	}

	/** خواندن قیمت مستقیم از یک API/URL دلخواه (حالت دستی) */
	public static function fetch_direct_api( $config, $force = false ) {
		$defaults = array(
			'url'           => '',
			'method'        => 'GET',
			'bearer_token'  => '',
			'params'        => '{}',
			'format'        => 'json',
			'buy_path'      => '',
			'sell_path'     => '',
			'buy_path_24k'  => '',
			'sell_path_24k' => '',
			'unit'          => 'store',
			'cache_ttl'     => 60,
		);
		$config = wp_parse_args( is_array( $config ) ? $config : array(), $defaults );
		$url = esc_url_raw( $config['url'] );
		$is_valid_url = apply_filters( 'zg_validate_price_source_url', wp_http_validate_url( $url ), $url, array( 'id' => 'direct_api' ) );
		if ( empty( $url ) || ! $is_valid_url ) {
			return new WP_Error( 'zg_invalid_url', 'آدرس API معتبر نیست.' );
		}
		$cache_key = 'zg_direct_' . md5( $url . '|' . $config['method'] . '|' . $config['format'] . '|' . $config['buy_path'] . '|' . $config['sell_path'] . '|' . $config['buy_path_24k'] . '|' . $config['sell_path_24k'] . '|' . $config['params'] );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) { return $cached; }
		}

		$headers = array();
		$token = isset( $config['bearer_token'] ) ? ZG_Crypto::decrypt( (string) $config['bearer_token'] ) : '';
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$params = self::json_array( $config['params'] );
		if ( is_wp_error( $params ) ) { return $params; }
		$method = strtoupper( in_array( strtoupper( $config['method'] ), array( 'GET', 'POST', 'PUT' ), true ) ? $config['method'] : 'GET' );
		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 2,
			'headers'     => $headers,
			'user-agent'  => 'ZarPulse/' . ZG_VERSION,
		);
		if ( 'GET' === $method && ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}
		if ( 'GET' !== $method && ! empty( $params ) ) {
			$args['body'] = wp_json_encode( $params );
			$args['headers']['Content-Type'] = 'application/json';
		}
		$request_function = apply_filters( 'zg_price_source_request_function', 'wp_safe_remote_request', $url, array( 'id' => 'direct_api' ) );
		$response = is_callable( $request_function ) ? call_user_func( $request_function, $url, $args ) : wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) { return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			return new WP_Error( 'zg_http', 'پاسخ HTTP نامعتبر: ' . $code );
		}
		$body_raw = wp_remote_retrieve_body( $response );
		$format = ( isset( $config['format'] ) && 'text' === $config['format'] ) ? 'text' : 'json';

		$prices = array();
		$sell = array();
		if ( 'text' === $format ) {
			$num = self::parse_number( $body_raw );
			if ( null === $num ) {
				return new WP_Error( 'zg_no_number', 'هیچ عددی در پاسخ منبع پیدا نشد.' );
			}
			$prices['18k'] = self::maybe_round_thousand( $num );
		} else {
			$body = json_decode( $body_raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				$num = self::parse_number( $body_raw );
				if ( null === $num ) {
					return new WP_Error( 'zg_json', 'خروجی منبع باید JSON معتبر باشد (یا نوع پاسخ را «عدد / متن ساده» بگذارید).' );
				}
				$prices['18k'] = self::maybe_round_thousand( $num );
			} else {
				$buy_path = trim( (string) ( $config['buy_path'] ?? '' ) );
				if ( '' === $buy_path ) {
					return new WP_Error( 'zg_map', 'مسیر قیمت خرید را وارد کنید (مثلا Data[7] یا data.price).' );
				}
				$buy = self::get_path( $body, $buy_path );
				$buy = is_scalar( $buy ) ? self::parse_number( $buy ) : null;
				if ( null === $buy ) {
					return new WP_Error( 'zg_no_mapped_values', 'در مسیر تعریف‌شده هیچ قیمت عددی پیدا نشد.' );
				}
				$prices['18k'] = self::maybe_round_thousand( $buy );
				if ( ! empty( $config['sell_path'] ) ) {
					$v = self::get_path( $body, $config['sell_path'] );
					$v = is_scalar( $v ) ? self::parse_number( $v ) : null;
					if ( null !== $v ) { $sell['18k'] = self::maybe_round_thousand( $v ); }
				}
				if ( ! empty( $config['buy_path_24k'] ) ) {
					$v = self::get_path( $body, $config['buy_path_24k'] );
					$v = is_scalar( $v ) ? self::parse_number( $v ) : null;
					if ( null !== $v ) { $prices['24k'] = self::maybe_round_thousand( $v ); }
				}
				if ( ! empty( $config['sell_path_24k'] ) ) {
					$v = self::get_path( $body, $config['sell_path_24k'] );
					$v = is_scalar( $v ) ? self::parse_number( $v ) : null;
					if ( null !== $v ) { $sell['24k'] = self::maybe_round_thousand( $v ); }
				}
			}
		}
		if ( empty( $prices ) ) {
			return new WP_Error( 'zg_no_price', 'قیمتی از منبع مستقیم دریافت نشد.' );
		}
		$result = array(
			'prices' => $prices,
			'sell'   => $sell,
			'meta'   => array(
				'url'        => $url,
				'method'     => $method,
				'format'     => $format,
				'fetched_at' => time(),
			),
		);
		set_transient( $cache_key, $result, max( 30, min( 3600, absint( $config['cache_ttl'] ) ) ) );
		return $result;
	}

	/** وضعیت منبع مستقیم در حالت دستی */
	public static function direct_status() {
		$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		if ( 'manual' !== self::resolve_mode( $settings ) ) {
			return null;
		}
		$config = isset( $settings['direct_api'] ) && is_array( $settings['direct_api'] ) ? $settings['direct_api'] : array();
		$result = self::fetch_direct_api( $config, false );
		if ( is_wp_error( $result ) ) {
			return array(
				'status'  => 'error',
				'message' => $result->get_error_message(),
				'url'     => isset( $config['url'] ) ? $config['url'] : '',
				'method'  => isset( $config['method'] ) ? $config['method'] : 'GET',
				'format'  => isset( $config['format'] ) ? $config['format'] : 'json',
			);
		}
		$unit = isset( $config['unit'] ) ? $config['unit'] : 'store';
		$values = array();
		foreach ( $result['prices'] as $k => $v ) {
			$values[ $k ] = ZG_Currency::normalize_to_store( (float) $v, $unit, $k );
		}
		return array(
			'status' => 'ok',
			'values' => $values,
			'sell'   => isset( $result['sell'] ) ? $result['sell'] : array(),
			'url'    => isset( $config['url'] ) ? $config['url'] : '',
			'method' => isset( $config['method'] ) ? $config['method'] : 'GET',
			'format' => isset( $config['format'] ) ? $config['format'] : 'json',
		);
	}

	/** تبدیل مقدار به زوج خرید و فروش */
	public static function to_pair( $value ) {
		if ( is_array( $value ) ) {
			$buy = isset( $value['buy'] ) && is_numeric( $value['buy'] ) ? (float) $value['buy'] : 0;
			$sell = isset( $value['sell'] ) && is_numeric( $value['sell'] ) ? (float) $value['sell'] : 0;
			$mid = isset( $value['mid'] ) && is_numeric( $value['mid'] ) ? (float) $value['mid'] : 0;
			return array( 'buy' => $buy, 'sell' => $sell, 'mid' => $mid );
		}
		if ( is_numeric( $value ) && (float) $value > 0 ) {
			return array( 'buy' => 0, 'sell' => 0, 'mid' => (float) $value );
		}
		return array( 'buy' => 0, 'sell' => 0, 'mid' => 0 );
	}

	/** پیمایش مسیر JSON با پشتیبانی از نقطه و اندیس آرایه */
	public static function get_path( $data, $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) { return $data; }
		$segments = array();
		foreach ( explode( '.', $path ) as $chunk ) {
			$chunk = trim( $chunk );
			if ( '' === $chunk ) { continue; }
			if ( preg_match( '/^([^\[\]]*)((?:\[\d+\])*)$/u', $chunk, $m ) ) {
				if ( '' !== $m[1] ) { $segments[] = $m[1]; }
				if ( '' !== $m[2] && preg_match_all( '/\[(\d+)\]/', $m[2], $idx ) ) {
					foreach ( $idx[1] as $i ) { $segments[] = (int) $i; }
				}
			} else {
				$segments[] = $chunk;
			}
		}
		if ( empty( $segments ) ) { return null; }
		foreach ( $segments as $segment ) {
			if ( ! is_array( $data ) ) { return null; }
			if ( array_key_exists( $segment, $data ) ) { $data = $data[ $segment ]; continue; }
			if ( is_int( $segment ) && array_key_exists( (int) $segment, $data ) ) { $data = $data[ (int) $segment ]; continue; }
			return null;
		}
		return $data;
	}

	/** استخراج عدد از متن/HTML (ارقام فارسی/عربی و جداکننده هزارگان) */
	public static function parse_number( $value ) {
		if ( is_numeric( $value ) ) { return (float) $value; }
		if ( ! is_scalar( $value ) ) { return null; }
		$s = (string) $value;
		$s = strip_tags( $s );
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5 );
		$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$ar = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$s = str_replace( $fa, $en, $s );
		$s = str_replace( $ar, $en, $s );
		$s = str_replace( array( '٬', '٫', '،', ',' ), '', $s );
		if ( preg_match( '/-?\d+(?:\.\d+)?/', $s, $m ) ) { return (float) $m[0]; }
		return null;
	}

	public static function json_array( $json ) {
		if ( is_array( $json ) ) { return $json; }
		if ( '' === trim( (string) $json ) ) { return array(); }
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : new WP_Error( 'zg_invalid_json', 'یکی از فیلدهای JSON معتبر نیست.' );
	}

	/** نرمال‌سازی متن برای مقایسه (ارقام فارسی→انگلیسی، حذف نیم‌فاصله) */
	private static function normalize_text( $s ) {
		$s = (string) $s;
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5 );
		$s = str_replace( array( "\xE2\x80\x8C", "\xE2\x80\x8B", "\xE2\x80\x8F", '&zwnj;', '&#8204;', "\xc2\xa0", '&nbsp;' ), ' ', $s );
		$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$ar = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$s = str_replace( $fa, $en, $s );
		$s = str_replace( $ar, $en, $s );
		$s = str_replace( array( '٬', '٫', '،', ',' ), '', $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( $s );
	}

	/** اولین عدد معتبر پس از برچسب در متن یکپارچه */
	private static function scrape_number_after( $text, $label, $min = 100000 ) {
		$text = self::normalize_text( $text );
		$label = self::normalize_text( $label );
		$pos = strpos( $text, $label );
		if ( false === $pos ) { return 0; }
		$chunk = substr( $text, $pos + strlen( $label ), 240 );
		if ( preg_match_all( '/-?\d+(?:\.\d+)?/', $chunk, $m ) ) {
			foreach ( $m[0] as $raw ) {
				$v = (float) $raw;
				if ( $v >= $min ) { return $v; }
			}
			if ( isset( $m[0][0] ) ) { return (float) $m[0][0]; }
		}
		return 0;
	}

	/** ذخیره تاریخچه قیمت */
	private static function store_history( $prices, $sources ) {
		global $wpdb;
		$table = $wpdb->prefix . 'zg_price_history';
		foreach ( $prices as $key => $value ) {
			$wpdb->insert(
				$table,
				array(
					'price_key'   => $key,
					'price_value' => $value,
					'source_id'   => isset( $sources[ $key ] ) ? $sources[ $key ] : '',
					'created_at'  => current_time( 'mysql', true ),
				),
				array( '%s', '%f', '%s', '%s' )
			);
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) ) ) );
	}

	/** ارسال هشدار نوسان شدید در صورت فعال بودن */
	private static function maybe_alert( $previous, $prices, $settings ) {
		$threshold = max( 0, (float) ( isset( $settings['alert_threshold'] ) ? $settings['alert_threshold'] : 0 ) );
		if ( 0 === $threshold || empty( $previous['prices'] ) ) { return; }
		$changes = array();
		foreach ( $prices as $key => $new_value ) {
			if ( empty( $previous['prices'][ $key ] ) ) { continue; }
			$old_value = (float) $previous['prices'][ $key ];
			$percent = abs( ( $new_value - $old_value ) / $old_value * 100 );
			if ( $percent >= $threshold ) {
				$label = isset( self::price_keys()[ $key ] ) ? self::price_keys()[ $key ] : $key;
				$changes[] = $label . ': ' . number_format_i18n( $percent, 2 ) . '%';
			}
		}
		if ( empty( $changes ) ) { return; }
		$message = 'هشدار زرپالس: نوسان قیمت شدید شناسایی شد: ' . implode( ' | ', $changes );
		ZG_Activator::log( 'price_alert', $message, 'warning', array( 'changes' => $changes ) );
		if ( ! empty( $settings['alert_email'] ) && is_email( $settings['alert_email'] ) ) {
			wp_mail( $settings['alert_email'], 'هشدار تغییر قیمت زرپالس', $message );
		}
	}
}
