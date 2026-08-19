<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZG_Plugin {
	private static $instance;
	public static function instance() { if ( ! self::$instance ) { self::$instance = new self(); } return self::$instance; }
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
		add_action( 'init', array( 'ZG_Cron', 'maybe_kick' ), 2 );
		add_filter( 'cron_schedules', array( 'ZG_Cron', 'schedules' ) );
		add_action( 'zg_refresh_prices', array( 'ZG_Cron', 'run' ) );
		add_action( 'wp_ajax_nopriv_zg_public_cron_tick', array( 'ZG_Cron', 'public_tick' ) );
		add_action( 'wp_ajax_zg_public_cron_tick', array( 'ZG_Cron', 'public_tick' ) );
	}
	public function boot() {
		load_plugin_textdomain( 'zarpulse-gold-pricing', false, dirname( ZG_BASENAME ) . '/languages' );
		if ( ! class_exists( 'WooCommerce' ) ) { add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) ); return; }
		$this->maybe_upgrade();
		ZG_Product::register();
		ZG_Price_Lock::register();
		ZG_Admin::register();
	}
	private function maybe_upgrade() {
		$db_version = get_option( 'zg_db_version', '' );
		if ( ZG_VERSION === $db_version ) { return; }
		$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		if ( empty( $db_version ) || version_compare( $db_version, '1.3.0', '<' ) ) { $settings['cron_interval'] = ! empty( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'zg_every_minute'; $settings['background_kick'] = 1; }
		update_option( 'zg_settings', $settings, false );
		$sources = get_option( 'zg_sources', array() );
		if ( is_array( $sources ) ) {
			foreach ( $sources as $id => $source ) {
				if ( empty( $source['unit'] ) ) { $sources[ $id ]['unit'] = ( isset( $source['type'] ) && 'auto_market' === $source['type'] ) ? 'rial' : 'store'; }
			}
			update_option( 'zg_sources', $sources, false );
		}
		// مهاجرت به ۱.۹.۰: جایگزینی کامل منابع با منابع جدید (TGJU/اتحادیه/طلاین) و تنظیم حالت جدید.
		if ( version_compare( $db_version, '1.9.0', '<' ) ) {
			$obsolete = array( 'auto_market', 'talasea', 'melligold', 'world_ounce', 'usd_market', 'tgju_gold', 'tgju_mesghal', 'tgju_coin', 'tgju_usd', 'tgju_ounce', 'goldapi_ounce' );
			$sources = get_option( 'zg_sources', array() );
			if ( is_array( $sources ) ) {
				foreach ( $obsolete as $oid ) { unset( $sources[ $oid ] ); }
				foreach ( ZG_Activator::default_sources() as $id => $src ) {
					if ( ! isset( $sources[ $id ] ) ) { $sources[ $id ] = $src; }
				}
				update_option( 'zg_sources', $sources, false );
			}
			$settings = get_option( 'zg_settings', array() );
			if ( is_array( $settings ) ) {
				if ( isset( $settings['pricing_mode'] ) && 'auto' === $settings['pricing_mode'] ) { $settings['pricing_mode'] = 'consensus'; }
				unset( $settings['buy_sell_spread_percent'] );
				update_option( 'zg_settings', $settings, false );
			}
		}
		// مهاجرت به ۱.۱۲.۰: دو حالته شدن سیستم قیمت‌گذاری (auto / manual) و فعال‌سازی موتور هوشمند.
		if ( version_compare( $db_version, '1.12.0', '<' ) ) {
			$settings = get_option( 'zg_settings', array() );
			if ( is_array( $settings ) ) {
				if ( ! isset( $settings['pricing_mode'] ) || in_array( $settings['pricing_mode'], array( 'consensus', 'reference', 'auto' ), true ) ) {
					$settings['pricing_mode'] = 'auto';
				}
				update_option( 'zg_settings', $settings, false );
			}
		}
		ZG_Cron::reschedule();
		update_option( 'zg_last_cron_started', 0, false );
		if ( ! wp_next_scheduled( 'zg_refresh_prices' ) ) { wp_schedule_single_event( time() + 10, 'zg_refresh_prices' ); }
		update_option( 'zg_db_version', ZG_VERSION, false );
	}

	public function woocommerce_notice() {
		if ( current_user_can( 'activate_plugins' ) ) { echo '<div class="notice notice-error"><p><strong>زرپالس:</strong> برای فعال‌سازی کامل افزونه، ووکامرس باید نصب و فعال باشد.</p></div>'; }
	}
}
