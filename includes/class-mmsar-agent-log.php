<?php
/**
 * Agent request log — records which agents fetch the surfaces this plugin publishes.
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
	 * The question this log answers is "which agents fetch what", not "how many times". Without a
	 * throttle a single crawler looping on one URL would drown out everything else.
	 */
	const THROTTLE = 300;

	/**
	 * Schema version. Bump to trigger dbDelta on the next load.
	 */
	const DB_VERSION = 3;

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'mmsar_agent_log_db_version';

	/**
	 * Option holding the retention limit. 0 means keep everything.
	 */
	const LIMIT_OPTION = 'mmsar_agent_log_limit';

	/**
	 * Option the log used before 1.16.0, when entries lived in a capped array.
	 */
	const LEGACY_OPTION = 'mmsar_agent_log';

	/**
	 * Option used to claim the one-time migration.
	 *
	 * Created with add_option(), which is an INSERT against a unique index, so exactly one caller
	 * can succeed. That
	 * gives a lock that holds across concurrent requests, which a read-then-write guard does
	 * not: two requests arriving during the same upgrade both read the legacy option before either
	 * deleted it, and both wrote its contents into the table. Every migrated entry appeared twice.
	 */
	const MIGRATED_FLAG = 'mmsar_agent_log_migrated';

	/**
	 * How often pruning runs, as one prune per N inserts.
	 *
	 * Trimming on every insert would add a COUNT and a DELETE to requests that are already doing
	 * the useful work. The log is allowed to overshoot its limit by up to this many rows between
	 * prunes, which nobody can observe and which costs one extra row of storage each.
	 */
	const PRUNE_EVERY = 50;

	/**
	 * User-agent fragments identifying a known agent or AI crawler, matched case-insensitively.
	 *
	 * Used only when logging ordinary page views. The plugin's own endpoints do not consult this
	 * list — anything fetching those is agent traffic by definition.
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
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );

		// Ordinary page views are only inspected when the owner opts in, and even then the work is
		// one pass over the user-agent. Everything else is triggered from a serve point the plugin
		// already owns, so a normal HTML request costs nothing at all.
		if ( '1' === get_option( 'mmsar_agent_log_pages', '' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_record_page_view' ), 20 );
		}
	}

	/**
	 * The log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mmsar_agent_log';
	}

	/**
	 * Creates or upgrades the table when the stored schema version is behind.
	 *
	 * Runs from an option read on every load, which is a cached lookup, rather than only on
	 * activation — updating a plugin's files in place does not re-fire the activation hook, so a
	 * schema added in an update would otherwise never be created on an existing install.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one space around types.
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at datetime NOT NULL,
			surface varchar(100) NOT NULL DEFAULT '',
			detail varchar(190) NOT NULL DEFAULT '',
			agent varchar(120) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			verified varchar(12) NOT NULL DEFAULT '',
			verified_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY logged_at (logged_at),
			KEY verified (verified)
			) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		self::migrate_legacy_entries();
	}

	/**
	 * Moves entries from the pre-1.16.0 option into the table, then removes the option.
	 *
	 * @return void
	 */
	private static function migrate_legacy_entries() {
		// Claim the migration before reading anything. Whichever request creates this option owns
		// the job; any other request racing it here stops now rather than importing the same rows
		// a second time.
		if ( ! add_option( self::MIGRATED_FLAG, time(), '', false ) ) {
			return;
		}

		$legacy = get_option( self::LEGACY_OPTION, array() );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			delete_option( self::LEGACY_OPTION );
			return;
		}

		global $wpdb;
		// Oldest first, so the table's ascending ids match the order the requests happened in.
		foreach ( array_reverse( $legacy ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration into this plugin's own table.
				self::table(),
				array(
					'logged_at' => isset( $entry['time'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) : current_time( 'mysql', true ),
					'surface'   => isset( $entry['surface'] ) ? (string) $entry['surface'] : '',
					'agent'     => isset( $entry['agent'] ) ? (string) $entry['agent'] : '',
					'ip'        => isset( $entry['ip'] ) ? (string) $entry['ip'] : '',
				),
				array( '%s', '%s', '%s', '%s' )
			);
		}

		delete_option( self::LEGACY_OPTION );
	}

	/**
	 * Whether logging is switched on.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return mmsar_feature_enabled( 'agent_log' );
	}

	/**
	 * The retention limit. 0 means keep everything.
	 *
	 * @return int
	 */
	public static function get_limit() {
		return absint( get_option( self::LIMIT_OPTION, 0 ) );
	}

	/**
	 * Total number of recorded entries.
	 *
	 * @return int
	 */
	public static function count_entries() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; a cached count would show a stale log.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * One page of entries, newest first.
	 *
	 * @param int    $per_page Rows per page.
	 * @param int    $offset   Rows to skip.
	 * @param string $verified Restrict to one verification verdict. Empty string means no filter,
	 *                         which is why 'pending' rather than '' addresses the unchecked rows.
	 * @return array[] Entries as associative arrays.
	 */
	public static function get_entries( $per_page = 50, $offset = 0, $verified = '' ) {
		global $wpdb;

		$where = self::verified_where( $verified );

		if ( null !== $where ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT logged_at, surface, detail, agent, ip, verified, verified_at FROM %i WHERE verified = %s ORDER BY id DESC LIMIT %d OFFSET %d',
					self::table(),
					$where,
					absint( $per_page ),
					absint( $offset )
				),
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT logged_at, surface, detail, agent, ip, verified, verified_at FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
				self::table(),
				absint( $per_page ),
				absint( $offset )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Translates a requested verdict filter into the value to match, or null for no filter.
	 *
	 * The unchecked state is stored as an empty string, and an empty string is also how a caller
	 * says "no filter", so the two need different spellings on the way in. 'pending' is the word
	 * for the first; anything unrecognised is treated as no filter rather than as a query for a
	 * verdict that cannot exist, which would silently return nothing.
	 *
	 * @param string $verified Requested filter.
	 * @return string|null Value to match, or null to apply no filter.
	 */
	private static function verified_where( $verified ) {
		$verified = (string) $verified;
		if ( '' === $verified ) {
			return null;
		}
		if ( 'pending' === $verified ) {
			return MMSAR_Agent_Log_Verify::PENDING;
		}
		return in_array( $verified, MMSAR_Agent_Log_Verify::verdicts(), true ) ? $verified : null;
	}

	/**
	 * Number of entries matching a verdict filter.
	 *
	 * @param string $verified Verdict filter, as accepted by get_entries().
	 * @return int
	 */
	public static function count_entries_verified( $verified = '' ) {
		$where = self::verified_where( $verified );
		if ( null === $where ) {
			return self::count_entries();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; a cached count would show a stale log.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE verified = %s', self::table(), $where )
		);
	}

	/**
	 * Whether the log table has been created yet.
	 *
	 * The table is only created once the agent log is switched on, so anything that queries it
	 * outside a serve path has to cope with it being absent. Checked with SHOW TABLES rather than
	 * by catching an error, so a site with the log off never emits a database error at all.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check for this plugin's own table; nothing to cache usefully.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Distinct (ip, agent) pairs that have not been verified yet, newest first.
	 *
	 * Grouped rather than listed per row because the work is one DNS resolution per address: this
	 * site's own log is 634 addresses across 1,584 rows, and a single crawler sweep can be 41 rows
	 * from 41 different addresses. Paired with the agent because the verdict is about a *claim* —
	 * the same address arriving as ClaudeBot and as GPTBot is two claims and can be one forgery
	 * and one genuine crawl.
	 *
	 * Newest first so a freshly arrived crawler is judged while its rDNS assignment is still the
	 * one it used, which is the verdict worth the most.
	 *
	 * @param int $limit Maximum pairs to return.
	 * @return array[] Rows of `ip` and `agent`.
	 */
	public static function get_unverified_pairs( $limit = 10 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would re-resolve rows already decided.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ip, agent, MAX(id) AS newest FROM %i
				WHERE verified = %s
				   OR ( verified = %s AND ( verified_at IS NULL OR verified_at < %s ) )
				GROUP BY ip, agent ORDER BY newest DESC LIMIT %d',
				self::table(),
				MMSAR_Agent_Log_Verify::PENDING,
				MMSAR_Agent_Log_Verify::NODNS,
				self::nodns_retry_cutoff(),
				max( 1, absint( $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The UTC datetime before which a `nodns` row is due another attempt.
	 *
	 * @return string `Y-m-d H:i:s`.
	 */
	private static function nodns_retry_cutoff() {
		return gmdate( 'Y-m-d H:i:s', time() - MMSAR_Agent_Log_Verify::RETRY_NODNS_AFTER );
	}

	/**
	 * Writes a verdict to every unverified row sharing an address and claimed agent.
	 *
	 * Scoped to rows still at the pending value, so a verdict reached now can never overwrite one
	 * reached earlier — an older row keeps the `verified_at` it was actually judged at, which is
	 * the whole point of storing that column.
	 *
	 * @param string $ip      Client IP.
	 * @param string $agent   Claimed agent.
	 * @param string $verdict Verdict to store.
	 * @return int Rows updated.
	 */
	public static function apply_verdict( $ip, $agent, $verdict ) {
		global $wpdb;

		// Writable states are exactly two: never judged, and judged `nodns` long enough ago to be
		// due another attempt. `verified`, `failed` and `unverifiable` are untouchable here, which
		// is what stops a later pass quietly rewriting a conclusion reached against better data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Annotating this plugin's own table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET verified = %s, verified_at = %s
				WHERE ip = %s AND agent = %s
				  AND ( verified = %s
				        OR ( verified = %s AND ( verified_at IS NULL OR verified_at < %s ) ) )',
				self::table(),
				mb_substr( (string) $verdict, 0, 12 ),
				current_time( 'mysql', true ),
				(string) $ip,
				(string) $agent,
				MMSAR_Agent_Log_Verify::PENDING,
				MMSAR_Agent_Log_Verify::NODNS,
				self::nodns_retry_cutoff()
			)
		);
		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * The verdicts a re-check reconsiders: the ones that mean "could not decide".
	 *
	 * `verified` and `failed` are deliberately absent. Both are conclusions, and re-running them
	 * risks overwriting a sound verdict reached against better data than today's — a `verified`
	 * from a live range file should not become `failed` because a bundled snapshot has since aged.
	 * The undecided two are the ones that turn into information when the suffix map or the range
	 * data improves, which is the only reason to re-check at all.
	 *
	 * @return string[]
	 */
	public static function recheckable_verdicts() {
		return array( MMSAR_Agent_Log_Verify::NODNS, MMSAR_Agent_Log_Verify::UNVERIFIABLE );
	}

	/**
	 * Distinct (ip, agent) pairs currently holding an undecided verdict.
	 *
	 * @return array[] Rows of `ip` and `agent`.
	 */
	public static function get_undecided_pairs() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT ip, agent FROM %i WHERE verified IN ( %s, %s )',
				self::table(),
				MMSAR_Agent_Log_Verify::NODNS,
				MMSAR_Agent_Log_Verify::UNVERIFIABLE
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Distinct (ip, agent) pairs whose verdict could actually come out differently today.
	 *
	 * Undecided is not the same as re-checkable, and conflating them offers a button that provably
	 * cannot change anything. Two cases qualify:
	 *
	 * - **`nodns`**, always. The resolver failing is a transient condition by definition, and this
	 *   is the one verdict the design promises to retry.
	 * - **`unverifiable`, but only where the claimed agent now has a method.** That verdict records
	 *   that this plugin knew no way to check the operator, so the only thing that can change it is
	 *   the plugin learning one. Where it still has not — `meta-externalagent`, `YouBot`,
	 *   `Bytespider`, `CCBot` at the time of writing — re-running produces `unverifiable` again,
	 *   every time, for as long as nobody publishes a method.
	 *
	 * The second test cannot be done in SQL: whether an operator is covered is a fact about the
	 * suffix map and the bundled range data, both of which live in PHP and are filterable. So the
	 * agents are pulled distinct and filtered here, which is a handful of rows rather than a scan.
	 *
	 * @return array[] Rows of `ip` and `agent`.
	 */
	public static function get_recheckable_pairs() {
		global $wpdb;

		// `unverifiable` only. `nodns` used to be in here, which is why a button reading
		// "Re-check 1" sat on screen permanently for an address with no reverse record: the button
		// was the only thing that ever retried that verdict. It is retried by the ordinary pass now
		// (see get_unverified_pairs), so the button is left with the one job a person is actually
		// needed for — reopening rows that became answerable because this plugin learned a new
		// operator, which is not something the plugin can detect about itself.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT ip, agent FROM %i WHERE verified = %s',
				self::table(),
				MMSAR_Agent_Log_Verify::UNVERIFIABLE
			),
			ARRAY_A
		);

		$pairs = array();
		foreach ( (array) $rows as $pair ) {
			$agent = isset( $pair['agent'] ) ? (string) $pair['agent'] : '';
			if ( MMSAR_Agent_Log_Verify::has_method( $agent ) ) {
				$pairs[] = $pair;
			}
		}
		return $pairs;
	}

	/**
	 * Agents holding an `unverifiable` verdict that this release still cannot check.
	 *
	 * Surfaced on screen so the absence of a re-check button is explained rather than merely
	 * observed: these entries are not stuck, they are answered, and the answer is "nobody publishes
	 * a way to confirm this crawler".
	 *
	 * @return array<string, int> Agent name => row count, busiest first.
	 */
	public static function get_uncheckable_agents() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, COUNT(*) AS requests FROM %i WHERE verified = %s GROUP BY agent ORDER BY requests DESC',
				self::table(),
				MMSAR_Agent_Log_Verify::UNVERIFIABLE
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$agent = isset( $row['agent'] ) ? (string) $row['agent'] : '';
			if ( '' !== $agent && ! MMSAR_Agent_Log_Verify::has_method( $agent ) ) {
				$out[ $agent ] = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
			}
		}
		return $out;
	}

	/**
	 * How many rows a re-check would actually reconsider.
	 *
	 * Counted per agent with a fixed set of placeholders rather than by building an `IN` list.
	 * A generated placeholder string is safe here — it is derived from a count, never from data —
	 * but it cannot be read as safe without tracing where the count comes from, and the WordPress.org
	 * review re-scans without honouring inline suppressions. The distinct re-checkable agents are a
	 * handful, so a short loop of fully static queries costs nothing and needs no explaining.
	 *
	 * @return int
	 */
	public static function count_recheckable() {
		global $wpdb;

		$total = 0;
		foreach ( self::recheckable_agents() as $agent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached count would be stale.
			$total += (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE verified = %s AND agent = %s',
					self::table(),
					MMSAR_Agent_Log_Verify::UNVERIFIABLE,
					$agent
				)
			);
		}
		return $total;
	}

	/**
	 * The distinct agents a re-check could answer differently.
	 *
	 * @return string[]
	 */
	private static function recheckable_agents() {
		$agents = array();
		foreach ( self::get_recheckable_pairs() as $pair ) {
			$agents[ (string) $pair['agent'] ] = true;
		}
		return array_keys( $agents );
	}

	/**
	 * Returns every undecided row to the pending state so a later pass judges it again.
	 *
	 * Scoped to the undecided verdicts, so a re-check can never disturb a `verified` or a `failed`.
	 * `verified_at` is cleared along with the verdict rather than kept: the row is about to be
	 * judged again, and leaving the old timestamp would date the new verdict to when the *previous*
	 * one was reached, which is the one thing that column exists to prevent.
	 *
	 * @return int Rows reset.
	 */
	public static function reset_undecided() {
		global $wpdb;

		$reset = 0;
		foreach ( self::recheckable_agents() as $agent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Annotating this plugin's own table.
			$rows   = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET verified = %s, verified_at = NULL WHERE verified = %s AND agent = %s',
					self::table(),
					MMSAR_Agent_Log_Verify::PENDING,
					MMSAR_Agent_Log_Verify::UNVERIFIABLE,
					$agent
				)
			);
			$reset += is_numeric( $rows ) ? (int) $rows : 0;
		}
		return $reset;
	}

	/**
	 * Counts per verdict across the whole log, plus how many rows are still undecided. /**
	 * Counts per verdict across the whole log, plus how many rows are still undecided.
	 *
	 * `pending` is the number that stops the rest being misread. Verdict counts over a partially
	 * verified log describe the part that has been checked and nothing else, and a reader with no
	 * pending count has no way to tell a quiet result from an unfinished one.
	 *
	 * @return array{counts: array<string, int>, pending: int, verified_first: string, verified_last: string}
	 */
	public static function get_verification_summary() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT verified, COUNT(*) AS requests FROM %i GROUP BY verified', self::table() ),
			ARRAY_A
		);

		$counts  = array_fill_keys( MMSAR_Agent_Log_Verify::verdicts(), 0 );
		$pending = 0;
		foreach ( (array) $rows as $row ) {
			$verdict = isset( $row['verified'] ) ? (string) $row['verified'] : '';
			$count   = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
			if ( MMSAR_Agent_Log_Verify::PENDING === $verdict ) {
				$pending += $count;
				continue;
			}
			if ( isset( $counts[ $verdict ] ) ) {
				$counts[ $verdict ] += $count;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$span = $wpdb->get_row(
			$wpdb->prepare( 'SELECT MIN(verified_at) AS first_at, MAX(verified_at) AS last_at FROM %i WHERE verified_at IS NOT NULL', self::table() ),
			ARRAY_A
		);

		return array(
			'counts'         => $counts,
			'pending'        => $pending,
			'verified_first' => isset( $span['first_at'] ) ? (string) $span['first_at'] : '',
			'verified_last'  => isset( $span['last_at'] ) ? (string) $span['last_at'] : '',
		);
	}

	/**
	 * The path of the current request, decoded and reduced to something safe to store.
	 *
	 * Shared rather than duplicated: a 404 path and a permalink path both end up in a column an
	 * administrator reads on screen and exports to CSV, and both want the same treatment. Lived in
	 * MMSAR_Not_Found until 1.24.0, which is where its reasoning is written up.
	 *
	 * The query string is dropped. It is rarely the interesting half of a URL, and leaving it out
	 * keeps arbitrary caller-supplied text — tracking parameters, injection attempts, scanner junk
	 * — out of that column.
	 *
	 * @param string $url Optional URL or path to reduce. Defaults to this request's own URI.
	 * @return string Leading-slash path, or '/' when there is nothing to report.
	 */
	public static function request_path( $url = '' ) {
		if ( '' === $url ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the request path for a log annotation, not a state change.
			$url = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		}
		if ( '' === $url ) {
			return '/';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return '/';
		}

		// Percent-decoded so the stored value is the path as the caller meant it, then stripped of
		// control characters, which is the actual hazard: this string is rendered on an admin screen
		// and written to a CSV export, and a decoded path can carry NUL, newlines or terminal escapes.
		//
		// Only control characters. Until 1.24.0 this stripped everything outside printable ASCII,
		// which was fine while the only caller was a 404 path but is wrong now that permalinks come
		// through here: on a site with accented or non-Latin slugs it would reduce /café/ and /cafè/
		// to the same value, and two different posts would share one by_detail row. Letters are not
		// the danger — esc_html() handles the screen and csv_cell() handles the spreadsheet.
		$path  = rawurldecode( $path );
		$clean = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		$path  = null === $clean ? '' : $clean;

		// Percent-decoding can produce a byte sequence that is not valid UTF-8, which would be
		// rejected on the way into a utf8mb4 column and lost entirely. Fall back to ASCII-only for
		// those rather than storing nothing.
		if ( '' !== $path && ! mb_check_encoding( $path, 'UTF-8' ) ) {
			$clean = preg_replace( '/[^\x20-\x7E]/', '', $path );
			$path  = null === $clean ? '' : $clean;
		}

		return '' === $path ? '/' : '/' . ltrim( $path, '/' );
	}

	/**
	 * A batch of entries older than a given id, newest first.
	 *
	 * Used for walking the whole log — an export, or anything else that reads every row. Paging by
	 * id rather than by OFFSET matters here because the log is appended to while the walk is in
	 * progress: with OFFSET, every row inserted mid-walk shifts the window and the reader sees a
	 * row twice or skips one. An id cursor addresses rows rather than positions, so newly appended
	 * rows are simply not part of the walk, and the query stays fast at any depth.
	 *
	 * @param int $before_id Return rows with a lower id than this. 0 starts from the newest row.
	 * @param int $limit     Maximum rows to return.
	 * @return array[] Entries as associative arrays, including id.
	 */
	public static function get_entries_before( $before_id = 0, $limit = 500 ) {
		global $wpdb;
		$before_id = absint( $before_id );
		$limit     = absint( $limit );

		if ( $before_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, logged_at, surface, detail, agent, ip, verified, verified_at FROM %i WHERE id < %d ORDER BY id DESC LIMIT %d',
					self::table(),
					$before_id,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, logged_at, surface, detail, agent, ip, verified, verified_at FROM %i ORDER BY id DESC LIMIT %d',
					self::table(),
					$limit
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Aggregate counts over the whole log.
	 *
	 * The log answers "which agents fetch what", and answering it from a page of raw rows means
	 * reading every page and tallying by hand. These are the tallies, computed by the database in
	 * one pass each, so a caller that only wants the shape of the traffic never has to pull the
	 * rows at all.
	 *
	 * Datetimes are UTC, as stored.
	 *
	 * @param int $top  Maximum rows in the by-agent, by-surface and by-detail breakdowns.
	 * @param int $days Maximum rows in the by-day breakdown, most recent first.
	 * @return array Aggregates.
	 */
	public static function get_summary( $top = 25, $days = 60 ) {
		global $wpdb;
		$table = self::table();
		$top   = max( 1, absint( $top ) );
		$days  = max( 1, absint( $days ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, COUNT(DISTINCT agent) AS unique_agents, COUNT(DISTINCT ip) AS unique_ips, MIN(logged_at) AS first_logged_at, MAX(logged_at) AS last_logged_at FROM %i',
				$table
			),
			ARRAY_A
		);

		// The verdict columns are the point of this breakdown as of 1.24.0. A `requests` figure on
		// its own is what the last analysis of this log had to work with, and it was wrong: GPTBot
		// looked like the best customer here at 74% of its requests hitting agent surfaces, and
		// most of those requests were a readiness scanner wearing its name. `verified` and `failed`
		// side by side on the same row is what makes that visible without cross-tabbing by hand.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_agent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agent, COUNT(*) AS requests, COUNT(DISTINCT surface) AS surfaces, COUNT(DISTINCT ip) AS unique_ips,
					SUM(CASE WHEN verified = 'verified' THEN 1 ELSE 0 END) AS verified,
					SUM(CASE WHEN verified = 'failed' THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN verified = 'unverifiable' THEN 1 ELSE 0 END) AS unverifiable,
					SUM(CASE WHEN verified = 'unclaimed' THEN 1 ELSE 0 END) AS unclaimed,
					SUM(CASE WHEN verified = 'nodns' THEN 1 ELSE 0 END) AS nodns,
					SUM(CASE WHEN verified = '' THEN 1 ELSE 0 END) AS pending,
					MIN(logged_at) AS first_seen, MAX(logged_at) AS last_seen
				FROM %i GROUP BY agent ORDER BY requests DESC, agent ASC LIMIT %d",
				$table,
				$top
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_surface = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT surface, COUNT(*) AS requests, COUNT(DISTINCT agent) AS agents, MIN(logged_at) AS first_seen, MAX(logged_at) AS last_seen FROM %i GROUP BY surface ORDER BY requests DESC, surface ASC LIMIT %d',
				$table,
				$top
			),
			ARRAY_A
		);

		// Only rows that carry a detail, because on every other surface the surface name already is
		// the whole request and a blank row here would say nothing. Grouped by the pair rather than
		// by detail alone: "/api/v2/products" means one thing under a 404 and another under an MCP
		// call, and merging them would invent a total that describes neither.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_detail = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT surface, detail, COUNT(*) AS requests, COUNT(DISTINCT agent) AS agents, MIN(logged_at) AS first_seen, MAX(logged_at) AS last_seen FROM %i WHERE detail <> '' GROUP BY surface, detail ORDER BY requests DESC, detail ASC LIMIT %d",
				$table,
				$top
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_day = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(logged_at) AS day, COUNT(*) AS requests, COUNT(DISTINCT agent) AS agents FROM %i GROUP BY day ORDER BY day DESC LIMIT %d',
				$table,
				$days
			),
			ARRAY_A
		);

		return array(
			'total'           => isset( $totals['total'] ) ? (int) $totals['total'] : 0,
			'unique_agents'   => isset( $totals['unique_agents'] ) ? (int) $totals['unique_agents'] : 0,
			'unique_ips'      => isset( $totals['unique_ips'] ) ? (int) $totals['unique_ips'] : 0,
			'first_logged_at' => isset( $totals['first_logged_at'] ) ? (string) $totals['first_logged_at'] : '',
			'last_logged_at'  => isset( $totals['last_logged_at'] ) ? (string) $totals['last_logged_at'] : '',
			'by_agent'        => self::int_columns( $by_agent, array( 'requests', 'surfaces', 'unique_ips', 'verified', 'failed', 'unverifiable', 'unclaimed', 'nodns', 'pending' ) ),
			'by_surface'      => self::int_columns( $by_surface, array( 'requests', 'agents' ) ),
			'by_detail'       => self::int_columns( $by_detail, array( 'requests', 'agents' ) ),
			'by_day'          => self::int_columns( $by_day, array( 'requests', 'agents' ) ),
		);
	}

	/**
	 * Casts the named columns of a result set to integers.
	 *
	 * MySQL hands back counts as numeric strings. Left alone they serialize into JSON as "12"
	 * rather than 12, which is the wrong type for an output schema that says integer.
	 *
	 * @param array[]  $rows    Result rows.
	 * @param string[] $columns Column names to cast.
	 * @return array[] Rows with those columns cast.
	 */
	private static function int_columns( $rows, $columns ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $index => $row ) {
			foreach ( $columns as $column ) {
				if ( isset( $row[ $column ] ) ) {
					$rows[ $index ][ $column ] = (int) $row[ $column ];
				}
			}
		}
		return $rows;
	}

	/**
	 * Deletes every entry.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Emptying this plugin's own table on explicit request.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
	}

	/**
	 * Drops rows beyond the retention limit, oldest first.
	 *
	 * @return void
	 */
	public static function prune() {
		$limit = self::get_limit();
		if ( $limit < 1 ) {
			return;
		}

		global $wpdb;
		// %i is the identifier placeholder, so the table name goes through prepare() like any other
		// value rather than being interpolated into the query string.
		$table = self::table();

		// The id of the newest row already outside the limit. Everything at or below it goes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would prune against a stale count.
		$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, $limit ) );
		if ( ! $cutoff ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, (int) $cutoff ) );
	}

	/**
	 * Records one agent request.
	 *
	 * Called from the plugin's serve points, which is why there is no user-agent test: a request
	 * for llms.txt or a .md URL is agent traffic whatever it calls itself, and filtering on
	 * user-agent would hide exactly the clients worth knowing about.
	 *
	 * @param string $surface             Human-readable name of what was served, e.g. 'llms.txt'.
	 * @param string $detail              What exactly was asked for within that surface — the
	 *                                    requested path on a 404, the method on an MCP call.
	 *                                    Empty for surfaces where the name is the whole answer.
	 * @param bool   $throttle_on_detail  Whether two requests differing only in $detail are two
	 *                                    entries rather than one. See below.
	 * @return void
	 */
	public static function record( $surface, $detail = '', $throttle_on_detail = false ) {
		if ( ! self::is_active() ) {
			return;
		}

		$agent  = self::agent_label();
		$ip     = self::client_ip();
		$detail = (string) $detail;

		// Throttle before touching the database. Only reached by requests already known to be
		// agent-facing, so this never runs on an ordinary page view.
		//
		// Whether $detail belongs in this key is the whole difference between the two callers, and
		// it is a judgement about who supplies the value. An MCP method name comes from a closed
		// set behind a rate limiter, so keying on it is safe and necessary: initialize, tools/list
		// and tools/call inside one session are three facts, and collapsing them to one would lose
		// the only thing anybody wants to know about that endpoint. A 404 path is supplied by the
		// caller and unbounded, so keying on it would let anything walking a URL list write a row
		// per request. There the row was going to be written anyway and the path is an annotation
		// on it, which samples the pattern over days without handing a fuzzer a write primitive.
		$throttle_detail = $throttle_on_detail ? $detail : '';
		$key             = 'mmsar_al_' . md5( $agent . '|' . $surface . '|' . $throttle_detail . '|' . $ip );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, self::THROTTLE );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Appending to this plugin's own table.
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'logged_at' => current_time( 'mysql', true ),
				'surface'   => mb_substr( $surface, 0, 100 ),
				'detail'    => self::fit_detail( $detail ),
				'agent'     => mb_substr( $agent, 0, 120 ),
				'ip'        => $ip,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		// Prune every so often rather than on every insert: an append is the cost this request
		// should pay, and a log a few rows over its limit between prunes is not observable.
		if ( $inserted && 0 === ( (int) $wpdb->insert_id % self::PRUNE_EVERY ) ) {
			self::prune();
		}

		self::mirror_to_activity_log( '' === $detail ? $surface : $surface . ' — ' . $detail, $agent, $ip );
	}

	/**
	 * Fits a detail value into the column without letting two different values become one.
	 *
	 * The column is varchar(190) and the values written to it are paths, which on a site with deep
	 * nesting or long slugs can exceed that. A plain truncation would be worse than lossy: two
	 * distinct posts sharing a 190-character prefix would collapse into a single `by_detail` row
	 * and report a total that belongs to neither, which is the same class of error the aggregates
	 * exist to avoid. An over-long value therefore keeps its readable head and carries a short
	 * digest of the whole original, so it stays legible and stays distinct.
	 *
	 * @param string $detail Raw detail value.
	 * @return string Value that fits the column.
	 */
	private static function fit_detail( $detail ) {
		$detail = (string) $detail;
		if ( mb_strlen( $detail ) <= 190 ) {
			return $detail;
		}
		return mb_substr( $detail, 0, 181 ) . '…' . substr( md5( $detail ), 0, 8 );
	}

	/**
	 * Copies an entry into the Activity Log plugin when its API is present.
	 *
	 * Database errors are suppressed for the duration of the call, and only for it. That plugin
	 * owns and upgrades its table on its own schedule; a site whose schema has not caught up
	 * produces an error on every insert, which with WP_DEBUG_DISPLAY on would print into a response
	 * being served. The entry is already stored above, so the mirror must never affect the page.
	 *
	 * @param string $surface What was served.
	 * @param string $agent   Requesting agent.
	 * @param string $ip      Client IP.
	 * @return void
	 */
	private static function mirror_to_activity_log( $surface, $agent, $ip ) {
		if ( ! function_exists( 'aal_insert_log' ) ) {
			return;
		}

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

	/**
	 * Records a normal HTML page view, but only when the user-agent looks like a known agent.
	 *
	 * This supplies the denominator: without it the log shows only the agents that asked for an
	 * agent-facing file, and "which agents ask for markdown" cannot be answered without also
	 * knowing which ones came and did not.
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

		self::record( 'HTML page view (' . self::accept_summary() . ')' );
	}

	/**
	 * A short label for the requesting agent: the matched agent name where recognized, otherwise a
	 * trimmed user-agent so unknown clients stay identifiable.
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
	 * Whether the request asked for markdown, HTML, or expressed no preference.
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
		return 'Accept: ' . mb_substr( $accept, 0, 30 );
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
	 * Client IP, preferring Cloudflare's header — behind a CDN, REMOTE_ADDR is the edge, so every
	 * agent would otherwise share one address and the throttle would collapse them together.
	 *
	 * @return string
	 */
	public static function client_ip() {
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
