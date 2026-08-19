<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZG_Product {
	private static $setting_price = false;

	public static function register() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_zg_preview_price', array( __CLASS__, 'ajax_preview' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'lock_cart_price' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_locked_cart_price' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_locked_order_price' ), 10, 4 );
	}

	public static function tab( $tabs ) {
		$tabs['zg_gold'] = array( 'label' => 'قیمت‌گذاری طلا', 'target' => 'zg_gold_product_data', 'class' => array( 'show_if_simple', 'show_if_variable', 'show_if_variation' ), 'priority' => 70 );
		return $tabs;
	}

	public static function panel() {
		global $post;
		if ( ! $post ) { return; }
		$config = ZG_Pricing::product_config( $post->ID );
		$formulas = ZG_Pricing::formulas();
		$types = array( 'bullion' => 'شمش', 'coin' => 'سکه', 'melted' => 'طلای آب‌شده', 'jewelry' => 'جواهر', 'bracelet' => 'دستبند', 'necklace' => 'گردنبند', 'ring' => 'انگشتر', 'custom' => 'محصول سفارشی' );
		?>
		<div id="zg_gold_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group zg-product-panel">
				<?php wp_nonce_field( 'zg_product_data', 'zg_product_nonce' ); ?>
				<p class="form-field"><label for="_zg_enabled">فعال‌سازی قیمت هوشمند</label><input type="checkbox" id="_zg_enabled" name="_zg_enabled" value="yes" <?php checked( $config['enabled'] ); ?>><span class="description">قیمت محصول با آخرین قیمت پایه و فرمول انتخابی بروزرسانی می‌شود.</span></p>
				<?php self::select_field( '_zg_product_type', 'نوع محصول', $config['product_type'], $types ); ?>
				<?php self::number_field( '_zg_weight', 'وزن (گرم)', $config['weight'], 'step="0.001" min="0"' ); ?>
				<?php self::number_field( '_zg_karat', 'عیار', $config['karat'] ? $config['karat'] : 18, 'step="0.01" min="0" max="24"' ); ?>
				<?php self::select_field( '_zg_formula_id', 'فرمول مورد استفاده', $config['formula_id'], array_combine( array_keys( $formulas ), wp_list_pluck( $formulas, 'name' ) ) ); ?>
				<?php
				self::number_field( '_zg_wage', 'اجرت ثابت کل', $config['wage'] ); self::number_field( '_zg_wage_per_gram', 'اجرت هر گرم', $config['wage_per_gram'] ); self::number_field( '_zg_wage_percent', 'اجرت درصدی (%)', $config['wage_percent'], 'step="0.01" min="0"' ); self::number_field( '_zg_stone', 'هزینه سنگ', $config['stone'] ); self::number_field( '_zg_profit_percent', 'سود فروشنده (%)', $config['profit_percent'], 'step="0.01" min="0"' ); self::number_field( '_zg_profit_fixed', 'سود ثابت', $config['profit_fixed'] );
				self::number_field( '_zg_extra', 'هزینه اضافی', $config['extra'] ); self::number_field( '_zg_packing', 'هزینه بسته‌بندی', $config['packing'] ); self::number_field( '_zg_shipping', 'هزینه ارسال', $config['shipping'] ); self::number_field( '_zg_insurance', 'هزینه بیمه', $config['insurance'] ); self::number_field( '_zg_manufacturing', 'هزینه ساخت', $config['manufacturing'] ); self::number_field( '_zg_tax_percent', 'مالیات (%)', $config['tax_percent'], 'step="0.01" min="0"' );
				?>
				<p class="form-field zg-preview-row"><label>پیش‌نمایش قیمت</label><button type="button" class="button button-secondary zg-preview-product" data-product-id="<?php echo esc_attr( $post->ID ); ?>">محاسبه قیمت</button><span class="zg-preview-result">برای مشاهده، روی دکمه کلیک کنید.</span></p>
				<p class="form-field"><label>بروزرسانی</label><span class="description">پس از ذخیره محصول، قیمت با فرمول محاسبه و ذخیره می‌شود. قیمت سبد مشتری در لحظه افزودن به سبد قفل می‌شود.</span></p>
			</div>
		</div>
		<?php
	}

	private static function number_field( $id, $label, $value, $attributes = 'step="1" min="0"' ) {
		printf( '<p class="form-field"><label for="%1$s">%2$s</label><input type="number" class="short zg-calculation-field" id="%1$s" name="%1$s" value="%3$s" %4$s></p>', esc_attr( $id ), esc_html( $label ), esc_attr( $value ), $attributes );
	}
	private static function select_field( $id, $label, $value, $options ) {
		echo '<p class="form-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select class="select short zg-calculation-field" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
		foreach ( $options as $key => $text ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $text ) . '</option>'; }
		echo '</select></p>';
	}

	public static function save( $product ) {
		if ( ! isset( $_POST['zg_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zg_product_nonce'] ) ), 'zg_product_data' ) || ! current_user_can( 'edit_post', $product->get_id() ) ) { return; }
		$fields = array( 'product_type', 'weight', 'karat', 'stone', 'wage', 'wage_per_gram', 'wage_percent', 'profit_percent', 'profit_fixed', 'extra', 'packing', 'shipping', 'insurance', 'manufacturing', 'tax_percent', 'formula_id' );
		update_post_meta( $product->get_id(), '_zg_enabled', isset( $_POST['_zg_enabled'] ) ? 'yes' : 'no' );
		foreach ( $fields as $field ) {
			$raw = isset( $_POST[ '_zg_' . $field ] ) ? wp_unslash( $_POST[ '_zg_' . $field ] ) : '';
			$value = in_array( $field, array( 'product_type', 'formula_id' ), true ) ? sanitize_key( $raw ) : wc_format_decimal( $raw );
			update_post_meta( $product->get_id(), '_zg_' . $field, $value );
		}
		if ( isset( $_POST['_zg_enabled'] ) ) {
			try {
				$result = ZG_Pricing::calculate( $product->get_id() );
				if ( $result['price'] <= 0 ) { throw new Exception( 'قیمت نهایی صفر است؛ قیمت پایه یا فرمول را بررسی کنید.' ); }
				// قیمت روی همان آبجکت ووکامرس تنظیم می‌شود تا ذخیره استاندارد محصول آن را بازنویسی نکند.
				$product->set_regular_price( wc_format_decimal( $result['price'] ) );
				$product->set_sale_price( '' );
				$product->set_price( wc_format_decimal( $result['price'] ) );
				update_post_meta( $product->get_id(), '_zg_last_price', $result['price'] );
				update_post_meta( $product->get_id(), '_zg_last_sync', current_time( 'mysql', true ) );
				delete_post_meta( $product->get_id(), '_zg_last_error' );
				wc_add_notice( 'زرپالس: قیمت محصول محاسبه و ذخیره شد.', 'success' );
			} catch ( Exception $e ) {
				update_post_meta( $product->get_id(), '_zg_last_error', sanitize_text_field( $e->getMessage() ) );
				wc_add_notice( 'زرپالس: ' . $e->getMessage(), 'error' );
			}
		}
	}

	public static function ajax_preview() {
		check_ajax_referer( 'zg_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) { wp_send_json_error( array( 'message' => 'اجازه دسترسی ندارید.' ), 403 ); }
		$map = array( 'enabled' => '_zg_enabled', 'product_type' => '_zg_product_type', 'weight' => '_zg_weight', 'karat' => '_zg_karat', 'stone' => '_zg_stone', 'wage' => '_zg_wage', 'wage_per_gram' => '_zg_wage_per_gram', 'wage_percent' => '_zg_wage_percent', 'profit_percent' => '_zg_profit_percent', 'profit_fixed' => '_zg_profit_fixed', 'extra' => '_zg_extra', 'packing' => '_zg_packing', 'shipping' => '_zg_shipping', 'insurance' => '_zg_insurance', 'manufacturing' => '_zg_manufacturing', 'tax_percent' => '_zg_tax_percent', 'formula_id' => '_zg_formula_id' );
		$posted = array();
		foreach ( $map as $key => $input ) { $posted[ $key ] = isset( $_POST[ $input ] ) ? sanitize_text_field( wp_unslash( $_POST[ $input ] ) ) : ''; }
		$posted['enabled'] = true;
		try {
			$calc = ZG_Pricing::calculate( absint( $_POST['product_id'] ), $posted );
			$settings = wp_parse_args( get_option( 'zg_settings', array() ), ZG_Activator::default_settings() );
			wp_send_json_success( array( 'price' => $calc['price'], 'formatted' => number_format_i18n( $calc['price'] ) . ' ' . $settings['currency_label'], 'formula' => $calc['formula']['name'] ) );
		} catch ( Exception $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 400 ); }
	}

	/** در اولین افزودن به سبد، قیمت همان لحظه را روی آیتم ذخیره می‌کند. */
	public static function lock_cart_price( $cart_item_data, $product_id, $variation_id ) {
		$id = $variation_id ? $variation_id : $product_id;
		if ( 'yes' !== get_post_meta( $id, '_zg_enabled', true ) ) { return $cart_item_data; }
		$product = wc_get_product( $id );
		if ( $product ) {
			$cart_item_data['zg_locked_unit_price'] = (float) $product->get_price();
			$cart_item_data['zg_price_locked_at'] = current_time( 'mysql' );
		}
		return $cart_item_data;
	}

	public static function apply_locked_cart_price( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) { return; }
		if ( self::$setting_price || ! is_a( $cart, 'WC_Cart' ) ) { return; }
		self::$setting_price = true;
		foreach ( $cart->get_cart() as $item ) { if ( isset( $item['zg_locked_unit_price'] ) && isset( $item['data'] ) ) { $item['data']->set_price( (float) $item['zg_locked_unit_price'] ); } }
		self::$setting_price = false;
	}

	public static function save_locked_order_price( $item, $cart_item_key, $values ) {
		if ( isset( $values['zg_locked_unit_price'] ) ) {
			$item->add_meta_data( 'قیمت قفل‌شده زرپالس', wc_format_decimal( $values['zg_locked_unit_price'] ), true );
			$item->add_meta_data( 'زمان قفل قیمت', sanitize_text_field( $values['zg_price_locked_at'] ), true );
		}
	}
}
