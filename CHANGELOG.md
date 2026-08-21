# Changelog

## 1.15.0 — 2026-08-19

### Removed

- **Markdown by content negotiation** (1.13.0–1.13.1). Serving a different representation from the canonical URL requires the cache layer to key on `Accept`; Cloudflare does not, so a markdown response cached for a URL was served to the next browser that requested it and a visitor received a file download in place of the page. Reproduced three times on cache-busted URLs. The mitigation — marking markdown responses `private, no-store` — failed on the host tested, which rewrote the header to `public, max-age=300, s-maxage=604800` before it reached the edge. Neither condition is under a plugin's control, and the failure mode lands on human visitors rather than agents, so the feature is withdrawn. Sites that control their own CDN can achieve the same result with a cache rule that bypasses the cache when `Accept` contains `text/markdown`.

### Fixed

- The **agent request log** now keeps its own record rather than depending entirely on the Activity Log plugin. Its `aal_insert_log()` API proved to be reachable in wp-admin but not on front-end requests on one live host — precisely the requests agents make — so the guard around it silently discarded every entry, and the log stayed empty with no indication why. Entries are now written to a capped option (most recent 200, no autoload, no new database table) and displayed on the Agent-Ready settings page, with the Activity Log copy kept as a mirror wherever that API is present. The settings section states which of the two is in effect.

- Database errors from the Activity Log mirror are suppressed for the duration of that call. It writes to a table it owns and upgrades on its own schedule; a site whose schema has not caught up produced an `Unknown column` error on every agent request, which with `WP_DEBUG_DISPLAY` enabled would print into a response being served. The entry is already stored in the plugin's own log by that point, so the mirror must never be able to affect the page.
### Changed

- The footer llms.txt link's description previously claimed to be "the only way a fetch tool reliably finds it". Measurement contradicted that: Anthropic's WebFetch was asked what it could see of a page carrying such a link and reported no copyright line, no footer navigation and no link text, while returning main-content links and inline JSON-LD verbatim — it extracts the article and discards the chrome. A footer link does reach crawlers that fetch and store raw HTML, and that is now what the description says.

## 1.14.0 — 2026-08-19

### New

- Optional visible link to `llms.txt` in the site footer, off by default. This exists because of a measurement rather than a guess: fetching a live site with Anthropic's WebFetch and asking what it could see established that it receives the response body converted to markdown and nothing else — the `<link rel="alternate" type="text/markdown">` tag, the `<link rel="canonical">` tag and the RSS `<link>` were all absent from what it received, while a URL carried in an inline JSON-LD block came through. HTTP response headers are likewise discarded. The consequence is that a site can advertise `llms.txt` through a `Link` header, a `robots.txt` directive, `/.well-known/api-catalog` and the Agent Skills index — four channels, all correct — and still be invisible to a body-only fetch tool. On the site tested, the HTML contained zero references to `llms.txt`. A visible anchor in the body is the one channel that reliably survives, which is also what the llms.txt proposal asks for ("link the file from your homepage"). Rendered on `wp_footer`, plain text and a real `href` rather than a hidden or `aria-hidden` element, since anything stripped from the accessibility tree is liable to be stripped by the same markdown conversion. Text filterable via `mmsar_llms_txt_link_text`.

## 1.13.1 — 2026-08-19

### Fixed

- Markdown served by content negotiation is now marked `Cache-Control: private, no-store`. `Vary: Accept` is advisory, and Cloudflare — like several CDNs — does not include `Accept` in its cache key, so the first representation cached for a URL was served to every subsequent request regardless of what it asked for. In practice: an agent fetched a post as markdown, the CDN cached that, and the next human visitor to the same URL was served a markdown file instead of the page. Reproduced on a live site minutes after the feature was switched on. Marking the response uncacheable removes the failure rather than depending on `Vary` being honoured. The consequence is that a URL already cached as HTML continues to be served as HTML to agents as well — negotiation now works on cache misses and is inert on hits, which fails safely: an agent receives HTML rather than a person receiving a download. Sites behind a CDN they control can restore full behaviour with a cache rule that bypasses the cache when `Accept` contains `text/markdown`.

## 1.13.0 — 2026-08-19

### New

