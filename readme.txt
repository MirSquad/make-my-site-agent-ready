=== Make My Site Agent-Ready ===
Contributors: illuminea
Tags: markdown, llm, ai, llms-txt, agents
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes your WordPress site ready for AI agents: markdown URLs, llms.txt, security.txt, api-catalog, Agent Skills, and AI crawler rules.

== Description ==

Make My Site Agent-Ready makes your WordPress content accessible to AI language models and AI agents. Every post and page gets a markdown endpoint automatically, a site index is generated for discovery, and the full site content is available in one request for LLMs that want it.

Every feature below can be switched off individually under Settings > Agent-Ready, so the plugin stays out of the way of anything you already manage elsewhere. Everything is on by default except structured data.

**Features:**

* **Individual feature toggles** — Turn off any output the plugin publishes (markdown URLs, llms.txt, llms-full.txt, robots.txt rules, security.txt, api-catalog, Agent Skills). A disabled feature registers nothing at all — no rewrite rule, no filter, no header — so the site behaves as if that part of the plugin did not exist.
* **`.md` URLs** — Append `.md` to any post or page URL to get a clean markdown version
* **llms.txt** — Auto-generated site index at `/llms.txt` listing all available markdown content
* **llms-full.txt** — Full site content in one file at `/llms-full.txt` for LLMs that want everything
* **security.txt** — Serves `/.well-known/security.txt` (RFC 9116). Enter your security contact as a full URL, a path like `/contact`, or an email address, and the plugin formats it correctly
* **api-catalog** — Serves `/.well-known/api-catalog` (RFC 9727), a machine-readable index linking llms.txt, llms-full.txt, security.txt, the Agent Skills index, sitemap, and feed
* **Agent Skills discovery** — Serves `/.well-known/agent-skills/index.json` plus a bundled skill teaching agents how to use this plugin's markdown endpoints
* **Link response headers** — Every front-end response carries `Link` headers (RFC 8288) pointing to api-catalog, llms.txt, and the Agent Skills index; singular posts/pages add one more pointing to their markdown alternate — so agents that only read headers, not HTML, can still find these resources
* **Content Signals** — Declares `Content-Signal: search=..., ai-input=..., ai-train=...` (contentsignals.org) under each AI crawler's group in `robots.txt`, configurable in Settings > Agent-Ready. Defaults to allowing search and live AI retrieval, declining AI training use.
* **Structured data (JSON-LD)** — Optional (off by default) pointer to the markdown alternate on each enabled post/page. When Yoast SEO is active and produces schema for the page, the pointer merges directly into Yoast's own `Article`/`WebPage` piece — no duplicate block. Otherwise, a standalone `Article`/`WebPage` JSON-LD block is added instead. Enable in Settings > Agent-Ready.
* **AI crawler rules** — Adds explicit `Allow: /` entries for GPTBot, ClaudeBot, and other AI crawlers in `robots.txt`
* **llms.txt discovery in robots.txt** — Adds an `Llms-txt:` directive pointing at your `/llms.txt`, so agents that fetch `robots.txt` first are told where the index is. Skipped if llms.txt is switched off, or if `robots.txt` already mentions it
* **Endpoints stay reachable** — If `robots.txt` disallows a path one of your published endpoints lives on (several SEO plugins disallow `/wp-json/` by default), an `Allow:` line for that individual endpoint is added above the rule blocking it. The endpoint stays reachable to agents that found it in your api-catalog, llms.txt or Agent Skills index; the rest of the REST API stays disallowed
* **YAML frontmatter** — Title, date, author, URL, excerpt, categories, and tags
* **Pre-generated** — Markdown is generated when posts are saved, so `.md` requests are instant
* **Discoverable** — Adds `<link rel="alternate" type="text/markdown">` to page headers
* **Lightweight** — No custom database tables, no cron jobs, no frontend JavaScript

**How it works:**

1. When you save a post, the plugin converts it to markdown and stores it in post meta
2. When someone requests `your-post.md`, the pre-generated markdown is served instantly
3. The `/llms.txt` file lists all available markdown URLs organized by category
4. The `/llms-full.txt` file concatenates the full content of all posts and pages

== Installation ==

