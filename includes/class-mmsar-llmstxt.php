<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMSAR_LLMs_Txt {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'serve_llms_txt' ] );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule(
			'^llms\.txt$',
			'index.php?llmmd_llms_txt=1',
			'top'
		);
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'llmmd_llms_txt';
		return $vars;
	}

	public static function serve_llms_txt() {
		if ( ! get_query_var( 'llmmd_llms_txt' ) ) {
			return;
		}

		$content = get_transient( 'llmmd_llms_txt' );
		if ( false === $content ) {
			$content = self::generate();
			set_transient( 'llmmd_llms_txt', $content, DAY_IN_SECONDS );
		}

		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/plain llms.txt, not HTML.
		echo $content;
		exit;
	}

	public static function generate() {
		$site_name   = self::decode( get_bloginfo( 'name' ) );
		$description = self::decode( get_bloginfo( 'description' ) );
		$home_url    = home_url( '/' );

		$lines   = [];
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
			$categories = get_categories( [ 'hide_empty' => true ] );

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

	private static function format_entry( $post ) {
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id && $front_page_id === $post->ID ) {
			$url = rtrim( home_url(), '/' ) . '/index.md';
		} else {
			$url = rtrim( get_permalink( $post->ID ), '/' ) . '.md';
		}
		$title   = str_replace( [ '[', ']' ], [ '\[', '\]' ], self::decode( get_the_title( $post ) ) );
		$excerpt = self::decode( get_the_excerpt( $post ) );
		$line    = '- [' . $title . '](' . $url . ')';
		if ( ! empty( $excerpt ) ) {
			$line .= ': ' . $excerpt;
		}
		return $line;
	}

	private static function decode( $str ) {
		return html_entity_decode( $str, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function get_posts_by_type( $type, $enabled_types ) {
		if ( ! in_array( $type, $enabled_types, true ) ) {
			return [];
		}
		return get_posts( [
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
	}

	private static function get_custom_type_posts( $enabled_types ) {
		$custom = array_diff( $enabled_types, [ 'post', 'page' ] );
		$result = [];
		foreach ( $custom as $type ) {
			$posts = get_posts( [
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			] );
			if ( ! empty( $posts ) ) {
				$result[ $type ] = $posts;
			}
		}
		return $result;
	}

	private static function get_posts_in_category( $cat_id, $enabled_types ) {
		if ( ! in_array( 'post', $enabled_types, true ) ) {
			return [];
		}
		return get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'cat'            => $cat_id,
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );
	}
}
