<?php
/**
 * Make My Site Agent-Ready — content negotiation self-check.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies from outside a request whether markdown content negotiation is safe on this site.
 *
 * Content negotiation is correct by the standards and still dangerous in practice, for one reason:
 * a shared cache that does not include `Accept` in its cache key will store the markdown response
 * and hand it to the next visitor asking for the same URL, so a reader gets a file download instead
 * of the page. That was why the feature was withdrawn in 1.15.0 — not because it was wrong, but
 * because nothing could tell the owner whether their host did this.
 *
 * It is detectable, and this class detects it: request one URL asking for markdown, then request
 * the very same URL as a browser would, and see which representation comes back. That is exactly
 * the reproduction that found the problem by hand, performed by the plugin instead of a person.
 *
 * Two things this deliberately does not claim:
 *
 * - A pass means no problem was found from here, not that none exists. The request leaves this
 *   server and may or may not traverse the same edge cache a distant reader hits.
 * - The URL is always cache-busted with a query argument that nothing else uses. Probing a real
 *   permalink would risk filling a shared cache with a markdown copy of a page people read — the
 *   check would cause the failure it is looking for.
 */
class MMSAR_Negotiation_Check {

	/**
	 * Query argument used to cache-bust the probe and mark the request as our own.
	 */
	const ARG = 'mmsar_check';

	/**
	 * Option holding the last result.
	 */
	const OPTION = 'mmsar_negotiation_check';

	/**
	 * Transient set when the feature is switched on, so the check runs once the option is live.
	 */
	const PENDING = 'mmsar_negotiation_check_pending';

	/**
	 * Transient carrying the outcome of an automatic run through to the next screen render.
	 */
	const NOTICE = 'mmsar_negotiation_check_notice';

	/**
	 * `Accept` a browser sends. Chrome's, near enough — markdown is not in it at any weight.
	 */
	const ACCEPT_BROWSER = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';