1. Upload the `make-my-site-agent-ready` folder to `/wp-content/plugins/`.
2. Activate from Plugins > Installed Plugins.
3. Configure under Settings > Agent-Ready.
4. Visit `/llms.txt` on your site to verify the index.

== Frequently Asked Questions ==

= Does this slow down my site? =

No. The only impact on normal page loads is a single `<link>` tag in the HTML head. Markdown is pre-generated on post save, so `.md` requests serve directly from the database with no runtime conversion.

= What URL format does it use? =

Append `.md` to any post or page URL. For example: `example.com/my-post.md`. The front page is available at `example.com/index.md`.

= What is llms.txt? =

It's an emerging convention (similar to robots.txt) that helps AI models discover available content on your site. The file at `/llms.txt` lists all your markdown-enabled content.

= What is llms-full.txt? =

A companion to `llms.txt` — it concatenates the full markdown content of all published posts and pages into a single file for LLMs that want the entire site in one request.

= Can I control which post types get markdown? =

Yes. Go to Settings > Agent-Ready and check the post types you want to enable.

= I have a very large site — will activation or "Regenerate all" time out? =

On activation and when you regenerate manually, the plugin converts every published post in one request, which can be slow or hit memory/time limits on sites with thousands of posts. Use the `mmsar_bulk_generate_limit` filter to cap how many posts are processed per run (default `-1` = all):

`add_filter( 'mmsar_bulk_generate_limit', function() { return 500; } );`

Remaining posts are still converted on demand the first time their `.md`, `/llms.txt`, or `/llms-full.txt` is requested, and the result is cached from then on.

= I made something else on my site agent-ready. Can I get it listed in these files? =

Yes, and you don't need to write any code. Go to **Settings > Agent-Ready > Your Endpoints**, fill in the empty row with a name and the URL, tick which documents it should appear in, and save. It's then listed in `/.well-known/api-catalog`, `/llms.txt`, and the Agent Skills index together.

The description field is what an agent reads to decide whether to use your endpoint, so say what it does and mention anything a caller must do first. Each saved endpoint tells you underneath exactly which documents it's currently appearing in — or why it isn't.

= Can a plugin register an endpoint in code instead? =

Yes. Plugin and theme authors can register one so it works on any site without the owner filling in a form:

`add_action( 'init', function() {`
`    if ( ! function_exists( 'mmsar_register_endpoint' ) ) { return; }`
`    mmsar_register_endpoint( array(`
`        'title'       => 'Contact form',`
`        'href'        => rest_url( 'my-plugin/v1/contact' ),`
`        'description' => 'Send the site owner a message.',`
`        'type'        => 'application/json',`
`        'methods'     => array( 'POST' ),`
`        'auth'        => 'none',`
`    ) );`
`} );`

Use the `mmsar_registered_endpoints` filter for the same thing without a direct call. Add `'surfaces' => array( 'llms_txt' )` to limit where it appears, and `'rel'` to set its api-catalog link relation. Endpoints that publish a SKILL.md of their own can pass `'skill_url'` to get their own entry in the Agent Skills index. Code-registered endpoints appear read-only under "Added by Plugins" on the settings page. Full documentation is in the plugin's README on GitHub.

== Changelog ==

= 1.12.0 - 2026-08-13 =
* New: `robots.txt` now carries an `Llms-txt:` directive pointing at `/llms.txt`. The site was publishing an llms.txt and advertising it in the api-catalog and the Agent Skills index, but said nothing about it in the one file agents and agent-readiness checkers fetch first. There is no ratified robots.txt directive for llms.txt, and compliant parsers ignore directives they do not recognise, so the line cannot affect crawling.
* New: A `Link: <.../llms.txt>; rel="describedby"; type="text/plain"` header on every front-end response, alongside the existing api-catalog and Agent Skills headers. Same relation and media type the api-catalog already uses for llms.txt, so header-reading and catalog-reading agents are told the same thing.
* Fix: Switching a feature off now always flushes rewrite rules, whichever way the setting was written. Saving on the settings page already did this; a change made by WP-CLI or by another plugin did not, leaving the endpoint reachable after its feature was switched off. The pending flush also survives for a day rather than a minute, so it still happens on a site that gets no traffic immediately afterwards.
* Both new outputs are skipped when the llms.txt feature is switched off, so neither ever points at a 404. The robots.txt directive is also skipped on sites set to discourage search engines, and when the finished robots.txt already mentions llms.txt — an owner who added the line by hand under Additional Rules keeps theirs instead of getting it twice.

