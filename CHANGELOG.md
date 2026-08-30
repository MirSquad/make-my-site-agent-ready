# Changelog

## 1.22.2 — 2026-08-30

Reviewer-readability only. No behaviour changes, and no executable code changed — the single edit moves an existing `phpcs:ignore` onto its own line and gives it a reason.

### Fixed
- The `phpcs:ignore` covering the `_llmmd_content` post-meta cleanup in `uninstall.php` had no `--` justification, while the table-drop query directly below it did. A WordPress.org reviewer re-scans without honouring inline ignores, so an unexplained one reads as something being hidden. It now states why the direct query is correct there: no core API deletes meta by key across all posts, and caching is meaningless for a one-time uninstall delete.

## 1.22.1 — 2026-08-29

WordPress.org Plugin Check compliance. No behaviour changes — every output this plugin serves is byte-identical to 1.22.0, which is verified rather than assumed: the Agent Skills SKILL.md and the MCP results panel were both captured before and after and hash to the same SHA-256, and the served SKILL.md still matches the digest the discovery index advertises.

### Fixed
- `Domain Path: /languages` and the `load_plugin_textdomain()` call both pointed at a directory that is empty and has never been included in the distributed zip. WordPress has loaded plugin translations just in time since 4.6 — far below this plugin's 6.2 minimum — so both were removed rather than propped up with an empty folder.
- `wp_register_ability_category()` now has its own `function_exists()` guard. `includes/abilities.php` already returned early when `wp_register_ability()` was missing, but that is a different function, and a checker cannot see a file-level return from inside a callback. The guard makes the WP 6.9 requirement true by construction.
- `Tested up to: 7.1`, which is the version this release was actually tested against.

### Changed
- The two heredoc blocks (`MMSAR_Agent_Skills::skill_md_content()` and `MMSAR_MCP::ui_results_html()`) are assembled from string arrays. Plugin Check disallows heredoc syntax.
- `readme.txt`'s Changelog keeps only recent releases and points at this file for the rest. WordPress.org truncates that section at 5,000 characters; it had reached roughly 33,000, so most of the history was being cut on the plugin page. Full history stays here.
- Added `composer.json` describing the two bundled vendor packages — `league/html-to-markdown` and `yahnis-elsts/plugin-update-checker`. Plugin Check flags a `vendor/` directory with no manifest beside it. It is included in the release zip.

### Deliberately not changed
- `apply_filters( 'robots_txt', … )` in the settings-page preview keeps its unprefixed core hook name, with a `phpcs:ignore` and a comment. The preview is only truthful if it runs the exact chain that builds the served file — every SEO plugin hooks that filter, and a prefixed hook of our own would preview nothing but our own contribution.

## 1.22.0 — 2026-08-29

