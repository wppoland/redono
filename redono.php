<?php
/**
 * Plugin Name:       Redono - Returns and RMA for WooCommerce
 * Plugin URI:        https://plogins.com/redono/
 * Description:       Let customers request returns, complaints and repairs from their account and manage RMAs in the admin.
 * Version:           1.3.9
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            WPPoland.com
 * Author URI:        https://wppoland.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       redono
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to: 11.0
 *
 * @package Returns
 */

declare(strict_types=1);

namespace Returns;

defined('ABSPATH') || exit;

const VERSION     = '1.3.9';
const PLUGIN_FILE = __FILE__;

define('RETURNS_DIR', plugin_dir_path(__FILE__));
define('RETURNS_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/autoload.php';

// Register the My Account endpoint and flush rewrite rules on activation so the
// "request-return" URL resolves immediately; flush again on deactivation to
// clean up. Endpoint registration itself happens on every init during boot.
register_activation_hook(__FILE__, static function (): void {
    add_rewrite_endpoint(Service\ReturnRequestForm::ENDPOINT, EP_ROOT | EP_PAGES);
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    flush_rewrite_rules();
});

// HPOS + cart/checkout blocks compatibility.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Plogins Returns - Returns and RMA for WooCommerce requires WooCommerce to be active.', 'redono');
            echo '</p></div>';
        });
        return;
    }

    // Translations load automatically on WordPress.org-hosted plugins (WP 4.6+)
    // via the slug + Domain Path header, so no manual load_plugin_textdomain()
    // call is needed (and Plugin Check discourages it).
    add_action('init', static function (): void {
        Plugin::instance()->boot();
    }, 0);
}, 10);