- **Markdown by content negotiation** (off by default). Agents ask for markdown with an `Accept` header on the URL they were already fetching; the `.md` mirror only helps a client that already knows it exists. Testing against a live CDN-fronted site found the CDN answering that header with its own whole-page conversion — nav chrome, "Skip to content", meta-derived frontmatter, 30% larger than the plugin's output. Serving markdown from the origin takes precedence, so the canonical URL now returns the same clean markdown as the `.md` endpoint. Accept parsing is deliberately strict, because getting it wrong serves markdown to a person: markdown must be named explicitly and outrank HTML, a wildcard counts only towards HTML, and a tie goes to HTML. `Vary: Accept` is sent on both representations, not just the markdown one.
- **Agent request log** (off by default). Records which agents fetch the plugin's files, and what they asked for, into the Activity Log plugin under the object type "Agent-Ready". Written from the plugin's own serve points, so an ordinary HTML page view does no work at all. An optional sub-setting additionally records page views from recognised AI crawlers — the denominator, without which the log shows who used these files but not who arrived and ignored them. The same agent, file and IP is recorded at most once per five minutes, so a crawler looping on one URL cannot fill the table.


## 1.12.1 — 2026-08-19

### Fixed

- Directives entered in **Additional Rules** are now appended at the very end of the `robots_txt` filter chain (`PHP_INT_MAX`) rather than with the AI-crawler rules at priority 99, so nothing that runs later can rewrite or remove them. The concrete failure: Yoast's `remove_default_robots()` calls `preg_replace()` against a `User-agent: * / Disallow: /wp-admin/ / Allow: /wp-admin/admin-ajax.php` block with no `$limit` argument, so it strips *every* match in the document rather than only the copy WordPress core emitted. An owner who pasted those three lines into Additional Rules in core's line order had them silently deleted from the served file — while this plugin's own settings preview continued to show them, because Yoast's robots.txt integration is gated to front-end requests and never runs in wp-admin. Silent loss confirmed by a preview showing the opposite is the part worth fixing; running last removes the whole class of problem rather than working around this one plugin.
- The new pass is registered ahead of the other `PHP_INT_MAX` passes, so an owner-supplied `Sitemap:` line still suppresses the automatic one, and `MMSAR_Robots_Allow`'s endpoint carve-outs still apply to user-defined groups.
- Extra rules are honoured on `blog_public = 0` sites exactly as before. Withholding rules this plugin invented on a site set to discourage crawlers is deliberate; dropping the owner's own text never was.

## 1.12.0 — 2026-08-13

### Added

- `robots.txt` now carries an `Llms-txt:` directive pointing at this site's `/llms.txt`. The gap it closes: a site could publish an llms.txt and name it in `/.well-known/api-catalog` and the Agent Skills index, while saying nothing about it in the file most agents and agent-readiness checkers fetch first. There is no ratified robots.txt directive for llms.txt — the llms.txt proposal says to link the file from your homepage and does not mention robots.txt — but RFC 9309 parsers skip top-level directives they do not recognise, so the line cannot affect crawling for anyone.
- A `Link: <https://example.com/llms.txt>; rel="describedby"; type="text/plain"` header on every front-end response, next to the existing `api-catalog` and Agent Skills headers. The relation and media type match how the api-catalog already lists llms.txt, so an agent reading headers and an agent reading the catalog get the same answer. `llms-full.txt` is deliberately left to the catalog rather than adding a second header to every page view.
- Feature toggles now flush rewrite rules no matter how the `mmsar_features` option is written. The settings page already handled this from inside its sanitize callback, which only runs for saves that go through the Settings API; a write from WP-CLI, another plugin, or an ability added later left the old rules in place, so an endpoint whose feature had just been switched off kept serving while the documents correctly stopped advertising it. `add_option_mmsar_features` / `update_option_mmsar_features` now raise the same flag, and the settings page raises it through the same helper so the two paths cannot drift apart.
- The pending-flush flag lives for a day instead of a minute. The flush can only run on a later request — rules for the current one are already registered — and a minute is long enough for the settings page's own redirect but not for a site whose features were changed by WP-CLI and that then sees no traffic, where the flag would expire and the flush would silently never happen.
- Both new outputs are gated on the `llms_txt` feature, so neither can advertise a switched-off endpoint. The robots.txt directive additionally returns early on `blog_public = 0`, matching the AI-crawler rules, and skips itself when the assembled robots.txt already mentions `llms.txt` — a line added by hand in the Additional Rules field survives the update instead of being duplicated. It is registered at `PHP_INT_MAX` after `MMSAR_Robots_Allow::filter()` so that parser never sees the new directive.

## 1.11.0 — 2026-08-12

