<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMSAR_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_notices', [ __CLASS__, 'static_robots_notice' ] );
		add_action( 'admin_notices', [ __CLASS__, 'structured_data_conflict_notice' ] );
	}

	public static function structured_data_conflict_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '1' !== get_option( 'mmsar_structured_data', '' ) ) {
			return;
		}
		// Yoast is handled: the markdown pointer merges into Yoast's own schema piece
		// instead of duplicating it, so no warning needed for Yoast specifically.
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Make My Site Agent-Ready: JSON-LD structured data is enabled, and an SEO plugin (RankMath) that already emits its own structured data is active. This plugin\'s block is intentionally minimal and separate (no shared @id), but consider whether you need both, or whether your SEO plugin\'s existing output already covers this.', 'make-my-site-agent-ready' );
		echo '</p></div>';
	}

	public static function static_robots_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Once the user has opted out of robots.txt handling, their physical file is being served
		// as they intended — warning about it would be nagging about a problem they just solved.
		if ( ! mmsar_feature_enabled( 'robots_txt' ) ) {
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

	/**
	 * Label and "what you lose" copy for each toggleable feature, in display order.
	 */
	public static function get_feature_labels() {
		return [
			'markdown'      => [
				__( 'Markdown URLs (.md)', 'make-my-site-agent-ready' ),
				__( 'Serves a plain-markdown version of each post and page at its URL plus .md, and points agents at it via a <link> tag and Link header. Turning this off also disables the JSON-LD structured data below, which exists only to advertise these URLs.', 'make-my-site-agent-ready' ),
			],
			'llms_txt'      => [
				__( 'llms.txt', 'make-my-site-agent-ready' ),
				__( 'An index of your site at /llms.txt, so an agent can see what content exists in one request.', 'make-my-site-agent-ready' ),
			],
			'llms_full_txt' => [
				__( 'llms-full.txt', 'make-my-site-agent-ready' ),
				__( 'The full markdown text of your content in a single file at /llms-full.txt.', 'make-my-site-agent-ready' ),
			],
			'robots_txt'    => [
				__( 'robots.txt AI crawler rules', 'make-my-site-agent-ready' ),
				__( 'Adds explicit Allow rules for AI crawlers, a Content-Signal directive, and a Sitemap line. See the robots.txt section below.', 'make-my-site-agent-ready' ),
			],
			'security_txt'  => [
				__( 'security.txt', 'make-my-site-agent-ready' ),
				__( 'Publishes a security contact at /.well-known/security.txt (RFC 9116).', 'make-my-site-agent-ready' ),
			],
			'api_catalog'   => [
				__( 'api-catalog', 'make-my-site-agent-ready' ),
				__( 'Lists your site\'s machine-readable endpoints at /.well-known/api-catalog (RFC 9727).', 'make-my-site-agent-ready' ),
			],
			'agent_skills'  => [
				__( 'Agent Skills discovery', 'make-my-site-agent-ready' ),
				__( 'Publishes an Agent Skills index at /.well-known/agent-skills/ describing how agents can work with this site.', 'make-my-site-agent-ready' ),
			],
		];
	}

	public static function sanitize_features( $input ) {
		$out = [];
		// Write every key explicitly. An unchecked checkbox posts nothing, so a key missing from
		// $input means "off" here — unlike mmsar_feature_enabled(), where missing means "never saved".
		foreach ( array_keys( mmsar_get_feature_keys() ) as $key ) {
			$out[ $key ] = ( isset( $input[ $key ] ) && '1' === $input[ $key ] ) ? '1' : '0';
		}
		// Enabling or disabling a feature adds or removes rewrite rules, which only take effect
		// after a flush.
		delete_transient( 'llmmd_llms_txt' );
		delete_transient( 'mmsar_llms_full_txt' );
		set_transient( 'mmsar_flush_needed', 1, MINUTE_IN_SECONDS );
		return $out;
	}

	public static function render_features_section() {
		echo '<p>';
		esc_html_e( 'Everything this plugin publishes is listed here. All of it is on by default — switch off anything you already handle elsewhere, and the plugin will stop touching it entirely.', 'make-my-site-agent-ready' );
		echo '</p>';
	}

	public static function render_features_field() {
		foreach ( self::get_feature_labels() as $key => $labels ) {
			list( $label, $description ) = $labels;
			$checked                     = mmsar_feature_enabled( $key ) ? 'checked' : '';
			echo '<div style="margin-bottom:14px;">';
			echo '<label style="font-weight:600;">';
			echo '<input type="checkbox" name="mmsar_features[' . esc_attr( $key ) . ']" value="1" ' . esc_attr( $checked ) . '> ';
			echo esc_html( $label );
			echo '</label>';
			echo '<p class="description" style="margin-left:24px;">' . esc_html( $description ) . '</p>';
			echo '</div>';
		}
	}

	public static function register_settings() {
		// Feature toggles.
		register_setting( 'mmsar_settings_group', 'mmsar_features', [
			'sanitize_callback' => [ __CLASS__, 'sanitize_features' ],
		] );

		add_settings_section(
			'mmsar_features',
			__( 'Features', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_features_section' ],
			'make-my-site-agent-ready'
		);

		add_settings_field(
			'mmsar_features_enabled',
			__( 'Enabled Features', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_features_field' ],
			'make-my-site-agent-ready',
			'mmsar_features'
		);

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

		// These two only make sense while the plugin is actually generating robots.txt. When it
		// isn't, the section shows the opt-out explanation on its own.
		if ( mmsar_feature_enabled( 'robots_txt' ) ) {
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
		}

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

		// Structured data (JSON-LD) settings.
		register_setting( 'mmsar_settings_group', 'mmsar_structured_data', [
			'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
			'default'           => '',
		] );

		add_settings_section(
			'mmsar_structured_data',
			__( 'Structured Data', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_structured_data_section' ],
			'make-my-site-agent-ready'
		);

		add_settings_field(
			'mmsar_structured_data_enabled',
			__( 'JSON-LD', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_structured_data_field' ],
			'make-my-site-agent-ready',
			'mmsar_structured_data'
		);

		// security.txt settings.
		register_setting( 'mmsar_settings_group', 'mmsar_security_txt_contact', [
			'sanitize_callback' => 'sanitize_text_field',
		] );

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
			'mmsar_security_txt_contact',
			__( 'Security Contact', 'make-my-site-agent-ready' ),
			[ __CLASS__, 'render_security_txt_contact_field' ],
			'make-my-site-agent-ready',
			'mmsar_security_txt'
		);

		add_settings_field(
			'mmsar_security_txt_content',
			__( 'Full Content (advanced)', 'make-my-site-agent-ready' ),
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
		if ( ! mmsar_feature_enabled( 'robots_txt' ) ) {
			echo '<p class="description"><em>';
			esc_html_e( 'Content Signals are published as a directive inside robots.txt, which is switched off in Features above. These settings are saved but have no effect until robots.txt handling is switched back on.', 'make-my-site-agent-ready' );
			echo '</em></p>';
			return;
		}
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

	public static function sanitize_checkbox( $input ) {
		return ( '1' === $input ) ? '1' : '';
	}

	public static function render_structured_data_section() {
		if ( ! mmsar_feature_enabled( 'markdown' ) ) {
			echo '<p class="description"><em>';
			esc_html_e( 'This structured data exists only to point agents at the .md version of a page, and Markdown URLs are switched off in Features above, so it has nothing to advertise. Switch Markdown URLs back on to use it.', 'make-my-site-agent-ready' );
			echo '</em></p>';
			return;
		}
		echo '<p>';
		printf(
			/* translators: %s: link to validator.schema.org */
			esc_html__( 'Adds a pointer to the .md alternate (Article/WebPage type, dates, and a markdown link) to each enabled post/page. Off by default. If Yoast SEO is active and produces structured data for the page, the pointer merges directly into Yoast\'s own schema — no duplicate block. Otherwise (no Yoast, or Yoast doesn\'t cover this page type), a standalone JSON-LD block is added instead; if a different SEO plugin like RankMath is active, you may not need both. Validate the output at %s before relying on it.', 'make-my-site-agent-ready' ),
			'<a href="https://validator.schema.org/" target="_blank">validator.schema.org</a>'
		);
		echo '</p>';
	}

	public static function render_structured_data_field() {
		$checked = ( '1' === get_option( 'mmsar_structured_data', '' ) ) ? 'checked' : '';
		echo '<label>';
		echo '<input type="checkbox" name="mmsar_structured_data" value="1" ' . $checked . '> ';
		esc_html_e( 'Add JSON-LD structured data pointing agents at the markdown alternate.', 'make-my-site-agent-ready' );
		echo '</label>';
	}

	public static function render_robots_txt_section() {
		$url = home_url( '/robots.txt' );

		if ( ! mmsar_feature_enabled( 'robots_txt' ) ) {
			echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p><strong>';
			esc_html_e( 'robots.txt handling is switched off.', 'make-my-site-agent-ready' );
			echo '</strong></p><p>';
			esc_html_e( 'This plugin is not touching your robots.txt at all — whatever served it before (a static file, your SEO plugin, or WordPress itself) is serving it unchanged. Because of that, your site is not publishing:', 'make-my-site-agent-ready' );
			echo '</p><ul style="list-style:disc;margin-left:22px;">';
			echo '<li>' . esc_html__( 'Explicit Allow rules for AI crawlers (GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, FacebookBot). Without them, these crawlers fall back to your general rules, which may be more restrictive than you intend.', 'make-my-site-agent-ready' ) . '</li>';
			echo '<li>' . esc_html__( 'The Content-Signal directive declaring how AI systems may use your content. The Content Signals settings below have no effect while this is off, because those directives are written into robots.txt.', 'make-my-site-agent-ready' ) . '</li>';
			echo '<li>' . esc_html__( 'A Sitemap directive, if nothing else on your site already adds one.', 'make-my-site-agent-ready' ) . '</li>';
			echo '</ul><p>';
			esc_html_e( 'If you manage AI crawler rules yourself, that is fine — nothing is broken. Add the rules to your own robots.txt, or switch this feature back on in Features above.', 'make-my-site-agent-ready' );
			echo '</p></div>';
			return;
		}

		echo '<p>';
		printf(
			/* translators: %s: robots.txt URL */
			esc_html__( 'This plugin appends explicit Allow rules for AI crawlers (GPTBot, ClaudeBot, etc.), a Content-Signal directive, and a Sitemap directive to %s.', 'make-my-site-agent-ready' ),
			'<a href="' . esc_url( $url ) . '" target="_blank"><code>robots.txt</code></a>'
		);
		echo '</p>';
		echo '<p>';
		esc_html_e( 'It appends rather than replaces, so it works alongside any robots.txt that WordPress generates on the fly — including one produced by an SEO plugin such as Yoast, Rank Math or All in One SEO. Their rules stay exactly as they are, and the AI crawler rules are added underneath.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '<p>';
		esc_html_e( 'It also tries to route /robots.txt through WordPress so these rules still apply on sites that have a physical robots.txt file in the site root. Whether that succeeds depends on your server: it works on Apache, but nginx and most CDNs serve an existing file directly without ever asking WordPress, so the physical file keeps winning. If you maintain that file deliberately, switch this feature off in Features above and the plugin will stop trying.', 'make-my-site-agent-ready' );
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
		echo '<p class="description">' . esc_html__( 'Read-only preview of the robots.txt output. Some plugins only modify robots.txt on front-end requests, so the served file can differ slightly from this preview — open /robots.txt above to see the real thing.', 'make-my-site-agent-ready' ) . '</p>';
	}

	public static function render_robots_txt_field() {
		$value = get_option( 'mmsar_robots_txt_extra', '' );
		echo '<textarea name="mmsar_robots_txt_extra" rows="5" class="large-text code" placeholder="# e.g. User-agent: Bingbot&#10;# Allow: /">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Optional extra directives appended to robots.txt. Leave empty if you only need the default AI crawler rules.', 'make-my-site-agent-ready' ) . '</p>';
	}

	public static function render_security_txt_section() {
		if ( ! mmsar_feature_enabled( 'security_txt' ) ) {
			echo '<p class="description"><em>';
			esc_html_e( 'security.txt is switched off in Features above, so nothing is served at /.well-known/security.txt. These settings are saved but inactive.', 'make-my-site-agent-ready' );
			echo '</em></p>';
			return;
		}
		$url = home_url( '/.well-known/security.txt' );
		echo '<p>';
		printf(
			/* translators: %s: security.txt URL */
			esc_html__( 'Serves a security contact file at %s per RFC 9116. This is where a security researcher looks first when they find a vulnerability on your site and want to report it responsibly.', 'make-my-site-agent-ready' ),
			'<a href="' . esc_url( $url ) . '" target="_blank"><code>/.well-known/security.txt</code></a>'
		);
		echo '</p>';
	}

	public static function render_security_txt_contact_field() {
		$value = get_option( 'mmsar_security_txt_contact', '' );
		echo '<input type="text" name="mmsar_security_txt_contact" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="/contact">';
		echo '<p class="description">';
		esc_html_e( 'Where should someone report a security issue? Usually your contact page. Paste the full URL of that page, or just the path.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '<p class="description">';
		printf(
			/* translators: 1: full URL example, 2: path example, 3: email example */
			esc_html__( 'All of these work: %1$s, %2$s, or an email address like %3$s.', 'make-my-site-agent-ready' ),
			'<code>' . esc_html( home_url( '/contact' ) ) . '</code>',
			'<code>/contact</code>',
			'<code>security@example.com</code>'
		);
		echo '</p>';

		$resolved = MMSAR_Endpoints::normalize_contact( $value );
		if ( '' === $resolved ) {
			echo '<p class="description"><strong>';
			printf(
				/* translators: %s: site admin email address */
				esc_html__( 'Nothing set, so the file currently falls back to your admin email (%s). Setting a contact page is usually better.', 'make-my-site-agent-ready' ),
				'<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>'
			);
			echo '</strong></p>';
		} else {
			echo '<p class="description">';
			printf(
				/* translators: %s: the resolved Contact line that will be published */
				esc_html__( 'Will publish: %s', 'make-my-site-agent-ready' ),
				'<code>Contact: ' . esc_html( $resolved ) . '</code>'
			);
			echo '</p>';
		}
	}

	public static function render_security_txt_field() {
		$value       = get_option( 'mmsar_security_txt', '' );
		$placeholder = MMSAR_Endpoints::default_security_txt();
		echo '<textarea name="mmsar_security_txt" rows="6" class="large-text code" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Optional. Leave this empty unless you need extra fields such as Encryption, Acknowledgments or Policy — the Security Contact above is enough for most sites. Anything entered here replaces the generated file entirely, including the Contact line, so it must contain both Contact and Expires.', 'make-my-site-agent-ready' ) . '</p>';
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only link to endpoints that are actually being served — a quick link to a 404 is a bug report
		// waiting to happen. robots.txt always exists, so it is listed unconditionally.
		$quick_links = [];
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$quick_links[ __( 'llms.txt', 'make-my-site-agent-ready' ) ] = home_url( '/llms.txt' );
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$quick_links[ __( 'llms-full.txt', 'make-my-site-agent-ready' ) ] = home_url( '/llms-full.txt' );
		}
		if ( mmsar_feature_enabled( 'security_txt' ) ) {
			$quick_links[ __( 'security.txt', 'make-my-site-agent-ready' ) ] = home_url( '/.well-known/security.txt' );
		}
		$quick_links[ __( 'robots.txt', 'make-my-site-agent-ready' ) ] = home_url( '/robots.txt' );
		if ( mmsar_feature_enabled( 'api_catalog' ) ) {
			$quick_links[ __( 'api-catalog', 'make-my-site-agent-ready' ) ] = home_url( '/.well-known/api-catalog' );
		}
		if ( mmsar_feature_enabled( 'agent_skills' ) ) {
			$quick_links[ __( 'Agent Skills index', 'make-my-site-agent-ready' ) ] = home_url( '/.well-known/agent-skills/index.json' );
		}
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
			<?php foreach ( $quick_links as $label => $link_url ) : ?>
				<p>
					<strong><?php echo esc_html( $label ); ?>:</strong>
					<a href="<?php echo esc_url( $link_url ); ?>" target="_blank"><?php echo esc_html( $link_url ); ?></a>
				</p>
			<?php endforeach; ?>

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
