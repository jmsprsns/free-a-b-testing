<?php
/**
 * Database table for test visitors.
 *
 * One row per visitor per test: which variant they were shown, when, and
 * whether (and when) they converted. Unique on (test, visitor), so repeat
 * visits and repeat conversions are never counted twice.
 *
 * @package Free_A_B_Testing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Table creation and upgrades.
 */
final class Free_A_B_Testing_Install {

	/**
	 * Bump when the table definition changes.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed table version.
	 */
	const DB_OPTION = 'free_a_b_testing_db_version';

	/**
	 * Visitors table name, with the site's prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'fabt_visitors';
	}

	/**
	 * Create the table if this version hasn't yet. Runs on every load, so
	 * updates that skip the activation hook still get it.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Create (or update) the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				test_id bigint(20) unsigned NOT NULL,
				slot smallint(5) unsigned NOT NULL,
				visitor char(32) NOT NULL,
				client char(16) NOT NULL DEFAULT '',
				seen_at datetime NOT NULL,
				converted_at datetime DEFAULT NULL,
				orders int(10) unsigned NOT NULL DEFAULT 0,
				revenue decimal(18,4) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY test_visitor (test_id,visitor),
				KEY test_slot (test_id,slot),
				KEY client (client,seen_at)
			) {$collate};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}
}