### Fixed

- An endpoint published in `/.well-known/api-catalog`, `llms.txt` and the Agent Skills index is no longer contradicted by a `Disallow` rule in the same site's `robots.txt`. The plugin now adds an `Allow:` line for the individual endpoint path, in the same user-agent group as the rule that blocks it and above that rule, so a compliant parser applies the more specific rule: the advertised action stays reachable while the broader path stays disallowed. The case that prompted this was `/wp-json/`, which Yoast SEO disallows by default via its `deny_wp_json_crawling` option — a site could describe a REST endpoint in three documents an agent reads before acting, and tell that same agent to stay off the path in a fourth.
- The paths come from `MMSAR_Registry::get_endpoints()`, the same list that feeds those three documents, so endpoints managed on the settings page and endpoints registered in code both get the treatment and no path is named twice in two places to drift apart.
- The rules being overridden are written by other plugins, so the check runs against the assembled document at `PHP_INT_MAX` — the same reason the `Sitemap:` directive is added there. Nothing is emitted for an endpoint that no rule blocks, one already allowed by an equally specific rule, one on another host, or the site root; on `blog_public = 0` the file is left untouched, matching the existing AI-crawler-rule behaviour.

## 1.10.1 — 2026-08-12

### Fixed

- The documents this plugin publishes now send their own `Cache-Control` header (`public, max-age=300, s-maxage=300`) instead of inheriting whatever a host or CDN applies by default. That default can be very long: on a CDN-fronted install, `/.well-known/api-catalog` was served with `s-maxage=604800` and pinned a week-old copy at the edge — the origin published a newly added endpoint correctly while every visitor and agent kept receiving the stale document. These files describe live configuration, so they must state their own cache policy rather than let one be assumed for them. Applies to `llms.txt`, `llms-full.txt`, `security.txt`, `api-catalog`, the Agent Skills index, and `SKILL.md`. Use the `mmsar_document_max_age` filter to change the duration, or return `0` to send `no-cache` instead.

## 1.10.0 — 2026-08-12

### New

- Endpoints can be added and managed from Settings > Agent-Ready, with no code. A name and a URL are the only required fields; tick which of the three documents it belongs in and save. Methods, content type, authentication and link relation sit behind a "Technical details" disclosure so the common case stays short.
- Each saved endpoint reports where it is actually being published, and says why when it isn't — a mistyped URL, or a document that is ticked but switched off, is stated on the row rather than the entry silently failing to appear. Storing a row and publishing it are treated as separate questions: a row with a bad URL is kept and flagged, not blanked.
- Three abilities for the WordPress Abilities API (WP 6.9+) — `list-endpoints`, `set-endpoint`, `delete-endpoint` — so an agent connected through the MCP Adapter can manage the same list. `set-endpoint` accepts partial updates: send an id and only the fields to change.

### Changed

- Endpoints registered in code by a plugin or theme now appear under their own read-only "Added by Plugins" heading, separate from the ones managed on the settings page. They are read-only to the abilities too, which return a `409` naming the owning plugin rather than reporting a success that changed nothing.

### Security

- URLs are validated, not merely escaped, before publication. `esc_url_raw()` percent-encodes its way out of trouble, so a value like `https://not a url` survives it as `https://not%20a%20url` and would have been published as a working link; publication now also requires a host that parses as one. Internationalized domains, `localhost`, and IPv4 hosts remain valid.

## 1.9.0 — 2026-08-12

### New

- Other plugins and themes can now add their own endpoints to the documents this plugin publishes. An endpoint is described once — via `mmsar_register_endpoint()` or the `mmsar_registered_endpoints` filter — and is listed in `/.well-known/api-catalog`, `/llms.txt`, and the Agent Skills index at the same time. The motivating case: a contact form (or any other integration) that has been made agent-ready but has nowhere to announce itself. Each entry can opt into a subset of those documents via `surfaces`, choose its api-catalog link relation via `rel`, and carry `methods`, `type`, and `auth` details that are rendered appropriately per document. An integration that serves a `SKILL.md` of its own can pass `skill_url` (and optionally `skill_digest`) to get its own entry in the Agent Skills index rather than a bullet inside this plugin's skill.
- Registered endpoints appear read-only under "Registered Endpoints" on the settings page, showing which documents each one is actually published in — an endpoint whose documents are all switched off says so, rather than silently claiming to be listed.
- Three whole-document filters for what the registry does not model: `mmsar_api_catalog_linkset`, `mmsar_llms_txt_content`, and `mmsar_agent_skills_index`.

