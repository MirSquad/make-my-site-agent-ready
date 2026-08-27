=== Make My Site Agent-Ready ===
Contributors: illuminea
Tags: markdown, llm, ai, llms-txt, agents
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.20.1
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

= 1.20.1 - 2026-08-27 =
* New: A 404 now answers with a JSON error — the same code/message/data.status shape the REST API uses — when the request asks for JSON. A client calling what it thinks is an API and getting a themed HTML page back cannot tell a wrong URL from a broken server without parsing markup. Browsers are unaffected: JSON has to be named in the Accept header and outrank HTML, which no browser does.
* New: The MCP discovery manifest carries your site icon, so a directory listing the server has something to show next to its name.
* Change: Tool input schemas are now closed (additionalProperties: false), and the no-argument tool says so explicitly rather than shipping an empty properties object — both stop a model inventing arguments that would be ignored.
* Change: The OpenAPI document now references its Error schema everywhere that shape is genuinely returned, which is now everywhere except the .md addresses. Those answer in Markdown even when the answer is "nothing here", and the spec says so rather than promising JSON.

= 1.20.0 - 2026-08-27 =
* New: An OpenAPI specification at /openapi.json, generated from what your site actually serves — the endpoints this plugin publishes, the REST routes really registered on your install, and anything you added under Endpoints. Everything else here tells an agent what exists; this is the file that tells an HTTP client how to call it, and it is what agent-readiness scanners look for. Skipped automatically if you already have a real openapi.json in your site root.
* New: A read-only MCP server, off by default. Switch it on and AI clients that speak the Model Context Protocol can connect to your site directly, with four tools: search the content, list it, read any page as Markdown, and get an overview of the site. It is strictly read-only, limited to the published content in the post types you have enabled, and rate-limited to 60 calls a minute per IP — it exposes nothing that llms-full.txt does not already publish. A discovery manifest is served at /.well-known/mcp.json.
* New: Agent-recoverable 404s. A normal 404 tells an agent its URL was wrong and nothing more, which leaves it with no way to find the page it wanted. Every 404 now carries Link headers and <link> tags pointing at your sitemap, llms.txt and endpoint catalog, and a client that explicitly asked for Markdown gets a short list of those destinations instead of the themed error page. Your 404 page looks exactly the same to visitors.
* New: llms.txt now opens with a "For agents" section naming the machine-readable endpoints your site publishes. It is the file an agent is most likely to fetch first, and until now it said nothing about the rest.
* Change: A missing .md URL now returns the same recovery list rather than a one-line "not found", and says specifically what went wrong — no such page, wrong post type, or nothing to convert.

= 1.19.0 - 2026-08-26 =
* New: "Export CSV" on the Agent Log screen. It writes every entry, not just the page on screen, with timestamps in UTC — the screen shows them in your site's timezone, and the column name says which you are holding. Values that a spreadsheet would run as a formula are neutralized on the way out, because the agent column holds a string the caller chose.
* New: A `get-agent-log` ability, so an AI agent connected to your site can read the log and tell you what is in it. It returns counts by agent, by surface and by day across the whole log, plus a page of individual entries, and reports whether logging is on, whether page views are being recorded, and the five-minute throttle — the three things that decide what the numbers can honestly be read to mean. Ask for `summary_only` to get the aggregates without any IP addresses. Administrators only, like the screen.

= 1.18.1 - 2026-08-23 =
* Fix: The content negotiation check could report the feature "working" when it was switched off. Some CDNs — Cloudflare among them — convert pages to markdown at the edge when they see the same Accept header, so markdown came back and the check credited it to this plugin. It now compares the response against the markdown the plugin actually generates and says plainly when something else is answering.
* New: A result for that case. If markdown comes back but it isn't yours, the check says so, names the likely cause, and points out that an edge conversion carries your site navigation and misses the frontmatter this plugin writes.
* Change: The content negotiation section now links to the switch that controls it, in the Features list at the top of the page. The section holds only the check, so it read as though there was no way to turn the feature on.

= 1.18.0 - 2026-08-23 =
* New: Markdown content negotiation is back, off by default. With it on, a request for an ordinary page URL is answered with that page's markdown when the request's Accept header asks for markdown — which is how AI clients actually ask. The .md addresses are unchanged and keep working either way.
* New: A self-check for it, at Settings > Agent-Ready. It asks your site for one of its own pages twice — once the way an AI client asks, then the same URL the way a browser asks — and tells you which version came back each time, along with the Cache-Control and Vary headers that actually arrived. The check is what makes this feature offerable at all: the reason it was withdrawn in 1.15.0 was that the failure could not be detected. It can now.
* Note: the one thing to watch for is a visitor opening a page in a browser and getting a markdown file, or a download prompt, instead of the page. That means a cache in front of your site is ignoring the Vary: Accept header and handing the markdown copy to everyone; Cloudflare is known to do this. Switch content negotiation off if you see it. If the self-check catches that condition itself, it switches the feature off for you and says so.
* Note: a site that had this feature switched on back in 1.13.0 or 1.13.1 still has that setting stored. Updating clears it, so content negotiation starts off for everyone and stays off until you turn it on and the check has run.

