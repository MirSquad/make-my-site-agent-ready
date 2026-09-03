=== Make My Site Agent-Ready ===
Contributors: illuminea
Tags: markdown, llm, ai, llms-txt, agents
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.24.4
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

= 1.24.4 - 2026-09-03 =
* **Fix: No DNS entries are retried automatically, so the re-check button stops asking.** *No DNS* was documented as the retryable verdict, but nothing actually retried it — the only way to reopen one was the button, which meant an address with no reverse record left a "Re-check 1" button on screen permanently, doing nothing each time it was pressed. The ordinary verification pass now picks up any *No DNS* entry older than a day, so a resolver problem repairs itself quietly and a genuinely unresolvable address stops asking for attention.
* **Change:** the re-check button is now only about entries that became answerable because an update taught the plugin a new operator — the one case where a person is actually needed, since the plugin cannot detect that about itself. When there is nothing of the kind, the button is not shown at all.
* `Verified`, `Spoofed` and `Unverifiable` verdicts remain untouchable by an automatic pass; only a never-checked entry or a day-old *No DNS* one can be written.

= 1.24.3 - 2026-09-03 =
* **Fix: the re-check button no longer offers work it cannot do.** It counted every undecided entry, but most of them are *Unverifiable* because nobody publishes a way to confirm that crawler at all — re-checking those produces the same answer every time. The button now counts only entries whose verdict could actually come out differently: anything that failed to resolve, and anything whose operator this plugin has since learned. On a log full of uncheckable crawlers the button correctly disappears.
* **New:** the panel names the crawlers it cannot check and says why, so a count that never moves reads as an answer rather than as something stuck.
* **Fix:** the re-check result now reports what actually changed. It said how many entries it had reopened, which looked like progress even when every one reached the same verdict; it now says either how many verdicts changed or that none did.

= 1.24.2 - 2026-09-03 =
* **New: a "Re-check undecided" button on the Agent Log screen.** A verdict of *Unverifiable* or *No DNS* records that the plugin had no way to check an identity — not that the caller was suspicious — so those entries become answerable the moment an update teaches it a new operator. That happened immediately: 1.24.1 taught it DuckAssistBot, and the entries already in the log kept saying *No DNS*. This button reopens them and judges them again. *Verified* and *Spoofed* entries are deliberately left alone, so a re-check can never overwrite a conclusion already reached.
* **Fix:** re-checking also clears the cached verdict for the addresses involved. Without that, an *Unverifiable* result cached against an address for a week would have been handed straight back and the re-check would have appeared to do nothing.

= 1.24.1 - 2026-09-03 =
* **Fix: DuckAssistBot is verified instead of unresolvable.** 1.24.0 checked it by reverse DNS, and live data showed why that was wrong: all 13 of its requests came from Azure addresses with no reverse record at all, so every one was recorded as "no DNS" rather than confirmed. DuckDuckGo publishes an IP range file instead, which covers all 13 — those requests now read as verified. The `duckduckgo.com` hostname suffix has been removed, so a DuckAssistBot claim from outside the published range is now identified as forged rather than left undecided, and costs no DNS lookup either way.
* **Dev:** the bundled range data gains a fourth operator group (DuckDuckGo, 486 prefixes, captured 2026-09-01). Existing verdicts are not rewritten in place — entries keep the verdict they were given, and re-checking is a matter of clearing the log or waiting for new traffic.

= 1.24.0 - 2026-09-03 =
* **New: the agent log verifies who callers actually are.** The `agent` column has always been a self-declared user-agent string, and on a real site it is routinely forged — three addresses in one nine-day sample each rotated through five or more AI-crawler identities, and a readiness scanner accounted for most traffic attributed to GPTBot. Each entry now carries a verdict: `verified`, `failed` (the identity was forged), `unverifiable` (no published way to check that operator — not an accusation), `unclaimed` (no crawler was named), or `nodns`.
* **New: two verification methods, chosen per operator.** Anthropic, OpenAI and Perplexity publish no reverse-DNS records for their crawlers, only IP range files, so those are checked against ranges bundled with the plugin. Google, Apple, Amazon, Microsoft and DuckDuckGo are checked by forward-confirmed reverse DNS — the address reverses to a hostname under a domain that operator owns, and that hostname resolves back to the same address. A user-agent is trivial to forge; neither of these is.
* **New: markdown surfaces record which page was fetched.** `.md` URLs, content negotiation and `SKILL.md` now store the permalink path of the post served, so `by_detail` distinguishes a crawler sweeping the whole corpus from one that wanted a specific article. Every alias for a post — the `.md` suffix, the negotiated canonical URL, a trailing slash — records the same value, so they aggregate together instead of splitting.
* **New:** an *Identity* column with badges and a verdict filter on the Agent Log screen, a "Verify now" button for clearing a backlog, `verified` and `verified_at` columns appended to the CSV export, a forged-count headline on the dashboard widget, and a `verification` block, `verified` input filter and per-agent verdict counts on the `get-agent-log` ability.
* **Fix:** a request for a `.md` URL that does not exist was not recorded at all. It took an earlier exit than the other two 404 paths, so the 404 an agent is most likely to produce against this plugin was the one the log could not show. It is now recorded with its path.
* **Fix:** stored paths no longer flatten non-ASCII characters. Accented and non-Latin slugs were reduced to the same value, which could merge two different posts into one `by_detail` row. Control characters are still stripped, which was the actual reason for the original filter.
* **Dev:** log schema bumped to version 3. The two new columns are added by `dbDelta` on the next page load after updating; existing entries are kept and start unverified. Verification never runs while a page is being served to a visitor — it runs in a small bounded batch when an administrator opens the Agent Log screen or calls the ability, and in a larger batch from the button. No cron, no third-party requests.
* **Note:** because the markdown surfaces now record a path, a crawler sweeping forty markdown files writes forty entries where it previously wrote one. That is the point of the change, but the log grows faster than before, so the retention limit is worth a look on content-heavy sites.

= 1.23.0 - 2026-08-31 =
* New: The agent log now records **what** was asked for, not just which surface. A new detail column carries the requested path on a 404 and the invoked method on an MCP call. Shown on the Agent Log screen, included in the CSV export, and returned by the `get-agent-log` ability both per-entry and as a new `by_detail` aggregate.
* New: **The MCP endpoint is logged.** Every JSON-RPC message is recorded with its method — `initialize`, `tools/list`, `tools/call: <tool name>` — along with declined GET stream requests, unparseable bodies and rate-limited callers. Previously only the `mcp.json` and `server-card.json` discovery documents were logged, so there was no way to tell whether a client that found the MCP server ever actually called it.
* New: **404s record the path.** A count of agent 404s said only that agents were asking for something absent; the path shows a crawler guessing at a URL pattern the site could support, which was previously invisible.
* Dev: Log schema bumped to version 2. The new column is added by `dbDelta` on the next page load after updating — existing entries are kept and simply carry an empty detail.

Older releases are listed in CHANGELOG.md in the plugin's GitHub repository:
https://github.com/miriamschwab/make-my-site-agent-ready/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 1.3.0 =
Plugin renamed to Make My Site Agent-Ready. Deactivate the old plugin and activate the new one. Existing settings are preserved automatically.