### Notes

- Registrations are validated before publication and dropped if they cannot be published safely: non-`http(s)` targets, missing title or URL, unrecognised link relations and HTTP methods, and invented media types. Text fields are flattened to a single line and markdown link/code syntax is escaped, so a description carrying a newline or `[link](…)` cannot forge a heading, a list item, or a link in `llms.txt` or `SKILL.md`. A media type is never guessed — an unstated one is omitted rather than assumed to be `application/json`, keeping the promise that a stated type is the type the endpoint really returns.
- `llms.txt` renders registered endpoints outside its day-long transient, so an endpoint registered by a newly activated plugin appears immediately rather than whenever the cache next expires.
- No change to existing behavior: with nothing registered, all documents are byte-for-byte what they were before.

## 1.8.2 — 2026-08-05

### Security

- Hardening: the request URI used for canonical-redirect checks is now sanitized and parsed with `wp_parse_url()`; admin output is explicitly escaped. Code documentation and WordPress coding-standards cleanup. No changes to behavior.

## 1.8.1 — 2026-07-22

### Packaging

- Fix: Added a `.gitattributes` with `export-ignore` rules so the archives GitHub generates from the repo — both the green "Code → Download ZIP" button and the auto-generated "Source code" assets on each release — contain only the plugin's runtime files (`includes/`, the main PHP file, `readme.txt`, `uninstall.php`, `vendor/`). Previously those archives also carried `.github/`, `README.md`, `CHANGELOG.md`, and `.gitignore`; a user who installed one of those source zips got dev/CI files bundled into their plugin directory. No functional change to the plugin — the named release asset built by the workflow was already clean.

## 1.8.0 — 2026-07-21

### Change

- Change: The settings page's separate "Quick Links" list (at the bottom of the page) has been folded into the Features toggle list at the top. Each feature that serves a fixed URL now shows a "View" link (e.g. `/llms.txt ↗`) right under its toggle, opening the live file in a new tab — and only when that feature is enabled, so a link never points at a switched-off endpoint. The standalone Quick Links section is removed.
- Change: Features that have their own settings section further down the page (Markdown URLs → Markdown Endpoints, robots.txt → robots.txt, security.txt → security.txt) now show a "Configure below ↓" link beside the toggle that jumps straight to that section. This makes it discoverable that there's more to configure than the on/off switch — previously a user could easily miss the robots.txt Additional Rules box or the security.txt Contact field. Implemented with `before_section`/`after_section` anchor wrappers on the relevant settings sections.

## 1.7.1 — 2026-07-21

Security and hardening pass following an external code review.

### Security

- Fix: Password-protected posts could leak through `/llms-full.txt` and `/llms.txt`. The per-page `.md` endpoint already returned 403 for protected content, but the two aggregate feeds queried published posts without excluding password-protected ones — so a post that gained a password *after* its markdown was cached in `_llmmd_content` stayed readable in the full-text dump and listed in the index. `generate_llms_full_txt()` now skips any post with a `post_password`, the llms.txt queries pass `has_password => false`, and saving a post that has just been password-protected deletes its cached `_llmmd_content` and rebuilds both aggregate transients.
- Fix: `MMSAR_Endpoints::normalize_contact()` trusted any URI scheme, so a compromised admin could publish a `javascript:` (or other unsafe-scheme) Contact line into security.txt. Only `https`, `http`, `mailto` and `tel` are now accepted as-is; anything else falls through to path/email handling.

### Bug fix

- Fix: `sanitize_content_signals()` fell back to `yes` for a missing or invalid value on *every* signal, including `ai_train` — contradicting the registered default (`no`) and `mmsar_content_signal_line()`, and silently opting content into AI training if the value ever arrived malformed. Each signal now falls back to its own correct default.
- Fix: `MMSAR_Server::serve_markdown()` now explicitly requires `post_status === 'publish'` before serving. Defense in depth: `resolve_post_id()` can reach a post via `get_page_by_path()`, which returns posts of any status, so a draft/pending/private post could in principle have been served on edge permalink setups.

### Change

