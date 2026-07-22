# Changelog

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
