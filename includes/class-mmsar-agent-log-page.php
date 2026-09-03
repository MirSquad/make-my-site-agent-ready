<?php
/**
 * The Agent Log admin screen.
 *
 * A page of its own rather than a section on the settings screen: a settings screen is for
 * configuration, and this is data that grows. Keeping them apart is what makes pagination, a
 * retention control and a clear button possible without the log pushing the settings off-screen.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent log screen.
 */
class MMSAR_Agent_Log_Page {

	const SLUG     = 'mmsar-agent-log';
	const PER_PAGE = 50;

	/**
	 * Rows read per query while streaming an export.
	 *
	 * The export walks the entire log, which can be far larger than anything the screen shows, so
	 * it is written out in batches rather than loaded into an array first. Whatever the log holds,
	 * peak memory is one batch.
	 */
	const EXPORT_BATCH = 500;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_mmsar_clear_agent_log', array( __CLASS__, 'handle_clear' ) );
		add_action( 'admin_post_mmsar_export_agent_log', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_mmsar_verify_agent_log', array( __CLASS__, 'handle_verify' ) );
		add_action( 'admin_post_mmsar_recheck_agent_log', array( __CLASS__, 'handle_recheck' ) );
	}

	/**
	 * Add menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_options_page(
			__( 'Agent Log', 'make-my-site-agent-ready' ),
			__( 'Agent Log', 'make-my-site-agent-ready' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'mmsar_agent_log_group',
			MMSAR_Agent_Log::LIMIT_OPTION,
			array(
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
		}
		if ( ! isset( $_POST['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmsar_nonce'] ) ), 'mmsar_clear_agent_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
		}

		MMSAR_Agent_Log::clear();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::SLUG,
					'mmsar_cleared' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Verifies a large batch of entries on request, then comes back with a count.
	 *
	 * The trickle on render decides ten addresses at a time, which keeps a live log current but
	 * would take a hundred and fifty page loads to work through a backlog the size of this site's
	 * own. This is how that backlog actually clears: one press, a few hundred addresses, its own
	 * time budget so the request cannot hang, and a report of what it found.
	 *
	 * @return void
	 */
	public static function handle_verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
		}
		if ( ! isset( $_POST['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmsar_nonce'] ) ), 'mmsar_verify_agent_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
		}

		$done = MMSAR_Agent_Log_Verify::run_batch(
			MMSAR_Agent_Log_Verify::BUTTON_IPS,
			MMSAR_Agent_Log_Verify::BUTTON_BUDGET
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => self::SLUG,
					'mmsar_verified'     => (int) $done['rows'],
					'mmsar_verified_ips' => (int) $done['ips'],
					'mmsar_verify_done'  => empty( $done['exhausted'] ) ? '0' : '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Reconsiders every entry whose identity could not be decided.
	 *
	 * A verdict is only as good as what this plugin knew when it was reached, and that changes: a
	 * `nodns` or an `unverifiable` means "no method available", not "the caller is a mystery
	 * forever". When an operator's suffix or range file is added, the rows it would now answer are
	 * sitting in the log holding the old non-answer. This is how they get another look.
	 *
	 * `verified` and `failed` are left alone — see MMSAR_Agent_Log::recheckable_verdicts().
	 *
	 * The cached verdicts have to go first. `unverifiable` is cached against the address for a
	 * week, so resetting the rows without clearing the cache would re-read the same stale answer
	 * and accomplish nothing at all.
	 *
	 * @return void
	 */
	public static function handle_recheck() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
		}
		if ( ! isset( $_POST['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmsar_nonce'] ) ), 'mmsar_recheck_agent_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
		}

		foreach ( MMSAR_Agent_Log::get_undecided_pairs() as $pair ) {
			MMSAR_Agent_Log_Verify::forget(
				isset( $pair['agent'] ) ? $pair['agent'] : '',
				isset( $pair['ip'] ) ? $pair['ip'] : ''
			);
		}

		$before = MMSAR_Agent_Log::get_verification_summary();
		$reset  = MMSAR_Agent_Log::reset_undecided();
		$done   = MMSAR_Agent_Log_Verify::run_batch(
			MMSAR_Agent_Log_Verify::BUTTON_IPS,
			MMSAR_Agent_Log_Verify::BUTTON_BUDGET
		);

		// Report what moved, not merely what was touched. A pass that reopened rows and wrote back
		// the same verdicts is a legitimate outcome and should say so, rather than reporting a
		// count that looks like progress.
		$after   = MMSAR_Agent_Log::get_verification_summary();
		$changed = 0;
		foreach ( $after['counts'] as $verdict => $count ) {
			$was = isset( $before['counts'][ $verdict ] ) ? (int) $before['counts'][ $verdict ] : 0;
			if ( $count > $was ) {
				$changed += $count - $was;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => self::SLUG,
					'mmsar_rechecked'    => (int) $reset,
					'mmsar_changed'      => (int) $changed,
					'mmsar_verified_ips' => (int) $done['ips'],
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Streams the whole log as a CSV download.
	 *
	 * @return void
	 */ /**
		 * Streams the whole log as a CSV download.
		 *
		 * @return void
		 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
		}
		if ( ! isset( $_POST['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmsar_nonce'] ) ), 'mmsar_export_agent_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
		}

		$filename = 'agent-log-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing to the response body, which WP_Filesystem does not address.
		$handle = fopen( 'php://output', 'w' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'Could not start the export.', 'make-my-site-agent-ready' ) );
		}

		// Column names rather than the screen's labels: this file is meant to be parsed. The suffix
		// on the timestamp is not decoration — the rows are stored in UTC while the screen renders
		// them in the site's timezone, and a bare "logged_at" would leave a reader comparing an
		// exported row against the screen with no way to tell which one they were holding.
		// Appended, never reordered: the column order is documented and something is parsing it.
		fputcsv( $handle, array( 'logged_at_utc', 'agent', 'surface', 'detail', 'ip', 'verified', 'verified_at_utc', 'client_type' ) );

		$cursor = 0;
		do {
			$rows  = MMSAR_Agent_Log::get_entries_before( $cursor, self::EXPORT_BATCH );
			$count = count( $rows );
			foreach ( $rows as $row ) {
				fputcsv(
					$handle,
					array(
						self::csv_cell( isset( $row['logged_at'] ) ? $row['logged_at'] : '' ),
						self::csv_cell( isset( $row['agent'] ) ? $row['agent'] : '' ),
						self::csv_cell( isset( $row['surface'] ) ? $row['surface'] : '' ),
						self::csv_cell( isset( $row['detail'] ) ? $row['detail'] : '' ),
						self::csv_cell( isset( $row['ip'] ) ? $row['ip'] : '' ),
						self::csv_cell( isset( $row['verified'] ) ? $row['verified'] : '' ),
						self::csv_cell( isset( $row['verified_at'] ) ? (string) $row['verified_at'] : '' ),
						self::csv_cell( isset( $row['client_type'] ) ? $row['client_type'] : '' ),
					)
				);
				$cursor = (int) $row['id'];
			}
		} while ( self::EXPORT_BATCH === $count );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the response body handle opened above.
		fclose( $handle );
		exit;
	}

	/**
	 * Neutralizes a value that a spreadsheet would read as a formula.
	 *
	 * The agent column holds a user-agent string, which is supplied by whoever made the request and
	 * is stored verbatim so unknown clients stay identifiable. A cell opening with =, +, -, @ or a
	 * control character is executed as a formula by Excel and several other spreadsheets on open,
	 * which turns a log of untrusted strings into a small remote-code path on the reader's machine.
	 * Prefixing an apostrophe makes the cell text; the spreadsheet hides the prefix, and a parser
	 * that is not a spreadsheet sees one extra leading character on the few rows that need it.
	 *
	 * @param string $value Raw cell value.
	 * @return string Value safe to write.
	 */
	private static function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return $value;
		}
		return ( false !== strpos( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
	}

	/**
	 * The requested page number, clamped to what exists.
	 *
	 * Kept in its own method so the request variable is read, bounded and turned into an integer in
	 * one place, well away from anything that renders — both easier to check by eye and clearer to
	 * a static analyser than the same three steps inlined among the output.
	 *
	 * @param int $pages Total number of pages.
	 * @return int Page number between 1 and $pages.
	 */
	private static function current_page( $pages ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination of an admin screen.
		$requested = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		return (int) max( 1, min( (int) $pages, $requested ) );
	}

	/**
	 * The requested verdict filter, restricted to values that exist.
	 *
	 * Read, bounded and returned in one place for the same reason current_page() is: the request
	 * variable never reaches the rendering code raw.
	 *
	 * @return string A verdict, 'pending', or an empty string for no filter.
	 */
	private static function current_verdict() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of an admin screen.
		$requested = isset( $_GET['mmsar_verified_filter'] ) ? sanitize_key( wp_unslash( $_GET['mmsar_verified_filter'] ) ) : '';
		$allowed   = array_merge( array( 'pending' ), MMSAR_Agent_Log_Verify::verdicts() );
		return in_array( $requested, $allowed, true ) ? $requested : '';
	}

	/**
	 * The requested client-type filter.
	 *
	 * Defaults to excluding browser page views. This log exists to show what agents did, and once
	 * every page view is recorded a screen that lists them all shows mostly people. The rows are
	 * kept because they are the denominator every share is computed against; they are just not what
	 * the screen opens on.
	 *
	 * @return string A client type, 'all', or '' for the agent-only default.
	 */
	private static function current_client() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of an admin screen.
		$requested = isset( $_GET['mmsar_client'] ) ? sanitize_key( wp_unslash( $_GET['mmsar_client'] ) ) : '';
		$allowed   = array_merge( array( 'all' ), MMSAR_Agent_Log::client_types() );
		return in_array( $requested, $allowed, true ) ? $requested : '';
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Decide a few identities before reading, so simply opening this screen keeps a live log
		// current. Bounded by a wall-clock budget, so a slow resolver delays the page rather than
		// holding it: whatever is left over is picked up on the next render or by the button below.
		MMSAR_Agent_Log_Verify::run_batch();

		$verdict = self::current_verdict();
		$client  = self::current_client();
		$total   = MMSAR_Agent_Log::count_entries();
		$shown   = MMSAR_Agent_Log::count_filtered( $verdict, $client );
		$pages   = max( 1, (int) ceil( $shown / self::PER_PAGE ) );
		$paged   = self::current_page( $pages );
		$entries = MMSAR_Agent_Log::get_entries( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE, $verdict, $client );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Agent Log', 'make-my-site-agent-ready' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a nonce-checked redirect.
		if ( isset( $_GET['mmsar_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Agent log cleared.', 'make-my-site-agent-ready' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a nonce-checked redirect.
		if ( isset( $_GET['mmsar_rechecked'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$reopened = absint( wp_unslash( $_GET['mmsar_rechecked'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$changed = isset( $_GET['mmsar_changed'] ) ? absint( wp_unslash( $_GET['mmsar_changed'] ) ) : 0;
			echo '<div class="notice notice-success is-dismissible"><p>';
			if ( $changed > 0 ) {
				printf(
					/* translators: 1: number of entries re-examined, 2: number whose verdict changed */
					esc_html__( 'Re-checked %1$s entries. %2$s now have a different verdict.', 'make-my-site-agent-ready' ),
					'<strong>' . esc_html( number_format_i18n( $reopened ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( $changed ) ) . '</strong>'
				);
			} else {
				printf(
					/* translators: %s: number of entries re-examined */
					esc_html__( 'Re-checked %s entries. Every one reached the same verdict as before, so nothing changed.', 'make-my-site-agent-ready' ),
					'<strong>' . esc_html( number_format_i18n( $reopened ) ) . '</strong>'
				);
			}
			echo '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a nonce-checked redirect.
		if ( isset( $_GET['mmsar_verified'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$rows = absint( wp_unslash( $_GET['mmsar_verified'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$ips = isset( $_GET['mmsar_verified_ips'] ) ? absint( wp_unslash( $_GET['mmsar_verified_ips'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$finished = isset( $_GET['mmsar_verify_done'] ) && '1' === $_GET['mmsar_verify_done'];
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: 1: number of entries, 2: number of IP addresses */
				esc_html__( 'Checked %1$s entries across %2$s addresses.', 'make-my-site-agent-ready' ),
				esc_html( number_format_i18n( $rows ) ),
				esc_html( number_format_i18n( $ips ) )
			);
			echo ' ';
			echo $finished
				? esc_html__( 'Nothing is left unchecked.', 'make-my-site-agent-ready' )
				: esc_html__( 'More entries are still unchecked — press it again to continue.', 'make-my-site-agent-ready' );
			echo '</p></div>';
		}

		if ( ! MMSAR_Agent_Log::is_active() ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: link to the settings page */
				esc_html__( 'The agent request log is switched off, so nothing new is being recorded. Turn it on under %s.', 'make-my-site-agent-ready' ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=make-my-site-agent-ready' ) ) . '">' . esc_html__( 'Settings > Agent-Ready', 'make-my-site-agent-ready' ) . '</a>'
			);
			echo '</p></div>';
		}

		self::render_verification_panel();
		self::render_retention_form( $total );

		if ( empty( $entries ) ) {
			echo '<p><em>' . esc_html(
				'' === $verdict
					? __( 'Nothing recorded yet. Agent traffic is intermittent — leave the log on and check back.', 'make-my-site-agent-ready' )
					: __( 'No entries match that verification filter.', 'make-my-site-agent-ready' )
			) . '</em></p>';
			echo '</div>';
			return;
		}

		if ( '' === $verdict && $shown !== $total ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: number of agent entries shown, 2: total entries in the log */
						__( 'Showing %1$s agent entries. Browser page views are recorded as the denominator and hidden here; %2$s entries in total.', 'make-my-site-agent-ready' ),
						number_format_i18n( $shown ),
						number_format_i18n( $total )
					)
				)
			);
		} elseif ( '' === $verdict ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: number of entries */
						_n( '%s entry recorded.', '%s entries recorded.', $total, 'make-my-site-agent-ready' ),
						number_format_i18n( $total )
					)
				)
			);
		} else {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: number of matching entries, 2: verification verdict, 3: total entries */
						__( '%1$s of %3$s entries are marked %2$s.', 'make-my-site-agent-ready' ),
						number_format_i18n( $shown ),
						MMSAR_Agent_Log_Verify::label( 'pending' === $verdict ? MMSAR_Agent_Log_Verify::PENDING : $verdict ),
						number_format_i18n( $total )
					)
				)
			);
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Agent', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Requested', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Client', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Identity', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'IP', 'make-my-site-agent-ready' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			// Stored in UTC; shown in the site's timezone, which is what the reader expects.
			$stamp = isset( $entry['logged_at'] ) ? strtotime( $entry['logged_at'] . ' UTC' ) : 0;
			echo '<tr>';
			echo '<td>' . esc_html( $stamp ? wp_date( 'Y-m-d H:i', $stamp ) : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['agent'] ) ? $entry['agent'] : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['surface'] ) ? $entry['surface'] : '—' ) . '</td>';
			$detail = isset( $entry['detail'] ) ? (string) $entry['detail'] : '';
			echo '<td>' . ( '' === $detail ? '<span style="color:#8c8f94;">—</span>' : '<code>' . esc_html( $detail ) . '</code>' ) . '</td>';
			$ctype = isset( $entry['client_type'] ) ? (string) $entry['client_type'] : '';
			echo '<td><span style="font-size:11px;color:' . ( MMSAR_Agent_Log::CLIENT_BROWSER === $ctype ? '#8c8f94' : 'inherit' ) . ';">'
				. esc_html( MMSAR_Agent_Log::client_type_label( $ctype ) ) . '</span></td>';
			echo '<td>' . wp_kses_post( self::verdict_badge( $entry ) ) . '</td>';
			echo '<td><code>' . esc_html( isset( $entry['ip'] ) ? $entry['ip'] : '' ) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						// Built from admin_url rather than add_query_arg's default, which would
						// assemble the base from REQUEST_URI and echo unsanitized query input.
						'base'      => admin_url(
							'options-general.php?page=' . self::SLUG
							. ( '' === $verdict ? '' : '&mmsar_verified_filter=' . rawurlencode( $verdict ) )
							. ( '' === $client ? '' : '&mmsar_client=' . rawurlencode( $client ) )
							. '&paged=%#%'
						),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * The verification verdict for one row, as a badge.
	 *
	 * `failed` is the one verdict that has to be unmissable, because it is the only one that says
	 * something was actively misrepresented: this row's caller named a crawler whose operator it
	 * demonstrably is not. The others are shades of "checked" and "no answer", and colouring them
	 * as loudly would bury the finding among them.
	 *
	 * The title attribute carries when the verdict was reached. A verdict is only as good as its
	 * age — reverse-DNS assignments and published ranges both change — so a judgement made ten
	 * days after the request is weaker evidence than one made in the same hour, and a reader
	 * comparing old rows needs to be able to see that gap.
	 *
	 * @param array $entry Log row.
	 * @return string Escaped markup.
	 */
	private static function verdict_badge( $entry ) {
		$verdict = isset( $entry['verified'] ) ? (string) $entry['verified'] : '';
		$label   = MMSAR_Agent_Log_Verify::label( $verdict );

		$styles = array(
			MMSAR_Agent_Log_Verify::VERIFIED     => 'background:#e6f4ea;color:#0a5c2e;border:1px solid #a8d5b8;',
			MMSAR_Agent_Log_Verify::FAILED       => 'background:#fce8e6;color:#8a1c11;border:1px solid #f0a9a2;font-weight:600;',
			MMSAR_Agent_Log_Verify::UNVERIFIABLE => 'background:#fff4e5;color:#7a4a00;border:1px solid #f0d0a0;',
			MMSAR_Agent_Log_Verify::NODNS        => 'background:#f0f0f1;color:#50575e;border:1px solid #dcdcde;',
			MMSAR_Agent_Log_Verify::UNCLAIMED    => 'background:transparent;color:#8c8f94;border:1px solid #dcdcde;',
		);
		$style  = isset( $styles[ $verdict ] ) ? $styles[ $verdict ] : 'background:transparent;color:#8c8f94;border:1px dashed #dcdcde;';

		$when  = isset( $entry['verified_at'] ) ? (string) $entry['verified_at'] : '';
		$stamp = $when ? strtotime( $when . ' UTC' ) : 0;
		$title = $stamp
			/* translators: %s: date and time the verdict was reached */
			? sprintf( __( 'Checked %s', 'make-my-site-agent-ready' ), wp_date( 'Y-m-d H:i', $stamp ) )
			: __( 'Not checked yet', 'make-my-site-agent-ready' );

		return '<span title="' . esc_attr( $title ) . '" style="' . esc_attr( $style ) . 'display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;white-space:nowrap;">'
			. esc_html( $label ) . '</span>';
	}

	/**
	 * The verification summary, the filter, and the button that clears the backlog.
	 *
	 * @return void
	 */
	private static function render_verification_panel() {
		$summary = MMSAR_Agent_Log::get_verification_summary();
		$counts  = $summary['counts'];
		$pending = (int) $summary['pending'];
		$failed  = isset( $counts[ MMSAR_Agent_Log_Verify::FAILED ] ) ? (int) $counts[ MMSAR_Agent_Log_Verify::FAILED ] : 0;
		$current = self::current_verdict();

		echo '<div style="margin:1em 0;padding:12px 14px;background:#fff;border:1px solid #c3c4c7;border-left-width:4px;border-left-color:' . ( $failed > 0 ? '#d63638' : '#72aee6' ) . ';">';

		echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Crawler identity', 'make-my-site-agent-ready' ) . '</strong></p>';

		if ( $failed > 0 ) {
			echo '<p style="margin:0 0 8px;">';
			printf(
				/* translators: %s: number of entries */
				esc_html( _n( '%s entry claimed an AI crawler it is not.', '%s entries claimed an AI crawler they are not.', $failed, 'make-my-site-agent-ready' ) ),
				'<strong>' . esc_html( number_format_i18n( $failed ) ) . '</strong>'
			);
			echo ' ' . esc_html__( 'The user-agent was forged; the address does not belong to the operator it named.', 'make-my-site-agent-ready' );
			echo '</p>';
		} else {
			echo '<p style="margin:0 0 8px;">' . esc_html__( 'No forged crawler identities found so far.', 'make-my-site-agent-ready' ) . '</p>';
		}

		// The pending count sits with the verdicts on purpose. Verdict totals over a half-checked
		// log describe the checked half and nothing else, and a reader who cannot see how much is
		// still outstanding has no way to tell a quiet result from an unfinished one.
		$parts = array();
		foreach ( MMSAR_Agent_Log_Verify::verdicts() as $verdict ) {
			$count = isset( $counts[ $verdict ] ) ? (int) $counts[ $verdict ] : 0;
			if ( $count > 0 ) {
				$parts[] = MMSAR_Agent_Log_Verify::label( $verdict ) . ': ' . number_format_i18n( $count );
			}
		}
		if ( $pending > 0 ) {
			$parts[] = MMSAR_Agent_Log_Verify::label( MMSAR_Agent_Log_Verify::PENDING ) . ': ' . number_format_i18n( $pending );
		}
		if ( $parts ) {
			echo '<p class="description" style="margin:0 0 8px;">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
		}

		$recheckable = MMSAR_Agent_Log::count_recheckable();
		$uncheckable = MMSAR_Agent_Log::get_uncheckable_agents();

		if ( $recheckable > 0 || $uncheckable ) {
			echo '<p class="description" style="margin:0 0 10px;">';
			esc_html_e( 'Unverifiable and No DNS mean this plugin had no way to check, not that the caller was suspicious. No DNS entries are retried on their own within a day. Verified and Spoofed entries are never re-checked.', 'make-my-site-agent-ready' );
			echo '</p>';
		}

		// Naming the agents matters more than the count here. Without it the reader sees a stuck
		// number and no button, which reads as something being broken; with it, the number is an
		// answer — nobody publishes a way to confirm these crawlers, so there is nothing to retry.
		if ( $uncheckable ) {
			$total_uncheckable = array_sum( $uncheckable );
			$names             = array_keys( $uncheckable );
			echo '<p class="description" style="margin:0 0 10px;">';
			/* translators: 1: number of entries, 2: comma-separated list of crawler names */
			$template = _n(
				'%1$s entry is unverifiable because no operator publishes a way to confirm it (%2$s). Re-checking cannot change that — it becomes checkable only if a future update adds that operator.',
				'%1$s entries are unverifiable because no operator publishes a way to confirm them (%2$s). Re-checking cannot change that — they become checkable only if a future update adds their operator.',
				$total_uncheckable,
				'make-my-site-agent-ready'
			);
			printf(
				esc_html( $template ),
				'<strong>' . esc_html( number_format_i18n( $total_uncheckable ) ) . '</strong>',
				esc_html( implode( ', ', $names ) )
			);
			echo '</p>';
		}

		$captured = MMSAR_Agent_Log_Verify::ranges_captured();
		if ( $captured ) {
			echo '<p class="description" style="margin:0 0 10px;">';
			printf(
				/* translators: %s: date the bundled IP ranges were captured */
				esc_html__( 'Anthropic, OpenAI and Perplexity publish no reverse-DNS records for their crawlers, so those are checked against the IP ranges they publish instead. The bundled copy dates from %s; a range added since then reads as forged until the plugin is updated.', 'make-my-site-agent-ready' ),
				esc_html( $captured )
			);
			echo '</p>';
		}

		if ( $pending > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 0 0;">';
			echo '<input type="hidden" name="action" value="mmsar_verify_agent_log">';
			wp_nonce_field( 'mmsar_verify_agent_log', 'mmsar_nonce' );
			submit_button( __( 'Verify now', 'make-my-site-agent-ready' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		// Shown only when a re-check could produce a different answer. Offering it otherwise is
		// offering an action with no possible outcome, which is what the count of merely-undecided
		// rows used to do: it stayed at 107 however many times it was pressed.
		if ( $recheckable > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 0 0;">';
			echo '<input type="hidden" name="action" value="mmsar_recheck_agent_log">';
			wp_nonce_field( 'mmsar_recheck_agent_log', 'mmsar_nonce' );
			submit_button(
				sprintf(
					/* translators: %s: number of entries that could be answered differently */
					__( 'Re-check %s', 'make-my-site-agent-ready' ),
					number_format_i18n( $recheckable )
				),
				'secondary',
				'submit',
				false
			);
			echo '</form>';
		}

		// A plain link set rather than a select: five options, no JavaScript anywhere in this
		// plugin, and each one is a URL an administrator can bookmark or send to someone.
		echo '<span style="display:inline-block;">';
		$options = array( '' => __( 'All', 'make-my-site-agent-ready' ) );
		foreach ( MMSAR_Agent_Log_Verify::verdicts() as $verdict ) {
			$options[ $verdict ] = MMSAR_Agent_Log_Verify::label( $verdict );
		}
		if ( $pending > 0 ) {
			$options['pending'] = MMSAR_Agent_Log_Verify::label( MMSAR_Agent_Log_Verify::PENDING );
		}

		$links = array();
		foreach ( $options as $value => $label ) {
			$url     = '' === $value
				? admin_url( 'options-general.php?page=' . self::SLUG )
				: add_query_arg(
					array(
						'page'                  => self::SLUG,
						'mmsar_verified_filter' => $value,
					),
					admin_url( 'options-general.php' )
				);
			$links[] = $value === $current
				? '<strong>' . esc_html( $label ) . '</strong>'
				: '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo wp_kses_post( implode( ' | ', $links ) );
		echo '</span>';

		$ccounts = MMSAR_Agent_Log::get_client_type_counts();
		if ( $ccounts[ MMSAR_Agent_Log::CLIENT_BROWSER ] > 0 ) {
			$current_client = self::current_client();
			$copts          = array(
				''                              => __( 'Agents only', 'make-my-site-agent-ready' ),
				MMSAR_Agent_Log::CLIENT_BROWSER => __( 'Browsers', 'make-my-site-agent-ready' ),
				'all'                           => __( 'Everything', 'make-my-site-agent-ready' ),
			);
			$clinks         = array();
			foreach ( $copts as $value => $label ) {
				$url      = '' === $value
					? admin_url( 'options-general.php?page=' . self::SLUG )
					: add_query_arg(
						array(
							'page'         => self::SLUG,
							'mmsar_client' => $value,
						),
						admin_url( 'options-general.php' )
					);
				$clinks[] = $value === $current_client
					? '<strong>' . esc_html( $label ) . '</strong>'
					: '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '<span style="display:inline-block;margin-left:1.2rem;">';
			echo wp_kses_post( implode( ' | ', $clinks ) );
			echo '</span>';

			echo '<p class="description" style="margin:.7rem 0 0;">';
			printf(
				/* translators: %s: number of browser page views */
				esc_html__( '%s page views came from a browser engine and are hidden by default. They are recorded so shares have a denominator, not because they are agent traffic. A browser signature means the request came from a real browser, which is usually a person and is also what an agent driving a headless Chrome looks like.', 'make-my-site-agent-ready' ),
				'<strong>' . esc_html( number_format_i18n( $ccounts[ MMSAR_Agent_Log::CLIENT_BROWSER ] ) ) . '</strong>'
			);
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * The retention control and the clear button.
	 *
	 * @param int $total Current number of entries.
	 * @return void
	 */
	private static function render_retention_form( $total ) {
		$limit = MMSAR_Agent_Log::get_limit();

		echo '<form method="post" action="options.php" style="margin:1em 0;">';
		settings_fields( 'mmsar_agent_log_group' );
		echo '<label for="mmsar-log-limit"><strong>' . esc_html__( 'Entries to keep', 'make-my-site-agent-ready' ) . '</strong></label> ';
		echo '<input type="number" min="0" step="1" id="mmsar-log-limit" name="' . esc_attr( MMSAR_Agent_Log::LIMIT_OPTION ) . '" value="' . esc_attr( (string) $limit ) . '" class="small-text"> ';
		submit_button( __( 'Save', 'make-my-site-agent-ready' ), 'secondary', 'submit', false );
		echo '<p class="description">';
		esc_html_e( '0 keeps everything, which is the default. Set a number to have the oldest entries dropped once the log passes it. Entries are trimmed periodically rather than on every request, so the count can sit slightly above your limit between trims. Worth a look on a content-heavy site: since 1.24.0 the Markdown surfaces record which page was fetched, so a crawler sweeping forty Markdown files now writes forty entries where it previously wrote one. That is the point of the change, and it does make the log grow faster than it used to.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '</form>';

		if ( $total > 0 ) {
			// Export first and in its own form, so the button that keeps the data sits ahead of the
			// one that destroys it and neither can be submitted by aiming at the other.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 1.5em 0;">';
			echo '<input type="hidden" name="action" value="mmsar_export_agent_log">';
			wp_nonce_field( 'mmsar_export_agent_log', 'mmsar_nonce' );
			submit_button( __( 'Export CSV', 'make-my-site-agent-ready' ), 'secondary', 'submit', false );
			echo '</form>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-bottom:1.5em;">';
			echo '<input type="hidden" name="action" value="mmsar_clear_agent_log">';
			wp_nonce_field( 'mmsar_clear_agent_log', 'mmsar_nonce' );
			submit_button( __( 'Clear log', 'make-my-site-agent-ready' ), 'delete', 'submit', false );
			echo '</form>';

			echo '<p class="description" style="margin-top:0;">';
			esc_html_e( 'The export contains every entry, not just this page, with timestamps in UTC.', 'make-my-site-agent-ready' );
			echo '</p>';
		}
	}
}