- Change: The Agent Skills SKILL.md and the `index.json` description now document only the endpoints that are actually enabled. Previously both advertised llms.txt, llms-full.txt and the per-page `.md` endpoints unconditionally, so an agent following a skill on a site with those features switched off would hit 404s — the same per-feature gating the api-catalog already applied.
- Change: `/robots.txt` no longer appends `Allow: /` rules for AI crawlers when the site is set to discourage search engines (`blog_public = 0`). WordPress emits a blanket `Disallow: /` in that mode, and overriding it for AI bots contradicted the admin's explicit intent. The owner's own extra rules are still honoured.
- Change: The api-catalog now advertises `llms.txt` and `llms-full.txt` as `text/plain`, matching the `Content-Type` header both endpoints actually send (they were cataloged as `text/markdown`).
- Change: `mmsar_prevent_canonical_redirect()` guards against a missing `$_SERVER['REQUEST_URI']` and an unparseable path, avoiding notices in CLI or unusual request contexts.

## 1.7.0 — 2026-07-20

### New feature

- New: Every output the plugin publishes can now be switched off individually under Settings > Agent-Ready — markdown URLs, llms.txt, llms-full.txt, robots.txt rules, security.txt, api-catalog, and Agent Skills discovery. Requested by a user who manages robots.txt and llms.txt elsewhere and had no way to stop the plugin producing them. A disabled feature registers nothing at all — no rewrite rule, no filter, no `Link` header — rather than registering hooks that then return early, so the site behaves exactly as if that part of the plugin did not exist.
- Stored as a single `mmsar_features` array option. `mmsar_feature_enabled()` treats a *missing* key as the feature's default (on) rather than off, which is what makes the upgrade safe: every install predating 1.7.0 has no `mmsar_features` row at all, and reading that absence as "off" would have silently killed working endpoints on every existing site the moment they updated. Verified on the miriamschwab.me clone — with the option absent, all seven endpoints still return 200.
- Toggling a feature sets a short-lived `mmsar_flush_needed` transient and flushes rewrite rules on the next request, since rewrite rules are cached in an option and the settings save happens after rules are registered on that request.
- `Link` headers are now emitted per-feature. Previously all three were sent unconditionally; a header advertising a switched-off endpoint would point an agent at a 404, which is worse than no header.
- The same rule now applies to `/.well-known/api-catalog`, which listed llms.txt, llms-full.txt, security.txt and the Agent Skills index unconditionally. Caught while verifying the live site after 1.7.0 was installed: the per-feature reasoning had been applied to the `Link` headers but not carried across to the catalog, so switching off llms.txt would still have advertised it. The catalog now lists only enabled endpoints, and omits the `describedby` or `service-desc` key entirely when nothing in it is enabled.
- The `get-settings` ability now reports the feature states, so an agent can see what the site is actually publishing.

### Change

- Change: Switching off robots.txt handling disables *both* halves of that feature — appending the AI crawler rules via the `robots_txt` filter, and the rewrite rule that routes `/robots.txt` through WordPress. The rewrite rule is the disruptive half: it exists to override a physical `robots.txt` file in the webroot, so leaving it registered while the rules were off would have hijacked a hand-maintained file and then added nothing to it.
- The settings screen states plainly what is lost when it is off (AI crawler Allow rules, the Content-Signal directive, the Sitemap directive) rather than just hiding the section, and the Content Signals and Structured Data sections now explain when they are inert because the feature they depend on is off.
- The "physical robots.txt found" admin notice is suppressed once the feature is off — at that point the file is being served as the user intends, so the warning is nagging about a problem they just solved.
- Admin copy corrected during live testing: the override of a physical `robots.txt` was described as unconditional, but testing on the nginx-based Local clone showed the static file still wins, because nginx serves an existing file without ever consulting WordPress. It works on Apache. The copy now says so instead of promising behaviour that fails on most modern stacks. The read-only robots.txt preview claimed to be "exactly what gets served"; on this site Yoast strips the core block on front-end requests only, so the preview and the served file genuinely differ. Softened accordingly.

- Change: security.txt gains a dedicated Security Contact field. `MMSAR_Endpoints::normalize_contact()` accepts a full URL, a bare path (`/contact` or `contact`), or an email address, and expands each into a valid RFC 9116 Contact URI — a bare path or bare email is not valid on its own, but all three are what people naturally type. The field shows the resolved `Contact:` line beneath it so the result is visible before saving.
- With no contact configured, the generated file now falls back to the site admin email rather than the previous hardcoded guess of `home_url('/contact')`, which published a broken security contact on every site without a page at that exact path. The free-text textarea remains for sites needing extra fields (Encryption, Policy, Acknowledgments) and still overrides the generated file entirely.

