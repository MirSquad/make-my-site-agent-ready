<?php
/**
 * Agent Skills discovery: /.well-known/agent-skills/index.json and one bundled SKILL.md
 * Spec: https://github.com/cloudflare/agent-skills-discovery-rfc (draft v0.2.0)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMSAR_Agent_Skills {

	const SKILL_NAME = 'fetch-content-as-markdown';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'serve' ] );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule(
			'^\.well-known/agent-skills/index\.json$',
			'index.php?mmsar_agent_skills_index=1',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/agent-skills/' . self::SKILL_NAME . '/SKILL\.md$',
			'index.php?mmsar_agent_skill_md=1',
			'top'
		);
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_agent_skills_index';
		$vars[] = 'mmsar_agent_skill_md';
		return $vars;
	}

	public static function serve() {
		if ( get_query_var( 'mmsar_agent_skills_index' ) ) {
			self::serve_index();
		}
		if ( get_query_var( 'mmsar_agent_skill_md' ) ) {
			self::serve_skill_md();
		}
	}

	// -------------------------------------------------------------------------
	// The skill content itself — one static string, so the index's digest and
	// the served file are always computed from the same source, no drift risk.
	// -------------------------------------------------------------------------

	/**
	 * The endpoints this skill can document, in the order they appear, each gated on its own feature
	 * toggle. A section is only listed when the endpoint it describes is actually being served —
	 * advertising an endpoint an agent then hits as a 404 makes the whole catalog less trustworthy.
	 *
	 * @return array List of [ 'key' => feature key, 'line' => markdown bullet, 'note' => extra note ].
	 */
	private static function endpoint_sections( $home ) {
		$sections = [];
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$sections[] = [
				'line' => "- `{$home}llms.txt` — a curated index of the site's most important pages, one line per entry with a short description. Start here for an overview.",
			];
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$sections[] = [
				'line' => "- `{$home}llms-full.txt` — every published post and page concatenated into one file, each entry separated by `---` with its title and URL. Use this for a single-fetch full-corpus read.",
			];
		}
		if ( mmsar_feature_enabled( 'markdown' ) ) {
			$sections[] = [
				'line' => "- `{$home}<slug>.md` — the raw Markdown for any single published post or page, mirroring its canonical URL with a `.md` suffix (e.g. `{$home}about.md` for the About page). Use this instead of fetching and parsing the HTML version of a specific page.",
				'note' => "- The homepage's `.md` mirror is at `{$home}index.md`.",
			];
		}
		return $sections;
	}

	private static function skill_md_content() {
		$site_name  = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$home       = home_url( '/' );
		$skill_name = self::SKILL_NAME;

		$sections   = self::endpoint_sections( $home );
		$bullets    = implode( "\n", array_column( $sections, 'line' ) );
		$notes      = array_filter( array_column( $sections, 'note' ) );
		$notes_body = $notes ? "\n" . implode( "\n", $notes ) : '';

		return <<<MD
---
name: {$skill_name}
description: Use this when you want {$site_name}'s content as clean Markdown instead of parsing HTML — for summarizing a page, answering questions about the site, or indexing it. Covers the machine-readable endpoints this site exposes; no authentication required.
---

# Fetching {$site_name}'s content as Markdown

This site exposes its content in Markdown alongside every normal HTML page, generated automatically from the same source and updated whenever a post or page is saved.

## Endpoints

{$bullets}

## Notes

- These are plain GET requests, no authentication, served as `text/plain` or `text/markdown`.
- Content reflects what's currently published — there's no separate draft/staging feed.{$notes_body}
MD;
	}

	private static function skill_digest() {
		return 'sha256:' . hash( 'sha256', self::skill_md_content() );
	}

	/**
	 * Joins a list into "a", "a and b", or "a, b, and c" for the one-line index description.
	 */
	private static function human_join( $items ) {
		$items = array_values( $items );
		$count = count( $items );
		if ( $count <= 1 ) {
			return implode( '', $items );
		}
		if ( 2 === $count ) {
			return $items[0] . ' and ' . $items[1];
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ', and ' . $last;
	}

	// -------------------------------------------------------------------------
	// /.well-known/agent-skills/index.json
	// -------------------------------------------------------------------------

	private static function serve_index() {
		$skill_url = home_url( '/.well-known/agent-skills/' . self::SKILL_NAME . '/SKILL.md' );

		// Only name the endpoints that are actually enabled, so the one-line description never points
		// an agent at something the SKILL.md itself no longer documents.
		$enabled = [];
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$enabled[] = 'llms.txt';
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$enabled[] = 'llms-full.txt';
		}
		if ( mmsar_feature_enabled( 'markdown' ) ) {
			$enabled[] = 'per-page .md endpoints';
		}
		$description = $enabled
			? 'Fetch this site\'s content as Markdown via ' . self::human_join( $enabled ) . '.'
			: 'Fetch this site\'s content as Markdown.';

		$index = [
			'$schema' => 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
			'skills'  => [
				[
					'name'        => self::SKILL_NAME,
					'type'        => 'skill-md',
					'description' => $description,
					'url'         => $skill_url,
					'digest'      => self::skill_digest(),
				],
			],
		];

		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	// -------------------------------------------------------------------------
	// /.well-known/agent-skills/fetch-content-as-markdown/SKILL.md
	// -------------------------------------------------------------------------

	private static function serve_skill_md() {
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/markdown, not HTML.
		echo self::skill_md_content();
		exit;
	}
}
