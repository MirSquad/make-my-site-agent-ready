# Make My Site Agent-Ready — WordPress Plugin

A WordPress plugin that makes your site ready for AI agents and language models. Serves clean markdown at `.md` URLs, generates `/llms.txt` and `/llms-full.txt` site indexes, serves `/.well-known/security.txt`, adds AI crawler rules to `robots.txt`, and exposes WordPress Abilities API endpoints for AI agent management.

## Why

AI models and agents increasingly need to read website content, but HTML is noisy — navigation, ads, scripts, and styling all get in the way. This plugin gives every post and page a clean `.md` URL that serves pre-generated markdown with YAML frontmatter. An LLM can read `your-site.com/about.md` and get structured, clean content instead of parsing raw HTML.

Eight existing plugins were analyzed before building this one. Most were overengineered — custom converters, content negotiation, user-agent sniffing. This plugin takes a simpler approach: generate markdown once on save, serve it instantly on request.

## Features

- **`.md` URL suffix** — any post or page is available at its URL with `.md` appended (e.g., `your-site.com/my-post.md`)
- **Front page** at `/index.md`
- **YAML frontmatter** — title, date, author, URL, excerpt, categories, and tags
- **Pre-generated on save** — markdown is stored in post meta, so `.md` requests serve instantly with zero processing
- **`/llms.txt` site index** — lists all available markdown URLs organized by category, cached with 24-hour transient
- **`/llms-full.txt`** — full site content concatenated as markdown in a single file, for LLMs that want everything at once
- **`/.well-known/security.txt`** — serves a security.txt file (RFC 9116) with configurable content via Settings
- **AI crawler rules in `robots.txt`** — explicit `Allow: /` entries for GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, and FacebookBot; adds `Sitemap:` directive if not already present
- **`<link rel="alternate">`** — HTML pages include a link tag pointing to their markdown version
- **Post type selector** — choose which content types get markdown versions
- **CSS root selector** — configure which part of the page HTML to convert (useful for sites with complex layouts)
- **Bulk regeneration** — "Regenerate All" button on the settings page
- **Proper HTTP headers** — `Content-Type: text/markdown`, `X-Robots-Tag: noindex`, `X-Content-Type-Options: nosniff`, canonical link
- **Password protection** — password-protected posts return 403 on `.md` URLs
- **Clean uninstall** — removes all plugin data (post meta, options, transients)

## How it works

1. When you save a post, the plugin converts its rendered HTML to markdown using [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) and stores it in post meta
2. A single rewrite rule (`^(.+)\.md/?$`) catches all `.md` requests
3. The plugin resolves the request to a post, reads the pre-generated markdown from meta, and serves it with proper headers
4. The `/llms.txt` endpoint builds a categorized index of all available markdown URLs
5. The `/llms-full.txt` endpoint concatenates the full content of all posts and pages into a single file

Since markdown is generated at save time, serving `.md` requests is essentially a single meta query — no HTML parsing, no API calls, no processing overhead.

## Installation

1. Download or clone this repository
2. Upload the `make-my-site-agent-ready` folder to `wp-content/plugins/`
3. Activate the plugin in WordPress
4. Go to **Settings > Agent-Ready** to configure post types, options, and security.txt content
5. Visit **Settings > Permalinks** and click Save (to flush rewrite rules)

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
