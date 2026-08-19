<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZG_Cron {
	const HOOK = 'zg_refresh_prices';
	const LOCK = 'zg_cron_run_lock';

	public static function schedules( $schedules ) {
		$schedules['zg_every_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => 'هر دقیقه (زرپالس)' );
		$schedules['zg_every_5_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'هر ۵ دقیقه (زرپالس)' );
		$schedules['zg_every_15_minutes'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'هر ۱۵ دقیقه (زرپالس)' );
		$schedules['zg_every_30_minutes'] = array( 'interval' => 30 * MINUTE_IN_SECONDS, 'display' => 'هر ۳۰ دقیقه (زرپالس)' );
		return $schedules;
	}

	public static function interval_key() {
		// بروزرسانی لحظه‌ای: همیشه هر دقیقه.
		return 'zg_every_minute';
	}

	public static function reschedule() {
		self::unschedule();
		$ok = wp_schedule_event( time() + 10, self::interval_key(), self::HOOK, array(), true );
		if ( is_wp_error( $ok ) ) {
			ZG_Activator::log( 'cron_schedule_error', $ok->get_error_message(), 'error' );
			return false;
		}
		return true;
	}

	public static function ensure_schedule() {
		if ( wp_next_scheduled( self::HOOK ) ) { return true; }
		return self::reschedule();
	}

	public static function unschedule() {
		while ( $timestamp = wp_next_scheduled( self::HOOK ) ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public static function maybe_kick() {
		if ( wp_doing_cron() ) { return; }
		self::ensure_schedule();

		// اگر به‌تازگی اجرا شده باشد کاری لازم نیست؛ در غیر این صورت Cron را بیدار می‌کنیم.
		if ( self::fresh() ) { return; }
		if ( get_transient( 'zg_background_kick_lock' ) ) { return; }
		set_transient( 'zg_background_kick_lock', 1, 45 );

		$next = wp_next_scheduled( self::HOOK );
		if ( ! $next ) {
			wp_schedule_single_event( time() + 3, self::HOOK, array() );
		}

		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			// پنل مدیریت/AJAX: اجرای غیرمسدودکننده.
			$spawned = false;
			if ( function_exists( 'spawn_cron' ) ) {
				$spawned = (bool) spawn_cron( time() );
			}
			if ( ! $spawned ) {
				self::async_loopback();
			}
			// تضمین نهایی پس از ارسال پاسخ.
			add_action( 'shutdown', array( __CLASS__, 'shutdown_kick' ), 20 );
		} else {
			// فرانت‌اند: پیش از رندر صفحه اجرا می‌شود تا محصول قیمت تازه‌ای داشته باشد.
			add_action( 'wp', array( __CLASS__, 'frontend_sync' ), 1 );
		}
	}

	/** آیا قیمت‌ها به‌اندازه کافی تازه هستند؟ (لحظه‌ای: حداکثر ۳۰ ثانیه) */
	public static function fresh() {
		$last = (int) get_option( 'zg_last_cron_run', 0 );
		return $last && ( time() - $last ) < 30;
	}

	/** بروزرسانی همگام در فرانت‌اند (قبل از نمایش صفحه) تا قیمت تازه باشد. */
	public static function frontend_sync() {
		if ( wp_doing_cron() || self::fresh() || get_transient( self::LOCK ) ) { return; }
		self::run();
	}

	/** اجرای تضمینی پس از ارسال صفحه (غیرمسدودکننده برای بازدیدکننده). */
	public static function shutdown_kick() {
		if ( wp_doing_cron() || self::fresh() ) { return; }
		self::run();
	}

	public static function run() {
		if ( get_transient( self::LOCK ) ) { return; }
		set_transient( self::LOCK, 1, 90 );
		update_option( 'zg_last_cron_started', time(), false );
		try {
			$refresh = ZG_Sources::refresh( true );
			if ( is_wp_error( $refresh ) ) {
				update_option( 'zg_last_cron_error', $refresh->get_error_message(), false );
				update_option( 'zg_last_cron_run', time(), false );
				ZG_Activator::log( 'cron_error', $refresh->get_error_message(), 'error' );
				return;
			}
			$sync = ZG_Pricing::sync_all();
			if ( is_wp_error( $sync ) ) {
				update_option( 'zg_last_cron_error', $sync->get_error_message(), false );
				update_option( 'zg_last_cron_run', time(), false );
				ZG_Activator::log( 'cron_error', $sync->get_error_message(), 'error' );
				return;
			}
			update_option( 'zg_last_cron_run', time(), false );
			update_option( 'zg_last_cron_result', array( 'updated' => isset( $sync['updated'] ) ? (int) $sync['updated'] : 0, 'errors' => isset( $sync['errors'] ) ? count( $sync['errors'] ) : 0 ), false );
			delete_option( 'zg_last_cron_error' );
		} finally {
			delete_transient( self::LOCK );
			if ( ! wp_next_scheduled( self::HOOK ) ) { self::ensure_schedule(); }
		}
	}

	public static function interval_seconds() {
		$interval = self::interval_key();
		$map = array( 'zg_every_minute' => 60, 'zg_every_5_minutes' => 300, 'zg_every_15_minutes' => 900, 'zg_every_30_minutes' => 1800, 'hourly' => HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS );
		return isset( $map[ $interval ] ) ? $map[ $interval ] : 60;
	}

	public static function wpconfig_path() {
		return ABSPATH . 'wp-config.php';
	}

	/** وضعیت wp-config.php برای راه‌اندازی Cron واقعی سرور. */
	public static function wpconfig_status() {
		$path = self::wpconfig_path();
		if ( ! file_exists( $path ) ) { return array( 'exists' => false, 'writable' => false, 'disabled' => false, 'path' => $path ); }
		$content = (string) @file_get_contents( $path );
		$disabled = false !== strpos( $content, 'DISABLE_WP_CRON' );
		return array( 'exists' => true, 'writable' => is_writable( $path ), 'disabled' => $disabled, 'path' => $path );
	}

	/** افزودن خودکار define( 'DISABLE_WP_CRON', true ) به wp-config.php. */
	public static function write_disable_wp_cron() {
		$path = self::wpconfig_path();
		if ( ! file_exists( $path ) ) { return new WP_Error( 'zg_wpconfig_missing', 'فایل wp-config.php پیدا نشد.' ); }
		$content = (string) @file_get_contents( $path );
		if ( '' === $content ) { return new WP_Error( 'zg_wpconfig_empty', 'امکان خواندن wp-config.php وجود نداشت.' ); }
		if ( false === strpos( $content, 'DISABLE_WP_CRON' ) ) {
			$line = "define( 'DISABLE_WP_CRON', true ); // زرپالس: Cron واقعی سرور";
			$marker = "That's all, stop editing!";
			if ( false !== strpos( $content, $marker ) ) {
				$content = str_replace( $marker, $line . "\n\n" . $marker, $content );
			} else {
				$content = preg_replace( '/<\?php\s*/', "<?php\n" . $line . "\n", $content, 1 );
			}
			if ( false === @file_put_contents( $path, $content, LOCK_EX ) ) {
				return new WP_Error( 'zg_wpconfig_not_writable', 'wp-config.php قابل نوشتن نیست؛ خط را دستی اضافه کنید (راهنمای زیر).' );
			}
		}
		return true;
	}

	public static function token() {
		$token = get_option( 'zg_cron_token', '' );
		if ( ! is_string( $token ) || strlen( $token ) < 24 ) {
			$token = wp_generate_password( 48, false, false );
			update_option( 'zg_cron_token', $token, false );
		}
		return $token;
	}

	public static function async_loopback() {
		$url = add_query_arg( array( 'action' => 'zg_public_cron_tick', 'token' => self::token() ), admin_url( 'admin-ajax.php' ) );
		wp_remote_post( $url, array( 'timeout' => 0.01, 'blocking' => false, 'sslverify' => apply_filters( 'https_local_ssl_verify', true ), 'headers' => array( 'Cache-Control' => 'no-cache', 'X-ZarPulse-Cron' => '1' ) ) );
	}

	public static function public_tick() {
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
		if ( ! $token || ! hash_equals( self::token(), $token ) ) { status_header( 403 ); wp_die( 'Forbidden', 'ZarPulse Cron', array( 'response' => 403 ) ); }
		if ( self::fresh() ) { wp_die( 'OK', 'ZarPulse Cron', array( 'response' => 200 ) ); }
		self::run();
		wp_die( 'OK', 'ZarPulse Cron', array( 'response' => 200 ) );
	}
}
