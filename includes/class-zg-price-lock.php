<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * رزرو زمان‌دار نرخ برای کالاهای زرپالس.
 * هر آیتم سبد، زمان انقضای مستقل خود را دارد و پس از انقضا از سبد حذف می‌شود.
 */
class ZG_Price_Lock {
	private static $pruning = false;

	public static function register() {
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'set_expiry_on_add' ), 20, 3 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'remember_recent_item' ), 20, 6 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'prune_expired' ), 5 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart' ), 5 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_cart' ), 5 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_expiry' ), 15, 4 );
		add_shortcode( 'zarpulse_price_lock_timer', array( __CLASS__, 'shortcode' ) );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'render_cart_timer' ), 5 );
		add_action( 'woocommerce_checkout_before_order_review', array( __CLASS__, 'render_checkout_timer' ), 5 );
		add_action( 'woocommerce_widget_shopping_cart_after_buttons', array( __CLASS__, 'render_mini_cart_timer' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_zg_lock_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_zg_lock_status', array( __CLASS__, 'ajax_status' ) );
	}

	public static function settings() {
		return wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
	}

	public static function is_enabled() {
		$settings = self::settings();
		return ! empty( $settings['price_lock_enabled'] );
	}

	public static function minutes() {
		$settings = self::settings();
		return max( 1, min( 120, absint( $settings['price_lock_minutes'] ) ) );
	}

	public static function set_expiry_on_add( $cart_item_data, $product_id, $variation_id ) {
		if ( ! self::is_enabled() ) { return $cart_item_data; }
		$product_id = $variation_id ? absint( $variation_id ) : absint( $product_id );
		if ( 'yes' !== get_post_meta( $product_id, '_zg_enabled', true ) ) { return $cart_item_data; }
		$cart_item_data['zg_price_expires_at'] = time() + ( self::minutes() * MINUTE_IN_SECONDS );
		return $cart_item_data;
	}

	public static function remember_recent_item( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( ! empty( $cart_item_data['zg_price_expires_at'] ) && function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'zg_recent_lock_item', sanitize_text_field( $cart_item_key ) );
		}
	}

	public static function prune_expired( $cart = null, $show_notice = false ) {
		if ( self::$pruning || ! self::is_enabled() || ! function_exists( 'WC' ) ) { return false; }
		$cart = $cart ? $cart : WC()->cart;
		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) { return false; }
		self::$pruning = true;
		$removed = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['zg_price_expires_at'] ) || time() < absint( $item['zg_price_expires_at'] ) ) { continue; }
			$removed[] = isset( $item['data'] ) ? $item['data']->get_name() : 'محصول';
			$cart->remove_cart_item( $key );
		}
		self::$pruning = false;
		if ( $show_notice && ! empty( $removed ) && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( 'مهلت رزرو قیمت برای «' . implode( '، ', array_map( 'wp_strip_all_tags', $removed ) ) . '» تمام شد و محصول از سبد خرید حذف شد. لطفاً برای دریافت نرخ تازه، دوباره آن را به سبد اضافه کنید.', 'error' );
		}
		return ! empty( $removed );
	}

	public static function validate_cart() {
		self::prune_expired( null, true );
	}

	public static function save_order_expiry( $item, $cart_item_key, $values ) {
		if ( isset( $values['zg_price_expires_at'] ) ) {
			$item->add_meta_data( 'مهلت رزرو قیمت', wp_date( 'Y/m/d H:i:s', absint( $values['zg_price_expires_at'] ) ), true );
		}
	}

	public static function timer_items() {
		if ( ! self::is_enabled() || ! function_exists( 'WC' ) || ! WC()->cart ) { return array(); }
		$items = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( empty( $item['zg_price_expires_at'] ) || ! isset( $item['data'] ) ) { continue; }
			$items[] = array(
				'key' => $key,
				'name' => $item['data']->get_name(),
				'expires' => absint( $item['zg_price_expires_at'] ),
				'price' => isset( $item['zg_locked_unit_price'] ) ? (float) $item['zg_locked_unit_price'] : (float) $item['data']->get_price(),
			);
		}
		return $items;
	}

	public static function render( $context = 'cart' ) {
		$items = self::timer_items();
		if ( empty( $items ) ) { return ''; }
		$minutes = self::minutes();
		ob_start();
		?>
		<section class="zg-lock-timers zg-lock-context-<?php echo esc_attr( $context ); ?>" data-lock-minutes="<?php echo esc_attr( $minutes ); ?>">
			<div class="zg-lock-heading"><span class="zg-lock-icon">⌛</span><div><strong>رزرو نرخ لحظه‌ای فعال است</strong><small>برای هر محصول تا پایان زمان خودش فرصت پرداخت دارید.</small></div></div>
			<div class="zg-lock-items">
				<?php foreach ( $items as $item ) : ?>
					<article class="zg-lock-item" data-zg-expiry="<?php echo esc_attr( $item['expires'] ); ?>" data-zg-key="<?php echo esc_attr( $item['key'] ); ?>">
						<div class="zg-lock-item-main"><span class="zg-lock-pulse"></span><div><b><?php echo esc_html( $item['name'] ); ?></b><small>قیمت رزرو شده: <?php echo wp_kses_post( wc_price( $item['price'] ) ); ?></small></div></div>
						<div class="zg-lock-clock"><span class="zg-lock-time">--:--</span><small>زمان باقی‌مانده</small></div>
						<div class="zg-lock-progress"><i></i></div>
					</article>
				<?php endforeach; ?>
			</div>
			<p class="zg-lock-note">پس از اتمام زمان، آیتم منقضی می‌شود و برای دریافت قیمت جدید باید دوباره به سبد افزوده شود.</p>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function shortcode() {
		return self::render( 'shortcode' );
	}

	public static function automatic_enabled() {
		$settings = self::settings();
		return self::is_enabled() && ( $settings['price_lock_display_mode'] ?? 'automatic' ) === 'automatic';
	}

	public static function render_cart_timer() { if ( self::automatic_enabled() ) { echo self::render( 'cart' ); } }
	public static function render_checkout_timer() { if ( self::automatic_enabled() ) { echo self::render( 'checkout' ); } }
	public static function render_mini_cart_timer() { if ( self::automatic_enabled() ) { echo self::render( 'mini-cart' ); } }

	public static function assets() {
		if ( is_admin() || ! self::is_enabled() ) { return; }
		wp_enqueue_style( 'zg-price-lock', ZG_URL . 'assets/css/price-lock.css', array(), ZG_VERSION );
		wp_enqueue_script( 'zg-price-lock', ZG_URL . 'assets/js/price-lock.js', array( 'jquery' ), ZG_VERSION, true );
		wp_localize_script( 'zg-price-lock', 'ZGPriceLock', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'zg_price_lock' ),
			'modalTitle' => 'قیمت محصول برای شما رزرو شد',
			'modalBody' => 'برای نهایی کردن خرید، قبل از پایان زمان باقی‌مانده پرداخت را انجام دهید. پس از پایان مهلت، محصول از سبد حذف می‌شود.',
			'confirm' => 'متوجه شدم',
		) );
	}

	public static function ajax_status() {
		check_ajax_referer( 'zg_price_lock', 'nonce' );
		self::prune_expired();
		$recent = function_exists( 'WC' ) && WC()->session ? WC()->session->get( 'zg_recent_lock_item' ) : '';
		if ( $recent && WC()->session ) { WC()->session->set( 'zg_recent_lock_item', '' ); }
		wp_send_json_success( array( 'items' => self::timer_items(), 'recent' => $recent ? sanitize_text_field( $recent ) : '' ) );
	}
}
