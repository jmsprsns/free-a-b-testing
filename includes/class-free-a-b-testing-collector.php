<?php
/**
 * The endpoint the inline script reports to: views (a visitor saw their
 * variant) and conversions (they reached the goal). It runs through
 * admin-ajax.php (action fabt_hit), which works even where a security
 * plugin switches the REST API off for visitors.
 *
 * It's public by necessity (visitors aren't logged in, and cached pages
 * can't carry a nonce), so it trusts nothing it's sent:
 *
 * - Real POST requests only, under 1 KB, from this site (Origin and
 *   Sec-Fetch-Site), so a link, an <img> or another website can't post.
 * - The test must be running, the run number current and the variant one
 *   the test really has. The visitor ID must be 22 letters and digits.
 * - A visitor is counted once per test, and converts at most once; a
 *   conversion for another variant than the one they were counted in is
 *   ignored. Purchase goals can't be converted from here at all: only a
 *   paid WooCommerce order converts those (see the Woo class).
 * - Bots, headless browsers, prefetches and (by default) the site's own
 *   editors are ignored, and each connection can add a limited number of
 *   visitors and conversions an hour.
 *
 * Every accepted request gets the same empty 204, counted or not, so the
 * response says nothing about the filters.
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * View and conversion collection.
 */
final class Free_A_B_Testing_Collector {

	/**
	 * The admin-ajax action.
	 */
	const ACTION = 'fabt_hit';

	/**
	 * Largest request body accepted, in bytes.
	 */
	const MAX_BODY = 1024;

	/**
	 * Plugin instance.
	 *
	 * @var Free_A_B_Testing_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Free_A_B_Testing_Plugin $plugin Plugin instance.
	 */
	public function __construct( Free_A_B_Testing_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'hit' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'hit' ) );
	}

	/**
	 * Handle a view or conversion.
	 *
	 * @return void
	 */
	public function hit() {
		nocache_headers();

		if ( 'POST' !== strtoupper( Free_A_B_Testing_Request::server( 'REQUEST_METHOD' ) ) ) {
			self::finish( 405 );
		}

		if ( (int) Free_A_B_Testing_Request::server( 'CONTENT_LENGTH' ) > self::MAX_BODY || ! Free_A_B_Testing_Request::same_origin() ) {
			self::finish( 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Public endpoint for cached pages; see the class comment for what guards it.
		$kind    = isset( $_POST['a'] ) && is_string( $_POST['a'] ) ? sanitize_key( wp_unslash( $_POST['a'] ) ) : '';
		$test_id = isset( $_POST['t'] ) && is_string( $_POST['t'] ) ? absint( $_POST['t'] ) : 0;
		$run     = isset( $_POST['r'] ) && is_string( $_POST['r'] ) ? absint( $_POST['r'] ) : 0;
		$slot    = isset( $_POST['s'] ) && is_string( $_POST['s'] ) && preg_match( '/^\d{1,3}$/D', sanitize_text_field( wp_unslash( $_POST['s'] ) ) ) ? absint( $_POST['s'] ) : -1;
		// Matched exactly as sent: only 22 letters and digits pass, nothing is cleaned into shape.
		$visitor = isset( $_POST['v'] ) && is_string( $_POST['v'] ) ? wp_unslash( $_POST['v'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by the pattern below.
		// phpcs:enable

		if ( ! in_array( $kind, array( 'v', 'c' ), true ) || ! $test_id || $slot < 0 || ! preg_match( '/^[A-Za-z0-9]{22}$/D', $visitor ) ) {
			self::finish( 400 );
		}

		if ( Free_A_B_Testing_Request::is_bot() ) {
			self::finish();
		}

		if ( $this->plugin->get_setting( 'exclude_editors' ) && current_user_can( 'edit_posts' ) ) {
			self::finish();
		}

		$test = Free_A_B_Testing_Tests::get( $test_id );

		if ( ! $test || 'running' !== $test['status'] || $run !== $test['run'] || ! in_array( $slot, Free_A_B_Testing_Tests::active_slots( $test ), true ) ) {
			self::finish();
		}

		$live = Free_A_B_Testing_Tests::live();

		if ( empty( $live[ $test['post_id'] ] ) || $live[ $test['post_id'] ] !== $test['id'] ) {
			self::finish();
		}

		if ( 'c' === $kind && 'woo' === $test['goal']['type'] ) {
			self::finish();
		}

		self::record( $test['id'], $slot, Free_A_B_Testing_Request::visitor_hash( $visitor ), 'c' === $kind, (int) $this->plugin->get_setting( 'hourly_limit' ) );

		self::finish();
	}

	/**
	 * Count a visitor (and their conversion).
	 *
	 * A conversion can arrive before its view (both go out as the page
	 * loads, and a fast tap can win the race), so it adds the visitor too.
	 *
	 * @param int    $test_id Test ID.
	 * @param int    $slot    Slot.
	 * @param string $visitor Visitor hash.
	 * @param bool   $convert Whether this is a conversion.
	 * @param int    $limit   Most new rows and conversions per connection per hour.
	 * @return void
	 */
	public static function record( $test_id, $slot, $visitor, $convert, $limit ) {
		global $wpdb;

		$table  = Free_A_B_Testing_Install::table();
		$client = Free_A_B_Testing_Request::client_hash();
		$now    = current_time( 'mysql', true );
		$since  = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the name isn't user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT slot, converted_at FROM {$table} WHERE test_id = %d AND visitor = %s", $test_id, $visitor ) );

		if ( $row ) {
			if ( ! $convert || null !== $row->converted_at || (int) $row->slot !== (int) $slot ) {
				return;
			}

			if ( self::recent( $client, 'converted_at', $since ) >= $limit ) {
				return;
			}

			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET converted_at = %s WHERE test_id = %d AND visitor = %s AND converted_at IS NULL", $now, $test_id, $visitor ) );

			return;
		}

		if ( self::recent( $client, 'seen_at', $since ) >= $limit ) {
			return;
		}

		// INSERT IGNORE: if a parallel request just added this visitor, keep theirs.
		if ( $convert ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (test_id, slot, visitor, client, seen_at, converted_at) VALUES (%d, %d, %s, %s, %s, %s)", $test_id, $slot, $visitor, $client, $now, $now ) );
		} else {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (test_id, slot, visitor, client, seen_at) VALUES (%d, %d, %s, %s, %s)", $test_id, $slot, $visitor, $client, $now ) );
		}
		// phpcs:enable
	}

	/**
	 * How many visitors (or conversions) one connection added in the last hour.
	 *
	 * @param string $client Connection hash.
	 * @param string $column seen_at or converted_at.
	 * @param string $since  UTC datetime.
	 * @return int
	 */
	private static function recent( $client, $column, $since ) {
		global $wpdb;

		$table  = Free_A_B_Testing_Install::table();
		$column = 'converted_at' === $column ? 'converted_at' : 'seen_at';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table and a fixed column name.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE client = %s AND {$column} > %s", $client, $since ) );
	}

	/**
	 * End the request with an empty response.
	 *
	 * @param int $status HTTP status.
	 * @return void
	 */
	private static function finish( $status = 204 ) {
		status_header( $status );
		header( 'Content-Type: text/plain; charset=utf-8' );
		exit;
	}
}