### Bug fix

- Fix: The `Sitemap:` directive in robots.txt **and the sitemap entry in `/.well-known/api-catalog`** both hardcoded `sitemap_index.xml`, which is Yoast's filename. Sites on WordPress core sitemaps (`wp-sitemap.xml`), All in One SEO (`sitemap.xml`), or SEOPress (`sitemaps.xml`) advertised a URL that 404s. `mmsar_get_sitemap_url()` now detects the active sitemap provider, asks core's `WP_Sitemaps::sitemaps_enabled()` rather than assuming, and emits no Sitemap line at all when there is nothing valid to point at.
- Fix: The "don't add a Sitemap line if one already exists" guard never worked alongside Yoast. It ran at the default filter priority, while Yoast hooks `robots_txt` at priority 99999 — so the check ran first, saw no Sitemap line, added one, and Yoast then appended its own, leaving two directives in the served file. Confirmed live on the clone before the fix. The Sitemap line is now added by a separate filter at `PHP_INT_MAX`, after every other plugin has written its output, so the check is made against what is actually served. Verified: one Sitemap line, Yoast's own, with ours correctly standing down.

## 1.6.1 — 2026-07-15

### Bug fix

- Fix: 1.6.0's Yoast schema injection never actually fired live — verified on miriamschwab.me immediately after installing, view-source still showed the old standalone duplicate block with no `encoding` field merged into Yoast's graph. Root cause: `MMSAR_Structured_Data::init()` gated registering the `wpseo_schema_article`/`wpseo_schema_webpage` filters behind `defined('WPSEO_VERSION')`, checked at top-level plugin-load time. Plugin load order across a site isn't alphabetical or dependency-aware — if this plugin's file loads before Yoast's, `WPSEO_VERSION` isn't defined yet at the moment of that check, so the filters silently never got registered, and every page fell back to the standalone block regardless of Yoast being active. Fixed by registering both filters unconditionally — if Yoast isn't installed, `wpseo_schema_article`/`wpseo_schema_webpage` simply never fire, so there was no actual need to gate registration on the constant at all.

## 1.6.0 — 2026-07-14

### Change

