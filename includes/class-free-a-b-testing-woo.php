<?php
/**
 * WooCommerce purchase goals.
 *
 * At checkout (classic or block), the visitor's cookie says which running
 * purchase-goal tests they're in. Only tests where that visitor was really
 * counted, in the same variant, are written onto the order. When the order
 * is paid (processing or completed), each of those visitors converts once
 * and the order total is added to their variant's revenue. An order is
 * only ever counted once, however many status changes it goes through.
 *
 * Nothing here can be triggered from the browser: a purchase conversion
 * needs a real order that reached a paid status.
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce attribution.
 */
final class Free_A_B_Testing_Woo {

	/**
	 * Order meta: tests the order belongs to.
	 */
	const META = '_fabt_tests';

	/**
	 * Order meta: set once the order has been counted.
	 */
	const COUNTED = '_fabt_counted';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'classic_checkout' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'attribute' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'paid' ) );
	}

	/**
	 * Classic checkout passes the order ID first.
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $data     Posted data.
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public function classic_checkout( $order_id, $data = array(), $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		if ( $order ) {
			$this->attribute( $order );
		}
	}

	/**
	 * Note on the order which running purchase tests the buyer is in.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function attribute( $order ) {
		global $wpdb;

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$cookie = Free_A_B_Testing_Request::cookie();

		if ( ! $cookie || ! $cookie['tests'] ) {
			return;
		}

		$visitor = Free_A_B_Testing_Request::visitor_hash( $cookie['visitor'] );
		$table   = Free_A_B_Testing_Install::table();
		$tests   = array();

		foreach ( $cookie['tests'] as $test_id => $entry ) {
			$test = Free_A_B_Testing_Tests::get( $test_id );

			if ( ! $test || 'running' !== $test['status'] || 'woo' !== $test['goal']['type'] || $entry['run'] !== $test['run'] ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
			$counted = $wpdb->get_var( $wpdb->prepare( "SELECT slot FROM {$table} WHERE test_id = %d AND visitor = %s", $test['id'], $visitor ) );

			if ( null !== $counted && (int) $counted === $entry['slot'] ) {
				$tests[] = array(
					'test'    => $test['id'],
					'slot'    => $entry['slot'],
					'visitor' => $visitor,
				);
			}
		}

		if ( $tests ) {
			$order->update_meta_data( self::META, $tests );
			$order->save();
		}
	}

	/**
	 * Count a paid order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function paid( $order_id ) {
		global $wpdb;

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order || $order->get_meta( self::COUNTED ) ) {
			return;
		}

		$tests = $order->get_meta( self::META );

		if ( ! is_array( $tests ) || ! $tests ) {
			return;
		}

		// Marked first, so a status change mid-way can't count it twice.
		$order->update_meta_data( self::COUNTED, time() );
		$order->save();

		$table = Free_A_B_Testing_Install::table();
		$total = (float) $order->get_total();
		$now   = current_time( 'mysql', true );

		foreach ( $tests as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['test'] ) || ! isset( $entry['slot'], $entry['visitor'] ) || ! is_string( $entry['visitor'] ) ) {
				continue;
			}

			$test = Free_A_B_Testing_Tests::get( (int) $entry['test'] );

			if ( ! $test || 'running' !== $test['status'] ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET converted_at = COALESCE(converted_at, %s), orders = orders + 1, revenue = revenue + %f WHERE test_id = %d AND visitor = %s AND slot = %d", $now, $total, $test['id'], $entry['visitor'], (int) $entry['slot'] ) );
		}
	}
}