= 1.11.0 - 2026-08-12 =
* Fix: An endpoint published in the api-catalog, llms.txt or Agent Skills index is no longer blocked by a `Disallow` rule in the same robots.txt. The plugin now adds an `Allow:` line for the individual endpoint path, in the same user-agent group and above the rule that blocks it, so compliant crawlers apply the more specific rule and the endpoint stays reachable while the broader path stays disallowed. This mattered most for `/wp-json/`, which Yoast SEO disallows by default (the "deny_wp_json_crawling" option) — a site could advertise a REST endpoint in three discovery documents and tell agents to stay off it in the fourth.
* The Allow paths are derived from the same registered-endpoint list that feeds those three documents, so endpoints added on the settings page and endpoints registered in code by a plugin or theme are both covered, and the two can't drift apart.
* Lines are only added where they are actually needed: nothing is emitted for an endpoint no rule blocks, for one already allowed by an equally specific rule, or for one hosted on another domain. On sites set to discourage search engines (blog_public = 0) the file is left alone entirely, matching the existing behaviour for AI crawler rules.

= 1.10.1 - 2026-08-12 =
* Fix: The files this plugin publishes now send their own `Cache-Control` header (`public, max-age=300, s-maxage=300`) instead of inheriting whatever the host or CDN applies by default. On a CDN-fronted site that default can be very long — one real install had `/.well-known/api-catalog` pinned at the edge for a week, so an endpoint added on the settings page was published correctly by the site but not visible to anyone fetching it. Changes now appear within about five minutes. Use the `mmsar_document_max_age` filter to change the duration, or return 0 to disable caching entirely.

= 1.10.0 - 2026-08-12 =
* New: Add and manage endpoints from Settings > Agent-Ready — no code required. Give it a name and a URL, tick which of the three documents it belongs in, and save. Optional fields for methods, content type, authentication and link relation are tucked behind "Technical details".
* New: Three abilities for the WordPress Abilities API (WP 6.9+) — list-endpoints, set-endpoint and delete-endpoint — so an AI agent connected through the MCP Adapter can manage the same list. Endpoints registered in code by a plugin or theme are read-only to these, and are reported as such rather than appearing to change.
* New: Each saved endpoint reports where it is actually being published, and says why when it isn't — a mistyped URL or an unticked document is stated on the row instead of the entry quietly disappearing.
* Change: Endpoints registered in code by a plugin or theme now appear under their own read-only "Added by Plugins" heading, separate from the ones you manage.

= 1.9.0 - 2026-08-12 =
* New: Other plugins and themes can now add their own endpoints to the files this plugin publishes. An endpoint registered once with `mmsar_register_endpoint()` (or the `mmsar_registered_endpoints` filter) is listed in `/.well-known/api-catalog`, `/llms.txt`, and the Agent Skills index, so something like an agent-ready contact form becomes discoverable everywhere agents look. Registered endpoints are shown read-only on the settings page.
* New: `mmsar_api_catalog_linkset`, `mmsar_llms_txt_content`, and `mmsar_agent_skills_index` filters, for changes the endpoint registry does not cover.
* No change to existing behavior: a site with nothing registered publishes byte-for-byte the same files it did before.

= 1.8.2 - 2026-08-05 =
* Hardening: the request URI used for canonical-redirect checks is now sanitized and parsed with wp_parse_url(); admin output is explicitly escaped. Code documentation and WordPress coding-standards cleanup. No changes to behavior.