= 1.17.1 - 2026-08-23 =
* Change: Spelling normalized to US English throughout the plugin's text — settings descriptions, readme and code comments. No functional change.

= 1.17.0 - 2026-08-23 =
* New: A "Recent Agent Requests" dashboard widget showing the 20 most recent entries — agent, what it fetched, and how long ago — with a link to the full log. Hide it like any dashboard widget, from Screen Options.

= 1.16.1 - 2026-08-23 =
* Fix: Entries carried over from the previous version's log could be imported twice, so every migrated row appeared as a duplicate pair. Two requests arriving during the same upgrade each read the old option before either had deleted it, and both wrote its contents into the new table. The migration is now claimed atomically, so exactly one request can perform it however many arrive at once. If you already see duplicates, use "Clear log" once — nothing is imported again.

= 1.16.0 - 2026-08-23 =
* New: The agent request log has its own screen at Settings > Agent Log, with pagination, a "Clear log" button, and a setting for how many entries to keep. The default is unlimited.
* Change: The log now stores entries in its own database table instead of an option. Keeping an unlimited log in an option meant every recorded request read and rewrote the entire history — about 63KB of read and write per request once 200 entries had built up, and growing without limit from there. A table appends at the same cost no matter how large the log gets, and lets the screen page through it. Existing entries are migrated automatically on update, and the table is dropped on uninstall.
* Note: this makes the agent log the one feature that adds a database table, and only once you switch it on. The readme no longer claims the plugin adds none.
* Change: Minimum WordPress version is now 6.2 (was 6.0), for `$wpdb->prepare()`'s `%i` identifier placeholder — it lets the log's table name be passed through prepare() like any other value instead of interpolated into the query. WordPress 6.2 was released in April 2023.

= 1.15.1 - 2026-08-21 =
* Fix: "Visit plugin site" no longer appears twice on the Plugins screen. WordPress core already adds that link for any plugin that sets a Plugin URI header and is not installed from WordPress.org, and the plugin was appending a second, identical one. Core's link is kept; the filter that added the duplicate is removed.

= 1.15.0 - 2026-08-19 =
* Removed: Markdown by content negotiation, added in 1.13.0. Serving markdown from the canonical URL depends on `Vary: Accept` being honored, and Cloudflare does not include `Accept` in its cache key — so a markdown response cached for a URL was handed to the next visitor who opened it in a browser, as a file download instead of the page. Marking the response uncacheable did not help on the host tested, because the host rewrote the `Cache-Control` header on its way out. A plugin at the origin cannot guarantee either condition, and the failure lands on human visitors, so the feature has been withdrawn rather than shipped with a warning.
* Fix: The agent request log now keeps its own record and no longer depends on the Activity Log plugin being loaded. That plugin's API is available in wp-admin but not on front-end requests on some hosts, which is exactly when agent traffic arrives — so every entry was being dropped at the point it mattered, with nothing to show for it. Entries are now listed on the Agent-Ready settings page itself (most recent 200), and still copied to Activity Log wherever its API is reachable. Database errors from the Activity Log copy are also suppressed, so a stale schema in that plugin cannot surface errors in a response being served.
* Change: The footer llms.txt link's description no longer claims to be "the only way a fetch tool reliably finds it". Testing showed Anthropic's WebFetch extracts a page's main content and discards headers and footers, so a footer link does not reach it. It does reach crawlers that fetch and store raw HTML, which is what the description now says.

= 1.14.0 - 2026-08-19 =
* New: Optional visible link to your llms.txt in the site footer (off by default). Tested against Anthropic's WebFetch on a live site: it receives only the response body converted to markdown, and discards HTTP headers and `<link>` elements from `<head>`. So the `Link: rel="describedby"` header, the `Llms-txt:` robots.txt directive, the api-catalog entry and the Agent Skills index are all invisible to that class of client — a site can advertise llms.txt four ways and still not be showing it to the tools most likely to look. A real link in the page body is the one channel that survives. Off by default because it is the only thing this plugin adds that a visitor can see; the text is filterable via `mmsar_llms_txt_link_text`.