Scoped llms.txt indexes have generated and served since 1.21.0, but nothing ever advertised them. Every page sent the same `Link: </llms.txt>; rel="describedby"` the homepage did, so `/media/llms.txt` could only be found by an agent that had already guessed the path — precisely the v1 behaviour the [llms.txt v2 proposal](https://llmstxt.org/) exists to replace. v2 states the rule directly: "a file covers the pages under its path, and the most specific file applies."

### Added
- `MMSAR_LLMs_Txt::section_for_request()`, `::url_for_request()` and `::send_link_header()`. Resolution is a longest-prefix match of the request path against the registered section slugs, read from `REQUEST_URI` so it answers on 404s too, with the home path stripped first so it holds on installs in a subdirectory. Memoized per request.
- The `describedby` header on all three Markdown responses — `.md` URLs, Markdown 404s, and negotiated Markdown pages. These are served at `template_redirect` priority 1 and exit before the ordinary page headers go out at priority 10, so they previously advertised no index at all. The v2 proposal calls this case out specifically: the header form "also works for non-HTML resources, such as the markdown files themselves", which have no `<head>` to carry a `<link>` element.

### Changed
- The `describedby` relation on page responses resolves per request rather than being fixed at the root.
- The footer llms.txt link follows the same scoping and names its section — "Press & Talks index for AI agents (llms.txt)" under `/media/`. The `mmsar_llms_txt_link_text` filter now also receives the resolved URL and the covering section; existing filters are unaffected.
- 404 recovery points at the covering index, with a label saying which section it covers.
- Four hand-written copies of the header were replaced by one shared emitter, so the relation and media type cannot drift between them.

### Unchanged, deliberately
- The advertised media type stays `text/plain`, matching what `serve_llms_txt()` actually sends. The v2 proposal specifies no media type for `describedby` — its canonical example attaches `type="text/markdown"` only to the `rel="alternate"` Markdown-page link, which this plugin already does correctly.

## 1.21.3 — 2026-08-28

Housekeeping only — no functional change, and the plugin behaves identically.

The audit had been sitting at 0 errors but 54 warnings. Nothing in it touched security or correctness (the gate fails on errors, and there were none), but the standing rule is a clean 0/0, so the gap is now closed.

### Changed
- Array `=>` and assignment `=` alignment across six files in `includes/` — 51 whitespace-only edits.
- One stand-alone `$rank++` changed to `++$rank` in the NLWeb result loop. Identical semantics; the return value was never used.
- Two private static method parameters renamed off PHP reserved words: `entry( $namespace, … )` → `$urn_namespace` in the AI catalog, and `requested_limit( $arguments, $default )` → `$fallback` in the MCP handler. Both methods are private with positional callers only, so nothing outside the class is affected.

## 1.21.2 — 2026-08-27

Small release with an awkward reason: 1.21.1 was tagged, then amended before it was pushed, so two different builds briefly carried that version number. One was installed on a live site and the other reached GitHub. Nothing was broken by it, but a version number that means two things is worse than a version bump, so the corrected build ships as 1.21.2.

### New

- **`get_site_overview` lists `/auth.md`.** It was the one document the overview never mentioned, which is the wrong way round: an agent asking a site what it offers should be told how to get in, and for most sites the answer is that it does not need to.

### Fixed

- The overview advertised `/openapi.json` unconditionally, including on a site where the plugin has deliberately stood down from serving it because a real `openapi.json` already sits in the web root. It checks `MMSAR_OpenAPI::is_serving()` now, like every other surface that links to it.
- A stray blank line at the end of the overview output.

### Changed

- The overview builder is four small methods, one per section, instead of one function with four conditionals wrapped around blocks that were never re-indented. The indentation was what a pre-push audit caught; the shape of the function was the actual problem.
- Removed a method left dead by 1.21.1's ARD rewrite, and an `array_values()` call on something that was already a list.

## 1.21.1 — 2026-08-27

Three fixes from re-scanning with 1.21.0 live. Also worth recording what the scan got *wrong*: it reported `/.well-known/api-catalog` as absent, the About/Contact/Privacy pages as missing, and the site's own homepage as an unresolvable link — all three demonstrably fine, and all three passing in the previous run. Transient scan failures read exactly like regressions in a diff, and chasing them would have meant "fixing" working code.

### Fixed

- **The ARD catalog did not conform to its own specification.** It was written from the prose description rather than the schema, and got two things wrong: the entry array was named `resources` where the spec says `entries`, and `type` carried a category word (`mcpServer`, `api`) where the spec wants an IANA media type describing the artifact at `url`. A validator therefore saw a catalog that was present, parseable, and empty of anything it could check. Entries now also carry `capabilities`, `representativeQueries` and a `trustManifest`.
- The `trustManifest` states `verificationMethod: same-origin`, which is the honest description of what is actually proven: the catalog is served over HTTPS from the domain it names, so a reader that fetched it has already verified the binding. A signature or a third-party attestation would be asserting a check nobody performed.
- The document is served at `/.well-known/ard.json` as well as `/.well-known/ai-catalog.json`. The specification names the first; directories and scanners look for the second.

### New

- **A Content-Security-Policy inside the MCP Apps UI template.** A `ui://` resource arrives as a string over the MCP connection rather than as an HTTP response, so there is no header to attach a policy to and it has to be declared in the document. The panel's markup, styles and script are all inline and it needs nothing from the network, so everything else is denied — a result title that somehow carried markup still cannot fetch or execute.

### Changed

- A GET to the MCP endpoint carries the RateLimit headers alongside its 405. The limiter applies to that request too, and a client that only ever issues a GET would otherwise never learn the policy exists.

## 1.21.0 — 2026-08-27

A second pass driven by re-scanning the site with 1.20.1 installed. The scan's remaining findings sorted into three piles, and this release deliberately only works two of them: things that genuinely improve how an agent uses a site, and cheap conventions that cost nothing to honor. The third pile — OAuth metadata, RFC 9728 protected-resource documents, `agent_auth` registration endpoints, Web Bot Auth key directories — is scored and is not implemented, because this plugin ships to sites that have no authorization server, and publishing discovery documents pointing at endpoints that do not exist is worse than scoring zero.

### New

- **"When to use this" in llms.txt.** Everything else in an llms.txt is inventory: what exists, where it lives. This is the only part that helps an agent decide whether any of it is worth fetching for the question in front of it. Without it an agent either reads the whole index to discover it was the wrong site, or skips a site that would have answered. Owners write their own on the settings page; the generated fallback describes the shape of the site and what its endpoints are good for, and stops there — it cannot know the subject matter and does not pretend to.
- **`/auth.md`.** The honest version of a document that is usually aspirational. Most sites running this plugin have no authorization server and no API keys, and the truthful answer to "how do I authenticate" is "you don't" — which is worth publishing rather than omitting, because an agent that finds no auth document has to assume it needs a credential it cannot obtain. It follows the section shape of the WorkOS draft, including the sections that do not apply: "there is no registration step" is information, and a missing heading is not. Sites that *do* have a credentialed endpoint get a walkthrough generated from the `auth` field already recorded in the endpoint registry, so the document reports what the owner said rather than inventing a scheme.
- **`/.well-known/ai-catalog.json`** (Agentic Resource Discovery). The fourth discovery document this plugin publishes, and not redundant: api-catalog is a linkset of URLs, llms.txt is prose for a model, the Agent Skills index describes skills. ARD is the only one whose entries carry a typed resource kind and a stable domain-anchored identifier, which is what a directory building a listing actually reads.
- **`/.well-known/mcp/server-card.json`.** The same server, described again with full tool detail so a directory can preview it without opening a transport. Worth stating plainly that this location is a convention rather than a ratified spec — MCP covers the connection, not discovery.
- **`?mode=agent`** on any page. Also a convention rather than a standard, and also worth honoring, because the underlying complaint is real: a client handed a bare URL has no way to ask for the machine-readable version unless it already knows this site's particular conventions. A query parameter is the one lever a client always has.
- **Per-section llms.txt.** `/press/llms.txt` indexes exactly what lives at `/press/...`, so the address an agent guesses from a content URL is the one that works. Post types with no rewrite slug are skipped rather than given an invented address.
- **NLWeb `/ask`, with SSE streaming**, plus a Schema Map and a `Schemamap:` directive in robots.txt. Off by default: unlike every other surface here it answers by running a query rather than serving a file. `_meta.response_type` is `list`, never `summary` — there is no model behind this endpoint, and a caller needs to know it is receiving sources to read rather than an answer to quote. A generated summary would need an API key, a per-request cost and a hallucination budget, none of which belong in a plugin that otherwise only serves files.
- **RateLimit headers on the MCP endpoint.** The limiter has been there since 1.20.0; it just never told anyone. Both spellings go out — the individual `RateLimit-*` fields most clients already read, and the single structured `RateLimit` field the IETF draft settled on — and `RateLimit-Reset` is computed from the transient's own expiry rather than a flat window, which would be wrong for every request after the first.
- **Optional MCP Apps support.** An experimental `ui://` resource rendering search results as a card list. Off by default and labelled experimental for one honest reason: no MCP Apps host was available to render it against, so unlike everything else in this plugin it has not been verified against the thing that consumes it. A host that ignores the metadata still gets the normal text result, which is why declaring it is safe rather than merely cheap.

### Changed

- **A 404 on an API-shaped path returns JSON even when the client did not ask for it.** Readiness scanners probe `/api`, `/api/v1` and `/v1`; so do plenty of real clients. A request to `/api/v1` has announced what it is by the request line alone, and answering it with a themed HTML error page is precisely the failure "agents can't parse HTML error pages" describes. The prefix list is short and conservative — a person typing a URL does not arrive at `/api/v2/`.
- `get_site_overview` takes an optional `sections` argument. Asking for `endpoints` alone is a fraction of the tokens of the whole overview.
- The OpenAPI document references its `Error` schema across the full `4XX` and `5XX` ranges, and `info.description` now states the authentication position, where the rate limits apply, and how deprecation would be signalled if it ever happened.

### Fixed

- **`/auth.md` was swallowed by the `.md` catch-all**, which tried to resolve `auth` as a page slug and 404ed. The catch-all now excludes it by pattern. Relying on registration order would have been fragile: every one of these rules is registered `top`, so the winner is whichever class happened to hook `init` last.
- **Per-section llms.txt rules were never registered.** They are derived from the registered post types, and a theme registering a custom type on `init` at the default priority does so *after* a plugin's `init` callbacks — plugins load first. The section list came back empty on every request. Now built at priority 20.
- Scoped llms.txt URLs were being 301'd to a trailing-slash variant by WordPress's canonical redirect, putting a redirect in front of a document agents are told to fetch by exact URL.

## 1.20.1 — 2026-08-27

Follow-up to 1.20.0, driven by re-scanning miriamschwab.me with it installed. The score moved 56 → 68, and the remaining findings split cleanly into two piles: conventions with no published spec behind them, which were left alone, and one real gap, which is fixed here.

### New

- **A 404 answers in JSON when the request asks for JSON.** The gap was visible in the scan and real underneath it: readiness scanners probe `/api`, `/api/v1` and `/v1` looking for an API root, and every one of those came back as a themed HTML error page. That is the failure "agents can't parse HTML error pages" actually describes — not that WordPress returns bad errors, but that a client calling what it believes is an API gets markup and cannot tell a wrong URL from a broken server. The body uses the same `code` / `message` / `data.status` shape as the REST API, so one error format now covers the whole site rather than just `/wp-json/`, and carries a `links` array with the same destinations the response already sends as `Link` headers.
- The `Accept` test is the same strict one the Markdown paths use, generalized: the type has to be named and has to outrank HTML, a wildcard counts towards HTML and never towards the requested type, and a tie goes to HTML. A browser's `Accept` leads with `text/html` and reaches JSON only through a low-q wildcard, so it can never match — which is what keeps a person from being handed a file download in place of a page.
- **The MCP manifest carries the site icon.** A directory listing the server shows whatever branding it can find, and a name with no mark beside it reads as an unfinished entry. `get_site_icon_url()` is the one image every WordPress site is prompted for during setup, so it is the one most likely to exist.

### Changed

- **Tool input schemas are closed** (`additionalProperties: false`), and `get_site_overview` — which takes nothing — now says so with an explicit empty, closed object rather than a bare `properties: {}`. That reads to some clients as "schema not supplied" and to a model as an invitation to guess an argument the tool will ignore.
- **The OpenAPI document references its `Error` schema everywhere that shape is genuinely returned**, which after the JSON 404 is everywhere except the `.md` addresses. Those are the deliberate exception and now say so: a client that asked for a `.md` URL wants Markdown, and gets Markdown even when the answer is "nothing here". The point of the change is that the reference became accurate — the schema was already defined, and describing responses the site did not actually return would have been worse than leaving the gap.

### Not done, and why

- **`/.well-known/mcp/server-card.json`** and **MCP Apps `ui://` resources** are both scored, and both were left alone. Neither names a published spec — the scanner's own check metadata lists no `specUrl` for either — and this plugin ships to other people's sites. Publishing an undocumented well-known file on someone else's domain because one scanner rewards it is not a trade this plugin should make on their behalf.

## 1.20.0 — 2026-08-27

### New

- **An OpenAPI specification at `/openapi.json`.** Everything this plugin published up to now describes the site to a reader — api-catalog is a list of links, llms.txt is prose, SKILL.md is instructions for a model. None of them is a contract an HTTP client can turn into a working request without a human or a model in the loop, and OpenAPI is the format that is. The document is generated rather than authored: paths come from the feature toggles, the REST routes come from `rest_get_server()->get_routes()` on the actual install, and the rest comes from the endpoint registry. Nothing is documented that the site does not answer — a spec that names an endpoint returning 404 is worse than no spec, because an agent will build a request from it.
- The REST section is a curated list of read routes, not the whole route table. A normal install's REST index runs to hundreds of kilobytes, which is useless to an agent: it exhausts the context before it says anything. Seven routes cover "what content is on this site", which is what an agent fetching a spec from a content site is nearly always asking.
- The `Error` schema is stated explicitly, with `code`, `message` and `data.status`. WordPress's REST API has always returned structured JSON errors and has never said so anywhere machine-readable, so an agent had to discover it by failing once. `security: []` is set at the root for the same reason — a spec with no `security` field cannot be distinguished from one whose author forgot it, and the safe assumption an agent makes is that it needs credentials it does not have.
- The rewrite rule stands down if a real `openapi.json` exists in the site root. A rule registered at the top of the stack beats a file on disk, so registering one unconditionally would replace someone's hand-written description of their API with a generated description of their blog.

- **A read-only MCP server, off by default.** Every other surface here is a document an agent has to find, fetch and interpret. MCP is the other half: a connection opened once, asked what it can do, and then called. For a content site the answer is small and stable — search it, list it, read a page, describe the site — which is exactly what an agent otherwise reconstructs by crawling. Transport is Streamable HTTP; the endpoint answers with a plain JSON body rather than an SSE stream, which the spec permits and which is honest here, because every tool returns a complete result in one step and there is nothing to stream.
- Three limits, all because the endpoint is unauthenticated and world-reachable: read-only with no tool capable of anything else; published content only, from the enabled post types, skipping password-protected posts, so it reaches nothing `llms-full.txt` does not already publish; and rate-limited per IP, because unlike a static document these calls run queries. `resources/read` serves only the URIs the server itself advertises — reading an arbitrary URI on request would turn a public endpoint into a request proxy, which is how a public MCP server becomes an SSRF vector.
- Off by default is the point of difference from the rest of the plugin. Everything else added here publishes a file; this answers requests by running queries, and adding an endpoint like that to someone's site uninvited is not the plugin's call.
- A discovery manifest is served at `/.well-known/mcp.json`. MCP has no ratified well-known location — the spec covers the connection, not how a client holding only a domain name finds one — so this follows the de-facto convention that directories and readiness scanners actually look for, and repeats the endpoint URL at the top level so a client that disagrees about the document's shape can still pull the URL out of it.

- **Agent-recoverable 404s.** A person who lands on a 404 has a back button, a nav bar and a search box. An agent has whatever is in the response, and the usual WordPress 404 gives it a themed HTML page whose only machine-readable content is the status code — correct, and useless. It knows the URL was wrong; it has no idea what the right one might be. Every 404 now carries `Link` headers and `<link>` elements pointing at the sitemap, llms.txt, the OpenAPI document and the endpoint catalog, and a request that explicitly asked for Markdown gets a short list of those destinations in place of the error page. Headers as well as markup because headers survive a HEAD request, a discarded non-200 body, and a client that never parses HTML.
- Nothing about this changes what a browser gets. Markdown is served only to a request that named `text/markdown` and ranked it above HTML — the same strict test the page-level negotiation uses, where a wildcard counts towards HTML and a tie goes to HTML.

- **llms.txt opens with a "For agents" section** naming the machine-readable endpoints the site publishes — the Markdown mirror, llms-full.txt, the OpenAPI document, the MCP server, the catalog. It is the file an agent is most likely to fetch first, and it was the one file that said nothing about the rest of them: an agent could read the whole index and never learn there was an API to call.

### Changed

- A missing `.md` URL returns the same recovery list rather than a single sentence, and distinguishes its cases: no published page at that path, a page whose post type has Markdown switched off (with a pointer to the HTML version), and a page with nothing to convert. A client that asked for `/nothing-here.md` has already shown it is not a person, so the body is better spent on where to go next.

## 1.19.0 — 2026-08-26

### New

- **CSV export on the Agent Log screen.** The log was readable only fifty rows at a time in wp-admin, which is fine for a glance and useless for a question like "who has been here and what did they take" — answering that meant paging through the whole thing by eye and tallying by hand. The export writes the entire log, streamed in batches of 500 so peak memory is one batch whatever the log holds. Two details are deliberate. The timestamp column is named `logged_at_utc` rather than `logged_at`: rows are stored in UTC and the screen renders them in the site's timezone, so a bare name would leave a reader comparing an exported row against the screen with no way to tell which of the two they were holding. And the walk pages by id cursor rather than `OFFSET`, because the log is appended to while the export runs — with `OFFSET`, every row inserted mid-walk shifts the window and the reader gets a row twice or misses one.
- **Formula injection is neutralized in the export.** The agent column stores the user-agent string verbatim, on purpose, so unrecognized clients stay identifiable — which means it holds a string the caller chose. Excel and several other spreadsheets execute a cell beginning `=`, `+`, `-`, `@`, tab or CR as a formula on open. Those cells are written with a leading apostrophe, which spreadsheets hide and treat as text. This is the one place the plugin hands untrusted stored input to a program on someone else's machine, so it is handled at the point of writing rather than left to the reader.
- **A `get-agent-log` ability.** The plugin publishes surfaces so agents can read the site, records who reads them, and then made that record the one thing on the site an agent could not get at — it lived in a custom table, which no REST route reaches. The ability closes that. It returns aggregates over the whole log — by agent, by surface, by day, with unique agent and IP counts — computed in the database rather than tallied from a page of rows, plus one page of individual entries. Administrators only, matching the screen.
- The ability's output also carries `logging_enabled`, `page_views_recorded`, `retention_limit` and `throttle_seconds`, which sound like housekeeping and are not: each one changes what the numbers mean. A quiet week reads as "no agents called" unless you know logging was switched off. Counts read as a share of agent traffic unless you know page views are unrecorded, in which case an agent that visited and ignored the agent-facing files left no trace at all. The first entry reads as the start of the record unless a retention limit has been dropping the oldest rows. And with the same agent, surface and IP recorded at most once per five minutes, every count is a lower bound on requests — reach, not volume. A caller that cannot see those four fields will misread the log confidently, so they travel with it.
- `summary_only` returns the aggregates and omits the entries, which is both the cheaper call and the way to read the log without handling IP addresses at all — the aggregates carry counts of distinct IPs, never the addresses.


## 1.18.1 — 2026-08-23

### Fixed

- **The negotiation check could report the feature working while it was switched off.** It concluded "negotiation works" from the response's content type alone, and a content type is not proof of authorship: some CDNs convert a page to markdown at the edge on seeing a markdown-preferring `Accept`, and Cloudflare does this on at least one production host. The check saw `text/markdown` come back, credited it to the plugin, and reported the rewritten `Cache-Control` as a warning — directly above a line still reading "content negotiation is currently off". A check whose job is to be trusted about a feature that can break pages for readers cannot be wrong in the reassuring direction.
- The check now compares the returned body against the markdown the plugin would actually serve for that post. Bytes are the only claim that survives a CDN in the middle: an identifying header can be stripped or forged at the edge, but an edge conversion cannot reproduce this plugin's output, because it converts the whole page — navigation, skip links, meta-derived frontmatter — rather than the content. This is the same byte-identity property the `.md` endpoint already guarantees, used as evidence.

### New

- **A `foreign` result**: markdown came back, the browser correctly got HTML, but the markdown is not this plugin's. Reported as a warning rather than a failure — agents are getting markdown and nothing is broken — while naming the likely cause and what is lost, since an edge conversion carries the site chrome and omits the frontmatter. Whether switching the feature on takes precedence over an edge conversion is host-specific and stated as unknown rather than guessed.

### Changed

- The content negotiation section now links back to the toggle that controls it, in the Features list at the top of the settings page, and the Features section gained an anchor for it to target. Every feature's switch lives in that list, but this is the first section with no settings of its own — only the check — so it read as though the feature could not be turned on at all.

## 1.18.0 — 2026-08-23

### New

- **Markdown content negotiation, reinstated off by default.** A request for an ordinary page URL is answered with that page's markdown when its `Accept` header prefers markdown, instead of markdown living only at the `.md` address. This is the surface agents actually reach — a fetch tool sends one request to the canonical URL, and the `.md` mirror only helps a client that already knows the mirror exists. The `Accept` parsing is deliberately strict: markdown must be named explicitly and outrank HTML, a wildcard counts only towards HTML, and a tie goes to HTML, because getting this wrong serves a markdown file to a person. `Vary: Accept` is sent on both representations, and the markdown one is marked `private, no-store, max-age=0`. Output is byte-identical to the `.md` endpoint. Scope is singular posts and pages of enabled types only — archives, feeds, drafts, password-protected posts and 404s are untouched.
- **A self-check for it**, at Settings > Agent-Ready. It requests one of the site's own pages twice — once with a markdown-preferring `Accept`, then the same URL with a browser's — and reports which representation came back each time, plus the `Cache-Control` and `Vary` values that actually arrived, verbatim. This is the piece that was missing in 1.15.0. The feature was withdrawn then not because it was wrong but because its failure mode was undetectable from inside a plugin; performing the two requests from outside one makes it detectable. The probe URL always carries a throwaway query argument, both to test a cache entry nothing has seen and so the check can never leave a markdown copy of a real page sitting in a shared cache — a check that caused the failure it looks for would be worse than none. It runs automatically when the feature is switched on, and on a button otherwise; there is no cron job.

### Changed

- **The check switches the feature back off when a browser-style request is answered with markdown.** That single condition means a cache is ignoring `Vary: Accept` and serving the markdown copy to readers, which is exactly what withdrew the feature in 1.15.0. It is the only case where the plugin changes a setting by itself, and it says so at the top of the screen when it does. Headers altered in transit — the `no-store` directive rewritten, `Vary` stripped — are reported as a warning rather than a shutdown: negotiation is working, but the safety net is not there, so the owner is told what to watch for and left to decide.
- **Settings copy describes what the plugin attempts, not an outcome it cannot guarantee.** The 1.13.1 wording said markdown responses "are marked uncacheable so a cached copy can never be shown to a visitor". The first half was true and the promise was not: the host rewrote `Cache-Control` before it reached the edge, so the guarantee did not hold. The toggle now names the one symptom an owner can actually notice — a visitor getting a markdown file or a download prompt instead of the page — says to switch it off the moment that happens, names Cloudflare as a known cause, and points at the check rather than asking to be believed.

### Fixed

- **A setting left behind by 1.13.x no longer re-enables the feature silently.** 1.15.0 removed the `markdown_negotiation` key from the code but not from the database, so a site that once had it on still has `'1'` stored. Reinstating the key without handling that would have switched content negotiation straight back on for exactly the installs it had already failed on, with no check ever having run. The stored value is discarded on update, so the feature starts from its default — off — for everyone. The one-time step is claimed with `add_option()` rather than a read-then-write check, for the same concurrency reason as the 1.16.1 log migration.

## 1.17.1 — 2026-08-23

### Changed

- Spelling normalized to US English across the plugin — settings-page descriptions, `readme.txt`, `README.md` and code comments. British forms (`honoured`, `behaviour`, `normalised`, `recognised`, `sanitising`) had crept into 31 places over several releases, including strings users read. No functional change: every edited line is a comment, a docblock or a translatable string, and no identifier, hook or option name was touched.

## 1.17.0 — 2026-08-23

### New

- A **Recent Agent Requests** dashboard widget listing the 20 most recent entries, with the agent, what it fetched and elapsed time, plus a link to the full log. Elapsed time rather than a timestamp because the question the dashboard answers is whether anything is hitting the site now; the exact time is one click away. No setting controls the widget — WordPress already lets each user hide a dashboard widget from Screen Options, and a second switch would mean two places to check when it is not showing. Says so plainly when the log is switched off, rather than looking broken.

## 1.16.1 — 2026-08-23

### Fixed

- The one-time import of entries from the pre-1.16.0 option could run twice, duplicating every migrated row. The guard was a read-then-write check on a version option, which does not hold across concurrent requests: two requests arriving during the same upgrade both read the legacy option before either deleted it, and both inserted its contents. Observed on a live site immediately after updating — ten recorded requests appeared as twenty rows. The migration is now claimed with `add_option()`, which is an INSERT against a unique index and so can only succeed for one caller no matter how many arrive together. Sequential re-runs were already safe; this closes the concurrent case. Sites already showing duplicates can clear the log once — the import cannot repeat.

## 1.16.0 — 2026-08-23

### New

- The agent request log has a screen of its own at **Settings > Agent Log**: paginated at 50 entries a page, with a **Clear log** button and a retention setting. A settings screen is for configuration and a log is data that grows, so mixing them meant the log pushed the settings off the page and had nowhere to put pagination or a clear button. Linked from the feature toggle and from the settings section, both of which now also show the current entry count.
- Retention is configurable, defaulting to **unlimited**. Set a number and the oldest entries are dropped once the log passes it. Trimming runs once every 50 inserts rather than on every request, so the count can sit slightly above the limit between trims — an append is the cost the request should pay, and a handful of extra rows is not observable.

### Changed

- Entries are stored in a dedicated table rather than a serialized option. The option was viable only because the log was capped at 200; with unlimited retention it becomes pathological, since every recorded request reads and rewrites the whole history — roughly 63KB of I/O per request at 200 entries, scaling linearly with the log. An `INSERT` costs the same at any size, and an indexed table is what makes paging through the log possible. Entries from the option are migrated on upgrade and the option is removed; the table is created by `dbDelta` on load rather than on activation, since updating a plugin's files in place never re-fires the activation hook.
- The plugin no longer claims to add no database tables. It adds exactly one, only when the agent log is switched on, and drops it on uninstall.
- Minimum WordPress version raised from 6.0 to 6.2, for the `%i` identifier placeholder in `$wpdb->prepare()`. It lets the table name be prepared like any other value rather than interpolated, which is both safer and what the coding standards want; carrying a dual-path fallback for a 2023 release to avoid it was not worth the duplicated queries.

## 1.15.1 — 2026-08-21

### Fixed

- "Visit plugin site" appeared twice in the plugin's row on the Plugins screen. WordPress core adds that link itself whenever a plugin sets a `Plugin URI` header and has no WordPress.org slug — the two are an if/else in `WP_Plugins_List_Table`, so a plugin distributed outside the directory always gets it. This plugin was appending a second, identical link on top of core's. The whole `plugin_row_meta` filter is removed rather than patched: the only other thing it did was strip a `plugin-install.php` "View details" link, which is the branch core takes *instead* of the Plugin URI one and so could never have been present alongside it. Core's link is left to stand.

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

- Markdown served by content negotiation is now marked `Cache-Control: private, no-store`. `Vary: Accept` is advisory, and Cloudflare — like several CDNs — does not include `Accept` in its cache key, so the first representation cached for a URL was served to every subsequent request regardless of what it asked for. In practice: an agent fetched a post as markdown, the CDN cached that, and the next human visitor to the same URL was served a markdown file instead of the page. Reproduced on a live site minutes after the feature was switched on. Marking the response uncacheable removes the failure rather than depending on `Vary` being honored. The consequence is that a URL already cached as HTML continues to be served as HTML to agents as well — negotiation now works on cache misses and is inert on hits, which fails safely: an agent receives HTML rather than a person receiving a download. Sites behind a CDN they control can restore full behavior with a cache rule that bypasses the cache when `Accept` contains `text/markdown`.

## 1.13.0 — 2026-08-19

### New

- **Markdown by content negotiation** (off by default). Agents ask for markdown with an `Accept` header on the URL they were already fetching; the `.md` mirror only helps a client that already knows it exists. Testing against a live CDN-fronted site found the CDN answering that header with its own whole-page conversion — nav chrome, "Skip to content", meta-derived frontmatter, 30% larger than the plugin's output. Serving markdown from the origin takes precedence, so the canonical URL now returns the same clean markdown as the `.md` endpoint. Accept parsing is deliberately strict, because getting it wrong serves markdown to a person: markdown must be named explicitly and outrank HTML, a wildcard counts only towards HTML, and a tie goes to HTML. `Vary: Accept` is sent on both representations, not just the markdown one.
- **Agent request log** (off by default). Records which agents fetch the plugin's files, and what they asked for, into the Activity Log plugin under the object type "Agent-Ready". Written from the plugin's own serve points, so an ordinary HTML page view does no work at all. An optional sub-setting additionally records page views from recognized AI crawlers — the denominator, without which the log shows who used these files but not who arrived and ignored them. The same agent, file and IP is recorded at most once per five minutes, so a crawler looping on one URL cannot fill the table.


## 1.12.1 — 2026-08-19

### Fixed

- Directives entered in **Additional Rules** are now appended at the very end of the `robots_txt` filter chain (`PHP_INT_MAX`) rather than with the AI-crawler rules at priority 99, so nothing that runs later can rewrite or remove them. The concrete failure: Yoast's `remove_default_robots()` calls `preg_replace()` against a `User-agent: * / Disallow: /wp-admin/ / Allow: /wp-admin/admin-ajax.php` block with no `$limit` argument, so it strips *every* match in the document rather than only the copy WordPress core emitted. An owner who pasted those three lines into Additional Rules in core's line order had them silently deleted from the served file — while this plugin's own settings preview continued to show them, because Yoast's robots.txt integration is gated to front-end requests and never runs in wp-admin. Silent loss confirmed by a preview showing the opposite is the part worth fixing; running last removes the whole class of problem rather than working around this one plugin.
- The new pass is registered ahead of the other `PHP_INT_MAX` passes, so an owner-supplied `Sitemap:` line still suppresses the automatic one, and `MMSAR_Robots_Allow`'s endpoint carve-outs still apply to user-defined groups.
- Extra rules are honored on `blog_public = 0` sites exactly as before. Withholding rules this plugin invented on a site set to discourage crawlers is deliberate; dropping the owner's own text never was.

## 1.12.0 — 2026-08-13

### Added

- `robots.txt` now carries an `Llms-txt:` directive pointing at this site's `/llms.txt`. The gap it closes: a site could publish an llms.txt and name it in `/.well-known/api-catalog` and the Agent Skills index, while saying nothing about it in the file most agents and agent-readiness checkers fetch first. There is no ratified robots.txt directive for llms.txt — the llms.txt proposal says to link the file from your homepage and does not mention robots.txt — but RFC 9309 parsers skip top-level directives they do not recognize, so the line cannot affect crawling for anyone.
- A `Link: <https://example.com/llms.txt>; rel="describedby"; type="text/plain"` header on every front-end response, next to the existing `api-catalog` and Agent Skills headers. The relation and media type match how the api-catalog already lists llms.txt, so an agent reading headers and an agent reading the catalog get the same answer. `llms-full.txt` is deliberately left to the catalog rather than adding a second header to every page view.
- Feature toggles now flush rewrite rules no matter how the `mmsar_features` option is written. The settings page already handled this from inside its sanitize callback, which only runs for saves that go through the Settings API; a write from WP-CLI, another plugin, or an ability added later left the old rules in place, so an endpoint whose feature had just been switched off kept serving while the documents correctly stopped advertising it. `add_option_mmsar_features` / `update_option_mmsar_features` now raise the same flag, and the settings page raises it through the same helper so the two paths cannot drift apart.
- The pending-flush flag lives for a day instead of a minute. The flush can only run on a later request — rules for the current one are already registered — and a minute is long enough for the settings page's own redirect but not for a site whose features were changed by WP-CLI and that then sees no traffic, where the flag would expire and the flush would silently never happen.
- Both new outputs are gated on the `llms_txt` feature, so neither can advertise a switched-off endpoint. The robots.txt directive additionally returns early on `blog_public = 0`, matching the AI-crawler rules, and skips itself when the assembled robots.txt already mentions `llms.txt` — a line added by hand in the Additional Rules field survives the update instead of being duplicated. It is registered at `PHP_INT_MAX` after `MMSAR_Robots_Allow::filter()` so that parser never sees the new directive.

## 1.11.0 — 2026-08-12

### Fixed

- An endpoint published in `/.well-known/api-catalog`, `llms.txt` and the Agent Skills index is no longer contradicted by a `Disallow` rule in the same site's `robots.txt`. The plugin now adds an `Allow:` line for the individual endpoint path, in the same user-agent group as the rule that blocks it and above that rule, so a compliant parser applies the more specific rule: the advertised action stays reachable while the broader path stays disallowed. The case that prompted this was `/wp-json/`, which Yoast SEO disallows by default via its `deny_wp_json_crawling` option — a site could describe a REST endpoint in three documents an agent reads before acting, and tell that same agent to stay off the path in a fourth.
- The paths come from `MMSAR_Registry::get_endpoints()`, the same list that feeds those three documents, so endpoints managed on the settings page and endpoints registered in code both get the treatment and no path is named twice in two places to drift apart.
- The rules being overridden are written by other plugins, so the check runs against the assembled document at `PHP_INT_MAX` — the same reason the `Sitemap:` directive is added there. Nothing is emitted for an endpoint that no rule blocks, one already allowed by an equally specific rule, one on another host, or the site root; on `blog_public = 0` the file is left untouched, matching the existing AI-crawler-rule behavior.

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

- Registrations are validated before publication and dropped if they cannot be published safely: non-`http(s)` targets, missing title or URL, unrecognized link relations and HTTP methods, and invented media types. Text fields are flattened to a single line and markdown link/code syntax is escaped, so a description carrying a newline or `[link](…)` cannot forge a heading, a list item, or a link in `llms.txt` or `SKILL.md`. A media type is never guessed — an unstated one is omitted rather than assumed to be `application/json`, keeping the promise that a stated type is the type the endpoint really returns.
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
- Change: `/robots.txt` no longer appends `Allow: /` rules for AI crawlers when the site is set to discourage search engines (`blog_public = 0`). WordPress emits a blanket `Disallow: /` in that mode, and overriding it for AI bots contradicted the admin's explicit intent. The owner's own extra rules are still honored.
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
- Admin copy corrected during live testing: the override of a physical `robots.txt` was described as unconditional, but testing on the nginx-based Local clone showed the static file still wins, because nginx serves an existing file without ever consulting WordPress. It works on Apache. The copy now says so instead of promising behavior that fails on most modern stacks. The read-only robots.txt preview claimed to be "exactly what gets served"; on this site Yoast strips the core block on front-end requests only, so the preview and the served file genuinely differ. Softened accordingly.

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
