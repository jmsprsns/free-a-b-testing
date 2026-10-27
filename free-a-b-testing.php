<?php
/**
 * Plugin Name:       Free A/B Testing
 * Plugin URI:        https://www.contentpowered.com
 * Description:       A/B test headlines, buttons, blocks or whole pages. Cache-safe and flicker-free, with goal tracking, statistical confidence and one-click winners.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       free-a-b-testing
 * Update URI:        https://github.com/jmsprsns/free-a-b-testing
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$free_a_b_testing_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'FREE_A_B_TESTING_VERSION', $free_a_b_testing_headers['version'] );
define( 'FREE_A_B_TESTING_REPO', $free_a_b_testing_headers['repo'] );
define( 'FREE_A_B_TESTING_FILE', __FILE__ );
define( 'FREE_A_B_TESTING_DIR', plugin_dir_path( __FILE__ ) );
define( 'FREE_A_B_TESTING_URL', plugin_dir_url( __FILE__ ) );

unset( $free_a_b_testing_headers );

require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-icons.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-updater.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-menu.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-feedback.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-install.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-tests.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-merge.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-stats.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-request.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-frontend.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-collector.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-woo.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-admin.php';
require_once FREE_A_B_TESTING_DIR . 'includes/class-free-a-b-testing-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Free_A_B_Testing_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Free_A_B_Testing_Plugin
 */
function free_a_b_testing_plugin() {
	return Free_A_B_Testing_Plugin::instance();
}
add_action( 'plugins_loaded', 'free_a_b_testing_plugin' );

/**
 * Create the visitors table on activation (and on the first load after an
 * update, see Free_A_B_Testing_Install::maybe_upgrade()).
 *
 * @return void
 */
function free_a_b_testing_activate() {
	Free_A_B_Testing_Install::install();
}
register_activation_hook( FREE_A_B_TESTING_FILE, 'free_a_b_testing_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Tests, variants and results stay put (uninstall.php removes them). Pages
 * with a running test are purged from page caches, so cached copies stop
 * carrying the test.
 *
 * @return void
 */
function free_a_b_testing_deactivate() {
	foreach ( array_keys( Free_A_B_Testing_Tests::live() ) as $post_id ) {
		Free_A_B_Testing_Tests::purge_page( (int) $post_id );
	}
}
register_deactivation_hook( FREE_A_B_TESTING_FILE, 'free_a_b_testing_deactivate' );

/**
 * Tell WooCommerce the plugin works with its order tables (HPOS) and the
 * block checkout: orders are only read and written through WC_Order.
 *
 * @return void
 */
function free_a_b_testing_declare_woo_compat() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FREE_A_B_TESTING_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FREE_A_B_TESTING_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'free_a_b_testing_declare_woo_compat' );
