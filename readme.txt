=== Make My Site Agent-Ready ===
Contributors: illuminea
Tags: markdown, llm, ai, llms-txt, agents
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes your WordPress site ready for AI agents: .md URLs, llms.txt, llms-full.txt, security.txt, and AI crawler rules.

== Description ==

Make My Site Agent-Ready makes your WordPress content accessible to AI language models and AI agents. Every post and page gets a markdown endpoint automatically, a site index is generated for discovery, and the full site content is available in one request for LLMs that want it.

**Features:**

* **`.md` URLs** — Append `.md` to any post or page URL to get a clean markdown version
* **llms.txt** — Auto-generated site index at `/llms.txt` listing all available markdown content
* **llms-full.txt** — Full site content in one file at `/llms-full.txt` for LLMs that want everything
* **security.txt** — Serves `/.well-known/security.txt` with configurable contact info
* **AI crawler rules** — Adds explicit `Allow: /` entries for GPTBot, ClaudeBot, and other AI crawlers in `robots.txt`
* **YAML frontmatter** — Title, date, author, URL, excerpt, categories, and tags
* **Pre-generated** — Markdown is generated when posts are saved, so `.md` requests are instant
* **Discoverable** — Adds `<link rel="alternate" type="text/markdown">` to page headers
* **Lightweight** — No custom database tables, no cron jobs, no frontend JavaScript

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

== Changelog ==

= 1.3.0 =
* Rename: plugin renamed to "Make My Site Agent-Ready" with slug make-my-site-agent-ready
* New: /llms-full.txt endpoint serving full site content concatenated as markdown
* New: /.well-known/security.txt endpoint with configurable content in Settings
* New: AI crawler rules (GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, FacebookBot) in robots.txt
* Fix: trailing slash redirect on /llms.txt (and other plugin endpoints) caused by WordPress canonical redirect
* Abilities: regenerate-files ability now always registered; marked destructive so AI confirms before running
* Abilities: removed write abilities opt-in checkbox — destructive annotation handles confirmation

= 1.2.2 =
* Fix: $input = null for PHP 8 compatibility in abilities execute callbacks

= 1.2.1 =
* Fix: meta.mcp.public key in abilities registration

= 1.2.0 =
* Add: WordPress Abilities API integration (get-settings, regenerate-files)

= 1.1.2 =
* Fix: YAML frontmatter url and markdown_url fields now quoted for spec compliance
* Fix: Markdown link titles in llms.txt now escape ] characters to prevent broken links
* Fix: Version check moved into plugins_loaded hook
* Add: llmmd_bulk_generate_limit filter for large-site memory control
* Internal docs removed from repository

= 1.1.1 =
* Replace "View details" plugin row link with "Visit plugin site"

= 1.1.0 =
* Security: sanitize CSS selectors to prevent XPath injection
* Security: add X-Content-Type-Options: nosniff header on .md responses
* Security: use $wpdb->prepare() in uninstall.php
* Fix YAML escape order (backslashes before quotes)
* Auto-clear llms.txt transient on plugin version upgrade

= 1.0.5 =
* Decode HTML entities in llms.txt

= 1.0.4 =
* Decode HTML entities in YAML frontmatter

= 1.0.3 =
* Fix front page /index.md
* Add alternate link tag to homepage

= 1.0.2 =
* Fix front page /index.md returning 404

= 1.0.1 =
* Add post excerpts/descriptions to llms.txt entries

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.0 =
Plugin renamed to Make My Site Agent-Ready. Deactivate the old plugin and activate the new one. Existing settings are preserved automatically.
