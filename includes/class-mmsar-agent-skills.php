<?php
/**
 * Agent Skills discovery: /.well-known/agent-skills/index.json and one bundled SKILL.md
 * Spec: https://github.com/cloudflare/agent-skills-discovery-rfc (draft v0.2.0)
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR Agent Skills handler.
 */
class MMSAR_Agent_Skills {

	const SKILL_NAME = 'fetch-content-as-markdown';

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ) );
	}

	/**
	 * Add rewrite rules.
	 *
	 * @return void
	 */
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

	/**
	 * Add query vars.
	 *
	 * @param mixed $vars Vars.
	 * @return mixed Result.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_agent_skills_index';
		$vars[] = 'mmsar_agent_skill_md';
		return $vars;
	}

	/**
	 * Serve.
	 *
	 * @return void
	 */
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
	 * @param string $home Home URL used to build absolute endpoint links.
	 * @return array List of [ 'key' => feature key, 'line' => markdown bullet, 'note' => extra note ].
	 */
	private static function endpoint_sections( $home ) {
		$sections = array();
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$sections[] = array(
				'line' => "- `{$home}llms.txt` — a curated index of the site's most important pages, one line per entry with a short description. Start here for an overview.",
			);
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$sections[] = array(
				'line' => "- `{$home}llms-full.txt` — every published post and page concatenated into one file, each entry separated by `---` with its title and URL. Use this for a single-fetch full-corpus read.",
			);
		}
		if ( mmsar_feature_enabled( 'markdown' ) ) {
			$sections[] = array(
				'line' => "- `{$home}<slug>.md` — the raw Markdown for any single published post or page, mirroring its canonical URL with a `.md` suffix (e.g. `{$home}about.md` for the About page). Use this instead of fetching and parsing the HTML version of a specific page.",
				'note' => "- The homepage's `.md` mirror is at `{$home}index.md`.",
			);
		}
		if ( mmsar_feature_enabled( 'mcp_server' ) ) {
			// Listed ahead of OpenAPI because a client that can use it should: connecting once beats
			// building requests, and the tools return content already parsed.
			$mcp       = MMSAR_MCP::endpoint_url();
			$sections[] = array(
				'line' => "- `{$mcp}` — a read-only MCP server over Streamable HTTP, no authentication. If your client speaks MCP, connect here instead of fetching files: it can search this site, list its content, and return any page as Markdown.",
			);
		}
		if ( mmsar_feature_enabled( 'openapi' ) && MMSAR_OpenAPI::is_serving() ) {
			$openapi   = MMSAR_OpenAPI::url();
			$sections[] = array(
				'line' => "- `{$openapi}` — an OpenAPI description of this site's HTTP API, including how to search and filter content and what shape its errors take. Fetch this when you need to build a request rather than read a page.",
			);
		}
		return $sections;
	}

	/**
	 * Skill md content.
	 *
	 * @return mixed Result.
	 */
	private static function skill_md_content() {
		$site_name  = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$home       = home_url( '/' );
		$skill_name = self::SKILL_NAME;

		$sections   = self::endpoint_sections( $home );
		$bullets    = implode( "\n", array_column( $sections, 'line' ) );
		$notes      = array_filter( array_column( $sections, 'note' ) );
		$notes_body = $notes ? "\n" . implode( "\n", $notes ) : '';
		// Endpoints other plugins registered that an agent can call, rather than just read.
		$actions = MMSAR_Registry::skill_md_section();

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
{$actions}
MD;
	}

	/**
	 * Skill digest.
	 *
	 * @return mixed Result.
	 */
	private static function skill_digest() {
		return 'sha256:' . hash( 'sha256', self::skill_md_content() );
	}

	/**
	 * Joins a list into "a", "a and b", or "a, b, and c" for the one-line index description.
	 *
	 * @param string[] $items Items to join.
	 * @return string Human-readable joined string.
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

	/**
	 * Serve index.
	 *
	 * @return void
	 */
	private static function serve_index() {
		$skill_url = home_url( '/.well-known/agent-skills/' . self::SKILL_NAME . '/SKILL.md' );

		// Only name the endpoints that are actually enabled, so the one-line description never points
		// an agent at something the SKILL.md itself no longer documents.
		$enabled = array();
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

		// This skill's SKILL.md also documents any callable endpoints other plugins registered, so
		// say so here — the one-line description is what an agent uses to decide whether to fetch it.
		if ( MMSAR_Registry::skill_md_section() ) {
			$description .= ' Also documents endpoints on this site that agents can call.';
		}

		$index = array(
			'$schema' => 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
			'skills'  => array(
				array(
					'name'        => self::SKILL_NAME,
					'type'        => 'skill-md',
					'description' => $description,
					'url'         => $skill_url,
					'digest'      => self::skill_digest(),
				),
			),
		);

		// Integrations that serve a SKILL.md of their own are listed as separate skills rather than
		// folded into ours, so each one keeps its own URL and digest.
		foreach ( MMSAR_Registry::agent_skill_entries() as $entry ) {
			$index['skills'][] = $entry;
		}

		/**
		 * Filters the complete Agent Skills index document before it is served.
		 *
		 * The escape hatch for skill types the endpoint registry does not model. Most integrations
		 * want the mmsar_registered_endpoints filter instead.
		 *
		 * @param array $index The Agent Skills discovery index, as a PHP array.
		 */
		$filtered = apply_filters( 'mmsar_agent_skills_index', $index );
		if ( is_array( $filtered ) ) {
			$index = $filtered;
		}

		mmsar_send_cache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'Agent Skills index' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	// -------------------------------------------------------------------------
	// /.well-known/agent-skills/fetch-content-as-markdown/SKILL.md
	// -------------------------------------------------------------------------

	/**
	 * Serve skill md.
	 *
	 * @return void
	 */
	private static function serve_skill_md() {
		mmsar_send_cache_headers();
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'SKILL.md' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/markdown, not HTML.
		echo self::skill_md_content();
		exit;
	}
}
