<?php
/**
 * Additional virtual endpoints: /llms-full.txt and /.well-known/security.txt
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMSAR_Endpoints {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'serve' ] );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule(
			'^llms-full\.txt$',
			'index.php?mmsar_llms_full_txt=1',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/security\.txt$',
			'index.php?mmsar_security_txt=1',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/api-catalog$',
			'index.php?mmsar_api_catalog=1',
			'top'
		);
		// Route robots.txt through WordPress so the robots_txt filter (and our AI rules) always fire,
		// even if a physical robots.txt file exists in the webroot.
		add_rewrite_rule(
			'^robots\.txt$',
			'index.php?robots=1',
			'top'
		);
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_llms_full_txt';
		$vars[] = 'mmsar_security_txt';
		$vars[] = 'mmsar_api_catalog';
		return $vars;
	}

	public static function serve() {
		if ( get_query_var( 'mmsar_llms_full_txt' ) ) {
			self::serve_llms_full_txt();
		}
		if ( get_query_var( 'mmsar_security_txt' ) ) {
			self::serve_security_txt();
		}
		if ( get_query_var( 'mmsar_api_catalog' ) ) {
			self::serve_api_catalog();
		}
	}

	// -------------------------------------------------------------------------
	// /llms-full.txt
	// -------------------------------------------------------------------------

	private static function serve_llms_full_txt() {
		$content = get_transient( 'mmsar_llms_full_txt' );
		if ( false === $content ) {
			$content = self::generate_llms_full_txt();
			set_transient( 'mmsar_llms_full_txt', $content, DAY_IN_SECONDS );
		}

		header( 'Content-Type: text/plain; charset=UTF-8' );
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/plain, not HTML.
		echo $content;
		exit;
	}

	private static function generate_llms_full_txt() {
		$post_types = mmsar_get_enabled_post_types();
		$posts      = get_posts( [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines   = [];
		$lines[] = '# ' . $site_name . ' — Full Content';
		$lines[] = '';
		$lines[] = '> Source: ' . home_url( '/llms-full.txt' );
		$lines[] = '> Generated: ' . gmdate( 'Y-m-d' );
		$lines[] = '';

		foreach ( $posts as $post ) {
			$title    = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$url      = get_permalink( $post );
			$markdown = get_post_meta( $post->ID, '_llmmd_content', true );

			if ( empty( $markdown ) ) {
				$markdown = MMSAR_Converter::convert_post( $post->ID );
				if ( ! empty( $markdown ) ) {
					update_post_meta( $post->ID, '_llmmd_content', $markdown );
				}
			}

			if ( empty( $markdown ) ) {
				continue;
			}

			$lines[] = '---';
			$lines[] = '';
			$lines[] = '# ' . $title;
			$lines[] = '';
			$lines[] = 'URL: ' . $url;
			$lines[] = '';
			$lines[] = trim( $markdown );
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// /.well-known/security.txt
	// -------------------------------------------------------------------------

	private static function serve_security_txt() {
		$content = get_option( 'mmsar_security_txt', '' );
		if ( empty( trim( $content ) ) ) {
			$content = self::default_security_txt();
		}

		header( 'Content-Type: text/plain; charset=UTF-8' );
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/plain, not HTML.
		echo $content;
		exit;
	}

	public static function default_security_txt() {
		$contact = home_url( '/contact' );
		$expires = gmdate( 'Y-m-d\T00:00:00.000\Z', strtotime( '+1 year' ) );
		return "Contact: {$contact}\nExpires: {$expires}\nPreferred-Languages: en\n";
	}

	// -------------------------------------------------------------------------
	// /.well-known/api-catalog — RFC 9727, served as a Linkset (RFC 9264)
	// -------------------------------------------------------------------------

	private static function serve_api_catalog() {
		$linkset = [
			'linkset' => [
				[
					'anchor'      => home_url( '/' ),
					'describedby' => [
						[ 'href' => home_url( '/llms.txt' ), 'type' => 'text/markdown' ],
						[ 'href' => home_url( '/llms-full.txt' ), 'type' => 'text/markdown' ],
						[ 'href' => home_url( '/.well-known/security.txt' ), 'type' => 'text/plain' ],
					],
					'service-desc' => [
						[ 'href' => home_url( '/.well-known/agent-skills/index.json' ), 'type' => 'application/json' ],
					],
					'item'        => [
						[ 'href' => home_url( '/sitemap_index.xml' ), 'type' => 'application/xml' ],
						[ 'href' => home_url( '/feed/' ), 'type' => 'application/rss+xml' ],
					],
				],
			],
		];

		header( 'Content-Type: application/linkset+json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $linkset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
