<?php
/**
 * Plugin Name:       Make My Site Agent-Ready
 * Plugin URI:        https://miriamschwab.me/plugins/make-my-site-agent-ready
 * Description:       Makes your WordPress site ready for AI agents: .md URLs, llms.txt, llms-full.txt, security.txt, and AI crawler rules in robots.txt.
 * Version:           1.3.0
 * Author:            Miriam Schwab
 * Author URI:        https://miriamschwab.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       make-my-site-agent-ready
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MMSAR_VERSION', '1.3.0' );
define( 'MMSAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMSAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MMSAR_PLUGIN_FILE', __FILE__ );

require_once MMSAR_PLUGIN_DIR . 'vendor/autoload.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-converter.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-server.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-llmstxt.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-endpoints.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-admin.php';
require_once MMSAR_PLUGIN_DIR . 'includes/abilities.php';

add_action( 'init', 'mmsar_load_textdomain' );
function mmsar_load_textdomain() {
	load_plugin_textdomain( 'make-my-site-agent-ready', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

function mmsar_get_enabled_post_types() {
	// Option key kept as llmmd_settings for data continuity with prior installs.
	$settings = get_option( 'llmmd_settings', [] );
	$defaults = [ 'post', 'page' ];
	return isset( $settings['post_types'] ) && is_array( $settings['post_types'] )
		? $settings['post_types']
		: $defaults;
}

function mmsar_get_root_selector() {
	$settings = get_option( 'llmmd_settings', [] );
	return isset( $settings['root_selector'] ) ? $settings['root_selector'] : '';
}

add_action( 'plugins_loaded', 'mmsar_check_version' );
function mmsar_check_version() {
	$stored = get_option( 'llmmd_version' );
	if ( $stored !== MMSAR_VERSION ) {
		delete_transient( 'llmmd_llms_txt' );
		delete_transient( 'mmsar_llms_full_txt' );
		update_option( 'llmmd_version', MMSAR_VERSION );
	}
}

// Prevent WordPress canonical redirect from appending trailing slashes to plugin-owned endpoints.
add_filter( 'redirect_canonical', 'mmsar_prevent_canonical_redirect' );
function mmsar_prevent_canonical_redirect( $redirect_url ) {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Reading path only, not using in queries or output.
	$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	$plugin_paths = [ '/llms.txt', '/llms-full.txt', '/.well-known/security.txt' ];
	foreach ( $plugin_paths as $p ) {
		if ( rtrim( $path, '/' ) === $p ) {
			return false;
		}
	}
	return $redirect_url;
}

MMSAR_Server::init();
MMSAR_LLMs_Txt::init();
MMSAR_Endpoints::init();
MMSAR_Admin::init();

add_action( 'save_post', 'mmsar_on_save_post', 20, 2 );
function mmsar_on_save_post( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		delete_post_meta( $post_id, '_llmmd_content' );
		return;
	}
	if ( ! in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
		return;
	}
	$markdown = MMSAR_Converter::convert_post( $post_id );
	update_post_meta( $post_id, '_llmmd_content', $markdown );
	delete_transient( 'llmmd_llms_txt' );
	delete_transient( 'mmsar_llms_full_txt' );
}

add_action( 'transition_post_status', 'mmsar_on_status_change', 10, 3 );
function mmsar_on_status_change( $new_status, $old_status, $post ) {
	if ( $new_status !== $old_status && in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
		if ( 'publish' === $old_status || 'publish' === $new_status ) {
			delete_transient( 'llmmd_llms_txt' );
			delete_transient( 'mmsar_llms_full_txt' );
		}
	}
}

add_action( 'wp_head', 'mmsar_alternate_link' );
function mmsar_alternate_link() {
	if ( is_front_page() && get_option( 'page_on_front' ) ) {
		$md_url = rtrim( home_url(), '/' ) . '/index.md';
		echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '">' . "\n";
		return;
	}
	if ( ! is_singular( mmsar_get_enabled_post_types() ) ) {
		return;
	}
	$url    = rtrim( get_permalink(), '/' );
	$md_url = $url . '.md';
	echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '">' . "\n";
}

add_filter( 'robots_txt', 'mmsar_robots_txt', 10, 2 );
function mmsar_robots_txt( $output, $public ) {
	$ai_crawlers = [
		'GPTBot',
		'ClaudeBot',
		'Anthropic-AI',
		'GoogleOther',
		'PerplexityBot',
		'FacebookBot',
	];

	$rules = "\n";
	foreach ( $ai_crawlers as $bot ) {
		$rules .= "User-agent: {$bot}\n";
		$rules .= "Allow: /\n\n";
	}

	if ( strpos( $output, 'Sitemap:' ) === false ) {
		$rules .= 'Sitemap: ' . home_url( '/sitemap_index.xml' ) . "\n";
	}

	return $output . $rules;
}

register_activation_hook( __FILE__, 'mmsar_activate' );
function mmsar_activate() {
	MMSAR_Server::add_rewrite_rules();
	MMSAR_LLMs_Txt::add_rewrite_rules();
	MMSAR_Endpoints::add_rewrite_rules();
	flush_rewrite_rules();
	mmsar_bulk_generate();
}

register_deactivation_hook( __FILE__, 'mmsar_deactivate' );
function mmsar_deactivate() {
	flush_rewrite_rules();
}

function mmsar_bulk_generate() {
	$post_types = mmsar_get_enabled_post_types();
	if ( empty( $post_types ) ) {
		return;
	}
	/**
	 * Filters the maximum number of posts processed during bulk markdown generation.
	 * Set to a positive integer on large sites to avoid timeouts. Default -1 processes all posts.
	 *
	 * @param int $limit Posts per page. -1 for all.
	 */
	$limit = (int) apply_filters( 'mmsar_bulk_generate_limit', -1 );
	$posts = get_posts( [
		'post_type'      => $post_types,
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'fields'         => 'ids',
	] );
	foreach ( $posts as $post_id ) {
		$markdown = MMSAR_Converter::convert_post( $post_id );
		update_post_meta( $post_id, '_llmmd_content', $markdown );
	}
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'mmsar_action_links' );
function mmsar_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=make-my-site-agent-ready' ) ) . '">' . esc_html__( 'Settings', 'make-my-site-agent-ready' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

add_filter( 'plugin_row_meta', 'mmsar_plugin_row_meta', 10, 2 );
function mmsar_plugin_row_meta( $links, $file ) {
	if ( plugin_basename( MMSAR_PLUGIN_FILE ) !== $file ) {
		return $links;
	}
	foreach ( $links as $key => $link ) {
		if ( strpos( $link, 'plugin-install.php' ) !== false ) {
			unset( $links[ $key ] );
		}
	}
	$links[] = '<a href="' . esc_url( 'https://miriamschwab.me/plugins/make-my-site-agent-ready' ) . '" target="_blank">' . esc_html__( 'Visit plugin site', 'make-my-site-agent-ready' ) . '</a>';
	return $links;
}
