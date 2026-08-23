<?php
/**
 * Dashboard widget showing the most recent agent requests.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent log dashboard widget.
 */
class MMSAR_Agent_Log_Widget {

	/**
	 * How many entries the widget shows.
	 */
	const ENTRIES = 20;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers the widget.
	 *
	 * No setting controls whether it appears: WordPress already lets each user hide a dashboard
	 * widget from Screen Options, and adding a second switch for the same job would mean two places
	 * to look when it is not showing.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'mmsar_agent_log',
			__( 'Recent Agent Requests', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Renders the widget.
	 *
	 * @return void
	 */
	public static function render() {
		$entries = MMSAR_Agent_Log::get_entries( self::ENTRIES, 0 );
		$log_url = admin_url( 'options-general.php?page=' . MMSAR_Agent_Log_Page::SLUG );
		$is_on   = MMSAR_Agent_Log::is_active();

		if ( ! $is_on ) {
			echo '<p>';
			printf(
				/* translators: %s: link to the plugin settings page */
				esc_html__( 'The agent request log is switched off, so nothing new is being recorded. Turn it on under %s.', 'make-my-site-agent-ready' ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=make-my-site-agent-ready' ) ) . '">' . esc_html__( 'Settings > Agent-Ready', 'make-my-site-agent-ready' ) . '</a>'
			);
			echo '</p>';
		}

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No agent requests recorded yet.', 'make-my-site-agent-ready' ) . '</p>';
			return;
		}

		echo '<table style="width:100%;border-collapse:collapse;">';
		echo '<tbody>';

		foreach ( $entries as $entry ) {
			// Stored in UTC. Shown as elapsed time, which is what "is anything hitting the site
			// right now" needs — an exact timestamp is a click away on the full log.
			$stamp = isset( $entry['logged_at'] ) ? strtotime( $entry['logged_at'] . ' UTC' ) : 0;
			$when  = $stamp
				? sprintf(
					/* translators: %s: human-readable time difference, e.g. "5 mins" */
					__( '%s ago', 'make-my-site-agent-ready' ),
					human_time_diff( $stamp )
				)
				: '—';

			echo '<tr style="border-bottom:1px solid #f0f0f1;">';
			echo '<td style="padding:6px 8px 6px 0;vertical-align:top;">';
			echo '<strong>' . esc_html( isset( $entry['agent'] ) ? $entry['agent'] : '—' ) . '</strong>';
			echo '<br><span style="color:#646970;">' . esc_html( isset( $entry['surface'] ) ? $entry['surface'] : '' ) . '</span>';
			echo '</td>';
			echo '<td style="padding:6px 0;vertical-align:top;text-align:right;white-space:nowrap;color:#646970;">';
			echo esc_html( $when );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		printf(
			'<p style="margin-top:10px;"><a href="%1$s">%2$s</a></p>',
			esc_url( $log_url ),
			esc_html__( 'View the full log', 'make-my-site-agent-ready' )
		);
	}
}
