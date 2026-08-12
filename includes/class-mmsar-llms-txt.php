<?php
/**
 * Make My Site Agent-Ready — llms txt component.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR LLMs Txt handler.
 */
class MMSAR_LLMs_Txt {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_llms_txt' ) );
	}

	/**
	 * Add rewrite rules.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule(
			'^llms\.txt$',
			'index.php?llmmd_llms_txt=1',
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
		$vars[] = 'llmmd_llms_txt';
		return $vars;
	}

	/**
	 * Serve llms txt.
	 *
	 * @return void
	 */
	public static function serve_llms_txt() {
		if ( ! get_query_var( 'llmmd_llms_txt' ) ) {
			return;
		}

		$content = get_transient( 'llmmd_llms_txt' );
		if ( false === $content ) {
			$content = self::generate();
			set_transient( 'llmmd_llms_txt', $content, DAY_IN_SECONDS );
		}

		// Registered endpoints are appended after the cache, not baked into it. The cached body is
		// invalidated by content changes, but the registry lives in code — an endpoint registered by
		// a plugin activated today would otherwise not appear until the day-long transient expired.
		$content .= MMSAR_Registry::llms_txt_section();

		/**
		 * Filters the complete llms.txt body before it is served.
		 *
		 * Runs on every request, after the cached content is assembled, so a filter here is free to
		 * depend on things the cache knows nothing about. Most integrations want the
		 * mmsar_registered_endpoints filter instead, which lists an endpoint here and in the
		 * api-catalog and Agent Skills index at the same time.
		 *
		 * @param string $content The llms.txt body.
		 */
		$content = (string) apply_filters( 'mmsar_llms_txt_content', $content );

		mmsar_send_cache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/plain llms.txt, not HTML.
		echo $content;
		exit;
	}

	/**
	 * Generate.
	 *
	 * @return mixed Result.
	 */
	public static function generate() {
		$site_name   = self::decode( get_bloginfo( 'name' ) );
		$description = self::decode( get_bloginfo( 'description' ) );
		$home_url    = home_url( '/' );

		$lines   = array();
		$lines[] = '# ' . $site_name;
		if ( ! empty( $description ) ) {
			$lines[] = '';
			$lines[] = '> ' . $description;
		}
		$lines[] = '';
		$lines[] = '## Site';
		$lines[] = '- [Home](' . $home_url . ')';

		$front_page_id = get_option( 'page_on_front' );
		if ( $front_page_id ) {
			$lines[] = '- [Home (Markdown)](' . rtrim( $home_url, '/' ) . '/index.md)';
		}

		$post_types   = mmsar_get_enabled_post_types();
		$pages        = self::get_posts_by_type( 'page', $post_types );
		$posts        = self::get_posts_by_type( 'post', $post_types );
		$custom_posts = self::get_custom_type_posts( $post_types );

		if ( ! empty( $pages ) ) {
			$lines[] = '';
			$lines[] = '## Pages';
			foreach ( $pages as $page ) {
				$lines[] = self::format_entry( $page );
			}
		}

		if ( ! empty( $posts ) ) {
			$categories = get_categories( array( 'hide_empty' => true ) );

			if ( ! empty( $categories ) ) {
				foreach ( $categories as $cat ) {
					$cat_posts = self::get_posts_in_category( $cat->term_id, $post_types );
					if ( empty( $cat_posts ) ) {
						continue;
					}
					$lines[] = '';
					$lines[] = '## ' . self::decode( $cat->name );
					foreach ( $cat_posts as $p ) {
						$lines[] = self::format_entry( $p );
					}
				}
			} else {
				$lines[] = '';
				$lines[] = '## Posts';
				foreach ( $posts as $p ) {
					$lines[] = self::format_entry( $p );
				}
			}
		}

		foreach ( $custom_posts as $type_name => $type_posts ) {
			$type_obj = get_post_type_object( $type_name );
			$label    = $type_obj ? self::decode( $type_obj->labels->name ) : $type_name;
			$lines[]  = '';
			$lines[]  = '## ' . $label;
			foreach ( $type_posts as $p ) {
				$lines[] = self::format_entry( $p );
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Format entry.
	 *
	 * @param mixed $post Post.
	 * @return mixed Result.
	 */
	private static function format_entry( $post ) {
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id && $front_page_id === $post->ID ) {
			$url = rtrim( home_url(), '/' ) . '/index.md';
		} else {
			$url = rtrim( get_permalink( $post->ID ), '/' ) . '.md';
		}
		$title   = str_replace( array( '[', ']' ), array( '\[', '\]' ), self::decode( get_the_title( $post ) ) );
		$excerpt = self::decode( get_the_excerpt( $post ) );
		$line    = '- [' . $title . '](' . $url . ')';
		if ( ! empty( $excerpt ) ) {
			$line .= ': ' . $excerpt;
		}
		return $line;
	}

	/**
	 * Decode.
	 *
	 * @param mixed $str Str.
	 * @return mixed Result.
	 */
	private static function decode( $str ) {
		return html_entity_decode( $str, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Get posts by type.
	 *
	 * @param mixed $type Type.
	 * @param mixed $enabled_types Enabled types.
	 * @return mixed Result.
	 */
	private static function get_posts_by_type( $type, $enabled_types ) {
		if ( ! in_array( $type, $enabled_types, true ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Get custom type posts.
	 *
	 * @param mixed $enabled_types Enabled types.
	 * @return mixed Result.
	 */
	private static function get_custom_type_posts( $enabled_types ) {
		$custom = array_diff( $enabled_types, array( 'post', 'page' ) );
		$result = array();
		foreach ( $custom as $type ) {
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
			if ( ! empty( $posts ) ) {
				$result[ $type ] = $posts;
			}
		}
		return $result;
	}

	/**
	 * Get posts in category.
	 *
	 * @param mixed $cat_id Cat id.
	 * @param mixed $enabled_types Enabled types.
	 * @return mixed Result.
	 */
	private static function get_posts_in_category( $cat_id, $enabled_types ) {
		if ( ! in_array( 'post', $enabled_types, true ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'has_password'   => false,
				'cat'            => $cat_id,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}
}
