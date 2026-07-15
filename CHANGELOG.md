# Changelog

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
