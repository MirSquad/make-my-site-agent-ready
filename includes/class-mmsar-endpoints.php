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
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			add_rewrite_rule(
				'^llms-full\.txt$',
				'index.php?mmsar_llms_full_txt=1',
				'top'
			);
		}
		if ( mmsar_feature_enabled( 'security_txt' ) ) {
			add_rewrite_rule(
				'^\.well-known/security\.txt$',
				'index.php?mmsar_security_txt=1',
				'top'
			);
		}
		if ( mmsar_feature_enabled( 'api_catalog' ) ) {
			add_rewrite_rule(
				'^\.well-known/api-catalog$',
				'index.php?mmsar_api_catalog=1',
				'top'
			);
		}
		// Route robots.txt through WordPress so the robots_txt filter (and our AI rules) always fire,
		// even if a physical robots.txt file exists in the webroot. This rule is what overrides a
		// static file, so it must not be registered when the robots.txt feature is off — otherwise
		// opting out would still hijack a hand-maintained robots.txt, just without adding anything.
		if ( mmsar_feature_enabled( 'robots_txt' ) ) {
			add_rewrite_rule(
				'^robots\.txt$',
				'index.php?robots=1',
				'top'
			);
		}
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

	/**
	 * Normalises whatever the user typed in the Contact field into a value security.txt accepts.
	 *
	 * RFC 9116 requires Contact to be a URI, so a bare path or a bare email address is not valid
	 * on its own. People reasonably type all three forms, so accept them all and expand:
	 *   https://example.com/contact  ->  used as-is
	 *   /contact  or  contact        ->  https://thissite.com/contact
	 *   security@example.com         ->  mailto:security@example.com
	 */
	public static function normalize_contact( $contact ) {
		$contact = trim( (string) $contact );
		if ( '' === $contact ) {
			return '';
		}
		// Already a URI of some scheme (https:, mailto:, tel:) — trust it.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $contact ) ) {
			return $contact;
		}
		if ( is_email( $contact ) ) {
			return 'mailto:' . $contact;
		}
		return home_url( '/' . ltrim( $contact, '/' ) );
	}

	public static function default_security_txt() {
		$contact = self::normalize_contact( get_option( 'mmsar_security_txt_contact', '' ) );
		if ( '' === $contact ) {
			// No contact configured. Guessing a URL that probably 404s would publish a broken
			// security.txt, so fall back to the admin email, which always exists.
			$contact = 'mailto:' . get_option( 'admin_email' );
		}
		$expires = gmdate( 'Y-m-d\T00:00:00.000\Z', strtotime( '+1 year' ) );
		return "Contact: {$contact}\nExpires: {$expires}\nPreferred-Languages: en\n";
	}

	// -------------------------------------------------------------------------
	// /.well-known/api-catalog — RFC 9727, served as a Linkset (RFC 9264)
	// -------------------------------------------------------------------------

	private static function serve_api_catalog() {
		// This document exists to tell an agent what it can fetch, so it must only list resources
		// that are actually being served — a catalog entry pointing at a switched-off endpoint
		// sends agents to a 404 and makes the whole catalog less trustworthy.
		$entry = [ 'anchor' => home_url( '/' ) ];

		$describedby = [];
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$describedby[] = [ 'href' => home_url( '/llms.txt' ), 'type' => 'text/markdown' ];
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$describedby[] = [ 'href' => home_url( '/llms-full.txt' ), 'type' => 'text/markdown' ];
		}
		if ( mmsar_feature_enabled( 'security_txt' ) ) {
			$describedby[] = [ 'href' => home_url( '/.well-known/security.txt' ), 'type' => 'text/plain' ];
		}
		if ( $describedby ) {
			$entry['describedby'] = $describedby;
		}

		if ( mmsar_feature_enabled( 'agent_skills' ) ) {
			$entry['service-desc'] = [
				[ 'href' => home_url( '/.well-known/agent-skills/index.json' ), 'type' => 'application/json' ],
			];
		}

		$items = [];
		// Same detection the robots.txt Sitemap directive uses, rather than assuming Yoast's filename.
		$sitemap_url = mmsar_get_sitemap_url();
		if ( $sitemap_url ) {
			$items[] = [ 'href' => $sitemap_url, 'type' => 'application/xml' ];
		}
		$items[] = [ 'href' => home_url( '/feed/' ), 'type' => 'application/rss+xml' ];
		$entry['item'] = $items;

		$linkset = [ 'linkset' => [ $entry ] ];

		header( 'Content-Type: application/linkset+json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $linkset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
