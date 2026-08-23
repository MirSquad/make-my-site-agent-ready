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
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_mmsar_clear_agent_log', array( __CLASS__, 'handle_clear' ) );
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
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$total   = MMSAR_Agent_Log::count_entries();
		$pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged   = self::current_page( $pages );
		$entries = MMSAR_Agent_Log::get_entries( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Agent Log', 'make-my-site-agent-ready' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a nonce-checked redirect.
		if ( isset( $_GET['mmsar_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Agent log cleared.', 'make-my-site-agent-ready' ) . '</p></div>';
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

		self::render_retention_form( $total );

		if ( empty( $entries ) ) {
			echo '<p><em>' . esc_html__( 'Nothing recorded yet. Agent traffic is intermittent — leave the log on and check back.', 'make-my-site-agent-ready' ) . '</em></p>';
			echo '</div>';
			return;
		}

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

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Agent', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Requested', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'IP', 'make-my-site-agent-ready' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			// Stored in UTC; shown in the site's timezone, which is what the reader expects.
			$stamp = isset( $entry['logged_at'] ) ? strtotime( $entry['logged_at'] . ' UTC' ) : 0;
			echo '<tr>';
			echo '<td>' . esc_html( $stamp ? wp_date( 'Y-m-d H:i', $stamp ) : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['agent'] ) ? $entry['agent'] : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['surface'] ) ? $entry['surface'] : '—' ) . '</td>';
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
						// assemble the base from REQUEST_URI and echo unsanitised query input.
						'base'      => admin_url( 'options-general.php?page=' . self::SLUG . '&paged=%#%' ),
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
		esc_html_e( '0 keeps everything, which is the default. Set a number to have the oldest entries dropped once the log passes it. Entries are trimmed periodically rather than on every request, so the count can sit slightly above your limit between trims.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '</form>';

		if ( $total > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:1.5em;">';
			echo '<input type="hidden" name="action" value="mmsar_clear_agent_log">';
			wp_nonce_field( 'mmsar_clear_agent_log', 'mmsar_nonce' );
			submit_button( __( 'Clear log', 'make-my-site-agent-ready' ), 'delete', 'submit', false );
			echo '</form>';
		}
	}
}