= 1.13.1 - 2026-08-19 =
* Fix: Markdown served by content negotiation is now marked uncacheable (`Cache-Control: private, no-store`). `Vary: Accept` is advisory and Cloudflare ignores it, so a markdown response cached for a URL was being served to the next visitor who requested that URL in a browser — a person got a file download instead of the page. Found on a live CDN-fronted site within minutes of enabling the feature. Marking the response uncacheable removes the possibility rather than relying on `Vary`. The trade-off: a page already cached as HTML keeps being served as HTML to agents too, so negotiation works on cache misses and does nothing on hits. That is the correct direction to fail — an agent getting HTML, never a person getting a file.

= 1.13.0 - 2026-08-19 =
* New: Markdown by content negotiation (off by default). Fetch tools ask for markdown with an `Accept` header on the normal page URL rather than looking for a separate `.md` address — Anthropic's WebFetch does this, and a CDN that converts your HTML may already be answering it with a whole-page conversion including your navigation. With this on, the same clean markdown the `.md` endpoint serves is returned from the canonical URL instead. Browsers are unaffected: markdown must be named explicitly and outrank HTML, a wildcard counts only towards HTML, and a tie goes to HTML. `Vary: Accept` is sent on both representations.
* New: Agent request log (off by default). Records which agents fetch the files this plugin publishes, and what they asked for, into the Activity Log plugin under the type "Agent-Ready". Nothing is recorded on an ordinary page view — the log is written from the plugin's own endpoints, so a normal request costs nothing. An optional sub-setting also records page views from recognized AI crawlers, which is what lets you see who visited and ignored these files rather than only who used them. The same agent, file and IP is recorded at most once every five minutes.


= 1.12.1 - 2026-08-19 =
* Fix: Rules typed into Additional Rules are now appended at the very end of the robots.txt filter chain instead of alongside the AI-crawler rules, so another plugin can no longer rewrite or delete them. Yoast strips every `User-agent: * / Disallow: /wp-admin/ / Allow: /wp-admin/admin-ajax.php` block it finds — not just WordPress core's — so an owner who pasted those three lines here lost them from the served robots.txt while the settings preview still showed them, because Yoast's robots.txt code only runs on front-end requests. Extra rules now survive regardless of what other plugins do, and no longer need to be written in an unusual line order to get through.

= 1.12.0 - 2026-08-13 =
* New: `robots.txt` now carries an `Llms-txt:` directive pointing at `/llms.txt`. The site was publishing an llms.txt and advertising it in the api-catalog and the Agent Skills index, but said nothing about it in the one file agents and agent-readiness checkers fetch first. There is no ratified robots.txt directive for llms.txt, and compliant parsers ignore directives they do not recognize, so the line cannot affect crawling.
* New: A `Link: <.../llms.txt>; rel="describedby"; type="text/plain"` header on every front-end response, alongside the existing api-catalog and Agent Skills headers. Same relation and media type the api-catalog already uses for llms.txt, so header-reading and catalog-reading agents are told the same thing.
* Fix: Switching a feature off now always flushes rewrite rules, whichever way the setting was written. Saving on the settings page already did this; a change made by WP-CLI or by another plugin did not, leaving the endpoint reachable after its feature was switched off. The pending flush also survives for a day rather than a minute, so it still happens on a site that gets no traffic immediately afterwards.
* Both new outputs are skipped when the llms.txt feature is switched off, so neither ever points at a 404. The robots.txt directive is also skipped on sites set to discourage search engines, and when the finished robots.txt already mentions llms.txt — an owner who added the line by hand under Additional Rules keeps theirs instead of getting it twice.

= 1.11.0 - 2026-08-12 =
* Fix: An endpoint published in the api-catalog, llms.txt or Agent Skills index is no longer blocked by a `Disallow` rule in the same robots.txt. The plugin now adds an `Allow:` line for the individual endpoint path, in the same user-agent group and above the rule that blocks it, so compliant crawlers apply the more specific rule and the endpoint stays reachable while the broader path stays disallowed. This mattered most for `/wp-json/`, which Yoast SEO disallows by default (the "deny_wp_json_crawling" option) — a site could advertise a REST endpoint in three discovery documents and tell agents to stay off it in the fourth.
* The Allow paths are derived from the same registered-endpoint list that feeds those three documents, so endpoints added on the settings page and endpoints registered in code by a plugin or theme are both covered, and the two can't drift apart.
* Lines are only added where they are actually needed: nothing is emitted for an endpoint no rule blocks, for one already allowed by an equally specific rule, or for one hosted on another domain. On sites set to discourage search engines (blog_public = 0) the file is left alone entirely, matching the existing behavior for AI crawler rules.

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
