<?php
/**
 * Results: visitors, conversions and statistical confidence per variant.
 *
 * Each variant is compared with the original using a two-proportion z-test
 * (two-sided). With more than one variant, the p-values are Bonferroni-
 * corrected (multiplied by the number of variants), so "95%" means the same
 * thing however many variants a test has. A winner is only declared at 95%
 * confidence or more, and only once both sides have enough visitors and
 * conversions for the test to be valid (at least 30 visitors each, and 5 of
 * each outcome expected on each side).
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Results and significance.
 */
final class Free_A_B_Testing_Stats {

	/**
	 * Confidence needed to declare a winner.
	 */
	const CONFIDENCE = 0.95;

	/**
	 * Fewest visitors per side before a winner can be declared.
	 */
	const MIN_VISITORS = 30;

	/**
	 * Raw counts per slot.
	 *
	 * @param int $test_id Test ID.
	 * @return array<int,array{visitors:int,conversions:int,orders:int,revenue:float}>
	 */
	public static function counts( $test_id ) {
		global $wpdb;

		$table = Free_A_B_Testing_Install::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, fresh numbers wanted.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT slot, COUNT(*) AS visitors, COUNT(converted_at) AS conversions, SUM(orders) AS orders, SUM(revenue) AS revenue FROM {$table} WHERE test_id = %d GROUP BY slot", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
				$test_id
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['slot'] ] = array(
				'visitors'    => (int) $row['visitors'],
				'conversions' => (int) $row['conversions'],
				'orders'      => (int) $row['orders'],
				'revenue'     => (float) $row['revenue'],
			);
		}

		return $out;
	}

	/**
	 * Full results for a test.
	 *
	 * @param array $test Test.
	 * @return array{rows:array<int,array>,winner:int,leader:int,visitors:int,conversions:int,ready:bool}
	 *               winner is the winning slot, or -1 for none yet.
	 */
	public static function results( array $test ) {
		return self::analyse( self::counts( $test['id'] ), array_keys( $test['variants'] ) );
	}

	/**
	 * Work out rates, lifts, confidence and the winner from counts.
	 *
	 * @param array<int,array> $counts Slot => counts (missing slots count as zero).
	 * @param int[]            $slots  Slots in the test, the original (0) first.
	 * @return array
	 */
	public static function analyse( array $counts, array $slots ) {
		$rows      = array();
		$total_v   = 0;
		$total_c   = 0;
		$compared  = max( 1, count( $slots ) - 1 );
		$base      = isset( $counts[0] ) ? $counts[0] : array();
		$base_v    = isset( $base['visitors'] ) ? (int) $base['visitors'] : 0;
		$base_c    = isset( $base['conversions'] ) ? (int) $base['conversions'] : 0;
		$base_rate = $base_v ? $base_c / $base_v : 0.0;

		foreach ( $slots as $slot ) {
			$visitors    = isset( $counts[ $slot ]['visitors'] ) ? (int) $counts[ $slot ]['visitors'] : 0;
			$conversions = isset( $counts[ $slot ]['conversions'] ) ? min( $visitors, (int) $counts[ $slot ]['conversions'] ) : 0;
			$rate        = $visitors ? $conversions / $visitors : 0.0;
			$row         = array(
				'slot'        => (int) $slot,
				'visitors'    => $visitors,
				'conversions' => $conversions,
				'orders'      => isset( $counts[ $slot ]['orders'] ) ? (int) $counts[ $slot ]['orders'] : 0,
				'revenue'     => isset( $counts[ $slot ]['revenue'] ) ? (float) $counts[ $slot ]['revenue'] : 0.0,
				'rate'        => $rate,
				'lift'        => null,
				'confidence'  => null,
				'valid'       => false,
				'better'      => false,
			);

			if ( 0 !== (int) $slot ) {
				$row['lift']       = $base_rate > 0 ? ( $rate - $base_rate ) / $base_rate : null;
				$p                 = self::p_value( $conversions, $visitors, $base_c, $base_v );
				$row['confidence'] = null === $p ? null : max( 0.0, 1 - min( 1.0, $p * $compared ) );
				$row['valid']      = self::enough_data( $conversions, $visitors, $base_c, $base_v );
				$row['better']     = $rate > $base_rate;
			}

			$total_v += $visitors;
			$total_c += $conversions;

			$rows[ (int) $slot ] = $row;
		}

		// Winner: the best variant that beats the original with 95%
		// confidence, or the original if every variant loses to it at 95%.
		$winner    = -1;
		$all_lose  = count( $rows ) > 1;
		$best_rate = -1.0;

		foreach ( $rows as $slot => $row ) {
			if ( 0 === $slot ) {
				continue;
			}

			$significant = $row['valid'] && null !== $row['confidence'] && $row['confidence'] >= self::CONFIDENCE;

			if ( $significant && $row['better'] && $row['rate'] > $best_rate ) {
				$winner    = $slot;
				$best_rate = $row['rate'];
			}

			if ( ! $significant || $row['better'] || $row['rate'] === $base_rate ) {
				$all_lose = false;
			}
		}

		if ( -1 === $winner && $all_lose ) {
			$winner = 0;
		}

		// The current leader, for "B is ahead so far" before there's a winner.
		$leader = -1;
		$top    = -1.0;

		foreach ( $rows as $slot => $row ) {
			if ( $row['visitors'] && $row['rate'] > $top ) {
				$leader = $slot;
				$top    = $row['rate'];
			}
		}

		return array(
			'rows'        => $rows,
			'winner'      => $winner,
			'leader'      => $total_c ? $leader : -1,
			'visitors'    => $total_v,
			'conversions' => $total_c,
		);
	}

	/**
	 * Cumulative conversion rate per slot for each day of the test, in the
	 * site's time zone, for the chart.
	 *
	 * @param int $test_id Test ID.
	 * @return array{days:string[],series:array<int,array<int,float|null>>} Days (Y-m-d) and slot => rate per day (null before its first visitor).
	 */
	public static function daily( $test_id ) {
		global $wpdb;

		$table  = Free_A_B_Testing_Install::table();
		$offset = (int) round( 3600 * (float) get_option( 'gmt_offset' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$seen = $wpdb->get_results( $wpdb->prepare( "SELECT slot, DATE(DATE_ADD(seen_at, INTERVAL %d SECOND)) AS day, COUNT(*) AS n FROM {$table} WHERE test_id = %d GROUP BY slot, day", $offset, $test_id ) );
		$conv = $wpdb->get_results( $wpdb->prepare( "SELECT slot, DATE(DATE_ADD(converted_at, INTERVAL %d SECOND)) AS day, COUNT(*) AS n FROM {$table} WHERE test_id = %d AND converted_at IS NOT NULL GROUP BY slot, day", $offset, $test_id ) );
		// phpcs:enable

		$visits = array();
		$wins   = array();
		$days   = array();

		foreach ( (array) $seen as $row ) {
			$visits[ (int) $row->slot ][ $row->day ] = (int) $row->n;
			$days[ $row->day ]                       = true;
		}

		foreach ( (array) $conv as $row ) {
			$wins[ (int) $row->slot ][ $row->day ] = (int) $row->n;
			$days[ $row->day ]                     = true;
		}

		if ( ! $days ) {
			return array(
				'days'   => array(),
				'series' => array(),
			);
		}

		// Every day from the first to the last, gaps included.
		ksort( $days );
		$first = strtotime( (string) key( $days ) . ' 00:00:00 UTC' );
		end( $days );
		$last = strtotime( (string) key( $days ) . ' 00:00:00 UTC' );
		$all  = array();

		// At most 400 days, so a stray date can't make a huge chart.
		$last = min( $last, $first + 399 * DAY_IN_SECONDS );

		for ( $t = $first; $t <= $last; $t += DAY_IN_SECONDS ) {
			$all[] = gmdate( 'Y-m-d', $t );
		}

		$series = array();

		foreach ( array_keys( $visits ) as $slot ) {
			$v = 0;
			$c = 0;

			foreach ( $all as $day ) {
				$v += isset( $visits[ $slot ][ $day ] ) ? $visits[ $slot ][ $day ] : 0;
				$c += isset( $wins[ $slot ][ $day ] ) ? $wins[ $slot ][ $day ] : 0;

				$series[ $slot ][] = $v ? min( 1.0, $c / $v ) : null;
			}
		}

		ksort( $series );

		return array(
			'days'   => $all,
			'series' => $series,
		);
	}

	/**
	 * Two-sided p-value of a two-proportion z-test (pooled).
	 *
	 * @param int $c1 Conversions, variant.
	 * @param int $n1 Visitors, variant.
	 * @param int $c0 Conversions, original.
	 * @param int $n0 Visitors, original.
	 * @return float|null Null when there's nothing to compare yet.
	 */
	public static function p_value( $c1, $n1, $c0, $n0 ) {
		if ( $n1 < 1 || $n0 < 1 ) {
			return null;
		}

		$pooled = ( $c1 + $c0 ) / ( $n1 + $n0 );
		$var    = $pooled * ( 1 - $pooled ) * ( 1 / $n1 + 1 / $n0 );

		if ( $var <= 0 ) {
			return null;
		}

		$z = abs( $c1 / $n1 - $c0 / $n0 ) / sqrt( $var );

		return max( 0.0, min( 1.0, 2 * ( 1 - self::normal_cdf( $z ) ) ) );
	}

	/**
	 * Whether both sides have enough data for the z-test's normal
	 * approximation: 30+ visitors each, and at least 5 conversions and 5
	 * non-conversions expected on each side.
	 *
	 * @param int $c1 Conversions, variant.
	 * @param int $n1 Visitors, variant.
	 * @param int $c0 Conversions, original.
	 * @param int $n0 Visitors, original.
	 * @return bool
	 */
	public static function enough_data( $c1, $n1, $c0, $n0 ) {
		if ( $n1 < self::MIN_VISITORS || $n0 < self::MIN_VISITORS ) {
			return false;
		}

		$pooled = ( $c1 + $c0 ) / ( $n1 + $n0 );

		foreach ( array( $n1, $n0 ) as $n ) {
			if ( $n * $pooled < 5 || $n * ( 1 - $pooled ) < 5 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Standard normal CDF (Abramowitz and Stegun 7.1.26, error < 1.5e-7).
	 *
	 * @param float $z Value.
	 * @return float
	 */
	public static function normal_cdf( $z ) {
		$x = abs( $z ) / M_SQRT2;
		$t = 1 / ( 1 + 0.3275911 * $x );
		$y = 1 - ( ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t ) + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( -$x * $x );

		return $z >= 0 ? 0.5 * ( 1 + $y ) : 0.5 * ( 1 - $y );
	}

	/**
	 * Delete results for a test (or one of its slots).
	 *
	 * @param int      $test_id Test ID.
	 * @param int|null $slot    Slot, or null for all.
	 * @return void
	 */
	public static function delete( $test_id, $slot = null ) {
		global $wpdb;

		$where = array( 'test_id' => (int) $test_id );

		if ( null !== $slot ) {
			$where['slot'] = (int) $slot;
		}

		$wpdb->delete( Free_A_B_Testing_Install::table(), $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}
}
