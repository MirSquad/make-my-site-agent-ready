=== Make My Site Agent-Ready ===
Contributors: illuminea
Tags: markdown, llm, ai, llms-txt, agents
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.22.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes your WordPress site ready for AI agents: markdown URLs, llms.txt, security.txt, api-catalog, Agent Skills, and AI crawler rules.

== Description ==

Make My Site Agent-Ready makes your WordPress content accessible to AI language models and AI agents. Every post and page gets a markdown endpoint automatically, a site index is generated for discovery, and the full site content is available in one request for LLMs that want it.

Every feature below can be switched off individually under Settings > Agent-Ready, so the plugin stays out of the way of anything you already manage elsewhere. Everything is on by default except structured data.

**Features:**

* **Individual feature toggles** — Turn off any output the plugin publishes (markdown URLs, llms.txt, llms-full.txt, robots.txt rules, security.txt, api-catalog, Agent Skills). A disabled feature registers nothing at all — no rewrite rule, no filter, no header — so the site behaves as if that part of the plugin did not exist.
* **`.md` URLs** — Append `.md` to any post or page URL to get a clean markdown version
* **Markdown from the normal page URL** — Optional (off by default). Answers a request for an ordinary page with its markdown when the request's `Accept` header asks for markdown, which is how AI clients ask. Comes with a self-check that requests one of your own pages as an agent and then as a browser, reports which version came back and what cache headers survived, and switches the feature off by itself if a browser-style request is ever answered with markdown. Leave it off if your site is behind a CDN that ignores `Vary: Accept` — Cloudflare does
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
* **Lightweight** — No cron jobs, no frontend JavaScript. The optional agent request log is the only feature that adds a database table, and only once you switch it on

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

= A visitor got a markdown file instead of my page. What happened? =

Switch off "Markdown from the normal page URL" under Settings > Agent-Ready. That feature answers a page request with markdown when the request asks for markdown, and it relies on caches honoring the `Vary: Accept` header, which tells them the two versions are not interchangeable. Some CDNs ignore it — Cloudflare among them — and then hand the markdown copy to whoever asks next, including people. The plugin also marks that response uncacheable as a second line of defence, but some hosts rewrite that header before it leaves their network.

This is the only way the plugin can affect what a human visitor sees, which is why the feature ships off and why the check on that settings screen exists. Run it: if a browser-style request comes back as markdown, the check switches the feature off itself. Your `.md` URLs are unaffected and keep working.

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

= 1.22.1 - 2026-08-29 =
* Fix: Plugin Check now passes clean. The plugin declared `Domain Path: /languages` and called `load_plugin_textdomain()` against a directory that is empty and has never been in the distributed zip, so both described something that does not exist; WordPress has loaded plugin translations just in time since 4.6, well below this plugin's 6.2 minimum, so nothing is lost by removing them.
* Fix: `wp_register_ability_category()` is guarded by its own `function_exists()` check. The abilities file already returned early when the Abilities API was absent, but the category function is a separate one, and the guard now holds by construction rather than by the file-level return happening to have run first.
* Change: The two heredoc blocks — the Agent Skills SKILL.md and the MCP results panel — are built from string arrays instead. Output is byte-identical: the served SKILL.md hashes to the same SHA-256 the discovery index advertised before the change.
* Change: The Changelog in this file keeps only recent releases and points at CHANGELOG.md for the rest. WordPress.org truncates this section at 5,000 characters and it had grown to roughly 33,000, so most of the history was being silently cut.
* Change: Added a composer.json describing the two bundled vendor packages, and it now ships in the release zip alongside vendor/.
* Change: Tested up to 7.1.

= 1.22.0 - 2026-08-29 =
* New: The `Link: rel="describedby"` relation now resolves to the scoped llms.txt that covers the page being requested, instead of always pointing at the root index. A page under `/media/` advertises `/media/llms.txt`; a page outside any scoped section still advertises `/llms.txt`. The scoped indexes have generated and served since 1.21.0, but nothing advertised them, so they could only be found by an agent that had already guessed the path — which is the v1 behaviour the llms.txt v2 proposal exists to replace.
* New: Markdown responses carry the `describedby` header too. A `.md` URL, a Markdown 404 and a negotiated Markdown page all exit before the ordinary page headers are sent, so they previously advertised no index at all. The v2 proposal singles this case out: the header form "also works for non-HTML resources, such as the markdown files themselves", which have no `<head>` to carry a `<link>`.
* Change: The footer llms.txt link follows the same scoping, and names the section it points at — "Press & Talks index for AI agents" on a page under that section. Two links in one response claiming the same relation and disagreeing about the target is worse than either alone. The `mmsar_llms_txt_link_text` filter now also receives the resolved URL and the covering section.
* Change: A 404 under a scoped section sends an agent to that section's index rather than the site-wide one — the section it was already in is where the URL it wanted most likely lives.
* Note: The advertised media type stays `text/plain`, matching what the file is actually served as. The llms.txt v2 proposal specifies no media type for `describedby` at all — its own example attaches `type="text/markdown"` only to the `rel="alternate"` Markdown-page link — so `text/plain` is both accurate and conformant.

= 1.21.3 - 2026-08-28 =
* Housekeeping: coding-standards cleanup across the includes — array and assignment alignment, one pre-increment, and two parameter names that shadowed PHP reserved words. No functional change; the plugin behaves identically.

= 1.21.2 - 2026-08-27 =
* New: The MCP get_site_overview tool lists /auth.md among the site's endpoints. It was the one document the overview never mentioned, which is backwards: an agent asking what a site offers should be told how to get in.
* Fix: The overview advertised /openapi.json even on a site where the plugin has stood down from serving it, because a real openapi.json already exists in the web root. It now checks before listing it.
* Fix: Removed a stray blank line from the end of the overview output.
* Change: Internal code quality. The overview builder was one long function with four conditionals wrapped around unindented blocks; it is now four small methods, one per section. Also removed a dead method and a redundant array_values() call left behind by the 1.21.1 rewrite of the ARD catalog. No behavior change from any of these.

Older releases are listed in CHANGELOG.md in the plugin's GitHub repository:
https://github.com/MirSquad/make-my-site-agent-ready/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 1.3.0 =
Plugin renamed to Make My Site Agent-Ready. Deactivate the old plugin and activate the new one. Existing settings are preserved automatically.
