# Changelog

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
