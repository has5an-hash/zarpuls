<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZG_Admin {
	private static $capability = 'manage_woocommerce';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 58 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_cron_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'cron_notice' ) );
		add_action( 'wp_ajax_zg_cron_heartbeat', array( __CLASS__, 'ajax_cron_heartbeat' ) );
		add_action( 'wp_ajax_zg_sources_status', array( __CLASS__, 'ajax_sources_status' ) );
		add_action( 'wp_ajax_zg_test_auto_engine', array( __CLASS__, 'ajax_test_auto_engine' ) );
		add_action( 'wp_ajax_zg_dashboard_live', array( __CLASS__, 'ajax_dashboard_live' ) );
		add_action( 'wp_ajax_zg_test_direct_api', array( __CLASS__, 'ajax_test_direct_api' ) );
		add_action( 'wp_ajax_zg_configure_wp_cron', array( __CLASS__, 'ajax_configure_wp_cron' ) );
		add_action( 'wp_ajax_zg_reports_live', array( __CLASS__, 'ajax_reports_live' ) );
	}

	public static function menu() {
		add_menu_page( 'زرپالس | قیمت‌گذاری طلا', 'زرپالس', self::$capability, 'zarpulse-pricing', array( __CLASS__, 'dashboard' ), 'dashicons-chart-line', 56 );
		add_submenu_page( 'zarpulse-pricing', 'داشبورد زرپالس', 'داشبورد', self::$capability, 'zarpulse-pricing', array( __CLASS__, 'dashboard' ) );
		add_submenu_page( 'zarpulse-pricing', 'تنظیمات قیمت‌گذاری', 'منابع و حالت قیمت', self::$capability, 'zarpulse-sources', array( __CLASS__, 'sources' ) );
		add_submenu_page( 'zarpulse-pricing', 'فرمول‌های قیمت', 'فرمول‌ها', self::$capability, 'zarpulse-formulas', array( __CLASS__, 'formulas' ) );
		add_submenu_page( 'zarpulse-pricing', 'گزارش‌ها', 'گزارش‌ها', self::$capability, 'zarpulse-reports', array( __CLASS__, 'reports' ) );
		add_submenu_page( 'zarpulse-pricing', 'تنظیمات زرپالس', 'تنظیمات عمومی', self::$capability, 'zarpulse-settings', array( __CLASS__, 'settings' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'zarpulse' ) && 'post.php' !== $hook && 'post-new.php' !== $hook ) { return; }
		wp_enqueue_style( 'zg-admin', ZG_URL . 'assets/css/admin.css', array(), ZG_VERSION );
		wp_enqueue_script( 'zg-admin', ZG_URL . 'assets/js/admin.js', array( 'jquery' ), ZG_VERSION, true );
		wp_localize_script( 'zg-admin', 'ZGAdmin', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'zg_admin_nonce' ), 'cronNonce' => wp_create_nonce( 'zg_cron_heartbeat' ), 'calculating' => 'در حال محاسبه…', 'error' => 'خطا در محاسبه قیمت', 'cronEnabled' => true ) );
	}

	private static function guard() {
		if ( ! current_user_can( self::$capability ) ) { wp_die( 'شما اجازه دسترسی به این بخش را ندارید.' ); }
		check_admin_referer( 'zg_admin_action' );
	}

	public static function handle_actions() {
		if ( empty( $_POST['zg_admin_action'] ) ) { return; }
		self::guard();
		$action = sanitize_key( wp_unslash( $_POST['zg_admin_action'] ) );
		try {
			switch ( $action ) {
				case 'save_settings': self::save_settings(); self::notice( 'success', 'تنظیمات با موفقیت ذخیره شد.' ); break;
				case 'save_sources_page': self::save_sources_page(); self::notice( 'success', 'حالت قیمت‌گذاری ذخیره شد و نرخ‌ها بروزرسانی گردید.' ); break;
				case 'save_formula': self::save_formula(); self::notice( 'success', 'فرمول ذخیره شد.' ); break;
				case 'delete_formula': self::delete_formula(); self::notice( 'success', 'فرمول حذف شد.' ); break;
				default: throw new Exception( 'عملیات ناشناخته است.' );
			}
		} catch ( Exception $e ) { self::notice( 'error', $e->getMessage() ); }
		$url = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=zarpulse-pricing' );
		wp_safe_redirect( remove_query_arg( array( 'zg_notice', 'zg_source_test' ), $url ) );
		exit;
	}

	private static function save_settings() {
		$old = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		$settings = $old;
		$settings['currency_label'] = sanitize_text_field( wp_unslash( $_POST['currency_label'] ?? 'تومان' ) );
		$settings['currency_unit'] = in_array( $_POST['currency_unit'] ?? '', array( 'auto', 'rial', 'toman' ), true ) ? sanitize_key( $_POST['currency_unit'] ) : 'auto';
		$settings['price_lock_enabled'] = isset( $_POST['price_lock_enabled'] ) ? 1 : 0;
		$settings['price_lock_minutes'] = max( 1, min( 120, absint( $_POST['price_lock_minutes'] ?? 5 ) ) );
		$settings['price_lock_display_mode'] = in_array( $_POST['price_lock_display_mode'] ?? '', array( 'automatic', 'shortcode' ), true ) ? sanitize_key( $_POST['price_lock_display_mode'] ) : 'automatic';
		$settings['manual_prices'] = array();
		foreach ( ZG_Sources::price_keys() as $key => $label ) { $settings['manual_prices'][ $key ] = max( 0, (float) str_replace( ',', '', wp_unslash( $_POST['manual_prices'][ $key ] ?? 0 ) ) ); }
		$settings['manual_price_unit'] = in_array( $_POST['manual_price_unit'] ?? '', array( 'store', 'rial', 'toman' ), true ) ? sanitize_key( $_POST['manual_price_unit'] ) : 'store';
		$settings['round_thousand'] = isset( $_POST['round_thousand'] ) ? 1 : 0;
		$settings['category_profiles'] = array();
		$category_formula = isset( $_POST['category_formula'] ) && is_array( $_POST['category_formula'] ) ? wp_unslash( $_POST['category_formula'] ) : array();
		$category_profit = isset( $_POST['category_profit'] ) && is_array( $_POST['category_profit'] ) ? wp_unslash( $_POST['category_profit'] ) : array();
		foreach ( $category_formula as $term_id => $formula_id ) {
			$formula_id = sanitize_key( $formula_id );
			if ( $formula_id ) { $settings['category_profiles'][ absint( $term_id ) ] = array( 'formula_id' => $formula_id, 'profit_percent' => max( 0, (float) ( $category_profit[ $term_id ] ?? 0 ) ) ); }
		}
		update_option( 'zg_settings', $settings, false );
		ZG_Cron::reschedule();
	}

	/** ذخیره تنظیمات منابع و حالت قیمت‌گذاری */
	private static function save_sources_page() {
		$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		$settings['pricing_mode'] = ( isset( $_POST['pricing_mode'] ) && 'manual' === $_POST['pricing_mode'] ) ? 'manual' : 'auto';

		// تنظیمات منبع مستقیم در حالت دستی (API دلخواه).
		$direct = wp_parse_args( isset( $settings['direct_api'] ) && is_array( $settings['direct_api'] ) ? $settings['direct_api'] : array(), ZG_Activator::default_settings()['direct_api'] );
		$direct['url'] = esc_url_raw( wp_unslash( $_POST['direct_api_url'] ?? '' ) );
		$direct['method'] = in_array( strtoupper( $_POST['direct_api_method'] ?? 'GET' ), array( 'GET', 'POST', 'PUT' ), true ) ? strtoupper( sanitize_key( $_POST['direct_api_method'] ) ) : 'GET';
		$token = trim( sanitize_text_field( wp_unslash( $_POST['direct_api_token'] ?? '' ) ) );
		if ( '' !== $token ) { $direct['bearer_token'] = ZG_Crypto::encrypt( $token ); }
		$direct['params'] = trim( wp_unslash( $_POST['direct_api_params'] ?? '{}' ) );
		if ( '' === $direct['params'] ) { $direct['params'] = '{}'; }
		if ( is_wp_error( ZG_Sources::json_array( $direct['params'] ) ) ) { throw new Exception( 'پارامترهای درخواست API باید JSON معتبر باشند.' ); }
		$direct['format'] = in_array( $_POST['direct_api_format'] ?? 'json', array( 'json', 'text' ), true ) ? sanitize_key( $_POST['direct_api_format'] ) : 'json';
		$direct['buy_path'] = trim( wp_unslash( $_POST['direct_api_buy_path'] ?? '' ) );
		$direct['sell_path'] = trim( wp_unslash( $_POST['direct_api_sell_path'] ?? '' ) );
		$direct['buy_path_24k'] = trim( wp_unslash( $_POST['direct_api_buy_path_24k'] ?? '' ) );
		$direct['sell_path_24k'] = trim( wp_unslash( $_POST['direct_api_sell_path_24k'] ?? '' ) );
		$direct['unit'] = in_array( $_POST['direct_api_unit'] ?? '', array( 'store', 'rial', 'toman', 'usd' ), true ) ? sanitize_key( $_POST['direct_api_unit'] ) : 'store';
		$direct['cache_ttl'] = max( 30, min( 3600, absint( $_POST['direct_api_cache_ttl'] ?? 60 ) ) );
		$settings['direct_api'] = $direct;

		if ( 'manual' === $settings['pricing_mode'] ) {
			if ( empty( $direct['url'] ) || ! wp_http_validate_url( $direct['url'] ) ) {
				throw new Exception( 'در حالت دستی (API مستقیم)، آدرس API معتبر الزامی است.' );
			}
			if ( 'json' === $direct['format'] && '' === $direct['buy_path'] ) {
				throw new Exception( 'در حالت JSON، مسیر قیمت خرید را وارد کنید (مثلا Data[7] یا data.price).' );
			}
		}
		update_option( 'zg_settings', $settings, false );

		// بلافاصله پس از ذخیره، قیمت‌ها بازخوانی و محصولات بروزرسانی می‌شوند
		$refresh = ZG_Sources::refresh( true );
		if ( ! is_wp_error( $refresh ) ) { ZG_Pricing::sync_all(); }
	}

	private static function save_formula() {
		$all = ZG_Pricing::formulas(); $id = sanitize_key( wp_unslash( $_POST['formula_id'] ?? '' ) );
		if ( ! $id ) { $id = 'formula_' . wp_generate_password( 7, false, false ); }
		$name = sanitize_text_field( wp_unslash( $_POST['formula_name'] ?? '' ) ); $expression = trim( wp_unslash( $_POST['formula_expression'] ?? '' ) );
		if ( ! $name || ! $expression ) { throw new Exception( 'نام و عبارت فرمول الزامی است.' ); }
		$vars = array_fill_keys( array_keys( ZG_Formula_Engine::variables() ), 1 );
		try { ZG_Formula_Engine::evaluate( $expression, $vars ); } catch ( Exception $e ) { throw new Exception( 'فرمول ذخیره نشد: ' . $e->getMessage() ); }
		$all[ $id ] = array( 'id' => $id, 'name' => $name, 'description' => sanitize_textarea_field( wp_unslash( $_POST['formula_description'] ?? '' ) ), 'expression' => $expression, 'active' => isset( $_POST['formula_active'] ) ? 1 : 0 );
		update_option( 'zg_formulas', $all, false );
	}

	private static function delete_formula() {
		$id = sanitize_key( wp_unslash( $_POST['formula_id'] ?? '' ) );
		$all = ZG_Pricing::formulas();
		if ( isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			update_option( 'zg_formulas', $all, false );
		}
	}

	private static function notice( $type, $message ) {
		set_transient( 'zg_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
	}

	/** نمایش پیام‌های «ذخیره شد / خطا» داخل خود صفحه */
	private static function page_notice() {
		$notice = get_transient( 'zg_notice_' . get_current_user_id() );
		if ( ! $notice ) { return; }
		delete_transient( 'zg_notice_' . get_current_user_id() );
		$type = in_array( $notice['type'], array( 'error', 'warning', 'success' ), true ) ? $notice['type'] : 'success';
		$meta = array(
			'success' => array( 'title' => 'انجام شد', 'icon' => '✔' ),
			'warning' => array( 'title' => 'توجه', 'icon' => '!' ),
			'error'   => array( 'title' => 'خطا', 'icon' => '✖' ),
		);
		echo '<div class="zg-page-notice zg-page-notice--' . esc_attr( $type ) . '" role="alert"><span class="zg-page-notice__icon">' . esc_html( $meta[ $type ]['icon'] ) . '</span><div><strong>' . esc_html( $meta[ $type ]['title'] ) . '</strong><p>' . esc_html( $notice['message'] ) . '</p></div></div>';
	}

	/** یادآوری تنظیم Cron واقعی سرور */
	public static function cron_notice() {
		if ( ! current_user_can( self::$capability ) ) { return; }
		$status = ZG_Cron::wpconfig_status();
		if ( ! empty( $status['disabled'] ) || get_option( 'zg_cron_notice_dismissed' ) ) { return; }
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'zarpulse-settings' === $page ) { return; }
		$setup_url = admin_url( 'admin.php?page=zarpulse-settings#zg-cron-guide' );
		$dismiss_url = wp_nonce_url( add_query_arg( 'zg_dismiss_cron_notice', '1', admin_url( 'index.php' ) ), 'zg_cron_notice' );
		echo '<div class="notice notice-warning zg-cron-nudge"><p><strong>زرپالس:</strong> برای اینکه قیمت‌ها واقعاً «هر لحظه» بروزرسانی شوند باید <strong>Cron واقعی سرور</strong> را تنظیم کنید. <a class="button button-primary button-small" href="' . esc_url( $setup_url ) . '">همین حالا تنظیم کن</a> <a href="' . esc_url( $dismiss_url ) . '">فعلاً نه</a></p></div>';
	}

	public static function maybe_dismiss_cron_notice() {
		if ( ! current_user_can( self::$capability ) ) { return; }
		if ( ! isset( $_GET['zg_dismiss_cron_notice'] ) ) { return; }
		check_admin_referer( 'zg_cron_notice' );
		update_option( 'zg_cron_notice_dismissed', 1, false );
		wp_safe_redirect( remove_query_arg( array( 'zg_dismiss_cron_notice', '_wpnonce' ) ) );
		exit;
	}

	/** تنظیم خودکار wp-config.php */
	public static function ajax_configure_wp_cron() {
		check_ajax_referer( 'zg_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'برای تغییر wp-config.php باید دسترسی مدیر کل داشته باشید.' ), 403 ); }
		$result = ZG_Cron::write_disable_wp_cron();
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
		wp_send_json_success( array( 'message' => 'خط DISABLE_WP_CRON به wp-config.php اضافه شد. حالا Cron سرور را به آدرس داده‌شده وصل کنید (هر ۱ دقیقه).' ) );
	}

	public static function ajax_cron_heartbeat() {
		check_ajax_referer( 'zg_cron_heartbeat', 'nonce' );
		if ( ! current_user_can( self::$capability ) ) { wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 ); }
		$last = (int) get_option( 'zg_last_cron_run', 0 );
		$interval = ZG_Cron::interval_seconds();
		if ( $last && ( time() - $last ) < max( 45, $interval - 5 ) ) { wp_send_json_success( array( 'status' => 'fresh', 'updated_at' => $last ) ); }
		if ( get_transient( 'zg_cron_heartbeat_lock' ) ) { wp_send_json_success( array( 'status' => 'busy' ) ); }
		set_transient( 'zg_cron_heartbeat_lock', 1, 50 );
		try {
			ZG_Cron::run();
			$last = (int) get_option( 'zg_last_cron_run', 0 );
			$error = get_option( 'zg_last_cron_error', '' );
			if ( $error ) { wp_send_json_error( array( 'message' => $error, 'updated_at' => $last ) ); }
			wp_send_json_success( array( 'status' => 'updated', 'updated_at' => $last, 'result' => get_option( 'zg_last_cron_result', array() ) ) );
		} finally { delete_transient( 'zg_cron_heartbeat_lock' ); }
	}

	/** تست زنده موتور فرمول خودکار */
	public static function ajax_test_auto_engine() {
		check_ajax_referer( 'zg_admin_nonce', 'nonce' );
		if ( ! current_user_can( self::$capability ) ) { wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 ); }
		$calc = ZG_Sources::calculate_auto_prices( true );
		if ( is_wp_error( $calc ) || empty( $calc['gold_18k'] ) ) {
			$err = is_wp_error( $calc ) ? $calc->get_error_message() : 'خطا در محاسبه نرخ‌ها.';
			wp_send_json_error( array( 'message' => $err ) );
		}
		wp_send_json_success( array(
			'data'    => $calc,
			'message' => sprintf(
				'طلای ۱۸: %s تومان | طلای ۲۴: %s تومان | مظنه: %s تومان | سکه امامی: %s تومان',
				number_format_i18n( round( $calc['gold_18k'] ) ),
				number_format_i18n( round( $calc['gold_24k'] ) ),
				number_format_i18n( round( $calc['mazaneh_705'] ) ),
				number_format_i18n( round( $calc['emami_coin'] ) )
			),
		) );
	}

	/** وضعیت زنده منابع و موتور فرمول */
	public static function ajax_sources_status() {
		check_ajax_referer( 'zg_admin_nonce', 'nonce' );
		if ( ! current_user_can( self::$capability ) ) { wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 ); }
		$calc = ZG_Sources::calculate_auto_prices( false );
		$snapshot = ZG_Sources::snapshot();
		wp_send_json_success( array(
			'mode'           => ZG_Sources::resolve_mode(),
			'auto'           => is_wp_error( $calc ) ? null : $calc,
			'direct'         => ZG_Sources::direct_status(),
			'snapshot'       => $snapshot,
			'final'          => ZG_Sources::final_market_price(),
			'currency_label' => ZG_Currency::label(),
		) );
	}

	/** داده لحظه‌ای داشبورد */
	public static function ajax_dashboard_live() {
		check_ajax_referer( 'zg_admin_nonce', 'nonce' );
		if ( ! current_user_can( self::$capability ) ) { wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 ); }
		$snapshot = ZG_Sources::snapshot();
		$final = ZG_Sources::final_market_price();
		global $wpdb;
		$events = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}zg_events ORDER BY id DESC LIMIT 25" );
		wp_send_json_success( array(
			'mode'        => isset( $final['mode'] ) ? $final['mode'] : 'auto',
			'currency'    => isset( $final['currency'] ) ? $final['currency'] : ZG_Currency::label(),
			'final'       => $final,
			'prices'      => isset( $snapshot['prices'] ) ? $snapshot['prices'] : array(),
			'sell_prices' => isset( $snapshot['sell_prices'] ) ? $snapshot['sell_prices'] : array(),
			'sources'     => isset( $snapshot['sources'] ) ? $snapshot['sources'] : array(),
			'updated_at'  => isset( $snapshot['updated_at'] ) ? $snapshot['updated_at'] : 0,
			'trace'       => ZG_Sources::last_trace(),
			'events'      => $events,
			'cron'        => array(
				'last_run'   => (int) get_option( 'zg_last_cron_run', 0 ),
				'next'       => (int) wp_next_scheduled( 'zg_refresh_prices' ),
				'last_error' => get_option( 'zg_last_cron_error', '' ),
				'enabled'    => true,
			),
		) );
	}

	/** تست زنده تنظیمات API مستقیم (حالت دستی) */
	public static function ajax_test_direct_api() {
		check_ajax_referer( 'zg_admin_nonce', 'nonce' );
		if ( ! current_user_can( self::$capability ) ) { wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 ); }
		$config = array(
			'url'           => esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ),
			'method'        => in_array( strtoupper( $_POST['method'] ?? 'GET' ), array( 'GET', 'POST', 'PUT' ), true ) ? strtoupper( sanitize_key( $_POST['method'] ) ) : 'GET',
			'params'        => trim( wp_unslash( $_POST['params'] ?? '{}' ) ),
			'format'        => in_array( $_POST['format'] ?? 'json', array( 'json', 'text' ), true ) ? sanitize_key( $_POST['format'] ) : 'json',
			'buy_path'      => trim( wp_unslash( $_POST['buy_path'] ?? '' ) ),
			'sell_path'     => trim( wp_unslash( $_POST['sell_path'] ?? '' ) ),
			'buy_path_24k'  => trim( wp_unslash( $_POST['buy_path_24k'] ?? '' ) ),
			'sell_path_24k' => trim( wp_unslash( $_POST['sell_path_24k'] ?? '' ) ),
			'unit'          => in_array( $_POST['unit'] ?? '', array( 'store', 'rial', 'toman', 'usd' ), true ) ? sanitize_key( $_POST['unit'] ) : 'store',
			'cache_ttl'     => 60,
		);
		$existing = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
		$config['bearer_token'] = isset( $existing['direct_api']['bearer_token'] ) ? $existing['direct_api']['bearer_token'] : '';
		$token = trim( sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) );
		if ( '' !== $token ) { $config['bearer_token'] = ZG_Crypto::encrypt( $token ); }
		if ( '' === $config['params'] ) { $config['params'] = '{}'; }
		if ( empty( $config['url'] ) || ! wp_http_validate_url( $config['url'] ) ) { wp_send_json_error( array( 'message' => 'آدرس API معتبر نیست.' ) ); }
		$result = ZG_Sources::fetch_direct_api( $config, true );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
		wp_send_json_success( array( 'values' => $result['prices'], 'sell' => isset( $result['sell'] ) ? $result['sell'] : array(), 'meta' => isset( $result['meta'] ) ? $result['meta'] : array() ) );
	}

	private static function header( $title, $subtitle = '' ) {
		echo '<div class="wrap zg-wrap" dir="rtl"><div class="zg-heading"><div><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $subtitle ) . '</p></div><div class="zg-badge">زرپالس <span>۱.۱۲.۰</span></div></div>';
		self::page_notice();
	}
	private static function footer() { echo '</div>'; }
	private static function settings_data() { return wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() ); }

	public static function dashboard() {
		if ( ! current_user_can( self::$capability ) ) { return; }
		self::header( 'داشبورد قیمت‌گذاری طلا', 'کنترل لحظه‌ای بازار، محاسبه فرمول‌ها و وضعیت بروزرسانی فروشگاه' );
		$snapshot = ZG_Sources::snapshot();
		$currency_label = ZG_Currency::label();
		$next_cron = wp_next_scheduled( 'zg_refresh_prices' );
		$last_cron = (int) get_option( 'zg_last_cron_run', 0 );
		$cron_error = get_option( 'zg_last_cron_error', '' );
		global $wpdb;
		$final = ZG_Sources::final_market_price();
		$buy18 = isset( $final['buy'] ) ? (float) $final['buy'] : 0;
		$sell18 = isset( $final['sell'] ) ? (float) $final['sell'] : 0;
		$buy24 = isset( $final['buy_24k'] ) ? (float) $final['buy_24k'] : 0;
		$sell24 = isset( $final['sell_24k'] ) ? (float) $final['sell_24k'] : 0;
		$fmt = static function ( $v ) { return $v > 0 ? number_format_i18n( $v ) : '—'; };
		$active = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_zg_enabled' AND meta_value = 'yes'" );
		$errors = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_zg_last_error' AND meta_value <> ''" );
		?>
		<div class="zg-toolbar"><div><strong>آخرین بروزرسانی قیمت:</strong> <span class="zg-last-update" data-last-update><?php echo ! empty( $snapshot['updated_at'] ) ? esc_html( human_time_diff( $snapshot['updated_at'] ) . ' قبل' ) : 'هنوز دریافت نشده'; ?></span><span class="zg-cron-status" data-cron-status>بررسی زمان‌بندی…</span><?php if ( $last_cron ) : ?><small class="zg-cron-meta">آخرین اجرای خودکار: <?php echo esc_html( human_time_diff( $last_cron ) . ' قبل' ); ?></small><?php endif; ?><?php if ( $next_cron ) : ?><small class="zg-cron-meta">اجرای بعدی: <?php echo esc_html( human_time_diff( time(), $next_cron ) ); ?></small><?php endif; ?><?php if ( $cron_error ) : ?><small class="zg-cron-error">خطای آخر: <?php echo esc_html( $cron_error ); ?></small><?php endif; ?><span class="zg-live-badge">● بروزرسانی لحظه‌ای خودکار</span><span class="zg-cron-live" data-cron-live data-last-ts="<?php echo esc_attr( $last_cron ); ?>" data-next-ts="<?php echo esc_attr( $next_cron ); ?>"><span class="zg-cron-live-item"><i class="zg-cron-dot"></i> آخرین اجرا: <b data-last-run><?php echo $last_cron ? esc_html( human_time_diff( $last_cron ) . ' قبل' ) : '—'; ?></b></span><span class="zg-cron-live-item zg-cron-next"><i class="zg-cron-dot"></i> اجرای بعدی: <b data-next-run><?php echo $next_cron ? esc_html( human_time_diff( time(), $next_cron ) ) : '—'; ?></b></span></span></div></div>
		<div class="zg-cards">
			<div class="zg-card zg-gold"><span>طلای ۱۸ عیار</span>
				<div class="zg-card-duo"><div class="zg-duo"><small>فروش به مشتری (روی محصول)</small><b data-buy="18k"><?php echo esc_html( $fmt( $buy18 ) ); ?></b></div><div class="zg-duo"><small>خرید از مشتری</small><b data-sell="18k"><?php echo esc_html( $fmt( $sell18 ) ); ?></b></div></div>
				<small class="zg-card-unit" data-currency><?php echo esc_html( $currency_label ); ?></small></div>
			<div class="zg-card"><span>طلای ۲۴ عیار</span>
				<div class="zg-card-duo"><div class="zg-duo"><small>فروش به مشتری</small><b data-buy="24k"><?php echo esc_html( $fmt( $buy24 ) ); ?></b></div><div class="zg-duo"><small>خرید از مشتری</small><b data-sell="24k"><?php echo esc_html( $fmt( $sell24 ) ); ?></b></div></div>
				<small class="zg-card-unit" data-currency><?php echo esc_html( $currency_label ); ?></small></div>
			<div class="zg-card"><span>محصولات هوشمند</span><b><?php echo esc_html( number_format_i18n( $active ) ); ?></b><small>قابل بروزرسانی</small></div>
			<div class="zg-card <?php echo $errors ? 'zg-error-card' : ''; ?>"><span>خطاهای نیازمند بررسی</span><b><?php echo esc_html( number_format_i18n( $errors ) ); ?></b><small><?php echo $errors ? 'نیازمند اقدام' : 'همه‌چیز مرتب است'; ?></small></div>
		</div>
		<div class="zg-grid">
			<section class="zg-panel"><h2>قیمت‌های فعال بازار</h2><table class="widefat striped"><thead><tr><th>عنوان شاخص</th><th>قیمت فروش به مشتری (اعمال روی محصول)</th><th>قیمت خرید از مشتری</th><th>وضعیت</th></tr></thead><tbody><?php foreach ( ZG_Sources::price_keys() as $key => $label ) { $buy = isset( $snapshot['prices'][ $key ] ) ? $snapshot['prices'][ $key ] : 0; $sell = isset( $snapshot['sell_prices'][ $key ] ) ? $snapshot['sell_prices'][ $key ] : $buy; echo '<tr><td>' . esc_html( $label ) . '</td><td><strong class="zg-price-buy">' . ( $buy > 0 ? esc_html( number_format_i18n( $buy ) . ' ' . $currency_label ) : '—' ) . '</strong></td><td><span class="zg-price-sell">' . ( $sell > 0 ? esc_html( number_format_i18n( $sell ) . ' ' . $currency_label ) : '—' ) . '</span></td><td><span class="zg-status is-on">' . ( 'manual' === $final['mode'] ? 'API مستقیم' : 'موتور خودکار' ) . '</span></td></tr>'; } ?></tbody></table></section>
			<section class="zg-panel"><h2>شروع سریع</h2><ol class="zg-steps"><li><b>۱</b><span>در <a href="<?php echo esc_url( admin_url( 'admin.php?page=zarpulse-sources' ) ); ?>">منابع و حالت قیمت</a> حالت خودکار (پیشنهادی) یا دستی را انتخاب کنید.</span></li><li><b>۲</b><span>در <a href="<?php echo esc_url( admin_url( 'admin.php?page=zarpulse-formulas' ) ); ?>">فرمول‌ها</a> مدل قیمت‌گذاری فروشگاه را بسازید یا ویرایش کنید.</span></li><li><b>۳</b><span>از تب «قیمت‌گذاری طلا» در محصول ووکامرس، وزن و فرمول را انتخاب کنید.</span></li></ol><p class="description">برای پایداری زمان‌بندی، در سایت‌های کم‌ترافیک Cron واقعی سرور را به wp-cron.php متصل کنید (راهنما در تنظیمات عمومی).</p></section>
		</div>
		<section class="zg-panel zg-live-panel">
			<h2>وضعیت لحظه‌ای افزونه و روند محاسبه قیمت <span class="zg-live-indicator" data-dash-indicator>در حال بارگذاری…</span></h2>
			<div class="zg-live-summary">
				<span class="zg-chip" data-dash-mode>حالت: …</span>
				<span class="zg-chip" data-dash-consensus>روش: موتور قیمت‌گذاری خودکار زرپالس</span>
				<span class="zg-chip" data-dash-updated>آخرین بروزرسانی: …</span>
			</div>
			<div class="zg-trace" data-dash-trace><p class="description">این بخش هر ۵ ثانیه وضعیت محاسبه نرخ‌ها را به‌روزرسانی می‌کند.</p></div>
			<div class="zg-dash-log">
				<h3>لاگ زنده رویدادها <span class="zg-live-indicator" data-dash-log-indicator></span></h3>
				<div data-dash-events><p class="description">در حال بارگذاری…</p></div>
			</div>
		</section>
		<?php self::footer();
	}

	public static function sources() {
		if ( ! current_user_can( self::$capability ) ) { return; }
		self::header( 'تنظیمات قیمت‌گذاری بازار', 'محاسبه هوشمند و خودکار یا دریافت مستقیم از API' );
		$settings = self::settings_data();
		$currency = ZG_Currency::label();
		$mode     = ZG_Sources::resolve_mode( $settings );
		$direct   = wp_parse_args( isset( $settings['direct_api'] ) && is_array( $settings['direct_api'] ) ? $settings['direct_api'] : array(), ZG_Activator::default_settings()['direct_api'] );
		$auto_calc = ZG_Sources::calculate_auto_prices( false );
		$snapshot = ZG_Sources::snapshot();

		$gold_18k_buy = ( is_array( $auto_calc ) && isset( $auto_calc['gold_18k_buy'] ) ) ? (float) $auto_calc['gold_18k_buy'] : ( ( is_array( $auto_calc ) && isset( $auto_calc['gold_18k'] ) ) ? (float) $auto_calc['gold_18k'] : 0 );
		$gold_18k_sell = ( is_array( $auto_calc ) && isset( $auto_calc['gold_18k_sell'] ) ) ? (float) $auto_calc['gold_18k_sell'] : 0;

		$gold_24k_buy = ( is_array( $auto_calc ) && isset( $auto_calc['gold_24k_buy'] ) ) ? (float) $auto_calc['gold_24k_buy'] : ( ( is_array( $auto_calc ) && isset( $auto_calc['gold_24k'] ) ) ? (float) $auto_calc['gold_24k'] : 0 );
		$gold_24k_sell = ( is_array( $auto_calc ) && isset( $auto_calc['gold_24k_sell'] ) ) ? (float) $auto_calc['gold_24k_sell'] : 0;

		$mazaneh_705_buy = ( is_array( $auto_calc ) && isset( $auto_calc['mazaneh_705_buy'] ) ) ? (float) $auto_calc['mazaneh_705_buy'] : ( ( is_array( $auto_calc ) && isset( $auto_calc['mazaneh_705'] ) ) ? (float) $auto_calc['mazaneh_705'] : 0 );
		$mazaneh_705_sell = ( is_array( $auto_calc ) && isset( $auto_calc['mazaneh_705_sell'] ) ) ? (float) $auto_calc['mazaneh_705_sell'] : 0;

		$emami_coin_buy = ( is_array( $auto_calc ) && isset( $auto_calc['emami_coin_buy'] ) ) ? (float) $auto_calc['emami_coin_buy'] : ( ( is_array( $auto_calc ) && isset( $auto_calc['emami_coin'] ) ) ? (float) $auto_calc['emami_coin'] : 0 );
		$emami_coin_sell = ( is_array( $auto_calc ) && isset( $auto_calc['emami_coin_sell'] ) ) ? (float) $auto_calc['emami_coin_sell'] : 0;

		$half_coin_buy = ( is_array( $auto_calc ) && isset( $auto_calc['half_coin_buy'] ) ) ? (float) $auto_calc['half_coin_buy'] : ( ( is_array( $auto_calc ) && isset( $auto_calc['half_coin'] ) ) ? (float) $auto_calc['half_coin'] : 0 );
		$half_coin_sell = ( is_array( $auto_calc ) && isset( $auto_calc['half_coin_sell'] ) ) ? (float) $auto_calc['half_coin_sell'] : 0;

		$quarter_coin_buy = ( is_array( $auto_calc ) && isset( $auto_calc['quarter_coin_buy'] ) ) ? (float) $auto_calc['quarter_coin_buy'] : ( ( is_array( $auto_calc ) && isset( $auto_calc['quarter_coin'] ) ) ? (float) $auto_calc['quarter_coin'] : 0 );
		$quarter_coin_sell = ( is_array( $auto_calc ) && isset( $auto_calc['quarter_coin_sell'] ) ) ? (float) $auto_calc['quarter_coin_sell'] : 0;

		$emami_intrinsic = ( is_array( $auto_calc ) && isset( $auto_calc['emami_intrinsic'] ) ) ? (float) $auto_calc['emami_intrinsic'] : 0;
		$half_intrinsic = ( is_array( $auto_calc ) && isset( $auto_calc['half_coin_intrinsic'] ) ) ? (float) $auto_calc['half_coin_intrinsic'] : 0;
		$quarter_intrinsic = ( is_array( $auto_calc ) && isset( $auto_calc['quarter_coin_intrinsic'] ) ) ? (float) $auto_calc['quarter_coin_intrinsic'] : 0;
		?>
		<form method="post" class="zg-form" id="zg-sources-form">
		<?php wp_nonce_field( 'zg_admin_action' ); ?>
		<input type="hidden" name="zg_admin_action" value="save_sources_page">

		<div class="zg-grid zg-grid-wide">
		<section class="zg-panel">
		<h2>حالت قیمت‌گذاری</h2>
		<div class="zg-mode-switch zg-mode-switch--2">
			<label class="zg-mode-option is-recommended <?php echo 'auto' === $mode ? 'is-active' : ''; ?>">
				<input type="radio" name="pricing_mode" value="auto" data-mode-radio <?php checked( $mode, 'auto' ); ?>>
				<span class="zg-mode-option-card">
					<span class="zg-recommended-tag">پیشنهادی</span>
					<strong>محاسبه هوشمند و خودکار (پیشنهادی)</strong>
					<small>محاسبه بلادرنگ و دقیق نرخ طلا، مظنه و مسکوکات با موتور اختصاصی زرپالس</small>
				</span>
			</label>
			<label class="zg-mode-option <?php echo 'manual' === $mode ? 'is-active' : ''; ?>">
				<input type="radio" name="pricing_mode" value="manual" data-mode-radio <?php checked( $mode, 'manual' ); ?>>
				<span class="zg-mode-option-card">
					<strong>دستی (API مستقیم)</strong>
					<small>دریافت مستقیم نرخ‌ها از وب‌سرویس یا URL دلخواه شما بدون موتور محاسبه خودکار</small>
				</span>
			</label>
		</div>

		<!-- پنل حالت ۱: محاسبه خودکار -->
		<div data-mode-panel="auto" class="zg-mode-panel" style="<?php echo 'auto' === $mode ? 'display:block;' : 'display:none;'; ?>">
			<div class="zg-auto-live-box">
				<h3>نرخ‌های زنده محاسبه‌شده <span class="zg-live-indicator" data-auto-indicator>● زنده</span></h3>
				<div class="zg-auto-metrics-grid">
					<div class="zg-metric-card zg-metric-gold">
						<span>طلای ۱۸ عیار (فروش به مشتری / خرید از مشتری)</span>
						<b data-auto-18k><?php echo $gold_18k_buy > 0 ? number_format_i18n( round( $gold_18k_buy ) ) . ' / ' . number_format_i18n( round( $gold_18k_sell ) ) . ' تومان' : '—'; ?></b>
					</div>
					<div class="zg-metric-card">
						<span>طلای ۲۴ عیار خالص (فروش به مشتری / خرید از مشتری)</span>
						<b data-auto-24k><?php echo $gold_24k_buy > 0 ? number_format_i18n( round( $gold_24k_buy ) ) . ' / ' . number_format_i18n( round( $gold_24k_sell ) ) . ' تومان' : '—'; ?></b>
					</div>
					<div class="zg-metric-card">
						<span>مظنه ۷۰۵ (مثقال) (فروش به مشتری / خرید از مشتری)</span>
						<b data-auto-mazaneh><?php echo $mazaneh_705_buy > 0 ? number_format_i18n( round( $mazaneh_705_buy ) ) . ( $mazaneh_705_sell > 0 ? ' / ' . number_format_i18n( round( $mazaneh_705_sell ) ) : '' ) . ' تومان' : '—'; ?></b>
					</div>
					<div class="zg-metric-card">
						<span>سکه امامی (فروش به مشتری / خرید از مشتری)</span>
						<b data-auto-emami-coin><?php echo $emami_coin_buy > 0 ? number_format_i18n( round( $emami_coin_buy ) ) . ' / ' . number_format_i18n( round( $emami_coin_sell ) ) . ' تومان' : '—'; ?></b>
					</div>
					<div class="zg-metric-card">
						<span>نیم‌سکه (فروش به مشتری / خرید از مشتری)</span>
						<b data-auto-half-coin><?php echo $half_coin_buy > 0 ? number_format_i18n( round( $half_coin_buy ) ) . ' / ' . number_format_i18n( round( $half_coin_sell ) ) . ' تومان' : '—'; ?></b>
					</div>
					<div class="zg-metric-card">
						<span>ربع‌سکه (فروش به مشتری / خرید از مشتری)</span>
						<b data-auto-quarter-coin><?php echo $quarter_coin_buy > 0 ? number_format_i18n( round( $quarter_coin_buy ) ) . ' / ' . number_format_i18n( round( $quarter_coin_sell ) ) . ' تومان' : '—'; ?></b>
					</div>
					<div class="zg-metric-card">
						<span>ارزش ذاتی سکه امامی</span>
						<b data-auto-emami-intrinsic><?php echo $emami_intrinsic > 0 ? number_format_i18n( round( $emami_intrinsic ) ) . ' تومان' : '—'; ?></b>
					</div>
					<div class="zg-metric-card">
						<span>ارزش ذاتی نیم‌سکه / ربع‌سکه</span>
						<b data-auto-sub-intrinsic><?php echo $half_intrinsic > 0 ? number_format_i18n( round( $half_intrinsic ) ) . ' / ' . number_format_i18n( round( $quarter_intrinsic ) ) . ' تومان' : '—'; ?></b>
					</div>
				</div>
				<div class="zg-auto-actions">
					<button type="button" class="button button-secondary" data-test-auto-engine>تست و بازخوانی زنده موتور فرمول</button>
					<span class="zg-auto-test-result" data-auto-test-result></span>
				</div>
				<p class="zg-notice-subtle">نکته مهم: قیمت مسکوکات بر پایه ارزش ذاتی و ضرایب استاندارد بازار محاسبه می‌شود.</p>
			</div>
		</div>

		<!-- پنل حالت ۲: دستی (API مستقیم) -->
		<div data-mode-panel="manual" class="zg-mode-panel" style="<?php echo 'manual' === $mode ? 'display:block;' : 'display:none;'; ?>">
			<p class="description">در این حالت افزونه موتور محاسبه خودکار را کنار می‌گذارد و قیمت را مستقیماً از API یا URL شما می‌خواند.</p>
			<div class="zg-form-row">
				<label class="zg-block-label">آدرس API / URL <small>الزامی</small>
					<input dir="ltr" type="url" name="direct_api_url" placeholder="https://api.example.com/gold" value="<?php echo esc_attr( $direct['url'] ); ?>"></label>
				<label class="zg-block-label">متد
					<select name="direct_api_method">
						<option value="GET" <?php selected( $direct['method'], 'GET' ); ?>>GET</option>
						<option value="POST" <?php selected( $direct['method'], 'POST' ); ?>>POST</option>
						<option value="PUT" <?php selected( $direct['method'], 'PUT' ); ?>>PUT</option>
					</select></label>
				<label class="zg-block-label">نوع پاسخ
					<select name="direct_api_format" class="zg-direct-format">
						<option value="json" <?php selected( $direct['format'], 'json' ); ?>>JSON (با مسیر)</option>
						<option value="text" <?php selected( $direct['format'], 'text' ); ?>>عدد / متن ساده</option>
					</select></label>
			</div>
			<label class="zg-block-label">Bearer Token <small>اختیاری؛ رمزنگاری‌شده ذخیره می‌شود. برای حفظ توکن فعلی، خالی بگذارید.</small>
				<input dir="ltr" type="password" name="direct_api_token" placeholder="برای حفظ توکن فعلی خالی بگذارید" autocomplete="new-password"></label>
			<label class="zg-block-label">پارامترهای درخواست (JSON) <small>برای GET به‌صورت query و برای POST/PUT به‌صورت بدنه ارسال می‌شود.</small>
				<textarea dir="ltr" name="direct_api_params" placeholder="{}"><?php echo esc_textarea( $direct['params'] ); ?></textarea></label>

			<div class="zg-direct-json-fields">
				<div class="zg-form-row">
					<label class="zg-block-label">مسیر قیمت خرید <small>مثلا: Data[7] یا data.price</small>
						<input dir="ltr" type="text" name="direct_api_buy_path" placeholder="Data[7]" value="<?php echo esc_attr( $direct['buy_path'] ); ?>"></label>
					<label class="zg-block-label">مسیر قیمت فروش <small>اختیاری؛ خالی = برابر قیمت خرید</small>
						<input dir="ltr" type="text" name="direct_api_sell_path" placeholder="Data[8]" value="<?php echo esc_attr( $direct['sell_path'] ); ?>"></label>
				</div>
				<div class="zg-form-row">
					<label class="zg-block-label">مسیر قیمت خرید ۲۴ عیار <small>اختیاری</small>
						<input dir="ltr" type="text" name="direct_api_buy_path_24k" placeholder="data.gold24" value="<?php echo esc_attr( $direct['buy_path_24k'] ); ?>"></label>
					<label class="zg-block-label">مسیر قیمت فروش ۲۴ عیار <small>اختیاری</small>
						<input dir="ltr" type="text" name="direct_api_sell_path_24k" placeholder="data.gold24_sell" value="<?php echo esc_attr( $direct['sell_path_24k'] ); ?>"></label>
				</div>
			</div>
			<div class="zg-direct-text-hint" style="display:none;"><p class="zg-source-help">در حالت «عدد / متن ساده»، خود افزونه اولین عدد موجود در پاسخ را (حتی داخل HTML) به‌عنوان قیمت خرید ۱۸ عیار می‌خواند.</p></div>

			<div class="zg-form-row">
				<label class="zg-block-label">واحد قیمت منبع
					<select name="direct_api_unit">
						<option value="store" <?php selected( $direct['unit'], 'store' ); ?>>همان واحد فروشگاه</option>
						<option value="rial" <?php selected( $direct['unit'], 'rial' ); ?>>ریال</option>
						<option value="toman" <?php selected( $direct['unit'], 'toman' ); ?>>تومان</option>
						<option value="usd" <?php selected( $direct['unit'], 'usd' ); ?>>دلار</option>
					</select></label>
				<label class="zg-block-label">مدت کش (ثانیه)
					<input type="number" min="30" max="3600" name="direct_api_cache_ttl" value="<?php echo esc_attr( $direct['cache_ttl'] ); ?>"></label>
			</div>
			<p><button type="button" class="button zg-test-direct" data-direct-test>تست اتصال API</button> <span class="zg-direct-test-result" data-direct-test-result></span></p>
		</div>

		<p class="zg-sources-save">
			<button type="submit" class="button button-primary button-large">ذخیره تغییرات و بازخوانی قیمت‌ها</button>
			<span class="description">ذخیره، بلافاصله نرخ‌های جدید را محاسبه و قیمت محصولات را بروزرسانی می‌کند.</span>
		</p>
		</section>

		<section class="zg-panel">
			<h2>نرخ‌های فعال فروشگاه <span class="zg-live-indicator" data-live-indicator>● زنده</span></h2>
			<p class="description">این قیمت‌ها بر اساس واحد پول فروشگاه (<?php echo esc_html( $currency ); ?>) محاسبه و بر روی محصولات اعمال می‌شوند.</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>شاخص</th>
						<th>نرخ خرید (<?php echo esc_html( $currency ); ?>)</th>
						<th>نرخ فروش (<?php echo esc_html( $currency ); ?>)</th>
						<th>روش محاسبه</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$keys = ZG_Sources::price_keys();
					foreach ( $keys as $k => $lbl ) :
						$buy_val = isset( $snapshot['prices'][ $k ] ) ? $snapshot['prices'][ $k ] : 0;
						$sell_val = isset( $snapshot['sell_prices'][ $k ] ) ? $snapshot['sell_prices'][ $k ] : $buy_val;
					?>
					<tr>
						<td><strong><?php echo esc_html( $lbl ); ?></strong></td>
						<td class="zg-live-price" data-live-item="<?php echo esc_attr( $k ); ?>">
							<b><?php echo $buy_val > 0 ? esc_html( number_format_i18n( $buy_val ) . ' ' . $currency ) : 'در حال دریافت...'; ?></b>
						</td>
						<td class="zg-live-price" data-live-sell-item="<?php echo esc_attr( $k ); ?>">
							<b><?php echo $sell_val > 0 ? esc_html( number_format_i18n( $sell_val ) . ' ' . $currency ) : 'در حال دریافت...'; ?></b>
						</td>
						<td>
							<span class="zg-status is-on"><?php echo 'manual' === $mode ? 'API مستقیم' : 'موتور خودکار'; ?></span>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		</div>
		</form>
		<?php
		self::footer();
	}

	public static function formulas() {
		if ( ! current_user_can( self::$capability ) ) { return; }
		self::header( 'فرمول‌ساز قیمت', 'ساخت فرمول‌های امن با متغیرهای آماده؛ بدون اجرای کد PHP یا JavaScript' );
		$formulas = ZG_Pricing::formulas();
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$editing = $edit_id && isset( $formulas[ $edit_id ] ) ? $formulas[ $edit_id ] : array( 'id' => '', 'name' => '', 'description' => '', 'expression' => '((weight * base_18k * karat_factor) + wage + stone + extra + profit_fixed) * (1 + profit_percent / 100) * (1 + tax_percent / 100)', 'active' => 1 );
		?>
		<div class="zg-grid zg-grid-wide"><section class="zg-panel"><h2>فرمول‌های ذخیره‌شده</h2><div class="zg-formula-list"><?php foreach ( $formulas as $formula ) : ?><article><div><h3><?php echo esc_html( $formula['name'] ); ?> <span class="zg-status <?php echo $formula['active'] ? 'is-on' : 'is-off'; ?>"><?php echo $formula['active'] ? 'فعال' : 'غیرفعال'; ?></span></h3><p><?php echo esc_html( $formula['description'] ); ?></p><code dir="ltr"><?php echo esc_html( $formula['expression'] ); ?></code></div><div class="zg-list-actions"><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=zarpulse-formulas&edit=' . $formula['id'] ) ); ?>">ویرایش</a><form method="post"><?php wp_nonce_field( 'zg_admin_action' ); ?><input type="hidden" name="zg_admin_action" value="delete_formula"><input type="hidden" name="formula_id" value="<?php echo esc_attr( $formula['id'] ); ?>"><button class="button button-small zg-danger" onclick="return confirm('فرمول حذف شود؟')">حذف</button></form></div></article><?php endforeach; ?></div></section>
		<section class="zg-panel"><h2><?php echo $editing['id'] ? 'ویرایش فرمول' : 'ساخت فرمول تازه'; ?></h2><form method="post" class="zg-form"><input type="hidden" name="zg_admin_action" value="save_formula"><?php wp_nonce_field( 'zg_admin_action' ); ?><input type="hidden" name="formula_id" value="<?php echo esc_attr( $editing['id'] ); ?>"><label class="zg-block-label">نام فرمول<input required name="formula_name" value="<?php echo esc_attr( $editing['name'] ); ?>"></label><label class="zg-block-label">توضیح کوتاه<textarea name="formula_description"><?php echo esc_textarea( $editing['description'] ); ?></textarea></label><label class="zg-block-label">عبارت محاسبه <small>عملگرهای مجاز: +، -، *، / و پرانتز</small><textarea dir="ltr" class="zg-expression" required id="zg_formula_expression" name="formula_expression"><?php echo esc_textarea( $editing['expression'] ); ?></textarea></label><div class="zg-token-box"><strong>افزودن متغیر به فرمول</strong><div><?php foreach ( ZG_Formula_Engine::variables() as $key => $label ) { echo '<button type="button" class="button button-small zg-token" data-token="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button>'; } ?></div></div><label class="zg-check"><input type="checkbox" name="formula_active" value="1" <?php checked( $editing['active'] ); ?>> فرمول فعال باشد</label><p><button class="button button-primary">ذخیره فرمول</button><?php if ( $editing['id'] ) : ?><a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=zarpulse-formulas' ) ); ?>">انصراف</a><?php endif; ?></p></form></section></div>
		<?php self::footer();
	}

	public static function reports() {
		if ( ! current_user_can( self::$capability ) ) { return; }
		self::header( 'گزارش‌ها و رخدادها', 'مانیتورینگ زنده بروزرسانی‌ها، خطاهای منابع و تاریخچه قیمت' );
		?>
		<div class="zg-toolbar"><div><strong>گزارش‌ها به‌صورت زنده به‌روز می‌شوند</strong> <span class="zg-live-indicator" data-reports-indicator>در حال بارگذاری…</span></div></div>
		<div class="zg-grid"><section class="zg-panel"><h2>آخرین قیمت‌های ثبت‌شده</h2><div data-reports-history><?php echo self::reports_history_html(); ?></div></section><section class="zg-panel"><h2>راهنمای رفع خطا</h2><ul class="zg-help-list"><li>خطای منبع را ابتدا با «تست اتصال» در صفحه منابع بررسی کنید.</li><li>در صورت در دسترس نبودن موقت یک منبع، کش هوشمند مانع توقف فروشگاه می‌شود.</li><li>محصولات خطادار را از بخش محصولات و متای «آخرین خطا» بررسی کنید.</li></ul></section></div><section class="zg-panel"><h2>گزارش محصولات هوشمند</h2><p class="description">اثر سود، اختلاف خروجی فرمول فعلی با همان فرمول در حالت سود ثابت و درصدی صفر است؛ این عدد با نرخ جاری بازار محاسبه می‌شود.</p><div data-reports-products><?php echo self::reports_products_html(); ?></div></section><section class="zg-panel"><h2>رویدادهای اخیر</h2><div data-reports-events><?php echo self::reports_events_html(); ?></div></section>
		<?php self::footer();
	}

	/** گزارش زنده بدون ری‌لود صفحه */
	public static function ajax_reports_live() {
		check_ajax_referer( 'zg_admin_nonce', 'nonce' );
		if ( ! current_user_can( self::$capability ) ) { wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 ); }
		wp_send_json_success( array(
			'history'    => self::reports_history_html(),
			'products'   => self::reports_products_html(),
			'events'     => self::reports_events_html(),
			'updated_at' => time(),
		) );
	}

	private static function reports_history_html() {
		global $wpdb;
		$history = $wpdb->get_results( "SELECT h.* FROM {$wpdb->prefix}zg_price_history h INNER JOIN (SELECT price_key, MAX(id) id FROM {$wpdb->prefix}zg_price_history GROUP BY price_key) x ON x.id=h.id ORDER BY h.price_key" );
		$out = '<table class="widefat striped"><thead><tr><th>عنوان</th><th>قیمت</th><th>منبع</th><th>زمان</th></tr></thead><tbody>';
		if ( empty( $history ) ) { $out .= '<tr><td colspan="4">هنوز قیمتی ثبت نشده است.</td></tr>'; }
		foreach ( $history as $item ) {
			$unit = ZG_Currency::label();
			$label = isset( ZG_Sources::price_keys()[ $item->price_key ] ) ? ZG_Sources::price_keys()[ $item->price_key ] : $item->price_key;
			$out .= '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( number_format_i18n( $item->price_value ) . ' ' . $unit ) . '</td><td>' . esc_html( $item->source_id ) . '</td><td>' . esc_html( get_date_from_gmt( $item->created_at, 'Y/m/d H:i:s' ) ) . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	private static function reports_products_html() {
		$smart_products = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private' ), 'posts_per_page' => 50, 'meta_key' => '_zg_enabled', 'meta_value' => 'yes', 'orderby' => 'modified', 'order' => 'DESC' ) );
		$out = '<table class="widefat striped"><thead><tr><th>محصول</th><th>قیمت آخر</th><th>اثر سود در فرمول</th><th>آخرین بروزرسانی</th><th>وضعیت</th></tr></thead><tbody>';
		if ( empty( $smart_products ) ) { $out .= '<tr><td colspan="5">هنوز محصول هوشمندی ثبت نشده است.</td></tr>'; }
		foreach ( $smart_products as $smart_product ) {
			$impact = ZG_Pricing::profit_impact( $smart_product->ID );
			$error = get_post_meta( $smart_product->ID, '_zg_last_error', true );
			$out .= '<tr><td><a href="' . esc_url( get_edit_post_link( $smart_product->ID ) ) . '">' . esc_html( get_the_title( $smart_product ) ) . '</a></td><td>' . esc_html( number_format_i18n( (float) get_post_meta( $smart_product->ID, '_zg_last_price', true ) ) ) . '</td><td>' . esc_html( number_format_i18n( $impact ) ) . '</td><td>' . esc_html( get_post_meta( $smart_product->ID, '_zg_last_sync', true ) ?: '—' ) . '</td><td>' . ( $error ? '<span class="zg-level error">' . esc_html( $error ) . '</span>' : '<span class="zg-level info">سالم</span>' ) . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	private static function reports_events_html() {
		global $wpdb;
		$events = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}zg_events ORDER BY id DESC LIMIT 80" );
		$out = '<table class="widefat striped zg-events"><thead><tr><th>زمان</th><th>نوع</th><th>سطح</th><th>پیام</th></tr></thead><tbody>';
		if ( empty( $events ) ) { $out .= '<tr><td colspan="4">هنوز رویدادی ثبت نشده است.</td></tr>'; }
		foreach ( $events as $event ) {
			$out .= '<tr><td>' . esc_html( get_date_from_gmt( $event->created_at, 'Y/m/d H:i:s' ) ) . '</td><td>' . esc_html( $event->event_type ) . '</td><td><span class="zg-level ' . esc_attr( $event->level ) . '">' . esc_html( $event->level ) . '</span></td><td>' . esc_html( $event->message ) . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	public static function settings() {
		if ( ! current_user_can( self::$capability ) ) { return; }
		self::header( 'تنظیمات عمومی', 'واحد نمایش، ورود دستی، زمان‌بندی و پروفایل‌های دسته‌بندی' );
		$settings = self::settings_data();
		$formulas = ZG_Pricing::formulas();
		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$cron_url = home_url( 'wp-cron.php?doing_wp_cron' );
		$cron_tick_url = add_query_arg( array( 'action' => 'zg_public_cron_tick', 'token' => ZG_Cron::token() ), admin_url( 'admin-ajax.php' ) );
		$cron_status = ZG_Cron::wpconfig_status();
		$crontab_cmd = '*/1 * * * * wget -q -O /dev/null "' . $cron_tick_url . '" >/dev/null 2>&1';
		?>
		<form method="post" class="zg-settings-form"><input type="hidden" name="zg_admin_action" value="save_settings"><?php wp_nonce_field( 'zg_admin_action' ); ?>
		<div class="zg-grid"><section class="zg-panel"><h2>ورود دستی قیمت (پشتیبان)</h2><p class="description">وقتی هیچ منبع آنلاینی پاسخ ندهد، این قیمت‌ها به‌عنوان مسیر آخر استفاده می‌شوند.</p><?php foreach ( ZG_Sources::price_keys() as $key => $label ) : ?><label class="zg-block-label"><?php echo esc_html( $label ); ?><input type="number" min="0" step="0.01" name="manual_prices[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings['manual_prices'][ $key ] ?? 0 ); ?>"></label><?php endforeach; ?><label class="zg-block-label">واحد پول فروشگاه<select name="currency_unit"><option value="auto" <?php selected( $settings['currency_unit'] ?? 'auto', 'auto' ); ?>>تشخیص خودکار از ووکامرس (پیشنهاد می‌شود)</option><option value="rial" <?php selected( $settings['currency_unit'] ?? 'auto', 'rial' ); ?>>ریال</option><option value="toman" <?php selected( $settings['currency_unit'] ?? 'auto', 'toman' ); ?>>تومان</option></select><small>کد فعلی ووکامرس: <code dir="ltr"><?php echo esc_html( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : get_option( 'woocommerce_currency', 'IRR' ) ); ?></code> — واحد مؤثر: <strong><?php echo esc_html( ZG_Currency::store_unit() === 'rial' ? 'ریال' : 'تومان' ); ?></strong></small></label><label class="zg-block-label">عنوان نمایشی واحد پول<input name="currency_label" value="<?php echo esc_attr( $settings['currency_label'] ); ?>"></label><label class="zg-block-label">واحد قیمت‌های دستی<select name="manual_price_unit"><option value="store" <?php selected( $settings['manual_price_unit'] ?? 'store', 'store' ); ?>>همان واحد فروشگاه</option><option value="rial" <?php selected( $settings['manual_price_unit'] ?? 'store', 'rial' ); ?>>ریال</option><option value="toman" <?php selected( $settings['manual_price_unit'] ?? 'store', 'toman' ); ?>>تومان</option></select></label><label class="zg-check"><input type="checkbox" name="round_thousand" value="1" <?php checked( ! empty( $settings['round_thousand'] ) ); ?>> <strong>رند کردن ۳ رقم آخر به ۱,۰۰۰ تومان (000)</strong></label><small class="description" style="display:block;margin:-8px 0 14px 20px;color:#718187;">اگر فعال باشد، ۳ رقم آخر قیمت‌ها رند می‌شود (زیر ۵۰۰ به پایین و ۵۰۰ و بالاتر به سمت بالا رند می‌شود).</small></section>
		</div>
		<section class="zg-panel" id="zg-cron-guide"><h2>تنظیم Cron واقعی سرور</h2><p class="description">WP-Cron فقط وقتی کسی از سایت بازدید می‌کند اجرا می‌شود؛ در ساعات کم‌ترافیک قیمت‌ها عقب می‌افتند. برای بروزرسانی واقعاً لحظه‌ای، کافی است یک Cron Job در پنل هاست بسازید که هر ۱ دقیقه آدرس زیر را صدا بزند (این آدرس فقط وظیفه زرپالس را اجرا می‌کند و با توکن اختصاصی محافظت شده است):</p><div class="zg-cron-status"><span class="zg-cron-chip <?php echo $cron_status['exists'] ? 'is-ok' : 'is-err'; ?>">فایل wp-config.php: <?php echo $cron_status['exists'] ? 'موجود' : 'پیدا نشد'; ?></span><span class="zg-cron-chip <?php echo $cron_status['writable'] ? 'is-ok' : 'is-err'; ?>">قابل نوشتن: <?php echo $cron_status['writable'] ? 'بله' : 'خیر'; ?></span><span class="zg-cron-chip <?php echo $cron_status['disabled'] ? 'is-ok' : 'is-muted'; ?>">DISABLE_WP_CRON: <?php echo $cron_status['disabled'] ? 'فعال' : 'غیرفعال'; ?></span></div><div class="zg-cron-guide"><h3>۱) آدرس Cron (هر ۱ دقیقه اجرا شود)</h3><div class="zg-cron-url"><input dir="ltr" type="text" readonly value="<?php echo esc_url( $cron_tick_url ); ?>"><button type="button" class="button zg-copy-btn" data-copy-target="<?php echo esc_url( $cron_tick_url ); ?>">کپی آدرس</button></div><p class="description">نمونه دستور آماده برای پنل هاست (crontab):</p><div class="zg-cron-url"><input dir="ltr" type="text" readonly value="<?php echo esc_attr( $crontab_cmd ); ?>"><button type="button" class="button zg-copy-btn" data-copy-target="<?php echo esc_attr( $crontab_cmd ); ?>">کپی دستور</button></div><h3>۲) غیرفعال‌کردن WP-Cron خودکار وردپرس (اختیاری ولی پیشنهادی)</h3><?php if ( ! empty( $cron_status['disabled'] ) ) : ?><p class="zg-cron-done">✓ DISABLE_WP_CRON از قبل در wp-config.php تنظیم شده است؛ نیازی به اقدام نیست.</p><?php elseif ( ! empty( $cron_status['writable'] ) ) : ?><p class="description">می‌توانید با یک کلیک، خط <code dir="ltr">define( 'DISABLE_WP_CRON', true );</code> را خودکار به wp-config.php اضافه کنید تا Cron فقط توسط سرور اجرا شود:</p><p><button type="button" class="button button-primary" id="zg-configure-cron">تنظیم خودکار wp-config.php</button> <span class="zg-direct-test-result" data-cron-config-result></span></p><?php else : ?><p class="description">فایل wp-config.php در دسترس نوشتن نیست. این خط را خودتان به ابتدای فایل (بعد از <code dir="ltr">&lt;?php</code>) اضافه کنید:</p><textarea dir="ltr" readonly class="zg-cron-code">define( 'DISABLE_WP_CRON', true );</textarea><div class="zg-cron-url"><input dir="ltr" type="text" readonly value="<?php echo esc_url( $cron_url ); ?>"><button type="button" class="button zg-copy-btn" data-copy-target="<?php echo esc_url( $cron_url ); ?>">کپی آدرس جایگزین</button></div><p class="description">همچنین می‌توانید Cron سرور را مستقیماً به <code dir="ltr">wp-cron.php</code> وصل کنید (آدرس بالا).</p><?php endif; ?></div></section>
		<section class="zg-panel"><h2>پروفایل دسته‌بندی</h2><p class="description">برای هر دسته، یک فرمول و سود پیش‌فرض تعیین کنید. این مقادیر فقط وقتی فیلد متناظر محصول خالی باشد اعمال می‌شود.</p><table class="widefat striped"><thead><tr><th>دسته</th><th>فرمول پیش‌فرض</th><th>سود پیش‌فرض (%)</th></tr></thead><tbody><?php foreach ( $categories as $category ) : $profile = $settings['category_profiles'][ $category->term_id ] ?? array(); ?><tr><td><?php echo esc_html( $category->name ); ?></td><td><select name="category_formula[<?php echo esc_attr( $category->term_id ); ?>]"><option value="">— بدون پیش‌فرض —</option><?php foreach ( $formulas as $id => $formula ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( $profile['formula_id'] ?? '', $id, false ) . '>' . esc_html( $formula['name'] ) . '</option>'; } ?></select></td><td><input type="number" min="0" step="0.01" name="category_profit[<?php echo esc_attr( $category->term_id ); ?>]" value="<?php echo esc_attr( $profile['profit_percent'] ?? '' ); ?>"></td></tr><?php endforeach; ?></tbody></table></section>
		<section class="zg-panel"><h2>رزرو قیمت و تایمر پرداخت</h2><p class="description">برای کالاهای قیمت‌گذاری‌شده با زرپالس، زمان رزرو مستقل تعریف می‌شود. پس از پایان مهلت، هر آیتم منقضی‌شده از سبد حذف می‌شود تا مشتری نرخ تازه دریافت کند.</p><label class="zg-check"><input type="checkbox" name="price_lock_enabled" value="1" <?php checked( ! empty( $settings['price_lock_enabled'] ) ); ?>> فعال‌سازی قفل زمان‌دار قیمت</label><label class="zg-block-label">مدت رزرو هر محصول (دقیقه)<input type="number" min="1" max="120" name="price_lock_minutes" value="<?php echo esc_attr( $settings['price_lock_minutes'] ?? 5 ); ?>"></label><label class="zg-block-label">محل نمایش تایمر<select name="price_lock_display_mode"><option value="automatic" <?php selected( $settings['price_lock_display_mode'] ?? 'automatic', 'automatic' ); ?>>نمایش خودکار در مینی‌کارت، سبد و تسویه حساب</option><option value="shortcode" <?php selected( $settings['price_lock_display_mode'] ?? 'automatic', 'shortcode' ); ?>>نمایش سفارشی با شورت‌کد</option></select><small>در حالت سفارشی، کد <code dir="ltr">[zarpulse_price_lock_timer]</code> را در هر برگه، ابزارک یا قالب دلخواه قرار دهید.</small></label></section>
		<p><button class="button button-primary button-large">ذخیره همه تنظیمات</button></p></form>
		<?php self::footer();
	}
}
