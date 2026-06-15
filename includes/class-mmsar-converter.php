<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\HTMLToMarkdown\HtmlConverter;

class MMSAR_Converter {

	public static function convert_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return '';
		}

		$frontmatter = self::build_frontmatter( $post );
		$content     = self::get_rendered_content( $post );
		$markdown    = self::html_to_markdown( $content );

		return $frontmatter . $markdown;
	}

	private static function build_frontmatter( $post ) {
		$author = get_userdata( $post->post_author );
		$url    = get_permalink( $post );

		$lines   = [];
		$lines[] = '---';
		$lines[] = 'title: "' . self::escape_yaml( get_the_title( $post ) ) . '"';
		$lines[] = 'date: ' . get_the_date( 'Y-m-d', $post );
		$lines[] = 'modified: ' . get_the_modified_date( 'Y-m-d', $post );
		if ( $author ) {
			$lines[] = 'author: "' . self::escape_yaml( $author->display_name ) . '"';
		}
		$lines[] = 'url: "' . $url . '"';
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id && $front_page_id === $post->ID ) {
			$lines[] = 'markdown_url: "' . rtrim( $url, '/' ) . '/index.md"';
		} else {
			$lines[] = 'markdown_url: "' . rtrim( $url, '/' ) . '.md"';
		}
		$lines[] = 'type: ' . $post->post_type;

		$excerpt = get_the_excerpt( $post );
		if ( $excerpt ) {
			$lines[] = 'excerpt: "' . self::escape_yaml( $excerpt ) . '"';
		}

		$categories = get_the_category( $post->ID );
		if ( ! empty( $categories ) ) {
			$lines[] = 'categories:';
			foreach ( $categories as $cat ) {
				$lines[] = '  - "' . self::escape_yaml( $cat->name ) . '"';
			}
		}

		$tags = get_the_tags( $post->ID );
		if ( ! empty( $tags ) ) {
			$lines[] = 'tags:';
			foreach ( $tags as $tag ) {
				$lines[] = '  - "' . self::escape_yaml( $tag->name ) . '"';
			}
		}

		$lines[] = '---';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	private static function escape_yaml( $str ) {
		$str = html_entity_decode( $str, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$str = str_replace( '\\', '\\\\', $str );
		$str = str_replace( '"', '\\"', $str );
		return $str;
	}

	private static function get_rendered_content( $post ) {
		$content = $post->post_content;

		if ( has_blocks( $content ) ) {
			$content = do_blocks( $content );
		}
		$content = do_shortcode( $content );
		$content = wpautop( $content );
		$content = wptexturize( $content );

		$root_selector = mmsar_get_root_selector();
		if ( ! empty( $root_selector ) ) {
			$content = self::extract_root( $content, $root_selector );
		}

		$content = apply_filters( 'mmsar_rendered_content', $content, $post );

		return $content;
	}

	private static function extract_root( $html, $selector ) {
		if ( empty( trim( $html ) ) ) {
			return $html;
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath     = new DOMXPath( $doc );
		$selectors = array_map( 'trim', explode( ',', $selector ) );

		foreach ( $selectors as $sel ) {
			$xp    = self::css_to_xpath( $sel );
			$nodes = $xpath->query( $xp );
			if ( $nodes && $nodes->length > 0 ) {
				$output = '';
				foreach ( $nodes as $node ) {
					$output .= $doc->saveHTML( $node );
				}
				return $output;
			}
		}

		return $html;
	}

	private static function css_to_xpath( $selector ) {
		$selector = trim( $selector );
		if ( strpos( $selector, '#' ) === 0 ) {
			$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', substr( $selector, 1 ) );
			return "//*[@id='" . $id . "']";
		}
		if ( strpos( $selector, '.' ) === 0 ) {
			$class = preg_replace( '/[^a-zA-Z0-9_-]/', '', substr( $selector, 1 ) );
			return "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]";
		}
		$tag = preg_replace( '/[^a-zA-Z0-9]/', '', $selector );
		return '//' . $tag;
	}

	private static function html_to_markdown( $html ) {
		if ( empty( trim( $html ) ) ) {
			return '';
		}

		$html = preg_replace( '/<script\b[^>]*>.*?<\/script>/si', '', $html );
		$html = preg_replace( '/<style\b[^>]*>.*?<\/style>/si', '', $html );
		$html = preg_replace( '/<iframe\b[^>]*>.*?<\/iframe>/si', '', $html );
		$html = preg_replace( '/<nav\b[^>]*>.*?<\/nav>/si', '', $html );

		try {
			$converter = new HtmlConverter( [
				'strip_tags'              => true,
				'remove_nodes'            => 'script style iframe',
				'hard_break'              => false,
				'header_style'            => 'atx',
				'strip_placeholder_links' => true,
			] );

			$markdown = $converter->convert( $html );
		} catch ( \Exception $e ) {
			$markdown = wp_strip_all_tags( $html );
		}

		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );
		$markdown = trim( $markdown );

		return $markdown . "\n";
	}
}
