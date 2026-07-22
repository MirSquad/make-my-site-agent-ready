=== Make My Site Agent-Ready ===
Contributors: illuminea
Tags: markdown, llm, ai, llms-txt, agents
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes your WordPress site ready for AI agents: .md URLs, llms.txt, llms-full.txt, security.txt, api-catalog, Agent Skills discovery, Link response headers, Content Signals, optional JSON-LD structured data, and AI crawler rules.

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
* **Link response headers** — Every front-end response carries `Link` headers (RFC 8288) pointing to api-catalog and the Agent Skills index; singular posts/pages add a third pointing to their markdown alternate — so agents that only read headers, not HTML, can still find these resources
* **Content Signals** — Declares `Content-Signal: search=..., ai-input=..., ai-train=...` (contentsignals.org) under each AI crawler's group in `robots.txt`, configurable in Settings > Agent-Ready. Defaults to allowing search and live AI retrieval, declining AI training use.
* **Structured data (JSON-LD)** — Optional (off by default) pointer to the markdown alternate on each enabled post/page. When Yoast SEO is active and produces schema for the page, the pointer merges directly into Yoast's own `Article`/`WebPage` piece — no duplicate block. Otherwise, a standalone `Article`/`WebPage` JSON-LD block is added instead. Enable in Settings > Agent-Ready.
* **AI crawler rules** — Adds explicit `Allow: /` entries for GPTBot, ClaudeBot, and other AI crawlers in `robots.txt`
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

== Changelog ==

= 1.8.1 =
* Fix (packaging): The zip you get by downloading the repo from GitHub ("Download ZIP" or a release's "Source code" asset) now contains only the plugin files, not the `.github/` CI config or dev docs. No functional change to the plugin.

= 1.8.0 =
* Change: The settings page is easier to navigate. The old "Quick Links" list at the bottom is gone — each feature toggle at the top now carries its own "View" link to the live file (shown only while the feature is on), so everything is in one place.
* Change: Feature toggles that have more to configure (Markdown URLs, robots.txt, security.txt) now show a "Configure below ↓" link that jumps to the matching settings section, so options like the robots.txt Additional Rules box and the security.txt Contact field are easier to find.

= 1.7.1 =
* Security: Password-protected posts could appear in `/llms-full.txt` and `/llms.txt` (the per-page `.md` endpoint already blocked them). Both aggregate feeds now exclude password-protected content, and password-protecting a post clears its cached markdown.
* Security: The security.txt Contact line now only accepts safe URI schemes (https, http, mailto, tel), so an unsafe scheme like `javascript:` can no longer be published.
* Fix: Content Signals sanitization no longer falls back to "yes" for `ai-train` — a malformed value now correctly defaults to "no", matching the setting's own default.
* Fix: Markdown `.md` responses now explicitly require a published post (defense in depth against edge permalink setups resolving to non-public content).
* Change: Agent Skills discovery (SKILL.md and index.json) now documents only the endpoints that are actually enabled, so agents aren't sent to 404s when a feature is switched off.
* Change: On sites set to discourage search engines (blog_public = 0), the plugin no longer adds `Allow: /` AI-crawler rules to robots.txt, respecting the admin's intent.
* Change: api-catalog advertises llms.txt / llms-full.txt as `text/plain`, matching the headers they actually send.

= 1.7.0 =
* New: Every feature can now be switched off individually under Settings > Agent-Ready — markdown URLs, llms.txt, llms-full.txt, robots.txt rules, security.txt, api-catalog, and Agent Skills discovery. All default to on, so updating changes nothing until you choose otherwise.
* New: Turning off robots.txt handling stops the plugin both appending AI crawler rules and routing /robots.txt through WordPress, so a hand-maintained or SEO-plugin-managed robots.txt is left completely alone. The settings screen explains what you give up before you switch it off.
* New: security.txt now has a dedicated Security Contact field that accepts a full URL, a path like /contact, or an email address, and formats it into a valid RFC 9116 Contact line. With nothing set it falls back to the site admin email instead of guessing a /contact URL that may not exist.
* Fix: The Sitemap directive in robots.txt, and the sitemap entry in `/.well-known/api-catalog`, no longer hardcode Yoast's `sitemap_index.xml`. Both now detect Yoast, Rank Math, All in One SEO, SEOPress, or WordPress core sitemaps and use the right URL — previously sites without a Yoast-style sitemap advertised a URL that 404s.
* Fix: `/.well-known/api-catalog` now lists only the endpoints that are actually enabled, instead of always advertising llms.txt, llms-full.txt, security.txt and the Agent Skills index regardless of the feature toggles.
* Fix: The Sitemap directive is added at the very end of the robots.txt filter chain, so it correctly stands down when an SEO plugin has already added one. Yoast hooks that filter at priority 99999, so the previous check ran too early to see its output and emitted a duplicate Sitemap line.

= 1.6.1 =
* Fix: the Yoast schema injection added in 1.6.0 never actually registered — it was gated behind `defined('WPSEO_VERSION')` at plugin-load time, but plugin load order isn't guaranteed, so that check could run before Yoast's own file had loaded and defined the constant. The filters are now registered unconditionally; they simply never fire if Yoast isn't active.

= 1.6.0 =
* Change: JSON-LD structured data now merges into Yoast SEO's own `Article`/`WebPage` schema piece (via Yoast's `wpseo_schema_article`/`wpseo_schema_webpage` filters) when Yoast is active and produces schema for the page, instead of always adding a separate block. Falls back to the standalone block from 1.5.0 when Yoast isn't active or doesn't cover the page.
* Change: the admin conflict notice and settings description updated to reflect the new Yoast-merge behavior; RankMath (or other non-Yoast SEO plugins) still gets the standalone-block warning.

