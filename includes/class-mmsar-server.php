<?php
/**
 * Make My Site Agent-Ready — server component.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR Server handler.
 */
class MMSAR_Server {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_markdown' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'prevent_redirect' ) );

		// Content negotiation is a separate opt-in on top of the .md URLs, because it changes what
		// an existing URL returns rather than adding a new one. Registered only when switched on,
		// so a site that leaves it off never runs any of it.
		if ( mmsar_feature_enabled( 'markdown_negotiation' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_negotiated' ), 1 );
		}
	}

	/**
	 * Add rewrite rules.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		// Negative lookahead excludes /.well-known/ paths and /auth.md — this broad catch-all is for
		// post/page .md URLs only, and would otherwise also match (and shadow) the plugin's own
		// markdown documents: /.well-known/agent-skills/*/SKILL.md and /auth.md. Relying on
		// registration order instead would be fragile, because every one of these is registered
		// 'top' and the winner is then whichever class happened to hook `init` last.
		add_rewrite_rule(
			'^(?!\.well-known/|auth\.md)(.+)\.md/?$',
			'index.php?llmmd_path=$matches[1]&llmmd_serve=1',
			'top'
		);
	}

	/**
	 * Add query vars.
	 *
	 * @param mixed $vars Vars.
	 * @return mixed Result.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'llmmd_path';
		$vars[] = 'llmmd_serve';
		return $vars;
	}

	/**
	 * Prevent WordPress's canonical redirect from interfering with markdown-serving requests.
	 *
	 * @param string $redirect_url The redirect URL WordPress proposes.
	 * @return string|false The redirect URL, or false to cancel the redirect for our requests.
	 */
	public static function prevent_redirect( $redirect_url ) {
		if ( get_query_var( 'llmmd_serve' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Serve markdown.
	 *
	 * @return void
	 */
	public static function serve_markdown() {
		if ( ! get_query_var( 'llmmd_serve' ) ) {
			return;
		}

		$post_id = self::resolve_post_id();
		if ( ! $post_id ) {
			self::not_found( __( 'There is no published page at this path.', 'make-my-site-agent-ready' ) );
		}

		$post = get_post( $post_id );

		// Defense in depth: only ever serve markdown for a published post. resolve_post_id() can reach
		// a post through url_to_postid() or get_page_by_path() (which returns any status), so a draft,
		// pending, private or trashed post could otherwise slip through even though its markdown is
		// never cached. Treat anything not published as not found.
		if ( ! $post || 'publish' !== $post->post_status ) {
			self::not_found( __( 'There is no published page at this path.', 'make-my-site-agent-ready' ) );
		}

		if ( ! empty( $post->post_password ) ) {
			status_header( 403 );
			echo '# 403 Forbidden' . "\n\n";
			echo esc_html__( 'This content is password protected.', 'make-my-site-agent-ready' ) . "\n";
			exit;
		}

		if ( ! in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
			self::not_found( __( 'That page exists, but this site does not publish Markdown for its content type. Fetch the HTML page instead.', 'make-my-site-agent-ready' ) );
		}

		$markdown = get_post_meta( $post_id, '_llmmd_content', true );

		if ( empty( $markdown ) ) {
			$markdown = MMSAR_Converter::convert_post( $post_id );
			if ( ! empty( $markdown ) ) {
				update_post_meta( $post_id, '_llmmd_content', $markdown );
			}
		}

		if ( empty( $markdown ) ) {
			self::not_found( __( 'That page exists but has no text content to return.', 'make-my-site-agent-ready' ) );
		}

		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		// The resolved permalink path, not REQUEST_URI. Every alias that reached this post — the
		// `.md` suffix, content negotiation on the canonical URL, a trailing-slash variant — now
		// records one identical value, so a sweep shows up as one row per post rather than one row
		// per spelling. It is also drawn from the site's own published content rather than from the
		// caller, which is what makes it safe to key the throttle on; see record()'s docblock for
		// why a 404 path is not.
		MMSAR_Agent_Log::record( 'Markdown (.md URL)', MMSAR_Agent_Log::request_path( get_permalink( $post_id ) ), true );
		header( 'Link: <' . esc_url( get_permalink( $post_id ) ) . '>; rel="canonical"' );
		MMSAR_LLMs_Txt::send_link_header();
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw markdown as text/markdown, not HTML.
		echo $markdown;
		exit;
	}

	/**
	 * End the request with a Markdown 404 that says where to look instead.
	 *
	 * A client that asked for `/nothing-here.md` has already shown it prefers Markdown and is
	 * probably not a person, so the body is worth spending on recovery links rather than on the
	 * single sentence this used to return. The body comes from MMSAR_Not_Found so a missing `.md`
	 * URL and a missing HTML URL give an agent the same instructions.
	 *
	 * Does not return.
	 *
	 * @param string $reason One sentence on why there is nothing here.
	 * @return void
	 */
	private static function not_found( $reason ) {
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		// A missing `.md` URL was recorded nowhere at all until 1.24.0. 1.23.0 added path logging to
		// both branches of MMSAR_Not_Found, but this is a third branch: a `.md` request that
		// resolves to no published post exits here, before the general 404 handler ever runs. So the
		// one 404 an agent is most likely to generate against this plugin — guessing at a `.md`
		// address — was the one it could not see. The path is caller-supplied, so it is not part of
		// the throttle key, exactly as the other two 404 surfaces do it.
		MMSAR_Agent_Log::record( '404 (markdown)', MMSAR_Agent_Log::request_path() );
		MMSAR_LLMs_Txt::send_link_header();
		status_header( 404 );

		// The recovery links are the 404 feature's job, and it can be switched off. Fall back to the
		// bare message rather than to nothing, so this response is never empty.
		if ( mmsar_feature_enabled( 'agent_404' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw markdown as text/markdown, not HTML.
			echo MMSAR_Not_Found::body( '404 Not Found', $reason );
		} else {
			echo '# 404 Not Found' . "\n\n";
			echo esc_html( $reason ) . "\n";
		}
		exit;
	}

	/**
	 * Resolve post id.
	 *
	 * @return mixed Result.
	 */
	private static function resolve_post_id() {
		$path = get_query_var( 'llmmd_path' );
		if ( empty( $path ) ) {
			return 0;
		}

		if ( 'index' === $path ) {
			$front_page = get_option( 'page_on_front' );
			return $front_page ? (int) $front_page : 0;
		}

		$post_id = url_to_postid( home_url( '/' . $path . '/' ) );
		if ( $post_id ) {
			return $post_id;
		}

		$post_id = url_to_postid( home_url( '/' . $path ) );
		if ( $post_id ) {
			return $post_id;
		}

		$post = get_page_by_path( $path, OBJECT, mmsar_get_enabled_post_types() );
		if ( $post ) {
			return $post->ID;
		}

		return 0;
	}

	// -------------------------------------------------------------------------
	// Content negotiation on the canonical URL
	// -------------------------------------------------------------------------

	/**
	 * Serves markdown from the canonical post URL when the request asks for it via `Accept`.
	 *
	 * This is the surface agents actually reach. A crawler or fetch tool requesting a page sends
	 * one request to the canonical URL; the `.md` mirror only helps something that already knows
	 * the mirror exists. Anthropic's WebFetch, for one, sends an `Accept` header preferring
	 * Markdown precisely so a server can answer in Markdown without a second round trip.
	 *
	 * What this method can and cannot guarantee is the whole story of this feature. It declares
	 * `Vary: Accept` on both representations and marks the markdown one uncacheable, which is
	 * exactly what the standards provide for; whether anything between here and the reader honors
	 * either header is not observable from inside a request. It is observable from outside one,
	 * which is what MMSAR_Negotiation_Check exists to do.
	 *
	 * @return void
	 */
	public static function maybe_serve_negotiated() {
		if ( is_admin() || is_feed() || is_embed() || ! is_singular( mmsar_get_enabled_post_types() ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return;
		}

		// The HTML representation has to advertise that it varies too, or a shared cache is entitled
		// to serve a stored HTML copy to a Markdown-preferring request and vice versa. Sent on both
		// branches, and before the decision, so it goes out either way.
		header( 'Vary: Accept', false );

		if ( ! self::prefers_markdown() ) {
			return;
		}

		$markdown = get_post_meta( $post->ID, '_llmmd_content', true );
		if ( empty( $markdown ) ) {
			$markdown = MMSAR_Converter::convert_post( $post->ID );
			if ( ! empty( $markdown ) ) {
				update_post_meta( $post->ID, '_llmmd_content', $markdown );
			}
		}
		// Nothing to serve is not an error here: fall through and let WordPress render the page
		// normally, rather than turning a working HTML request into a 404.
		if ( empty( $markdown ) ) {
			return;
		}

		// The self-check's own requests are traffic this site made to itself. Recording them would
		// put a row in the owner's log for every check they run, attributed to their own server.
		if ( ! self::is_self_check_request() ) {
			MMSAR_Agent_Log::record( 'Markdown (content negotiation)', MMSAR_Agent_Log::request_path( get_permalink( $post->ID ) ), true );
		}

		// Ask that no shared cache store this representation. `Vary: Accept` is advisory, and a
		// cache that ignores it hands a stored markdown response to the next visitor who asks for
		// the same URL — a person gets a file download instead of the page. This header is the
		// second line of defence against that, and it is a request, not a guarantee: at least one
		// host has been observed rewriting it in transit, so what the origin sends is not
		// necessarily what reaches the edge. The check screen reports what actually arrives.
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Link: <' . esc_url( get_permalink( $post->ID ) ) . '>; rel="canonical"' );
		MMSAR_LLMs_Txt::send_link_header();
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw markdown as text/markdown, not HTML.
		echo $markdown;
		exit;
	}

	/**
	 * Whether this request is one of the negotiation self-check's own probes.
	 *
	 * The check appends a query argument both as a cache-buster — it must never fill a shared
	 * cache with a markdown copy of a URL real visitors use — and as a marker, so the response it
	 * triggers can be kept out of the agent log.
	 *
	 * @return bool
	 */
	private static function is_self_check_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Front-end request marker, not a state change.
		return isset( $_GET[ MMSAR_Negotiation_Check::ARG ] );
	}

	/**
	 * Whether the request's `Accept` header prefers Markdown over HTML.
	 *
	 * Deliberately strict, because getting this wrong serves Markdown to a browser. Markdown must be
	 * named explicitly and outrank HTML: a wildcard counts towards HTML but never towards Markdown,
	 * so the browsers and bots that accept anything keep getting HTML, and a tie goes to HTML
	 * because that is the representation a human reader expects.
	 *
	 * @return bool True when Markdown is explicitly preferred over HTML.
	 */
	private static function prefers_markdown() {
		$header = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( '' === $header ) {
			return false;
		}

		$markdown_q = -1.0;
		$html_q     = -1.0;

		foreach ( explode( ',', $header ) as $part ) {
			$bits = explode( ';', $part );
			$type = strtolower( trim( array_shift( $bits ) ) );
			if ( '' === $type ) {
				continue;
			}

			$q = 1.0;
			foreach ( $bits as $param ) {
				$param = strtolower( trim( $param ) );
				if ( 0 === strpos( $param, 'q=' ) ) {
					$q = (float) substr( $param, 2 );
				}
			}

			if ( 'text/markdown' === $type || 'text/x-markdown' === $type ) {
				$markdown_q = max( $markdown_q, $q );
			} elseif ( 'text/html' === $type || 'text/*' === $type || '*/*' === $type ) {
				$html_q = max( $html_q, $q );
			}
		}

		return $markdown_q > 0 && $markdown_q > $html_q;
	}
}
