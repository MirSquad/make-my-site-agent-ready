<?php
/**
 * Agent request log — records which agents fetch the surfaces this plugin publishes.
 *
 * Writes into the Activity Log plugin when it is active, so agent traffic appears alongside the
 * rest of the site's activity rather than in a separate place nobody opens.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent request log.
 */
class MMSAR_Agent_Log {

	/**
	 * How long the same agent, surface and IP is suppressed for, in seconds.
	 *
	 * A crawler can hit one URL repeatedly; the question this log answers is "which agents fetch
	 * what", not "how many times". Activity Log de-duplicates only within the same second, which is
	 * not enough on its own.
	 */
	const THROTTLE = 300;

	/**
	 * Option holding the plugin's own copy of the log, and how many entries it keeps.
	 *
	 * The log lives here rather than only in the Activity Log plugin because that plugin's API is
	 * not always loaded on front-end requests — on one live host it is available in wp-admin and
	 * absent when a visitor (or an agent) hits the site, so every entry was silently dropped at the
	 * point it mattered. Owning the data means the log works regardless, and can be shown on the
	 * screen where the setting lives. A capped option rather than a table keeps the plugin's
	 * promise of adding no database tables.
	 */
	const OPTION      = 'mmsar_agent_log';
	const MAX_ENTRIES = 200;

	/**
	 * User-agent fragments that identify a known agent or AI crawler, matched case-insensitively.
	 *
	 * Used only for the optional logging of ordinary HTML page views. The plugin's own endpoints do
	 * not consult this list — anything fetching those is agent traffic by definition.
	 */
	const AGENTS = array(
		'ClaudeBot',
		'Claude-User',
		'Claude-SearchBot',
		'Anthropic-AI',
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'PerplexityBot',
		'Perplexity-User',
		'Google-Extended',
		'GoogleOther',
		'Gemini',
		'Applebot-Extended',
		'meta-externalagent',
		'Bytespider',
		'CCBot',
		'cohere-ai',
		'DuckAssistBot',
		'Amazonbot',
		'YouBot',
		'Diffbot',
	);

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		// Ordinary page views are only inspected when the owner opts in, and even then the work is
		// one regex against the user-agent. Everything else this class records is triggered from a
		// serve point the plugin already owns, so a normal HTML request costs nothing at all.
		if ( '1' === get_option( 'mmsar_agent_log_pages', '' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_record_page_view' ), 20 );
		}
	}

	/**
	 * Whether logging is switched on and there is somewhere to write to.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return mmsar_feature_enabled( 'agent_log' );
	}

	/**
	 * The recorded entries, newest first.
	 *
	 * @return array[] Log entries.
	 */
	public static function get_entries() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Whether the Activity Log plugin's API is reachable from the current request.
	 *
	 * Reported on the settings screen, because "reachable in wp-admin" and "reachable on the front
	 * end" are different questions and only the second one matters for recording.
	 *
	 * @return bool
	 */
	public static function activity_log_available() {
		return function_exists( 'aal_insert_log' );
	}

	/**
	 * Records one agent request.
	 *
	 * Called from the plugin's serve points, which is why there is no user-agent test here: a
	 * request for llms.txt or a negotiated markdown response is agent traffic whatever it claims
	 * to be, and filtering on user-agent would hide exactly the clients worth knowing about.
	 *
	 * @param string $surface Human-readable name of what was served, e.g. 'llms.txt'.
	 * @return void
	 */
	public static function record( $surface ) {
		if ( ! self::is_active() ) {
			return;
		}

		$agent = self::agent_label();
		$ip    = self::client_ip();

		// Throttle before touching the database. The transient is only read for requests already
		// known to be agent-facing, so this never runs on an ordinary page view.
		$key = 'mmsar_al_' . md5( $agent . '|' . $surface . '|' . $ip );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, self::THROTTLE );

		// The plugin's own store is written first and unconditionally, so a log entry never depends
		// on another plugin being loaded for this request.
		$entries = self::get_entries();
		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'surface' => $surface,
				'agent'   => $agent,
				'ip'      => $ip,
			)
		);
		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ), false );

		// Mirrored into Activity Log when its API is present, so sites where it loads on the front
		// end get agent traffic alongside everything else. Absence is not an error.
		//
		// Database errors are suppressed for the duration of the call, and only for it. That plugin
		// writes to a table it owns and upgrades on its own schedule; a clone whose schema has not
		// caught up produces an "Unknown column" error on every insert, which with WP_DEBUG_DISPLAY
		// on would print into a response an agent or visitor is reading. The mirror is a convenience
		// — the entry is already safely stored above — so it must never be able to affect the page.
		if ( function_exists( 'aal_insert_log' ) ) {
			global $wpdb;
			$suppressed = $wpdb->suppress_errors( true );
			aal_insert_log(
				array(
					'action'         => 'requested',
					'object_type'    => 'Agent-Ready',
					'object_subtype' => $surface,
					'object_name'    => $agent,
					'object_id'      => 0,
					'user_id'        => 0,
					'hist_ip'        => $ip,
				)
			);
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Records a normal HTML page view, but only when the user-agent looks like a known agent.
	 *
	 * This is the optional half. It exists to supply the denominator: without it the log shows only
	 * the agents that asked for markdown, and "which agents ask for markdown" cannot be answered
	 * without also knowing which ones came and did not.
	 *
	 * @return void
	 */
	public static function maybe_record_page_view() {
		if ( is_admin() || is_feed() || ! self::is_active() ) {
			return;
		}

		$ua = self::user_agent();
		if ( '' === $ua ) {
			return;
		}

		$matched = '';
		foreach ( self::AGENTS as $needle ) {
			if ( false !== stripos( $ua, $needle ) ) {
				$matched = $needle;
				break;
			}
		}
		if ( '' === $matched ) {
			return;
		}

		// Record what it asked for as well as who it was — an agent taking HTML while the site
		// offers markdown is the interesting case, and it is invisible if only the format is logged.
		$wants = self::accept_summary();
		self::record( 'HTML page view (' . $wants . ')' );
	}

	/**
	 * A short label for the requesting agent: the matched agent name where recognised, otherwise a
	 * trimmed user-agent so unknown clients are still identifiable.
	 *
	 * @return string
	 */
	private static function agent_label() {
		$ua = self::user_agent();
		if ( '' === $ua ) {
			return 'unknown';
		}
		foreach ( self::AGENTS as $needle ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return $needle;
			}
		}
		return mb_substr( $ua, 0, 80 );
	}

	/**
	 * Whether the request asked for markdown, HTML, or expressed no preference. This is the field
	 * the whole log exists to collect.
	 *
	 * @return string
	 */
	private static function accept_summary() {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( '' === $accept ) {
			return 'no Accept';
		}
		if ( false !== stripos( $accept, 'markdown' ) ) {
			return 'asked for markdown';
		}
		if ( false !== stripos( $accept, 'text/html' ) ) {
			return 'asked for HTML';
		}
		return 'Accept: ' . mb_substr( $accept, 0, 40 );
	}

	/**
	 * User agent string for this request.
	 *
	 * @return string
	 */
	private static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	/**
	 * Client IP, preferring Cloudflare's header when present — behind a CDN, REMOTE_ADDR is the
	 * edge, so every agent would otherwise share one address and the throttle would collapse them.
	 *
	 * @return string
	 */
	private static function client_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		} else {
			return '';
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