	/**
	 * `Accept` a markdown-preferring agent sends, in the shape Anthropic's WebFetch uses.
	 */
	const ACCEPT_MARKDOWN = 'text/markdown,text/plain;q=0.9,text/html;q=0.8,*/*;q=0.5';

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_mmsar_negotiation_check', array( __CLASS__, 'handle_run' ) );
		// Late, so the saved option is already live: the check is meaningless until the feature it
		// is checking is actually serving.
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_pending' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'auto_check_notice' ) );
	}

	/**
	 * Flags that a check should run on the next admin request.
	 *
	 * Called from the settings sanitizer, which runs *before* the new option value is stored — the
	 * feature is not serving yet at that point, so running the check there would always report it
	 * inactive. Deferring to the next request is what makes the automatic check on enable work.
	 *
	 * @return void
	 */
	public static function schedule() {
		set_transient( self::PENDING, 1, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Runs a flagged check, and switches the feature back off if the check condemns it.
	 *
	 * @return void
	 */
	public static function maybe_run_pending() {
		if ( ! get_transient( self::PENDING ) ) {
			return;
		}
		delete_transient( self::PENDING );

		if ( ! mmsar_feature_enabled( 'markdown_negotiation' ) ) {
			return;
		}

		$result = self::run();
		if ( 'fail' === $result['status'] ) {
			self::disable_feature();
		}
		// The automatic run happens on the request that renders the settings screen, and its result
		// section sits well below the fold. Somebody who just ticked a box and saved needs to be
		// told at the top of the page — especially when the answer was to untick it again.
		set_transient( self::NOTICE, $result['status'], MINUTE_IN_SECONDS );
	}

	/**
	 * Reports the outcome of an automatic check once, at the top of the screen.
	 *
	 * @return void
	 */
	public static function auto_check_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = get_transient( self::NOTICE );
		if ( ! $status ) {
			return;
		}
		delete_transient( self::NOTICE );

		$link = '<a href="#mmsar-section-negotiation">' . esc_html__( 'See the full result', 'make-my-site-agent-ready' ) . '</a>';

		if ( 'fail' === $status ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Make My Site Agent-Ready: markdown content negotiation has been switched back off. The check found that a browser-style request for a page was answered with markdown, which means a cache in front of this site would serve markdown files to your visitors.', 'make-my-site-agent-ready' );
			echo ' ' . wp_kses_post( $link ) . '</p></div>';
			return;
		}

		if ( 'foreign' === $status ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'Make My Site Agent-Ready: markdown content negotiation is on, but the markdown coming back is not the markdown this plugin generates — something in front of your site, most likely a CDN, is converting pages itself.', 'make-my-site-agent-ready' );
			echo ' ' . wp_kses_post( $link ) . '</p></div>';
			return;
		}

		if ( 'pass' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Make My Site Agent-Ready: markdown content negotiation is on, and the check found it working — markdown for agents, HTML for browsers.', 'make-my-site-agent-ready' );
			echo ' ' . wp_kses_post( $link ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>';
		esc_html_e( 'Make My Site Agent-Ready: markdown content negotiation is on, but the check did not come back clean.', 'make-my-site-agent-ready' );
		echo ' ' . wp_kses_post( $link ) . '</p></div>';
	}

	/**
	 * Switches the negotiation feature off.
	 *
	 * The one automatic change this plugin makes to the owner's settings, and it is limited to the
	 * single condition where leaving it on is known to be serving files to readers. It is written
	 * back into the same option the settings screen writes, so the toggle reflects reality.
	 *
	 * @return void
	 */
	private static function disable_feature() {
		$features = get_option( 'mmsar_features', array() );
		if ( ! is_array( $features ) ) {
			$features = array();
		}
		$features['markdown_negotiation'] = '0';
		update_option( 'mmsar_features', $features );
	}

	/**
	 * Handles the "Run the check" button.
	 *
	 * A nonce-protected link rather than a form, because the settings screen is already one big
	 * form posting to options.php and a nested form is not valid HTML.
	 *
	 * @return void
	 */
	public static function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
		}
		if ( ! isset( $_GET['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['mmsar_nonce'] ) ), 'mmsar_negotiation_check' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
		}

		$result = self::run();
		if ( 'fail' === $result['status'] ) {
			self::disable_feature();
		}

		// Straight back to the section, which renders the result that was just stored. No notice
		// transient here, unlike the automatic run: the anchor puts the answer under the cursor.
		wp_safe_redirect(
			add_query_arg( 'page', 'make-my-site-agent-ready', admin_url( 'options-general.php' ) )
			. '#mmsar-section-negotiation'
		);
		exit;
	}

	/**
	 * The nonce-protected URL that runs the check.
	 *
	 * @return string
	 */
	public static function run_url() {
		return wp_nonce_url(
			add_query_arg( 'action', 'mmsar_negotiation_check', admin_url( 'admin-post.php' ) ),
			'mmsar_negotiation_check',
			'mmsar_nonce'
		);
	}

	/**
	 * The last stored result, or an empty array if the check has never run.
	 *
	 * @return array
	 */
	public static function get_result() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * A published post of an enabled type to probe, or null if there is none.
	 *
	 * Returns the post rather than just its URL because the check needs the markdown this plugin
	 * *would* serve for it, to tell that output apart from somebody else's — see run().
	 *
	 * @return WP_Post|null
	 */
	public static function test_post() {
		$posts = get_posts(
			array(
				'post_type'        => mmsar_get_enabled_post_types(),
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);
		return empty( $posts ) ? null : $posts[0];
	}

	/**
	 * The markdown this plugin would serve for a post — the same bytes maybe_serve_negotiated() sends.
	 *
	 * @param WP_Post $post Post to render.
	 * @return string
	 */
	private static function expected_markdown( $post ) {
		$markdown = get_post_meta( $post->ID, '_llmmd_content', true );
		if ( empty( $markdown ) ) {
			$markdown = MMSAR_Converter::convert_post( $post->ID );
		}
		return (string) $markdown;
	}

	/**
	 * Performs the two probes and stores what came back.
	 *
	 * Order is load-bearing. The markdown request goes first so that if a shared cache stores that
	 * response, the browser-style request that follows is the one that would be handed the stored
	 * copy — which is precisely the failure being tested for. Reversing them would fill the cache
	 * with HTML and hide it.
	 *
	 * @return array Result array with status, message, and the observed headers.
	 */
	public static function run() {
		$post = self::test_post();
		if ( ! $post ) {
			return self::store(
				'error',
				__( 'There is no published post or page to test with. Publish something first, then run the check again.', 'make-my-site-agent-ready' ),
				array()
			);
		}
		$url = (string) get_permalink( $post->ID );

		// A fresh value every run: the point is to test an entry no cache has seen before.
		$probe_url = add_query_arg( self::ARG, wp_generate_password( 12, false ), $url );

		$markdown_response = self::request( $probe_url, self::ACCEPT_MARKDOWN );
		$browser_response  = self::request( $probe_url, self::ACCEPT_BROWSER );

		if ( is_wp_error( $markdown_response ) || is_wp_error( $browser_response ) ) {
			$error = is_wp_error( $markdown_response ) ? $markdown_response : $browser_response;
			return self::store(
				'error',
				sprintf(
					/* translators: %s: HTTP error message */
					__( 'The check could not reach this site from itself: %s. That is usually a host blocking loopback requests, and says nothing either way about content negotiation.', 'make-my-site-agent-ready' ),
					$error->get_error_message()
				),
				array()
			);
		}

		$markdown_type = self::header( $markdown_response, 'content-type' );
		$browser_type  = self::header( $browser_response, 'content-type' );
		$cache_control = self::header( $markdown_response, 'cache-control' );
		$vary          = self::header( $markdown_response, 'vary' );
		$details       = array(
			'url'           => $probe_url,
			'markdown_type' => $markdown_type,
			'browser_type'  => $browser_type,
			'cache_control' => $cache_control,
			'vary'          => $vary,
		);

		$served_markdown = false !== stripos( $markdown_type, 'markdown' );
		$browser_got_md  = false !== stripos( $browser_type, 'markdown' );

		// The condition the feature was withdrawn over, and the only one that switches it back off.
		if ( $browser_got_md ) {
			return self::store(
				'fail',
				__( 'A browser-style request for the same URL was answered with markdown. Something between this site and its readers is caching the markdown response and serving it to everyone, ignoring the Vary: Accept header — Cloudflare is known to do this. Content negotiation has been switched back off, because on this site it hands visitors a file instead of a page.', 'make-my-site-agent-ready' ),
				$details
			);
		}

		if ( ! $served_markdown ) {
			return self::store(
				'inactive',
				__( 'A request preferring markdown came back as HTML. Negotiation is not reaching the client — either something in front of this site is serving a cached page, or the request never got to WordPress. Agents will still find the .md URLs, so nothing is broken; this feature is simply not doing anything here.', 'make-my-site-agent-ready' ),
				$details
			);
		}

		// Markdown came back and the browser got HTML — but "markdown came back" is not the same as
		// "this plugin answered". A CDN can convert the page to markdown at the edge on seeing the
		// same Accept header, and Cloudflare does exactly that on at least one host, which made an
		// earlier version of this check report the feature working while it was switched off. The
		// only claim that survives a CDN in the middle is the bytes: negotiation serves the same
		// markdown as the .md endpoint, so anything else is somebody else's conversion.
		if ( trim( wp_remote_retrieve_body( $markdown_response ) ) !== trim( self::expected_markdown( $post ) ) ) {
			return self::store(
				'foreign',
				__( 'Something between this site and the outside world is answering markdown requests with its own conversion of the page, not the markdown this plugin generates. That is usually a CDN — Cloudflare converts pages to markdown at the edge when it sees this header. Agents are getting markdown, so nothing is broken, but it is not your version: edge conversions typically carry the site navigation and skip links, and miss the frontmatter this plugin writes. Switching this feature on may or may not take precedence; run the check again afterwards to find out. Your .md addresses are unaffected either way.', 'make-my-site-agent-ready' ),
				$details
			);
		}

		// Markdown for markdown, HTML for the browser, and it is this plugin's markdown. The
		// remaining question is whether the safety net survives the trip: the response is sent `no-store` precisely so a cache that
		// ignores Vary still cannot keep it, and at least one host rewrites that in transit.
		$no_store = false !== stripos( $cache_control, 'no-store' );
		$varies   = false !== stripos( $vary, 'accept' );
		$missing  = array();
		if ( ! $no_store ) {
			$missing[] = __( 'Cache-Control no longer says no-store', 'make-my-site-agent-ready' );
		}
		if ( ! $varies ) {
			$missing[] = __( 'Vary: Accept is missing', 'make-my-site-agent-ready' );
		}

		if ( $missing ) {
			return self::store(
				'warn',
				sprintf(
					/* translators: %s: list of headers that did not survive */
					__( 'Negotiation works — markdown for agents, HTML for browsers — but the headers that keep the two apart were altered on the way out (%s). Something is rewriting this site\'s response headers, so the protection against a cache serving markdown to a visitor is not in place. Watch for readers getting a file instead of a page, and switch this off if it happens.', 'make-my-site-agent-ready' ),
					implode( '; ', $missing )
				),
				$details
			);
		}

		return self::store(
			'pass',
			__( 'Negotiation works: a markdown-preferring request got markdown, a browser-style request for the same URL got HTML, and both the Vary: Accept and no-store headers survived intact. This is a check from your own server, so it cannot see every cache between your site and a distant reader — keep an eye out for a page ever arriving as a file.', 'make-my-site-agent-ready' ),
			$details
		);
	}

	/**
	 * One probe request.
	 *
	 * @param string $url    URL to fetch.
	 * @param string $accept Accept header to send.
	 * @return array|WP_Error
	 */
	private static function request( $url, $accept ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Accept'        => $accept,
					'Cache-Control' => 'no-cache',
				),
				'user-agent'  => 'Make My Site Agent-Ready self-check',
			)
		);
	}

	/**
	 * A single response header as a string, whatever shape the HTTP API returns it in.
	 *
	 * @param array  $response Response.
	 * @param string $name     Header name.
	 * @return string
	 */
	private static function header( $response, $name ) {
		$value = wp_remote_retrieve_header( $response, $name );
		if ( is_array( $value ) ) {
			$value = implode( ', ', $value );
		}
		return (string) $value;
	}

	/**
	 * Stores and returns a result.
	 *
	 * @param string $status  One of pass, warn, fail, inactive, error.
	 * @param string $message Human-readable explanation.
	 * @param array  $details Observed values.
	 * @return array
	 */
	private static function store( $status, $message, $details ) {
		$result = array(
			'status'  => $status,
			'message' => $message,
			'details' => $details,
			'time'    => time(),
		);
		update_option( self::OPTION, $result, false );
		return $result;
	}
}