= 1.8.1 - 2026-07-22 =
* Fix (packaging): The zip you get by downloading the repo from GitHub ("Download ZIP" or a release's "Source code" asset) now contains only the plugin files, not the `.github/` CI config or dev docs. No functional change to the plugin.

= 1.8.0 - 2026-07-21 =
* Change: The settings page is easier to navigate. The old "Quick Links" list at the bottom is gone — each feature toggle at the top now carries its own "View" link to the live file (shown only while the feature is on), so everything is in one place.
* Change: Feature toggles that have more to configure (Markdown URLs, robots.txt, security.txt) now show a "Configure below ↓" link that jumps to the matching settings section, so options like the robots.txt Additional Rules box and the security.txt Contact field are easier to find.

= 1.7.1 - 2026-07-21 =
* Security: Password-protected posts could appear in `/llms-full.txt` and `/llms.txt` (the per-page `.md` endpoint already blocked them). Both aggregate feeds now exclude password-protected content, and password-protecting a post clears its cached markdown.
* Security: The security.txt Contact line now only accepts safe URI schemes (https, http, mailto, tel), so an unsafe scheme like `javascript:` can no longer be published.
* Fix: Content Signals sanitization no longer falls back to "yes" for `ai-train` — a malformed value now correctly defaults to "no", matching the setting's own default.
* Fix: Markdown `.md` responses now explicitly require a published post (defense in depth against edge permalink setups resolving to non-public content).
* Change: Agent Skills discovery (SKILL.md and index.json) now documents only the endpoints that are actually enabled, so agents aren't sent to 404s when a feature is switched off.
* Change: On sites set to discourage search engines (blog_public = 0), the plugin no longer adds `Allow: /` AI-crawler rules to robots.txt, respecting the admin's intent.
* Change: api-catalog advertises llms.txt / llms-full.txt as `text/plain`, matching the headers they actually send.

= 1.7.0 - 2026-07-20 =
* New: Every feature can now be switched off individually under Settings > Agent-Ready — markdown URLs, llms.txt, llms-full.txt, robots.txt rules, security.txt, api-catalog, and Agent Skills discovery. All default to on, so updating changes nothing until you choose otherwise.
* New: Turning off robots.txt handling stops the plugin both appending AI crawler rules and routing /robots.txt through WordPress, so a hand-maintained or SEO-plugin-managed robots.txt is left completely alone. The settings screen explains what you give up before you switch it off.
* New: security.txt now has a dedicated Security Contact field that accepts a full URL, a path like /contact, or an email address, and formats it into a valid RFC 9116 Contact line. With nothing set it falls back to the site admin email instead of guessing a /contact URL that may not exist.
* Fix: The Sitemap directive in robots.txt, and the sitemap entry in `/.well-known/api-catalog`, no longer hardcode Yoast's `sitemap_index.xml`. Both now detect Yoast, Rank Math, All in One SEO, SEOPress, or WordPress core sitemaps and use the right URL — previously sites without a Yoast-style sitemap advertised a URL that 404s.
* Fix: `/.well-known/api-catalog` now lists only the endpoints that are actually enabled, instead of always advertising llms.txt, llms-full.txt, security.txt and the Agent Skills index regardless of the feature toggles.
* Fix: The Sitemap directive is added at the very end of the robots.txt filter chain, so it correctly stands down when an SEO plugin has already added one. Yoast hooks that filter at priority 99999, so the previous check ran too early to see its output and emitted a duplicate Sitemap line.

= 1.6.1 - 2026-07-15 =
* Fix: the Yoast schema injection added in 1.6.0 never actually registered — it was gated behind `defined('WPSEO_VERSION')` at plugin-load time, but plugin load order isn't guaranteed, so that check could run before Yoast's own file had loaded and defined the constant. The filters are now registered unconditionally; they simply never fire if Yoast isn't active.

= 1.6.0 - 2026-07-14 =
* Change: JSON-LD structured data now merges into Yoast SEO's own `Article`/`WebPage` schema piece (via Yoast's `wpseo_schema_article`/`wpseo_schema_webpage` filters) when Yoast is active and produces schema for the page, instead of always adding a separate block. Falls back to the standalone block from 1.5.0 when Yoast isn't active or doesn't cover the page.
* Change: the admin conflict notice and settings description updated to reflect the new Yoast-merge behavior; RankMath (or other non-Yoast SEO plugins) still gets the standalone-block warning.

= 1.5.0 - 2026-07-14 =
* New: Optional JSON-LD structured data (`Article`/`WebPage`) on enabled posts/pages, pointing at the markdown alternate. Off by default; new admin notice warns if enabled alongside an active SEO plugin (Yoast/RankMath).
* Prompted by the plugin's own agent-readiness gap tracking

