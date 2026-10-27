<?php
/**
 * Main plugin orchestrator: owns the settings and boots every part.
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Free_A_B_Testing_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'free_a_b_testing_settings';

	/**
	 * Single shared instance.
	 *
	 * @var Free_A_B_Testing_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Free_A_B_Testing_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		Free_A_B_Testing_Install::maybe_upgrade();

		new Free_A_B_Testing_Tests();
		new Free_A_B_Testing_Frontend( $this );
		new Free_A_B_Testing_Collector( $this );
		new Free_A_B_Testing_Woo();

		if ( is_admin() ) {
			new Free_A_B_Testing_Admin( $this );
		}

		add_action( 'admin_init', array( $this, 'privacy_policy_text' ) );
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'exclude_editors' => 1,
			'respect_gpc'     => 0,
			'cookie_days'     => 90,
			'hourly_limit'    => 30,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Suggest a paragraph for the site's privacy policy (Settings > Privacy).
	 *
	 * @return void
	 */
	public function privacy_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$days = (int) $this->get_setting( 'cookie_days' );

		$text = '<p>' . sprintf(
			/* translators: %d: number of days. */
			esc_html__( 'Some pages on this site test different versions of their content (A/B testing). When you visit one, a cookie named "fabt" remembers which version you were shown, for up to %d days, so you see the same version each time. It holds a random ID and the version number, nothing that identifies you.', 'free-a-b-testing' ),
			$days
		) . '</p><p>' . esc_html__( 'We count how many visitors saw each version and whether they then clicked a chosen button, sent a form, tapped a phone number or placed an order. The random ID is stored scrambled (hashed), your IP address is only used, scrambled with a key that changes daily, to limit how many visits one connection can add, and none of it is shared with anyone else.', 'free-a-b-testing' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Free A/B Testing', 'free-a-b-testing' ), wp_kses_post( $text ) );
	}
}
