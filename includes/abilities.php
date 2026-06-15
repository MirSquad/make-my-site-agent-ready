<?php
/**
 * WordPress Abilities API integration for Make My Site Agent-Ready.
 * Requires WP 6.9+ (Abilities API). Does nothing on older versions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	return;
}

add_action( 'wp_abilities_api_categories_init', 'mmsar_register_ability_category' );
function mmsar_register_ability_category() {
	wp_register_ability_category( 'make-my-site-agent-ready', array(
		'label'       => __( 'Make My Site Agent-Ready', 'make-my-site-agent-ready' ),
		'description' => __( 'Inspect plugin settings and trigger content regeneration.', 'make-my-site-agent-ready' ),
	) );
}

add_action( 'wp_abilities_api_init', 'mmsar_register_abilities' );
function mmsar_register_abilities() {

	wp_register_ability( 'make-my-site-agent-ready/get-settings', array(
		'label'       => __( 'Get Settings', 'make-my-site-agent-ready' ),
		'description' => __( 'Retrieve plugin settings: enabled post types and content root selector.', 'make-my-site-agent-ready' ),
		'category'    => 'make-my-site-agent-ready',
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'enabled_post_types' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Post types for which markdown files are generated.',
				),
				'root_selector' => array(
					'type'        => 'string',
					'description' => 'CSS selector used to extract content. Empty string means full post content.',
				),
			),
		),
		'permission_callback' => fn() => current_user_can( 'manage_options' ),
		'execute_callback'    => function( $input = null ) {
			return array(
				'enabled_post_types' => mmsar_get_enabled_post_types(),
				'root_selector'      => mmsar_get_root_selector(),
			);
		},
		'meta' => array(
			'mcp'         => array( 'public' => true ),
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'make-my-site-agent-ready/regenerate-files', array(
		'label'       => __( 'Regenerate Markdown Files', 'make-my-site-agent-ready' ),
		'description' => __( 'Regenerate cached markdown for all published posts across all enabled post types. On large sites this may take several seconds.', 'make-my-site-agent-ready' ),
		'category'    => 'make-my-site-agent-ready',
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'permission_callback' => fn() => current_user_can( 'manage_options' ),
		'execute_callback'    => function( $input = null ) {
			mmsar_bulk_generate();
			delete_transient( 'llmmd_llms_txt' );
			delete_transient( 'mmsar_llms_full_txt' );
			return array(
				'success' => true,
				'message' => __( 'Markdown files regenerated for all published content.', 'make-my-site-agent-ready' ),
			);
		},
		'meta' => array(
			'mcp'         => array( 'public' => true ),
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );
}