= 1.4.3 - 2026-07-06 =
* New: Content Signals — `Content-Signal: search=..., ai-input=..., ai-train=...` (contentsignals.org / IETF AI Preferences draft) added under each AI crawler's group in robots.txt. Configurable in Settings > Agent-Ready (three yes/no toggles); defaults to search=yes, ai-input=yes, ai-train=no.
* Prompted by isitagentready.com flagging the absence of Content Signals in robots.txt

= 1.4.2 - 2026-07-06 =
* New: Link response headers (RFC 8288) on every front-end response — points agents to api-catalog and the Agent Skills index; singular posts/pages add a third pointing to their markdown alternate
* Prompted by isitagentready.com flagging the homepage's missing Link headers

= 1.4.1 - 2026-07-06 =
* Fix: the broad `.md` catch-all rewrite rule (used for post/page markdown URLs) also matched `/.well-known/agent-skills/*/SKILL.md`, causing the Agent Skills file to 404. The catch-all now excludes `/.well-known/` paths.

= 1.4.0 - 2026-07-06 =
* New: /.well-known/api-catalog endpoint (RFC 9727) indexing llms.txt, llms-full.txt, security.txt, the Agent Skills index, sitemap, and feed
* New: Agent Skills discovery — /.well-known/agent-skills/index.json plus a bundled skill teaching agents how to use this plugin's markdown endpoints
* Improvement: version bumps now auto-flush rewrite rules so new endpoints work without a manual Permalinks resave

= 1.3.0 - 2026-06-15 =
* Rename: plugin renamed to "Make My Site Agent-Ready" with slug make-my-site-agent-ready
* New: /llms-full.txt endpoint serving full site content concatenated as markdown
* New: /.well-known/security.txt endpoint with configurable content in Settings
* New: AI crawler rules (GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, FacebookBot) in robots.txt
* Fix: trailing slash redirect on /llms.txt (and other plugin endpoints) caused by WordPress canonical redirect
* Abilities: regenerate-files ability now always registered; marked destructive so AI confirms before running
* Abilities: removed write abilities opt-in checkbox — destructive annotation handles confirmation

= 1.2.2 - 2026-06-01 =
* Fix: $input = null for PHP 8 compatibility in abilities execute callbacks

= 1.2.1 - 2026-06-01 =
* Fix: meta.mcp.public key in abilities registration

= 1.2.0 - 2026-06-01 =
* Add: WordPress Abilities API integration (get-settings, regenerate-files)

= 1.1.2 - 2026-05-24 =
* Fix: YAML frontmatter url and markdown_url fields now quoted for spec compliance
* Fix: Markdown link titles in llms.txt now escape ] characters to prevent broken links
* Fix: Version check moved into plugins_loaded hook
* Add: llmmd_bulk_generate_limit filter for large-site memory control
* Internal docs removed from repository

= 1.1.1 - 2026-05-20 =
* Replace "View details" plugin row link with "Visit plugin site"

= 1.1.0 - 2026-05-20 =
* Security: sanitize CSS selectors to prevent XPath injection
* Security: add X-Content-Type-Options: nosniff header on .md responses
* Security: use $wpdb->prepare() in uninstall.php
* Fix YAML escape order (backslashes before quotes)
* Auto-clear llms.txt transient on plugin version upgrade

= 1.0.5 - 2026-05-20 =
* Decode HTML entities in llms.txt

= 1.0.4 - 2026-05-20 =
* Decode HTML entities in YAML frontmatter

= 1.0.3 - 2026-05-20 =
* Fix front page /index.md
* Add alternate link tag to homepage

= 1.0.2 - 2026-05-20 =
* Fix front page /index.md returning 404

= 1.0.1 - 2026-05-20 =
* Add post excerpts/descriptions to llms.txt entries

= 1.0.0 - 2026-05-20 =
* Initial release.

== Upgrade Notice ==

= 1.3.0 =
Plugin renamed to Make My Site Agent-Ready. Deactivate the old plugin and activate the new one. Existing settings are preserved automatically.
