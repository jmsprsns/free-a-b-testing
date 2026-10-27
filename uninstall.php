<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data
 * (settings, tests, variants and results). Pages that were tested keep
 * their current content, including any winner that was applied.
 *
 * @package Free_A_B_Testing
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Tests and variants, with their meta and revisions.
$free_a_b_testing_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('fabt_test', 'fabt_variant')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( $free_a_b_testing_ids as $free_a_b_testing_id ) {
	wp_delete_post( (int) $free_a_b_testing_id, true );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fabt_visitors" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option( 'free_a_b_testing_settings' );
delete_option( 'free_a_b_testing_live' );
delete_option( 'free_a_b_testing_db_version' );
delete_option( 'free_a_b_testing_salt' );

// Admin notices waiting to be shown.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_fabt\\_notice\\_%' OR option_name LIKE '\\_transient\\_timeout\\_fabt\\_notice\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-free-a-b-testing' );
