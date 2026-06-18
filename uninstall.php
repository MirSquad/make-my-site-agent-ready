<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'llmmd_settings' );
delete_option( 'llmmd_write_abilities' );
delete_option( 'llmmd_version' );
delete_option( 'mmsar_security_txt' );
delete_option( 'mmsar_robots_txt_extra' );
delete_transient( 'llmmd_llms_txt' );
delete_transient( 'mmsar_llms_full_txt' );

global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_llmmd_content' ) );
