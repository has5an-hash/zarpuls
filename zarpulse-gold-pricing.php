<?php
/**
 * Plugin Name: زرپالس | قیمت‌گذاری هوشمند طلا و جواهر ووکامرس
 * Description: دریافت قیمت بازار، فرمول‌ساز امن و بروزرسانی خودکار قیمت محصولات طلا، جواهر، سکه و شمش در ووکامرس.
 * Version: 1.12.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Hassan Mojtahedi
 * Text Domain: zarpulse-gold-pricing
 * Domain Path: /languages
 * WC requires at least: 7.5
 * WC tested up to: 10.8
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZG_VERSION', '1.12.0' );
define( 'ZG_FILE', __FILE__ );
define( 'ZG_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZG_URL', plugin_dir_url( __FILE__ ) );
define( 'ZG_BASENAME', plugin_basename( __FILE__ ) );

require_once ZG_PATH . 'includes/class-zg-crypto.php';
require_once ZG_PATH . 'includes/class-zg-activator.php';
require_once ZG_PATH . 'includes/class-zg-formula-engine.php';
require_once ZG_PATH . 'includes/class-zg-currency.php';
require_once ZG_PATH . 'includes/class-zg-sources.php';
require_once ZG_PATH . 'includes/class-zg-pricing.php';
require_once ZG_PATH . 'includes/class-zg-cron.php';
require_once ZG_PATH . 'includes/class-zg-product.php';
require_once ZG_PATH . 'includes/class-zg-price-lock.php';
require_once ZG_PATH . 'includes/class-zg-admin.php';
require_once ZG_PATH . 'includes/class-zg-plugin.php';

register_activation_hook( __FILE__, array( 'ZG_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ZG_Activator', 'deactivate' ) );

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ZG_FILE, true );
	}
} );

ZG_Plugin::instance();
