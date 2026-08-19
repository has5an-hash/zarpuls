<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZG_Activator {
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$history = $wpdb->prefix . 'zg_price_history';
		$events  = $wpdb->prefix . 'zg_events';
		dbDelta( "CREATE TABLE {$history} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			price_key varchar(30) NOT NULL,
			price_value decimal(20,4) NOT NULL,
			source_id varchar(80) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY (id), KEY price_key_created (price_key, created_at)
		) {$charset};" );
		dbDelta( "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id), KEY event_type_created (event_type, created_at)
		) {$charset};" );

		if ( ! get_option( 'zg_settings' ) ) {
			add_option( 'zg_settings', self::default_settings(), '', false );
		}
		if ( ! get_option( 'zg_formulas' ) ) {
			add_option( 'zg_formulas', self::default_formulas(), '', false );
		}
		if ( ! get_option( 'zg_sources' ) ) {
			add_option( 'zg_sources', self::default_sources(), '', false );
		}
		update_option( 'zg_db_version', ZG_VERSION, false );
		ZG_Cron::reschedule();
	}

	public static function deactivate() {
		ZG_Cron::unschedule();
	}

	public static function default_settings() {
		return array(
			'currency_label'  => 'تومان',
			'currency_unit'   => 'auto',
			'cron_interval'   => 'zg_every_minute',
			'background_kick' => 1,
			'alert_threshold' => 5,
			'alert_email'     => get_option( 'admin_email' ),
			'batch_size'      => 100,
			'manual_prices'   => array( '18k' => 0, '24k' => 0, 'coin' => 0, 'ounce' => 0, 'exchange' => 0 ),
			'manual_price_unit' => 'store',
			'round_thousand'    => 1,
			'category_profiles' => array(),
		// حالت قیمت‌گذاری نهایی بازار: auto (محاسبه هوشمند و خودکار) / manual (API مستقیم).
		'pricing_mode'      => 'auto',
		'manual_final_buy'  => 0,
		'manual_final_sell' => 0,
		// منبع مستقیم حالت دستی: خواندن قیمت از یک URL/API دلخواه بدون اجماع منابع.
		'direct_api'        => array(
			'url'            => '',
			'method'         => 'GET',
			'bearer_token'   => '',
			'params'         => '{}',
			'format'         => 'json',
			'buy_path'       => '',
			'sell_path'      => '',
			'buy_path_24k'   => '',
			'sell_path_24k'  => '',
			'unit'           => 'store',
			'cache_ttl'      => 60,
		),
			// قفل زمان‌دار سبد برای فروش کالاهای وابسته به نرخ لحظه‌ای.
			'price_lock_enabled' => 1,
			'price_lock_minutes' => 5,
			'price_lock_display_mode' => 'automatic',
		'price_lock_cart_position' => 'before_cart',
		'price_lock_checkout_position' => 'before_order_review',
	);
	}

	public static function default_sources() {
		// منابع واقعی و معتبر قیمت بازار طلا (رایگان، بدون کلید، قابل استخراج از سرور).
		return array(
			'tgju' => array(
				'name' => 'شبکه طلا و سکه — TGJU (لحظه‌ای)', 'type' => 'tgju',
				'enabled' => 1, 'priority' => 1, 'unit' => 'rial', 'cache_ttl' => 30,
			),
			'estjt' => array(
				'name' => 'اتحادیه طلا و جواهر تهران (نرخ مرجع)', 'type' => 'estjt',
				'enabled' => 1, 'priority' => 2, 'unit' => 'toman', 'cache_ttl' => 300,
			),
			'taline' => array(
				'name' => 'طلاین (Taline)', 'type' => 'taline',
				'enabled' => 1, 'priority' => 3, 'unit' => 'toman', 'cache_ttl' => 120,
			),
			'moj3' => array(
				'name' => 'موج سوم (Moj3)', 'type' => 'moj3',
				'enabled' => 1, 'priority' => 4, 'unit' => 'toman', 'cache_ttl' => 120,
			),
			'kifpool' => array(
				'name' => 'کیف پول (Kifpool)', 'type' => 'kifpool',
				'enabled' => 1, 'priority' => 5, 'unit' => 'toman', 'cache_ttl' => 120,
			),
			'talasea' => array(
				'name' => 'طلاسی (Talasea)', 'type' => 'talasea',
				'enabled' => 1, 'priority' => 6, 'unit' => 'toman', 'cache_ttl' => 120,
			),
			'miligold' => array(
				'name' => 'میلی گلد (Miligold)', 'type' => 'zariran', 'exchange' => 'miligold',
				'enabled' => 1, 'priority' => 7, 'unit' => 'toman', 'cache_ttl' => 180,
			),
			'daric' => array(
				'name' => 'داریک (Daric)', 'type' => 'zariran', 'exchange' => 'daric',
				'enabled' => 1, 'priority' => 8, 'unit' => 'toman', 'cache_ttl' => 180,
			),
			'wallgold' => array(
				'name' => 'وال گلد (WallGold)', 'type' => 'zariran', 'exchange' => 'wallgold',
				'enabled' => 1, 'priority' => 9, 'unit' => 'toman', 'cache_ttl' => 180,
			),
			'zarafza' => array(
				'name' => 'زرافزا (Zarafza)', 'type' => 'zariran', 'exchange' => 'zarafza',
				'enabled' => 1, 'priority' => 10, 'unit' => 'toman', 'cache_ttl' => 180,
			),
			'technogold' => array(
				'name' => 'تکنوگلد (TechnoGold)', 'type' => 'zariran', 'exchange' => 'technogold',
				'enabled' => 1, 'priority' => 11, 'unit' => 'toman', 'cache_ttl' => 180,
			),
		);
	}

	public static function default_formulas() {
		// الگوهای پیش‌فرض بر مبنای عرف فروش فیزیکی ایران هستند؛ مدیر فروشگاه می‌تواند آزادانه آن‌ها را تغییر دهد.
		// در الگوی مصنوعات، مالیات تنها روی اجرت، سود و خدمات اعمال می‌شود، نه ارزش ذاتی طلا.
		return array(
			'bullion' => array(
				'id' => 'bullion', 'name' => 'فرمول شمش و طلای آب‌شده', 'active' => 1,
				'description' => 'ارزش طلا + خدمات بسته‌بندی/بیمه + کارمزد کم؛ مالیات به‌صورت پیش‌فرض صفر است.',
				'expression' => '(weight * base_18k * karat_factor) + wage + (weight * wage_per_gram) + extra + packing + insurance + manufacturing + shipping + profit_fixed + (((weight * base_18k * karat_factor) + wage + (weight * wage_per_gram) + extra + packing + insurance + manufacturing + shipping) * profit_percent / 100) + ((wage + (weight * wage_per_gram) + extra + packing + insurance + manufacturing + shipping + profit_fixed + (((weight * base_18k * karat_factor) + wage + (weight * wage_per_gram) + extra + packing + insurance + manufacturing + shipping) * profit_percent / 100)) * tax_percent / 100)',
			),
			'jewelry' => array(
				'id' => 'jewelry', 'name' => 'فرمول استاندارد مصنوعات طلا و جواهر', 'active' => 1,
				'description' => 'ارزش خام + اجرت ثابت/گرمی/درصدی + سود روی ارزش و اجرت + مالیات فقط روی اجرت، سود و خدمات.',
				'expression' => '(weight * base_18k * karat_factor) + wage + (weight * wage_per_gram) + ((weight * base_18k * karat_factor) * wage_percent / 100) + stone + extra + packing + insurance + manufacturing + shipping + profit_fixed + (((weight * base_18k * karat_factor) + wage + (weight * wage_per_gram) + ((weight * base_18k * karat_factor) * wage_percent / 100) + stone + extra + packing + insurance + manufacturing + shipping) * profit_percent / 100) + ((wage + (weight * wage_per_gram) + ((weight * base_18k * karat_factor) * wage_percent / 100) + stone + extra + packing + insurance + manufacturing + shipping + profit_fixed + (((weight * base_18k * karat_factor) + wage + (weight * wage_per_gram) + ((weight * base_18k * karat_factor) * wage_percent / 100) + stone + extra + packing + insurance + manufacturing + shipping) * profit_percent / 100)) * tax_percent / 100)',
			),
			'coin' => array(
				'id' => 'coin', 'name' => 'فرمول سکه و پارسیان', 'active' => 1,
				'description' => 'قیمت پایه سکه + کارمزد و خدمات؛ مالیات به‌صورت پیش‌فرض صفر است.',
				'expression' => 'coin + wage + extra + shipping + insurance + packing + profit_fixed + ((coin + wage + extra + shipping + insurance + packing) * profit_percent / 100) + ((wage + extra + shipping + insurance + packing + profit_fixed + ((coin + wage + extra + shipping + insurance + packing) * profit_percent / 100)) * tax_percent / 100)',
			),
		);
	}

	public static function log( $type, $message, $level = 'info', $context = array() ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'zg_events', array(
			'event_type' => sanitize_key( $type ),
			'level'      => sanitize_key( $level ),
			'message'    => wp_strip_all_tags( $message ),
			'context'    => wp_json_encode( $context ),
			'created_at' => current_time( 'mysql', true ),
		), array( '%s', '%s', '%s', '%s', '%s' ) );
	}
}
