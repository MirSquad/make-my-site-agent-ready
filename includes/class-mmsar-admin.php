<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMSAR_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_notices', [ __CLASS__, 'static_robots_notice' ] );
	}

	public static function static_robots_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$robots_file = ABSPATH . 'robots.txt';
		if ( ! file_exists( $robots_file ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: path to robots.txt file */
			esc_html__( 'Make My Site Agent-Ready: A physical robots.txt file was found at %s. WordPress rewrite rules override it on Apache, but some hosts or CDNs (e.g. Cloudflare) may serve the static file directly, bypassing the AI crawler rules added by this plugin. Consider deleting the static file so WordPress generates robots.txt dynamically.', 'make-my-site-agent-ready' ),
			'<code>' . esc_html( $robots_file ) . '</code>'
		);
		echo '</p></div>';
	}

	public static function add_menu() {
		add_options_page(
			__( 'Make My Site Agent-Ready', 'make-my-site-agent-ready' ),
			__( 'Agent-Ready', 'make-my-site-agent-ready' ),
			'manage_options',
			'make-my-site-agent-ready',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings() {
		// Main settings (option key kept as llmmd_settings for data continuity).
		register_setting( 'mmsar_settings_group', 'llmmd_settings', [
			'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
		] );

		add_settings_section(
			'mmsar_main',
			__( 'Markdown Endpoints', 'make-my-site-agent-ready' ),
			'__return_false',
			'make-my-site-agent-ready'
		);

		add_settings_field(
			'mmsar_post_types',
			__( 'Enabled Post Types', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_post_types_field' ],
			'make-my-site-agent-ready',
			'mmsar_main'
		);

		add_settings_field(
			'mmsar_root_selector',
			__( 'Content Root Selector', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_root_selector_field' ],
			'make-my-site-agent-ready',
			'mmsar_main'
		);

		// robots.txt settings.
		register_setting( 'mmsar_settings_group', 'mmsar_robots_txt_extra', [
			'sanitize_callback' => 'sanitize_textarea_field',
		] );

		add_settings_section(
			'mmsar_robots_txt',
			__( 'robots.txt', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_robots_txt_section' ],
			'make-my-site-agent-ready'
		);

		add_settings_field(
			'mmsar_robots_txt_preview',
			__( 'Current Content', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_robots_txt_preview_field' ],
			'make-my-site-agent-ready',
			'mmsar_robots_txt'
		);

		add_settings_field(
			'mmsar_robots_txt_extra',
			__( 'Additional Rules', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_robots_txt_field' ],
			'make-my-site-agent-ready',
			'mmsar_robots_txt'
		);

		// Content Signals settings.
		register_setting( 'mmsar_settings_group', 'mmsar_content_signals', [
			'sanitize_callback' => [ __CLASS__, 'sanitize_content_signals' ],
			'default'           => [
				'search'   => 'yes',
				'ai_input' => 'yes',
				'ai_train' => 'no',
			],
		] );

		add_settings_section(
			'mmsar_content_signals',
			__( 'Content Signals', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_content_signals_section' ],
			'make-my-site-agent-ready'
		);

		add_settings_field(
			'mmsar_content_signals_values',
			__( 'AI Usage Preferences', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_content_signals_field' ],
			'make-my-site-agent-ready',
			'mmsar_content_signals'
		);

		// security.txt settings.
		register_setting( 'mmsar_settings_group', 'mmsar_security_txt', [
			'sanitize_callback' => 'sanitize_textarea_field',
		] );

		add_settings_section(
			'mmsar_security_txt',
			__( 'security.txt', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_security_txt_section' ],
			'make-my-site-agent-ready'
		);

		add_settings_field(
			'mmsar_security_txt_content',
			__( 'Content', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_security_txt_field' ],
			'make-my-site-agent-ready',
			'mmsar_security_txt'
		);
	}

	public static function sanitize_settings( $input ) {
		$sanitized = [];

		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$sanitized['post_types'] = array_map( 'sanitize_key', $input['post_types'] );
		} else {
			$sanitized['post_types'] = [];
		}

		if ( isset( $input['root_selector'] ) ) {
			$sanitized['root_selector'] = mb_substr( sanitize_text_field( $input['root_selector'] ), 0, 500 );
		} else {
			$sanitized['root_selector'] = '';
		}

		delete_transient( 'llmmd_llms_txt' );
		delete_transient( 'mmsar_llms_full_txt' );

		return $sanitized;
	}

	public static function render_post_types_field() {
		$settings   = get_option( 'llmmd_settings', [] );
		$enabled    = isset( $settings['post_types'] ) ? $settings['post_types'] : [ 'post', 'page' ];
		$post_types = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$checked = in_array( $pt->name, $enabled, true ) ? 'checked' : '';
			echo '<label style="display:block;margin-bottom:6px;">';
			echo '<input type="checkbox" name="llmmd_settings[post_types][]" value="' . esc_attr( $pt->name ) . '" ' . $checked . '> ';
			echo esc_html( $pt->labels->name ) . ' <code>' . esc_html( $pt->name ) . '</code>';
			echo '</label>';
		}
	}

	public static function render_root_selector_field() {
		$settings = get_option( 'llmmd_settings', [] );
		$value    = isset( $settings['root_selector'] ) ? $settings['root_selector'] : '';
		echo '<input type="text" name="llmmd_settings[root_selector]" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="main, article, .entry-content">';
		echo '<p class="description">' . esc_html__( 'CSS selector(s) to extract content from. Leave empty to use the full post content. Comma-separated for multiple selectors.', 'make-my-site-agent-ready' ) . '</p>';
	}

	public static function sanitize_content_signals( $input ) {
		$valid = [ 'yes', 'no' ];
		$out   = [];
		foreach ( [ 'search', 'ai_input', 'ai_train' ] as $key ) {
			$val         = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : 'yes';
			$out[ $key ] = in_array( $val, $valid, true ) ? $val : 'yes';
		}
		return $out;
	}

	public static function render_content_signals_section() {
		echo '<p>';
		esc_html_e( 'Content Signals (Content-Signal directives in robots.txt) declare how AI crawlers may use this content: for search indexing, for live retrieval when answering a query, and/or for training a model. This is an emerging, not-yet-ratified proposal — most crawlers do not honor it yet, but validators like isitagentready.com already check for it.', 'make-my-site-agent-ready' );
		echo '</p>';
	}

	public static function render_content_signals_field() {
		$settings = get_option( 'mmsar_content_signals', [
			'search'   => 'yes',
			'ai_input' => 'yes',
			'ai_train' => 'no',
		] );

		$fields = [
			'search'   => [
				__( 'Search', 'make-my-site-agent-ready' ),
				__( 'Allow this content to be indexed by search engines.', 'make-my-site-agent-ready' ),
			],
			'ai_input' => [
				__( 'AI Input', 'make-my-site-agent-ready' ),
				__( 'Allow this content to be fetched as live input to an AI system (e.g. an assistant answering a question by reading this page).', 'make-my-site-agent-ready' ),
			],
			'ai_train' => [
				__( 'AI Train', 'make-my-site-agent-ready' ),
				__( 'Allow this content to be included in a model training corpus.', 'make-my-site-agent-ready' ),
			],
		];

		foreach ( $fields as $key => $labels ) {
			list( $label, $description ) = $labels;
			$value                       = isset( $settings[ $key ] ) ? $settings[ $key ] : 'yes';
			echo '<p style="margin-bottom:14px;">';
			echo '<label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html( $label ) . '</label>';
			echo '<select name="mmsar_content_signals[' . esc_attr( $key ) . ']">';
			echo '<option value="yes"' . selected( $value, 'yes', false ) . '>' . esc_html__( 'Yes', 'make-my-site-agent-ready' ) . '</option>';
			echo '<option value="no"' . selected( $value, 'no', false ) . '>' . esc_html__( 'No', 'make-my-site-agent-ready' ) . '</option>';
			echo '</select>';
			echo '<p class="description">' . esc_html( $description ) . '</p>';
			echo '</p>';
		}
	}

	public static function render_robots_txt_section() {
		$url = home_url( '/robots.txt' );
		echo '<p>';
		printf(
			/* translators: %s: robots.txt URL */
			esc_html__( 'WordPress generates %s dynamically. This plugin appends explicit Allow rules for AI crawlers (GPTBot, ClaudeBot, etc.) and a Sitemap directive. Add any extra rules below — they will be appended after the AI crawler rules.', 'make-my-site-agent-ready' ),
			'<a href="' . esc_url( $url ) . '" target="_blank"><code>robots.txt</code></a>'
		);
		echo '</p>';
	}

	public static function render_robots_txt_preview_field() {
		$public  = (int) get_option( 'blog_public' );
		$content = "User-agent: *\n";
		if ( ! $public ) {
			$content .= "Disallow: /\n";
		} else {
			$content .= "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		}
		$content = apply_filters( 'robots_txt', $content, $public );
		echo '<textarea readonly rows="18" class="large-text code" style="background:#f6f7f7;color:#3c434a;">' . esc_textarea( $content ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Read-only preview of the current robots.txt output. This is exactly what gets served at /robots.txt.', 'make-my-site-agent-ready' ) . '</p>';
	}

	public static function render_robots_txt_field() {
		$value = get_option( 'mmsar_robots_txt_extra', '' );
		echo '<textarea name="mmsar_robots_txt_extra" rows="5" class="large-text code" placeholder="# e.g. User-agent: Bingbot&#10;# Allow: /">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Optional extra directives appended to robots.txt. Leave empty if you only need the default AI crawler rules.', 'make-my-site-agent-ready' ) . '</p>';
	}

	public static function render_security_txt_section() {
		$url = home_url( '/.well-known/security.txt' );
		echo '<p>';
		echo esc_html__( 'Serves a security.txt file at ', 'make-my-site-agent-ready' );
		echo '<a href="' . esc_url( $url ) . '" target="_blank"><code>/.well-known/security.txt</code></a>';
		echo esc_html__( ' per the security.txt standard (RFC 9116). Leave empty to use the auto-generated default.', 'make-my-site-agent-ready' );
		echo '</p>';
	}

	public static function render_security_txt_field() {
		$value       = get_option( 'mmsar_security_txt', '' );
		$placeholder = MMSAR_Endpoints::default_security_txt();
		echo '<textarea name="mmsar_security_txt" rows="6" class="large-text code" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Plain text content for /.well-known/security.txt. Required fields: Contact and Expires. Leave empty to use the auto-generated default.', 'make-my-site-agent-ready' ) . '</p>';
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$llms_txt_url        = home_url( '/llms.txt' );
		$llms_full_txt_url   = home_url( '/llms-full.txt' );
		$security_txt_url    = home_url( '/.well-known/security.txt' );
		$robots_txt_url      = home_url( '/robots.txt' );
		$api_catalog_url     = home_url( '/.well-known/api-catalog' );
		$agent_skills_url    = home_url( '/.well-known/agent-skills/index.json' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Make My Site Agent-Ready', 'make-my-site-agent-ready' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'mmsar_settings_group' );
				do_settings_sections( 'make-my-site-agent-ready' );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Quick Links', 'make-my-site-agent-ready' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'llms.txt:', 'make-my-site-agent-ready' ); ?></strong>
				<a href="<?php echo esc_url( $llms_txt_url ); ?>" target="_blank"><?php echo esc_html( $llms_txt_url ); ?></a>
			</p>
			<p>
				<strong><?php esc_html_e( 'llms-full.txt:', 'make-my-site-agent-ready' ); ?></strong>
				<a href="<?php echo esc_url( $llms_full_txt_url ); ?>" target="_blank"><?php echo esc_html( $llms_full_txt_url ); ?></a>
			</p>
			<p>
				<strong><?php esc_html_e( 'security.txt:', 'make-my-site-agent-ready' ); ?></strong>
				<a href="<?php echo esc_url( $security_txt_url ); ?>" target="_blank"><?php echo esc_html( $security_txt_url ); ?></a>
			</p>
			<p>
				<strong><?php esc_html_e( 'robots.txt:', 'make-my-site-agent-ready' ); ?></strong>
				<a href="<?php echo esc_url( $robots_txt_url ); ?>" target="_blank"><?php echo esc_html( $robots_txt_url ); ?></a>
			</p>
			<p>
				<strong><?php esc_html_e( 'api-catalog:', 'make-my-site-agent-ready' ); ?></strong>
				<a href="<?php echo esc_url( $api_catalog_url ); ?>" target="_blank"><?php echo esc_html( $api_catalog_url ); ?></a>
			</p>
			<p>
				<strong><?php esc_html_e( 'Agent Skills index:', 'make-my-site-agent-ready' ); ?></strong>
				<a href="<?php echo esc_url( $agent_skills_url ); ?>" target="_blank"><?php echo esc_html( $agent_skills_url ); ?></a>
			</p>

			<hr>

			<h2><?php esc_html_e( 'Regenerate Markdown', 'make-my-site-agent-ready' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Regenerate cached markdown for all published content. This happens automatically when posts are saved.', 'make-my-site-agent-ready' ); ?></p>
			<?php
			if ( isset( $_GET['mmsar_regenerated'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'mmsar_regenerate' ) ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'All markdown content has been regenerated.', 'make-my-site-agent-ready' ) . '</p></div>';
			}
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mmsar_regenerate">
				<?php wp_nonce_field( 'mmsar_regenerate', 'mmsar_nonce' ); ?>
				<?php submit_button( __( 'Regenerate All', 'make-my-site-agent-ready' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}

add_action( 'admin_post_mmsar_regenerate', 'mmsar_handle_regenerate' );
function mmsar_handle_regenerate() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
	}
	if ( ! isset( $_POST['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmsar_nonce'] ) ), 'mmsar_regenerate' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
	}

	mmsar_bulk_generate();
	delete_transient( 'llmmd_llms_txt' );
	delete_transient( 'mmsar_llms_full_txt' );

	wp_safe_redirect( add_query_arg(
		[
			'page'             => 'make-my-site-agent-ready',
			'mmsar_regenerated' => '1',
			'_wpnonce'         => wp_create_nonce( 'mmsar_regenerate' ),
		],
		admin_url( 'options-general.php' )
	) );
	exit;
}
