# LLM Markdown

A WordPress plugin that serves markdown versions of your site content at `.md` URLs, making your site readable by AI language models. Also generates an `llms.txt` site index following the emerging standard for LLM-friendly content discovery.

## Why

AI models and agents increasingly need to read website content, but HTML is noisy — navigation, ads, scripts, and styling all get in the way. This plugin gives every post and page a clean `.md` URL that serves pre-generated markdown with YAML frontmatter. An LLM can read `your-site.com/about.md` and get structured, clean content instead of parsing raw HTML.

Eight existing plugins were analyzed before building this one. Most were overengineered — custom converters, content negotiation, user-agent sniffing. This plugin takes a simpler approach: generate markdown once on save, serve it instantly on request.

## Features

- **`.md` URL suffix** — any post or page is available at its URL with `.md` appended (e.g., `your-site.com/my-post.md`)
- **Front page** at `/index.md`
- **YAML frontmatter** — title, date, author, URL, excerpt, categories, and tags
- **Pre-generated on save** — markdown is stored in post meta, so `.md` requests serve instantly with zero processing
- **`/llms.txt` site index** — lists all available markdown URLs organized by category, cached with 24-hour transient
- **`<link rel="alternate">`** — HTML pages include a link tag pointing to their markdown version
- **Post type selector** — choose which content types get markdown versions
- **CSS root selector** — configure which part of the page HTML to convert (useful for sites with complex layouts)
- **Bulk regeneration** — "Regenerate All" button on the settings page
- **Proper HTTP headers** — `Content-Type: text/markdown`, `X-Robots-Tag: noindex`, `X-Content-Type-Options: nosniff`, canonical link
- **Password protection** — password-protected posts return 403 on `.md` URLs
- **Clean uninstall** — removes all plugin data (post meta, options, transients)

## How it works

1. When you save a post, the plugin converts its rendered HTML to markdown using [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) and stores it in post meta (`_llmmd_content`)
2. A single rewrite rule (`^(.+)\.md/?$`) catches all `.md` requests
3. The plugin resolves the request to a post, reads the pre-generated markdown from meta, and serves it with proper headers
4. The `/llms.txt` endpoint builds a categorized index of all available markdown URLs

Since markdown is generated at save time, serving `.md` requests is essentially a single meta query — no HTML parsing, no API calls, no processing overhead.

## Installation

1. Download or clone this repository
2. Upload the entire plugin folder to `wp-content/plugins/`
3. Activate the plugin in WordPress
4. Go to **Settings > LLM Markdown** to configure post types and options
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

## Requirements

- WordPress 6.0+
- PHP 7.4+

## License

GPL-2.0-or-later
