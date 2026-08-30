<?php
/**
 * Uninstall handler for Make My Site Agent-Ready.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'llmmd_settings' );
delete_option( 'llmmd_write_abilities' );
delete_option( 'llmmd_version' );
delete_option( 'mmsar_security_txt' );
delete_option( 'mmsar_security_txt_contact' );
delete_option( 'mmsar_robots_txt_extra' );
delete_option( 'mmsar_content_signals' );
delete_option( 'mmsar_structured_data' );
delete_option( 'mmsar_features' );
delete_option( 'mmsar_endpoints' );
delete_option( 'mmsar_agent_log' );
delete_option( 'mmsar_agent_log_limit' );
delete_option( 'mmsar_agent_log_db_version' );
delete_option( 'mmsar_agent_log_migrated' );
delete_option( 'mmsar_agent_log_pages' );
delete_option( 'mmsar_negotiation_check' );
delete_option( 'mmsar_negotiation_reset_done' );
delete_transient( 'llmmd_llms_txt' );
delete_transient( 'mmsar_llms_full_txt' );
delete_transient( 'mmsar_flush_needed' );
delete_transient( 'mmsar_negotiation_check_pending' );
delete_transient( 'mmsar_negotiation_check_notice' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing this plugin's own cached markdown post meta on uninstall. No core API deletes meta by key across all posts, and caching is meaningless for a one-time delete.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_llmmd_content' ) );

// The agent log's own table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping this plugin's own table on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'mmsar_agent_log' ) );