= 1.5.0 =
* New: Optional JSON-LD structured data (`Article`/`WebPage`) on enabled posts/pages, pointing at the markdown alternate. Off by default; new admin notice warns if enabled alongside an active SEO plugin (Yoast/RankMath).
* Prompted by the plugin's own agent-readiness gap tracking

= 1.4.3 =
* New: Content Signals — `Content-Signal: search=..., ai-input=..., ai-train=...` (contentsignals.org / IETF AI Preferences draft) added under each AI crawler's group in robots.txt. Configurable in Settings > Agent-Ready (three yes/no toggles); defaults to search=yes, ai-input=yes, ai-train=no.
* Prompted by isitagentready.com flagging the absence of Content Signals in robots.txt

= 1.4.2 =
* New: Link response headers (RFC 8288) on every front-end response — points agents to api-catalog and the Agent Skills index; singular posts/pages add a third pointing to their markdown alternate
* Prompted by isitagentready.com flagging the homepage's missing Link headers

= 1.4.1 =
* Fix: the broad `.md` catch-all rewrite rule (used for post/page markdown URLs) also matched `/.well-known/agent-skills/*/SKILL.md`, causing the Agent Skills file to 404. The catch-all now excludes `/.well-known/` paths.

= 1.4.0 =
* New: /.well-known/api-catalog endpoint (RFC 9727) indexing llms.txt, llms-full.txt, security.txt, the Agent Skills index, sitemap, and feed
* New: Agent Skills discovery — /.well-known/agent-skills/index.json plus a bundled skill teaching agents how to use this plugin's markdown endpoints
* Improvement: version bumps now auto-flush rewrite rules so new endpoints work without a manual Permalinks resave

= 1.3.0 =
* Rename: plugin renamed to "Make My Site Agent-Ready" with slug make-my-site-agent-ready
* New: /llms-full.txt endpoint serving full site content concatenated as markdown
* New: /.well-known/security.txt endpoint with configurable content in Settings
* New: AI crawler rules (GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, FacebookBot) in robots.txt
* Fix: trailing slash redirect on /llms.txt (and other plugin endpoints) caused by WordPress canonical redirect
* Abilities: regenerate-files ability now always registered; marked destructive so AI confirms before running
* Abilities: removed write abilities opt-in checkbox — destructive annotation handles confirmation

= 1.2.2 =
* Fix: $input = null for PHP 8 compatibility in abilities execute callbacks

= 1.2.1 =
* Fix: meta.mcp.public key in abilities registration

= 1.2.0 =
* Add: WordPress Abilities API integration (get-settings, regenerate-files)

= 1.1.2 =
* Fix: YAML frontmatter url and markdown_url fields now quoted for spec compliance
* Fix: Markdown link titles in llms.txt now escape ] characters to prevent broken links
* Fix: Version check moved into plugins_loaded hook
* Add: llmmd_bulk_generate_limit filter for large-site memory control
* Internal docs removed from repository

= 1.1.1 =
* Replace "View details" plugin row link with "Visit plugin site"

= 1.1.0 =
* Security: sanitize CSS selectors to prevent XPath injection
* Security: add X-Content-Type-Options: nosniff header on .md responses
* Security: use $wpdb->prepare() in uninstall.php
* Fix YAML escape order (backslashes before quotes)
* Auto-clear llms.txt transient on plugin version upgrade

= 1.0.5 =
* Decode HTML entities in llms.txt

= 1.0.4 =
* Decode HTML entities in YAML frontmatter

= 1.0.3 =
* Fix front page /index.md
* Add alternate link tag to homepage

= 1.0.2 =
* Fix front page /index.md returning 404

= 1.0.1 =
* Add post excerpts/descriptions to llms.txt entries

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.0 =
Plugin renamed to Make My Site Agent-Ready. Deactivate the old plugin and activate the new one. Existing settings are preserved automatically.
