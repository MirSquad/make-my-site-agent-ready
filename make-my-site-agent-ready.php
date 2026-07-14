<?php
/**
 * Plugin Name:       Make My Site Agent-Ready
 * Plugin URI:        https://miriamschwab.me/plugins/make-my-site-agent-ready
 * Description:       Makes your WordPress site ready for AI agents: .md URLs, llms.txt, llms-full.txt, security.txt, api-catalog, Agent Skills discovery, Link response headers, Content Signals, optional JSON-LD structured data, and AI crawler rules in robots.txt.
 * Version:           1.5.0
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

define( 'MMSAR_VERSION', '1.5.0' );
define( 'MMSAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMSAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MMSAR_PLUGIN_FILE', __FILE__ );

require_once MMSAR_PLUGIN_DIR . 'vendor/autoload.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-converter.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-server.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-llmstxt.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-endpoints.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-agent-skills.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-structured-data.php';
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
		// Any version bump may have added new rewrite rules (e.g. new /.well-known/ endpoints).
		// Updating a plugin's files in place does not re-fire register_activation_hook, so this
		// is the only reliable way new endpoints start working without a manual permalink resave.
		add_action( 'init', 'flush_rewrite_rules', 20 );
	}
}

// Prevent WordPress canonical redirect from appending trailing slashes to plugin-owned endpoints.
add_filter( 'redirect_canonical', 'mmsar_prevent_canonical_redirect' );
function mmsar_prevent_canonical_redirect( $redirect_url ) {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Reading path only, not using in queries or output.
	$path         = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	$plugin_paths = [
		'/llms.txt',
		'/llms-full.txt',
		'/.well-known/security.txt',
		'/.well-known/api-catalog',
		'/.well-known/agent-skills/index.json',
		'/.well-known/agent-skills/' . MMSAR_Agent_Skills::SKILL_NAME . '/SKILL.md',
	];
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
MMSAR_Agent_Skills::init();
MMSAR_Structured_Data::init();
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

/**
 * The markdown URL for the current request, or null if the current page has none.
 * Shared by the <link> tag (mmsar_alternate_link) and the Link response header
 * (mmsar_send_link_headers) so both always agree — one source of truth for the URL logic.
 */
function mmsar_get_markdown_url() {
	if ( is_front_page() && get_option( 'page_on_front' ) ) {
		return rtrim( home_url(), '/' ) . '/index.md';
	}
	if ( ! is_singular( mmsar_get_enabled_post_types() ) ) {
		return null;
	}
	return rtrim( get_permalink(), '/' ) . '.md';
}

add_action( 'wp_head', 'mmsar_alternate_link' );
function mmsar_alternate_link() {
	$md_url = mmsar_get_markdown_url();
	if ( ! $md_url ) {
		return;
	}
	echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '">' . "\n";
}

/**
 * HTTP Link headers for agent discovery (RFC 8288), so agents that read headers without
 * parsing the HTML body can still find these resources. Hooked to template_redirect, not
 * send_headers — send_headers fires before WP_Query resolves the main query, so conditional
 * tags like is_front_page()/is_singular() are not yet reliable there. template_redirect fires
 * after the query resolves and before any template output, so headers can still be sent.
 */
add_action( 'template_redirect', 'mmsar_send_link_headers' );
function mmsar_send_link_headers() {
	header( 'Link: <' . esc_url_raw( home_url( '/.well-known/api-catalog' ) ) . '>; rel="api-catalog"', false );
	header( 'Link: <' . esc_url_raw( home_url( '/.well-known/agent-skills/index.json' ) ) . '>; rel="service-desc"', false );

	$md_url = mmsar_get_markdown_url();
	if ( $md_url ) {
		header( 'Link: <' . esc_url_raw( $md_url ) . '>; rel="alternate"; type="text/markdown"', false );
	}
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

	$extra = trim( get_option( 'mmsar_robots_txt_extra', '' ) );
	// Skip auto-adding Content-Signal if the site owner already added one manually in the
	// extra-rules textarea, so we never emit two conflicting directives.
	$has_manual_signal  = ( false !== stripos( $extra, 'Content-Signal:' ) );
	$content_signal_line = $has_manual_signal ? '' : mmsar_content_signal_line();

	$rules = "\n";
	foreach ( $ai_crawlers as $bot ) {
		$rules .= "User-agent: {$bot}\n";
		$rules .= "Allow: /\n";
		if ( $content_signal_line ) {
			$rules .= $content_signal_line . "\n";
		}
		$rules .= "\n";
	}

	if ( strpos( $output, 'Sitemap:' ) === false ) {
		$rules .= 'Sitemap: ' . home_url( '/sitemap_index.xml' ) . "\n";
	}

	if ( ! empty( $extra ) ) {
		$rules .= "\n" . $extra . "\n";
	}

	return $output . $rules;
}

/**
 * Builds the Content-Signal directive line from the mmsar_content_signals option.
 * Proposed spec: https://contentsignals.org/ — Content-Signal: search=yes, ai-input=yes, ai-train=no
 */
function mmsar_content_signal_line() {
	$settings = get_option( 'mmsar_content_signals', [
		'search'   => 'yes',
		'ai_input' => 'yes',
		'ai_train' => 'no',
	] );
	$search   = ( isset( $settings['search'] ) && 'no' === $settings['search'] ) ? 'no' : 'yes';
	$ai_input = ( isset( $settings['ai_input'] ) && 'no' === $settings['ai_input'] ) ? 'no' : 'yes';
	$ai_train = ( isset( $settings['ai_train'] ) && 'yes' === $settings['ai_train'] ) ? 'yes' : 'no';

	return "Content-Signal: search={$search}, ai-input={$ai_input}, ai-train={$ai_train}";
}

register_activation_hook( __FILE__, 'mmsar_activate' );
function mmsar_activate() {
	MMSAR_Server::add_rewrite_rules();
	MMSAR_LLMs_Txt::add_rewrite_rules();
	MMSAR_Endpoints::add_rewrite_rules();
	MMSAR_Agent_Skills::add_rewrite_rules();
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