- Change: JSON-LD structured data now merges into Yoast SEO's own schema instead of always adding a separate block. Live verification on miriamschwab.me showed Yoast's Schema Framework already declares `@type`, `url`, `headline`/`name`, and both dates on every page — the only new fact 1.5.0's block added was the `encoding`/`MediaObject` pointer to the markdown alternate. Now, when Yoast is active and produces a schema piece for the current page, `MMSAR_Structured_Data` injects just that `encoding` field directly into Yoast's own `Article` piece (for the `post` post type) or `WebPage` piece (everything else) via Yoast's documented `wpseo_schema_article`/`wpseo_schema_webpage` filters — no second block, no duplication, no `@id` question to even worry about since nothing new is created.
- Falls back to the full standalone block from 1.5.0 when Yoast isn't active, when Yoast doesn't produce a piece for this page (e.g. Yoast's schema output disabled via the `wpseo_json_ld_output` filter, or a post type Yoast gives its own distinct schema type to, like WooCommerce products), or with any other SEO plugin (RankMath, etc.).
- The admin conflict notice (Settings > Agent-Ready) no longer warns about Yoast specifically, since the merge behavior means there's nothing to conflict with — it still warns if RankMath (or another non-Yoast SEO plugin) is active, since those still get the standalone block.

## 1.5.0 — 2026-07-14

### New feature

- Add: Optional JSON-LD structured data — a new `mmsar_structured_data` checkbox (off by default) in Settings > Agent-Ready adds a minimal `Article` (for posts) or `WebPage` (for pages/other post types) block to each enabled post/page, with an `encoding`/`MediaObject` field pointing at the same `.md` URL used by the existing `<link>` tag and `Link` header. New `includes/class-mmsar-structured-data.php`.
- Deliberately omits `@id` and stays minimal so it can't collide with or duplicate an active SEO plugin's own JSON-LD graph (e.g. Yoast, RankMath) — a new admin notice warns (without blocking) if the setting is enabled while one of those is active.
- Prompted by the plugin's own agent-readiness gap tracking flagging "structured data for agents" as the one item from the v1.4.2/1.4.3 batch that hadn't shipped yet.

## 1.4.3 — 2026-07-06

### New feature

- Add: Content Signals — a new `mmsar_content_signals` option (three yes/no values: `search`, `ai_input`, `ai_train`) with an admin settings section (Settings > Agent-Ready), and a new `mmsar_content_signal_line()` helper that builds the `Content-Signal: search=..., ai-input=..., ai-train=...` directive (contentsignals.org / IETF AI Preferences draft) from it. Emitted once under each of the plugin's own AI-crawler groups in `robots.txt` (GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, FacebookBot) — deliberately not under `User-agent: *`, since that group is Yoast's, not this plugin's. Skips auto-adding if the site owner already has a manual `Content-Signal:` line in the Additional Rules textarea, to avoid emitting a conflicting duplicate.
- Default values: `search=yes, ai-input=yes, ai-train=no` — allow indexing and live AI retrieval, decline training-corpus use by default.
- Prompted by isitagentready.com flagging the absence of Content Signals in robots.txt.

## 1.4.2 — 2026-07-06

### New feature

- Add: HTTP `Link` response headers (RFC 8288) on every front-end response — `Link: </.well-known/api-catalog>; rel="api-catalog"` and `Link: </.well-known/agent-skills/index.json>; rel="service-desc"`, plus a third on singular posts/pages mirroring the existing `<link rel="alternate" type="text/markdown">` tag as a real header. Prompted by isitagentready.com flagging the homepage's missing Link headers.
- Refactored the markdown-URL logic shared by both the `<link>` tag and the new header into one function, `mmsar_get_markdown_url()`, so they can't drift out of sync.
- Hooked to `template_redirect`, not `send_headers` — `send_headers` fires before `WP_Query` resolves the main query, so `is_front_page()`/`is_singular()` are not yet reliable there. `template_redirect` fires after the query resolves and before any template output.

## 1.4.1 — 2026-07-06

### Bug fix

- Fix: `MMSAR_Server`'s broad `.md` catch-all rewrite rule (`^(.+)\.md/?$`, used for post/page markdown URLs) also matched `/.well-known/agent-skills/fetch-content-as-markdown/SKILL.md`, and won over the more specific Agent Skills rewrite rule regardless of registration order — the Agent Skills file 404'd as a result. Fixed with a negative lookahead (`^(?!\.well-known/)(.+)\.md/?$`) so the catch-all only ever matches actual post/page slugs, never a `/.well-known/` path. Found via live verification immediately after the 1.4.0 install: `api-catalog` and the Agent Skills `index.json` both served correctly, but the `SKILL.md` file itself returned MMSAR_Server's "content not found" 404 — the exact message text made the true cause traceable.

## 1.4.0 — 2026-07-06

### New features

- Add: `/.well-known/api-catalog` (RFC 9727) — a Linkset (RFC 9264) JSON document indexing llms.txt, llms-full.txt, security.txt, the Agent Skills index, sitemap, and feed in one machine-readable file.
- Add: Agent Skills discovery — `/.well-known/agent-skills/index.json` plus one bundled skill (`fetch-content-as-markdown`) at `/.well-known/agent-skills/fetch-content-as-markdown/SKILL.md`, teaching agents how to use this plugin's `.md`, llms.txt, and llms-full.txt endpoints instead of parsing HTML. New `includes/class-mmsar-agent-skills.php`.
- Add: Quick Links for both new endpoints in Settings > Agent-Ready.

### Improvement

- Version bumps now trigger an automatic `flush_rewrite_rules()` on the next request, so new rewrite rules (like the two added in this release) take effect without requiring a manual Permalinks resave — updating a plugin's files in place doesn't re-fire the activation hook.

## 1.3.3 — 2026-06-18

### Improvement

- Add: robots.txt "Current Content" read-only preview in the settings page, showing exactly what gets served — so users can verify the AI crawler rules are present without leaving the admin.

## 1.3.2 — 2026-06-18

### New features

- Add: robots.txt settings section in Settings > Agent-Ready — shows a link to the live file and an "Additional Rules" textarea for custom directives appended after the AI crawler rules.
- Add: robots.txt Quick Link in the settings page footer alongside llms.txt, llms-full.txt, and security.txt.

## 1.3.1 — 2026-06-15

### Bug fixes

- Fix: Remove `X-Robots-Tag: noindex` header from `/llms.txt` and `/llms-full.txt` — these files are meant to be discovered by AI agents, not hidden from crawlers.
- Fix: Add rewrite rule routing `robots.txt` through WordPress (`index.php?robots=1`) so the `robots_txt` filter (and AI crawler rules) fires even when a physical `robots.txt` file exists on disk.
- Add: Admin notice when a static `robots.txt` file is detected in the webroot, warning that CDNs (e.g. Cloudflare) may serve it directly, bypassing the plugin's AI crawler rules.

## 1.3.0 — 2026-06-15

### Plugin renamed: LLM Markdown → Make My Site Agent-Ready

- Slug: `make-my-site-agent-ready` (available on WP.org)
- Main file: `make-my-site-agent-ready.php`
- All prefixes updated: `LLMMD_` → `MMSAR_`, `llmmd_` → `mmsar_` (option keys kept as `llmmd_*` for data continuity)
- Text domain: `make-my-site-agent-ready`
- Admin menu: Settings > Agent-Ready

### New features

- Add: `/llms-full.txt` endpoint — full site content concatenated as markdown with `---` dividers (title + URL + content per entry). Cached with 24h TTL, invalidated on save/settings change.
- Add: `/.well-known/security.txt` endpoint — plain-text security.txt per RFC 9116. Configurable via Settings > Agent-Ready; falls back to auto-generated default using admin email.
- Add: AI crawler rules in `robots.txt` — explicit `Allow: /` for GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, FacebookBot; adds `Sitemap:` directive if not already present.

### Bug fix

- Fix: trailing slash redirect on `/llms.txt` (and other plugin endpoints) — `redirect_canonical` filter now returns `false` for all plugin-owned paths before WordPress can append a trailing slash.

### Abilities API

- Fix: `regenerate-files` ability now always registered — no opt-in checkbox required
- Change: `regenerate-files` marked `destructive: true` so AI tools prompt for confirmation before running
- Remove: "Enable write abilities" checkbox and `llmmd_write_abilities` option

## 1.2.2 — 2026-06-01

- Fix: `$input = null` for PHP 8 compatibility in abilities execute callbacks

## 1.2.1 — 2026-06-01

- Fix: `meta.mcp.public` key in abilities registration

## 1.2.0 — 2026-06-01

- Add: WordPress Abilities API integration (`llm-markdown/get-settings`, `llm-markdown/regenerate-files`)
- Add: "Enable write abilities" checkbox in settings

## 1.1.2 — 2026-05-24

- Fix: YAML frontmatter `url` and `markdown_url` fields now quoted for spec compliance
- Fix: Markdown link titles in llms.txt now escape `]` characters to prevent broken links
- Fix: Version check moved into `plugins_loaded` hook
- Add: `llmmd_bulk_generate_limit` filter for large-site memory control
- Internal planning docs removed from repository

## 1.1.1 — 2026-05-20

- Replace "View details" plugin row link with "Visit plugin site" pointing to miriamschwab.me

## 1.1.0 — 2026-05-20

- Security: sanitize CSS selectors to prevent XPath injection
- Security: add X-Content-Type-Options: nosniff header on .md responses
- Security: use $wpdb->prepare() in uninstall.php
- Fix YAML escape order (backslashes before quotes)
- Auto-clear llms.txt transient on plugin version upgrade

## 1.0.5 — 2026-05-20

- Decode HTML entities in llms.txt (titles, excerpts, site description, category names)
- Fix homepage URL in llms.txt showing domain.md instead of domain/index.md

## 1.0.4 — 2026-05-20

- Decode HTML entities in YAML frontmatter (&#8217; → ')
- Fix front page markdown_url showing domain.md instead of domain/index.md

## 1.0.3 — 2026-05-20

- Fix front page /index.md by handling "index" path in resolver instead of separate rewrite rule
- Add alternate link tag to homepage

## 1.0.2 — 2026-05-20

- Fix front page /index.md returning 404 (rewrite rule ordering — did not fully resolve)

## 1.0.1 — 2026-05-20

- Add post excerpts/descriptions to llms.txt entries

## 1.0.0 — 2026-05-20

Initial release.

- `.md` URL suffix serves markdown version of any post or page
- YAML frontmatter with title, date, author, URL, excerpt, categories, tags
- Pre-generated markdown stored in post meta on save (instant serving)
- Bulk generation on activation for existing content
- `/llms.txt` site index listing all available markdown URLs by category
- `<link rel="alternate" type="text/markdown">` in page headers
- Settings page for post type selection and content root CSS selector
- Proper HTTP headers: Content-Type, X-Robots-Tag noindex, canonical link
- Regenerate All button for bulk re-generation
- Clean uninstall removes all plugin data
