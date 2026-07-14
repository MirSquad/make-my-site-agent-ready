<?php
/**
 * Optional JSON-LD structured data pointing agents at the markdown alternate.
 * Opt-in, default off — see decisions-log.md for why this stays deliberately
 * minimal and separate from any SEO plugin's own structured-data graph.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMSAR_Structured_Data {

	public static function init() {
		add_action( 'wp_head', [ __CLASS__, 'output' ], 25 );
	}

	public static function output() {
		if ( '1' !== get_option( 'mmsar_structured_data', '' ) ) {
			return;
		}

		if ( ! is_singular( mmsar_get_enabled_post_types() ) ) {
			return;
		}

		$post_id  = get_the_ID();
		$markdown = get_post_meta( $post_id, '_llmmd_content', true );
		if ( empty( $markdown ) ) {
			return;
		}

		$md_url = mmsar_get_markdown_url();
		if ( ! $md_url ) {
			return;
		}

		$is_article = ( 'post' === get_post_type( $post_id ) );
		$title      = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$data = [
			'@context'      => 'https://schema.org',
			'@type'         => $is_article ? 'Article' : 'WebPage',
			'url'           => get_permalink( $post_id ),
			'datePublished' => get_the_date( 'c', $post_id ),
			'dateModified'  => get_the_modified_date( 'c', $post_id ),
			'encoding'      => [
				'@type'          => 'MediaObject',
				'contentUrl'     => $md_url,
				'encodingFormat' => 'text/markdown',
			],
		];
		$data[ $is_article ? 'headline' : 'name' ] = $title;

		// JSON_HEX_TAG escapes < and > (as </>) so a title containing a literal
		// "</script>" can't break out of the surrounding script tag.
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . '</script>' . "\n";
	}
}
