# Make My Site Agent-Ready — WordPress Plugin

A WordPress plugin that makes your site ready for AI agents and language models. Serves clean markdown at `.md` URLs, generates `/llms.txt` and `/llms-full.txt` site indexes, serves `/.well-known/security.txt`, publishes a machine-readable `/.well-known/api-catalog`, exposes Agent Skills discovery, sends `Link` response headers advertising all of it, declares AI usage preferences via Content Signals in `robots.txt`, adds AI crawler rules, optionally points agents at the markdown alternate via JSON-LD structured data (merging into Yoast SEO's own schema when active, so nothing is duplicated), and exposes WordPress Abilities API endpoints for AI agent management.

## Why

AI models and agents increasingly need to read website content, discover what's available, and know what a site owner will and won't let them do with it. HTML is noisy for the first problem — navigation, ads, scripts, and styling all get in the way. Discovery and usage preferences are largely unsolved by default WordPress at all. This plugin addresses all three: clean markdown for reading, machine-readable indexes and headers for discovery, and explicit signals for usage preferences.

Eight existing plugins were analyzed before building the original `.md`/llms.txt feature set. Most were overengineered — custom converters, content negotiation, user-agent sniffing. This plugin takes a simpler approach throughout: generate markdown once on save, serve pre-built indexes, declare preferences plainly.

## Features

### Content access
- **`.md` URL suffix** — any post or page is available at its URL with `.md` appended (e.g., `your-site.com/my-post.md`)
- **Front page** at `/index.md`
- **YAML frontmatter** — title, date, author, URL, excerpt, categories, and tags
- **Pre-generated on save** — markdown is stored in post meta, so `.md` requests serve instantly with zero processing
- **`/llms.txt` site index** — lists all available markdown URLs organized by category, cached with 24-hour transient
- **`/llms-full.txt`** — full site content concatenated as markdown in a single file, for LLMs that want everything at once
- **`<link rel="alternate">`** — HTML pages include a link tag pointing to their markdown version

### Discovery
- **`/.well-known/api-catalog`** (RFC 9727) — a Linkset (RFC 9264) JSON document indexing `llms.txt`, `llms-full.txt`, `security.txt`, the Agent Skills index, the sitemap, and the feed in one machine-readable file
- **Agent Skills discovery** — `/.well-known/agent-skills/index.json` plus a bundled skill (`fetch-content-as-markdown`) teaching an agent how to use this plugin's markdown endpoints instead of parsing HTML. The served skill file and its index digest are computed from the same source at request time, so they can never drift out of sync.
- **`Link` response headers** (RFC 8288) — every front-end response carries `Link` headers pointing to the api-catalog and the Agent Skills index; singular posts/pages add a third pointing to their markdown alternate. Lets agents that only read headers, never HTML, still find these resources.
- **Structured data (JSON-LD)** — opt-in, off by default. Points agents at the markdown alternate via an `encoding`/`MediaObject` field. When Yoast SEO is active and produces schema for the page, this merges directly into Yoast's own `Article`/`WebPage` piece — no duplicate block, nothing else in Yoast's graph touched. Otherwise (no Yoast, or a page type Yoast doesn't cover), a standalone minimal `Article`/`WebPage` JSON-LD block is added instead. Enable in Settings > Agent-Ready.

### Usage preferences and crawler rules
- **Content Signals** — `Content-Signal: search=..., ai-input=..., ai-train=...` (per [contentsignals.org](https://contentsignals.org/) / the IETF AI Preferences draft) declared under each AI crawler's group in `robots.txt`. Configurable per-site: allow indexing, allow live AI retrieval, allow/decline model training use, independently.
- **AI crawler rules in `robots.txt`** — explicit `Allow: /` entries for GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, and FacebookBot; adds a `Sitemap:` directive if not already present
- **`/.well-known/security.txt`** — serves a security.txt file (RFC 9116) with configurable content via Settings

### Configuration and operations
- **Settings page** (Settings > Agent-Ready) — post type selector, CSS root selector, robots.txt preview and extra-rules textarea, security.txt content, Content Signals toggles, a structured data (JSON-LD) toggle, and Quick Links to every endpoint the plugin serves
- **Bulk regeneration** — "Regenerate All" button on the settings page
- **Proper HTTP headers** — `Content-Type: text/markdown`, `X-Robots-Tag: noindex`, `X-Content-Type-Options: nosniff`, canonical link
- **Password protection** — password-protected posts return 403 on `.md` URLs
- **Clean uninstall** — removes all plugin data (post meta, options, transients)

## How it works

1. When you save a post, the plugin converts its rendered HTML to markdown using [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) and stores it in post meta
2. A single rewrite rule catches all `.md` requests (excluding `/.well-known/` paths, which route to their own handlers — see Architecture Notes below)
3. The plugin resolves the request to a post, reads the pre-generated markdown from meta, and serves it with proper headers
4. The `/llms.txt` endpoint builds a categorized index of all available markdown URLs
5. The `/llms-full.txt` endpoint concatenates the full content of all posts and pages into a single file
6. `/.well-known/api-catalog`, the Agent Skills endpoints, and `Content-Signal` directives are all generated the same way — computed from live site state at request time, not hand-maintained static files

Since markdown is generated at save time, serving `.md` requests is essentially a single meta query — no HTML parsing, no API calls, no processing overhead.

## Installation

1. Download or clone this repository
2. Upload the `make-my-site-agent-ready` folder to `wp-content/plugins/`
3. Activate the plugin in WordPress
4. Go to **Settings > Agent-Ready** to configure post types, robots.txt rules, security.txt content, and Content Signals
5. Visit **Settings > Permalinks** and click Save (to flush rewrite rules) — not required after future plugin updates, only on first install, since version bumps auto-flush rewrite rules from v1.4.0 onward

The plugin includes its only dependency (`league/html-to-markdown`) in the `vendor/` folder — no Composer install needed.

## Example output

**`your-site.com/hello-world.md`** returns:

```markdown
---
title: "Hello World"
date: "2026-01-15"
author: "Jane Doe"
url: "https://your-site.com/hello-world/"
excerpt: "Welcome to my site."
categories:
  - "Uncategorized"
tags: []
---

Welcome to WordPress. This is your first post. Edit or delete it, then start writing!
```

**`your-site.com/llms.txt`** returns a site index with all available markdown URLs grouped by category.

**`your-site.com/llms-full.txt`** returns the full content of every published post and page as concatenated markdown.

**`your-site.com/.well-known/api-catalog`** returns a Linkset JSON document indexing every discoverable resource the plugin serves.

**`your-site.com/robots.txt`** returns, per AI crawler group:
```
User-agent: GPTBot
Allow: /
Content-Signal: search=yes, ai-input=yes, ai-train=no
```

**A single post, with structured data enabled and Yoast SEO active**, gets an `encoding` field merged straight into Yoast's own `Article` piece:
```json
{
  "@type": "Article",
  "headline": "Hello World",
  "datePublished": "2026-01-15T09:00:00+00:00",
  "...": "...Yoast's other Article fields (author, publisher, wordCount, etc.), unchanged...",
  "encoding": {
    "@type": "MediaObject",
    "contentUrl": "https://your-site.com/hello-world.md",
    "encodingFormat": "text/markdown"
  }
}
```

**Without Yoast active** (or on a page type Yoast doesn't cover), the same information ships as its own standalone block instead:
```json
{
  "@context": "https://schema.org",
  "@type": "Article",
  "url": "https://your-site.com/hello-world/",
  "headline": "Hello World",
  "datePublished": "2026-01-15T09:00:00+00:00",
  "dateModified": "2026-01-15T09:00:00+00:00",
  "encoding": {
    "@type": "MediaObject",
    "contentUrl": "https://your-site.com/hello-world.md",
    "encodingFormat": "text/markdown"
  }
}
```

## Architecture notes

**The `.md` catch-all rewrite rule excludes `/.well-known/`.** The broad rule that serves post/page `.md` URLs (`^(.+)\.md/?$`) would otherwise also match paths like `/.well-known/agent-skills/*/SKILL.md`, and — depending on rewrite rule registration order — can shadow more specific rules for those paths. The catch-all is scoped with a negative lookahead (`^(?!\.well-known/)(.+)\.md/?$`) so this can't happen regardless of what else the plugin (or a future version of it) adds under `/.well-known/`.

**`Link` headers are sent on `template_redirect`, not `send_headers`.** `send_headers` fires before WordPress resolves the main query, so conditional tags like `is_singular()` aren't reliable yet at that point. `template_redirect` fires after the query resolves and still early enough to set headers.

**Content Signals are emitted per AI-crawler group, never under `User-agent: *`.** That group is typically owned by an SEO plugin (Yoast, by default here) — adding to it risks fighting another plugin's output.

**Structured data merges into Yoast's schema instead of duplicating it.** Yoast's Schema Framework already declares type, url, title, and dates on every page — the only new fact this plugin adds is the `encoding`/`MediaObject` pointer to the markdown alternate. When Yoast produces a schema piece for the current page, that one field is injected directly into Yoast's own `Article`/`WebPage` piece via Yoast's documented `wpseo_schema_article`/`wpseo_schema_webpage` filters — registered unconditionally (not gated on detecting Yoast at plugin-load time, since load order across plugins isn't guaranteed; if Yoast isn't active, these filters simply never fire). Falls back to a standalone block, with no `@id`, whenever the injection doesn't apply — no Yoast, Yoast's schema output disabled, or a content type (e.g. a WooCommerce product) Yoast gives its own distinct schema to.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## License

GPL-2.0-or-later

## WordPress Abilities API

This plugin exposes abilities for the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) (WordPress 6.9+), making it manageable by AI agents via the [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin.

### Requirements

- WordPress 6.9+
- [MCP Adapter plugin](https://github.com/WordPress/mcp-adapter)

### Available abilities

| Ability | Access | Description |
|---|---|---|
| `make-my-site-agent-ready/get-settings` | Always on | Returns the enabled post types and content root CSS selector |
| `make-my-site-agent-ready/regenerate-files` | Always on (destructive) | Regenerates cached markdown for all published content and clears the llms.txt and llms-full.txt caches. AI tools will ask for confirmation before running. |
