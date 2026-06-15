<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMSAR_Server {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'serve_markdown' ] );
		add_filter( 'redirect_canonical', [ __CLASS__, 'prevent_redirect' ], 10, 2 );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule(
			'^(.+)\.md/?$',
			'index.php?llmmd_path=$matches[1]&llmmd_serve=1',
			'top'
		);
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'llmmd_path';
		$vars[] = 'llmmd_serve';
		return $vars;
	}

	public static function prevent_redirect( $redirect_url, $requested_url ) {
		if ( get_query_var( 'llmmd_serve' ) ) {
			return false;
		}
		return $redirect_url;
	}

	public static function serve_markdown() {
		if ( ! get_query_var( 'llmmd_serve' ) ) {
			return;
		}

		$post_id = self::resolve_post_id();
		if ( ! $post_id ) {
			status_header( 404 );
			echo '# 404 Not Found' . "\n\n";
			echo esc_html__( 'The requested content was not found.', 'make-my-site-agent-ready' ) . "\n";
			exit;
		}

		$post = get_post( $post_id );

		if ( ! empty( $post->post_password ) ) {
			status_header( 403 );
			echo '# 403 Forbidden' . "\n\n";
			echo esc_html__( 'This content is password protected.', 'make-my-site-agent-ready' ) . "\n";
			exit;
		}

		if ( ! in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
			status_header( 404 );
			echo '# 404 Not Found' . "\n\n";
			echo esc_html__( 'Markdown is not available for this content type.', 'make-my-site-agent-ready' ) . "\n";
			exit;
		}

		$markdown = get_post_meta( $post_id, '_llmmd_content', true );

		if ( empty( $markdown ) ) {
			$markdown = MMSAR_Converter::convert_post( $post_id );
			if ( ! empty( $markdown ) ) {
				update_post_meta( $post_id, '_llmmd_content', $markdown );
			}
		}

		if ( empty( $markdown ) ) {
			status_header( 404 );
			echo '# 404 Not Found' . "\n\n";
			echo esc_html__( 'No content available.', 'make-my-site-agent-ready' ) . "\n";
			exit;
		}

		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Link: <' . esc_url( get_permalink( $post_id ) ) . '>; rel="canonical"' );
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw markdown as text/markdown, not HTML.
		echo $markdown;
		exit;
	}

	private static function resolve_post_id() {
		$path = get_query_var( 'llmmd_path' );
		if ( empty( $path ) ) {
			return 0;
		}

		if ( 'index' === $path ) {
			$front_page = get_option( 'page_on_front' );
			return $front_page ? (int) $front_page : 0;
		}

		$post_id = url_to_postid( home_url( '/' . $path . '/' ) );
		if ( $post_id ) {
			return $post_id;
		}

		$post_id = url_to_postid( home_url( '/' . $path ) );
		if ( $post_id ) {
			return $post_id;
		}

		$post = get_page_by_path( $path, OBJECT, mmsar_get_enabled_post_types() );
		if ( $post ) {
			return $post->ID;
		}

		return 0;
	}
}
