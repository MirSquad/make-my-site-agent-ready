<?php
/**
 * A public, read-only Model Context Protocol server for this site's content.
 *
 * Everything else this plugin publishes is a document an agent has to find, fetch and interpret.
 * MCP is the other half: a connection an agent opens once, asks "what can I do here", and then
 * calls. For a content site the answer is small and stable — search it, read a page, list what is
 * recent — which is exactly the shape MCP handles well and exactly what an agent otherwise has to
 * reconstruct by crawling.
 *
 * Three deliberate limits, because this endpoint is unauthenticated and world-reachable:
 *
 *   1. Read-only. No tool here writes anything, and none is capable of it. A site that wants agents
 *      to *do* things should expose those actions through its own authenticated endpoint and list
 *      it in the registry, where the auth requirement can be stated.
 *   2. Published content only, from the post types already enabled for Markdown, skipping anything
 *      password-protected. This exposes nothing that /llms-full.txt does not already publish.
 *   3. Rate-limited per IP, because unlike a static document these calls run queries.
 *
 * Transport is Streamable HTTP (MCP 2025-06-18): one endpoint, JSON-RPC 2.0 over POST, answering
 * with a plain JSON body. The spec permits a JSON response instead of an SSE stream when the server
 * has nothing to stream, which is always true here — every tool returns a complete result in one
 * step, so there is no progress to report and no server-initiated message to deliver.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR MCP server.
 */
class MMSAR_MCP {

	/**
	 * REST namespace and route for the MCP endpoint.
	 */
	const NAMESPACE_V1 = 'mmsar/v1';
	const ROUTE        = '/mcp';

	/**
	 * The MCP protocol revision this server implements.
	 */
	const PROTOCOL_VERSION = '2025-06-18';

	/**
	 * Protocol revisions this server can speak, newest first. A client that asks for one of these
	 * gets it back; a client that asks for anything else is answered with our own version, which
	 * the spec says it may then accept or disconnect over.
	 */
	const SUPPORTED_PROTOCOLS = array( '2025-06-18', '2025-03-26' );

	/**
	 * Largest number of items any tool will return in one call, whatever the client asks for.
	 */
	const MAX_LIMIT = 50;

	/**
	 * Requests allowed per IP per window, and the window in seconds.
	 */
	const RATE_LIMIT  = 60;
	const RATE_WINDOW = 60;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_manifest' ) );
	}

	/**
	 * The absolute URL agents connect to.
	 *
	 * @return string Absolute URL.
	 */
	public static function endpoint_url() {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * Register the JSON-RPC route.
	 *
	 * @return void
	 */
	public static function register_route() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					// Public on purpose: this is the anonymous read-only surface, and everything it
					// can reach is already published on the site's own pages.
					'permission_callback' => '__return_true',
				),
				// The Streamable HTTP transport lets a client open a GET for a server-initiated SSE
				// stream. This server never initiates anything, so it declines — which the spec
				// explicitly provides for — rather than holding a connection open forever.
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'decline_stream' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// JSON-RPC plumbing
	// -------------------------------------------------------------------------

	/**
	 * Handle one JSON-RPC request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response Response.
	 */
	public static function handle( WP_REST_Request $request ) {
		if ( ! self::within_rate_limit() ) {
			// -32000 is in the JSON-RPC implementation-defined server error range. The HTTP status
			// matters too: a client that understands 429 can back off without parsing the body.
			return self::error_response( null, -32000, 'Rate limit exceeded. Try again shortly.', 429 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return self::error_response( null, -32700, 'Parse error: request body is not valid JSON.', 400 );
		}

		// A batch is a JSON array of requests. Answering each in turn and returning an array of the
		// responses that have ids is the whole of it — notifications contribute nothing.
		if ( isset( $body[0] ) ) {
			$results = array();
			foreach ( $body as $single ) {
				$result = is_array( $single ) ? self::dispatch( $single ) : null;
				if ( null !== $result ) {
					$results[] = $result;
				}
			}
			// An all-notification batch has nothing to say, and the spec asks for no body at all.
			if ( empty( $results ) ) {
				return new WP_REST_Response( null, 202 );
			}
			return new WP_REST_Response( $results, 200 );
		}

		$result = self::dispatch( $body );
		if ( null === $result ) {
			return new WP_REST_Response( null, 202 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Declines a GET on the MCP endpoint.
	 *
	 * @return WP_REST_Response Response.
	 */
	public static function decline_stream() {
		return self::error_response( null, -32000, 'This server does not offer a server-initiated event stream. POST JSON-RPC requests to this URL instead.', 405 );
	}

	/**
	 * Route one JSON-RPC message to its handler.
	 *
	 * @param array $message Decoded JSON-RPC message.
	 * @return array|null The response object, or null for a notification.
	 */
	private static function dispatch( $message ) {
		$method = isset( $message['method'] ) ? (string) $message['method'] : '';
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		// A message with no id is a notification: it is acted on, but never answered. Note that
		// id 0 and id "" are both legitimate ids, so this tests for the key, not for truthiness.
		$id     = array_key_exists( 'id', $message ) ? $message['id'] : null;
		$is_notification = ! array_key_exists( 'id', $message );

		switch ( $method ) {
			case 'initialize':
				$result = self::initialize( $params );
				break;
			case 'ping':
				$result = new stdClass();
				break;
			case 'tools/list':
				$result = array( 'tools' => self::tools() );
				break;
			case 'tools/call':
				$result = self::call_tool( $params );
				break;
			case 'resources/list':
				$result = array( 'resources' => self::resources() );
				break;
			case 'resources/read':
				$result = self::read_resource( $params );
				break;
			case 'prompts/list':
				$result = array( 'prompts' => array() );
				break;
			default:
				// Notifications the client sends that need no action — notifications/initialized,
				// notifications/cancelled — land here and are correctly answered with nothing.
				if ( $is_notification ) {
					return null;
				}
				return self::rpc_error( $id, -32601, 'Unknown method: ' . $method );
		}

		if ( $is_notification ) {
			return null;
		}
		if ( $result instanceof WP_Error ) {
			return self::rpc_error( $id, -32602, $result->get_error_message() );
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * The initialize handshake.
	 *
	 * @param array $params Client params.
	 * @return array Result object.
	 */
	private static function initialize( $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version   = in_array( $requested, self::SUPPORTED_PROTOCOLS, true ) ? $requested : self::PROTOCOL_VERSION;

		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array(
				// listChanged is false on both: this server pushes nothing, so promising to notify
				// a client when the tool or resource list changes would be a promise it cannot keep.
				'tools'     => array( 'listChanged' => false ),
				'resources' => array(
					'listChanged' => false,
					'subscribe'   => false,
				),
			),
			'serverInfo'      => array(
				'name'    => 'mmsar-' . sanitize_title( $site_name ),
				'title'   => $site_name,
				'version' => MMSAR_VERSION,
			),
			'instructions'    => self::instructions( $site_name ),
		);
	}

	/**
	 * The instructions block returned at initialize.
	 *
	 * This is the one place the server can tell a model how to use it well, and it is read once per
	 * session rather than per call, so it is worth being specific about which tool suits which job.
	 *
	 * @param string $site_name Site title.
	 * @return string Instructions.
	 */
	private static function instructions( $site_name ) {
		$description = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lines       = array();

		$lines[] = 'Read-only access to the published content of ' . $site_name . ' (' . home_url( '/' ) . ').';
		if ( '' !== trim( $description ) ) {
			$lines[] = '';
			$lines[] = $description;
		}
		$lines[] = '';
		$lines[] = 'Start with `search_content` when you have a topic, or `list_content` when you want to see what is here. Both return URLs; pass one to `get_content` to read the full text as Markdown. Do not fetch the site\'s HTML pages — `get_content` returns the same content already parsed.';
		$lines[] = '';
		$lines[] = 'Everything here is public and already published. There is no draft or private content behind this server, and nothing here can modify the site.';

		return implode( "\n", $lines );
	}

	/**
	 * Build a JSON-RPC error object.
	 *
	 * @param mixed  $id      Request id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 * @return array Error response.
	 */
	private static function rpc_error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * A JSON-RPC error wrapped in an HTTP response with a matching status.
	 *
	 * @param mixed  $id      Request id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response Response.
	 */
	private static function error_response( $id, $code, $message, $status ) {
		return new WP_REST_Response( self::rpc_error( $id, $code, $message ), $status );
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	/**
	 * Whether this caller is still under the per-IP limit.
	 *
	 * A counter in a transient, which is the coarsest possible approach and the right one here:
	 * the point is to stop one client from running unbounded queries against a public endpoint,
	 * not to meter usage precisely. Sites that want real limits have a WAF for it.
	 *
	 * A courtesy brake rather than a security control, and worth being clear about why: the caller
	 * is identified through MMSAR_Agent_Log::client_ip(), which prefers `CF-Connecting-IP` so that a
	 * site behind Cloudflare limits real callers rather than collapsing all of them into the one
	 * edge address — and a header like that can be forged by anything not actually behind Cloudflare.
	 * That trade is acceptable here only because everything past this check is published content
	 * served from cache-friendly queries; nothing behind it needs protecting.
	 *
	 * @return bool True when the request may proceed.
	 */
	private static function within_rate_limit() {
		/**
		 * Filters the number of MCP calls allowed per IP per minute.
		 *
		 * Return 0 to disable rate limiting entirely — appropriate only where something in front
		 * of WordPress is already limiting this endpoint.
		 *
		 * @param int $limit Requests per window. Default 60.
		 */
		$limit = (int) apply_filters( 'mmsar_mcp_rate_limit', self::RATE_LIMIT );
		if ( $limit <= 0 ) {
			return true;
		}

		// Shared with the agent log rather than re-derived, so the two can never disagree about who
		// a caller is.
		$key   = 'mmsar_mcp_rl_' . md5( MMSAR_Agent_Log::client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		// The window restarts from the first request in it rather than sliding. A burst can
		// therefore straddle two windows; that is an acceptable trade for one transient write.
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	// -------------------------------------------------------------------------
	// Tools
	// -------------------------------------------------------------------------

	/**
	 * The tool definitions returned by tools/list.
	 *
	 * @return array[] Tool definitions.
	 */
	public static function tools() {
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$tools = array(
			array(
				'name'         => 'search_content',
				'title'        => 'Search content',
				'description'  => 'Full-text search across the published posts and pages of ' . $site_name . '. Returns titles, URLs and excerpts, newest-relevant first. Use this when you know roughly what you are looking for; pass a returned url to get_content to read the whole thing.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'query'     => array(
							'type'        => 'string',
							'description' => 'Words to search for. Keep it to a few meaningful terms — this is a keyword search, not a semantic one, so a full sentence usually matches less than its two most specific words.',
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Restrict to one content type. Omit to search everything.',
							'enum'        => array_values( mmsar_get_enabled_post_types() ),
						),
						'limit'     => array(
							'type'        => 'integer',
							'description' => 'How many results to return, 1-' . self::MAX_LIMIT . '. Default 10.',
							'minimum'     => 1,
							'maximum'     => self::MAX_LIMIT,
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
				'annotations'  => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'get_content',
				'title'       => 'Read a page',
				'description' => 'Returns the full text of one published post or page as Markdown, along with its title, URL and publication date. Give it a URL from search_content or list_content, or any URL on this site.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array(
							'type'        => 'string',
							'description' => 'The page\'s URL on this site. A path such as `/about/` works too.',
						),
					),
					'required'             => array( 'url' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'list_content',
				'title'       => 'List recent content',
				'description' => 'Lists published content newest first, without searching. Use this to see what is on the site, to find the most recent writing, or to page through everything.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Which content type to list. Omit for all of them.',
							'enum'        => array_values( mmsar_get_enabled_post_types() ),
						),
						'limit'     => array(
							'type'        => 'integer',
							'description' => 'How many items to return, 1-' . self::MAX_LIMIT . '. Default 20.',
							'minimum'     => 1,
							'maximum'     => self::MAX_LIMIT,
						),
						'offset'    => array(
							'type'        => 'integer',
							'description' => 'How many items to skip, for paging through the whole list.',
							'minimum'     => 0,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'get_site_overview',
				'title'       => 'Site overview',
				'description' => 'What this site is, who runs it, how much content it has, and which machine-readable endpoints it publishes. Call this once at the start if you have no context on the site.',
				// Takes nothing. Stated as an explicitly empty, closed object rather than an open
				// one: a bare `properties: {}` reads to some clients as "schema not supplied", and
				// to a model as an invitation to guess an argument that will be ignored.
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => new stdClass(),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'openWorldHint'   => false,
				),
			),
		);

		/**
		 * Filters the tools this server advertises.
		 *
		 * Adding a tool here also means handling it on `mmsar_mcp_call_tool`, which is where the
		 * dispatcher hands off anything it does not recognize. Anything added must stay read-only
		 * and unauthenticated-safe: this endpoint has no user behind it.
		 *
		 * @param array[] $tools Tool definitions.
		 */
		$filtered = apply_filters( 'mmsar_mcp_tools', $tools );
		return is_array( $filtered ) ? $filtered : $tools;
	}

	/**
	 * Dispatch a tools/call.
	 *
	 * @param array $params Call params.
	 * @return array|WP_Error Tool result, or an error for a malformed call.
	 */
	private static function call_tool( $params ) {
		$name      = isset( $params['name'] ) ? (string) $params['name'] : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		switch ( $name ) {
			case 'search_content':
				return self::tool_search( $arguments );
			case 'get_content':
				return self::tool_get( $arguments );
			case 'list_content':
				return self::tool_list( $arguments );
			case 'get_site_overview':
				return self::tool_overview();
		}

		/**
		 * Filters the result of a tools/call this server does not handle itself.
		 *
		 * Return an MCP tool result array to handle the call. Anything else leaves it unhandled and
		 * the client gets an unknown-tool error.
		 *
		 * @param mixed  $result    Null until an integration handles the call.
		 * @param string $name      Tool name.
		 * @param array  $arguments Tool arguments.
		 */
		$result = apply_filters( 'mmsar_mcp_call_tool', null, $name, $arguments );
		if ( is_array( $result ) ) {
			return $result;
		}

		return new WP_Error( 'mmsar_unknown_tool', 'Unknown tool: ' . $name );
	}

	/**
	 * search_content.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Tool result.
	 */
	private static function tool_search( $arguments ) {
		$query = isset( $arguments['query'] ) ? trim( (string) $arguments['query'] ) : '';
		if ( '' === $query ) {
			return self::tool_error( 'Provide a `query` — one or more words to search for.' );
		}

		$posts = get_posts(
			array(
				'post_type'           => self::requested_post_types( $arguments ),
				'post_status'         => 'publish',
				's'                   => $query,
				'posts_per_page'      => self::requested_limit( $arguments, 10 ),
				'has_password'        => false,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		if ( empty( $posts ) ) {
			return self::tool_text(
				'No published content matched "' . $query . '".' . "\n\n"
				. 'This is a keyword search, so try fewer or more specific words, or call list_content to see what is on the site.'
			);
		}

		$lines = array( 'Found ' . count( $posts ) . ' result' . ( 1 === count( $posts ) ? '' : 's' ) . ' for "' . $query . '":', '' );
		foreach ( $posts as $post ) {
			$lines[] = '## ' . self::title( $post );
			$lines[] = 'URL: ' . get_permalink( $post );
			$lines[] = 'Type: ' . $post->post_type . ' · Published: ' . get_the_date( 'Y-m-d', $post );
			$excerpt = self::excerpt( $post );
			if ( '' !== $excerpt ) {
				$lines[] = '';
				$lines[] = $excerpt;
			}
			$lines[] = '';
		}
		$lines[] = 'Pass any of these URLs to get_content to read the full text.';

		return self::tool_text( implode( "\n", $lines ) );
	}

	/**
	 * get_content.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Tool result.
	 */
	private static function tool_get( $arguments ) {
		$url = isset( $arguments['url'] ) ? trim( (string) $arguments['url'] ) : '';
		if ( '' === $url ) {
			return self::tool_error( 'Provide a `url` — the address of a page on this site.' );
		}

		$post = self::resolve( $url );
		if ( ! $post ) {
			return self::tool_error(
				'No published content at ' . $url . '.' . "\n\n"
				. 'Check the URL is on this site (' . home_url( '/' ) . '), or use search_content to find the right page.'
			);
		}

		$markdown = get_post_meta( $post->ID, '_llmmd_content', true );
		if ( empty( $markdown ) ) {
			$markdown = MMSAR_Converter::convert_post( $post->ID );
			if ( ! empty( $markdown ) ) {
				update_post_meta( $post->ID, '_llmmd_content', $markdown );
			}
		}
		if ( empty( $markdown ) ) {
			return self::tool_error( 'That page exists but has no readable text content.' );
		}

		// Returned as-is. The converter already opens every document with YAML frontmatter carrying
		// the title, canonical URL and dates, so adding a header here would state all of it twice —
		// and put a stray `---` immediately above the frontmatter's own opening `---`.
		return self::tool_text( trim( $markdown ) );
	}

	/**
	 * list_content.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Tool result.
	 */
	private static function tool_list( $arguments ) {
		$offset = isset( $arguments['offset'] ) ? max( 0, (int) $arguments['offset'] ) : 0;
		$limit  = self::requested_limit( $arguments, 20 );

		$query = new WP_Query(
			array(
				'post_type'           => self::requested_post_types( $arguments ),
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				'offset'              => $offset,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'has_password'        => false,
				'ignore_sticky_posts' => true,
			)
		);

		if ( ! $query->have_posts() ) {
			return self::tool_text( 0 === $offset ? 'This site has no published content in those types.' : 'No more content past that offset.' );
		}

		$total = (int) $query->found_posts;
		$lines = array(
			'Showing ' . count( $query->posts ) . ' of ' . $total . ' published item' . ( 1 === $total ? '' : 's' )
			. ( $offset ? ', starting at ' . $offset : '' ) . ':',
			'',
		);
		foreach ( $query->posts as $post ) {
			$lines[] = '- **' . self::title( $post ) . '** (' . $post->post_type . ', ' . get_the_date( 'Y-m-d', $post ) . ')';
			$lines[] = '  ' . get_permalink( $post );
		}

		$shown = $offset + count( $query->posts );
		if ( $shown < $total ) {
			$lines[] = '';
			$lines[] = 'Call list_content again with offset=' . $shown . ' for the next page.';
		}

		return self::tool_text( implode( "\n", $lines ) );
	}

	/**
	 * get_site_overview.
	 *
	 * @return array Tool result.
	 */
	private static function tool_overview() {
		$site_name   = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$description = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines   = array( '# ' . $site_name, '' );
		if ( '' !== trim( $description ) ) {
			$lines[] = $description;
			$lines[] = '';
		}
		$lines[] = 'URL: ' . home_url( '/' );
		$lines[] = '';
		$lines[] = '## Content';
		$lines[] = '';
		foreach ( mmsar_get_enabled_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			$counts = wp_count_posts( $post_type );
			$label  = $object ? $object->labels->name : $post_type;
			$lines[] = '- ' . $label . ': ' . ( isset( $counts->publish ) ? (int) $counts->publish : 0 ) . ' published';
		}
		$lines[] = '';
		$lines[] = '## Machine-readable endpoints';
		$lines[] = '';
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$lines[] = '- ' . home_url( '/llms.txt' ) . ' — one-line index of the site';
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$lines[] = '- ' . home_url( '/llms-full.txt' ) . ' — the entire site as one document';
		}
		if ( mmsar_feature_enabled( 'openapi' ) ) {
			$lines[] = '- ' . MMSAR_OpenAPI::url() . ' — OpenAPI description of the HTTP API';
		}
		if ( mmsar_feature_enabled( 'api_catalog' ) ) {
			$lines[] = '- ' . home_url( '/.well-known/api-catalog' ) . ' — endpoint catalog';
		}
		$sitemap = mmsar_get_sitemap_url();
		if ( $sitemap ) {
			$lines[] = '- ' . $sitemap . ' — XML sitemap';
		}

		// Endpoints that can be called rather than just read are the ones an agent most wants to
		// know about, and the registry is the only place they are described.
		$callable = array();
		foreach ( MMSAR_Registry::get_endpoints() as $endpoint ) {
			if ( empty( $endpoint['methods'] ) || array( 'GET' ) === $endpoint['methods'] ) {
				continue;
			}
			$callable[] = '- **' . $endpoint['title'] . '** — `' . implode( ', ', $endpoint['methods'] ) . ' ' . $endpoint['href'] . '`'
				. ( '' !== $endpoint['description'] ? ' — ' . $endpoint['description'] : '' );
		}
		if ( $callable ) {
			$lines[] = '';
			$lines[] = '## Endpoints you can call';
			$lines[] = '';
			$lines[] = 'These are outside this MCP server — call them over plain HTTP.';
			$lines[] = '';
			foreach ( $callable as $line ) {
				$lines[] = $line;
			}
		}

		return self::tool_text( implode( "\n", $lines ) );
	}

	// -------------------------------------------------------------------------
	// Resources
	// -------------------------------------------------------------------------

	/**
	 * The resources returned by resources/list.
	 *
	 * The same documents the site already publishes over HTTP, offered through the connection the
	 * client already has. A client that can attach a resource to its context does not have to spend
	 * a tool call to read the site's index.
	 *
	 * @return array[] Resource descriptors.
	 */
	public static function resources() {
		$resources = array();

		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$resources[] = array(
				'uri'         => home_url( '/llms.txt' ),
				'name'        => 'llms.txt',
				'title'       => 'Content index',
				'description' => 'One line per page: what exists on this site and what each page is about.',
				'mimeType'    => 'text/plain',
			);
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$resources[] = array(
				'uri'         => home_url( '/llms-full.txt' ),
				'name'        => 'llms-full.txt',
				'title'       => 'Full site text',
				'description' => 'Every published post and page, in full, as one Markdown document. Large — prefer search_content unless you need everything.',
				'mimeType'    => 'text/plain',
			);
		}

		return $resources;
	}

	/**
	 * resources/read.
	 *
	 * Only the URIs this server advertises are readable. Fetching an arbitrary URI on request would
	 * turn an unauthenticated public endpoint into a request proxy, which is how a public MCP
	 * server becomes an SSRF vector against whatever the site can reach.
	 *
	 * @param array $params Request params.
	 * @return array|WP_Error Result, or an error.
	 */
	private static function read_resource( $params ) {
		$uri = isset( $params['uri'] ) ? (string) $params['uri'] : '';

		foreach ( self::resources() as $resource ) {
			if ( $resource['uri'] !== $uri ) {
				continue;
			}

			$response = wp_remote_get( $uri, array( 'timeout' => 10 ) );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'mmsar_resource_unavailable', 'Could not read ' . $uri . ': ' . $response->get_error_message() );
			}

			return array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => $resource['mimeType'],
						'text'     => wp_remote_retrieve_body( $response ),
					),
				),
			);
		}

		return new WP_Error( 'mmsar_unknown_resource', 'Unknown resource: ' . $uri . '. Call resources/list for the readable ones.' );
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Wrap text in an MCP tool result.
	 *
	 * @param string $text Result text.
	 * @return array Tool result.
	 */
	private static function tool_text( $text ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => false,
		);
	}

	/**
	 * A tool result that reports a problem with the call.
	 *
	 * Returned as a successful JSON-RPC response with isError set, not as a protocol error: the
	 * distinction in MCP is that a protocol error means the call could not be made, while this
	 * means the call was made and did not find anything. Only the second is something a model can
	 * usefully react to, so it must reach the model rather than the client's error handler.
	 *
	 * @param string $text Explanation, including what to try instead.
	 * @return array Tool result.
	 */
	private static function tool_error( $text ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => true,
		);
	}

	/**
	 * The post types a call asked for, always intersected with the enabled ones.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string[] Post types.
	 */
	private static function requested_post_types( $arguments ) {
		$enabled = mmsar_get_enabled_post_types();
		if ( empty( $arguments['post_type'] ) ) {
			return $enabled;
		}
		$requested = sanitize_key( (string) $arguments['post_type'] );
		return in_array( $requested, $enabled, true ) ? array( $requested ) : $enabled;
	}

	/**
	 * The result count a call asked for, clamped.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $default   Default when unspecified.
	 * @return int Limit.
	 */
	private static function requested_limit( $arguments, $default ) {
		$limit = isset( $arguments['limit'] ) ? (int) $arguments['limit'] : $default;
		return max( 1, min( self::MAX_LIMIT, $limit ) );
	}

	/**
	 * Resolve a URL or path to a published post on this site.
	 *
	 * @param string $url URL or path.
	 * @return WP_Post|null The post, or null.
	 */
	private static function resolve( $url ) {
		// A bare path is the form a model most often produces, so accept it — but resolve it
		// against this site, which also means a URL pointing anywhere else finds nothing.
		if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) {
			return null;
		}

		// Tolerate a .md URL: it is what llms.txt and the Markdown mirror hand out, so a model that
		// read either will sometimes pass one back.
		$url = preg_replace( '#\.md(/)?$#', '$1', $url );

		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			// url_to_postid() does not resolve the front page, which is a legitimate thing to ask for.
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			if ( '' === $path || 'index' === $path ) {
				$post_id = (int) get_option( 'page_on_front' );
			}
		}
		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return null;
		}
		if ( ! in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * A post's title, with entities decoded.
	 *
	 * @param WP_Post $post Post.
	 * @return string Title.
	 */
	private static function title( $post ) {
		return html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * A short plain-text excerpt for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return string Excerpt.
	 */
	private static function excerpt( $post ) {
		$excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 45, '…' );
		return trim( html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	// -------------------------------------------------------------------------
	// /.well-known/mcp.json
	// -------------------------------------------------------------------------

	/**
	 * Add rewrite rules.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^\.well-known/mcp\.json$', 'index.php?mmsar_mcp_manifest=1', 'top' );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array Query vars.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_mcp_manifest';
		return $vars;
	}

	/**
	 * Serve the discovery manifest.
	 *
	 * MCP has no ratified well-known location — the spec covers the connection, not how a client
	 * that has only a domain name finds one. /.well-known/mcp.json is the de-facto convention that
	 * agent directories and readiness scanners actually look for, so that is where this goes. The
	 * document names both the transport and the endpoint, so a client that ignores the shape
	 * entirely can still pull the URL out of it.
	 *
	 * @return void
	 */
	public static function serve_manifest() {
		if ( ! get_query_var( 'mmsar_mcp_manifest' ) ) {
			return;
		}

		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$manifest = array(
			'name'            => 'mmsar-' . sanitize_title( $site_name ),
			'title'           => $site_name,
			'description'     => 'Read-only access to the published content of ' . $site_name . ': search it, list it, and read any page as Markdown.',
			'version'         => MMSAR_VERSION,
			'protocolVersion' => self::PROTOCOL_VERSION,
			'transport'       => array(
				'type' => 'streamable-http',
				'url'  => self::endpoint_url(),
			),
			// Repeated at the top level because clients and directories disagree about where the
			// endpoint lives in this document, and both spellings are cheap.
			'url'             => self::endpoint_url(),
			'authentication'  => array(
				'type'        => 'none',
				'description' => 'Public and read-only. No credentials required.',
			),
			'capabilities'    => array(
				'tools'     => array_column( self::tools(), 'name' ),
				'resources' => array_column( self::resources(), 'uri' ),
			),
			'websiteUrl'      => home_url( '/' ),
		);

		// A directory listing this server shows whatever branding it can find, and a name with no
		// mark next to it reads as an unfinished entry. The site icon is the one image every
		// WordPress site is asked for during setup, so it is the one most likely to actually exist.
		$icon = get_site_icon_url( 512 );
		if ( $icon ) {
			$manifest['icons'] = array(
				array(
					'src'   => $icon,
					'sizes' => '512x512',
				),
			);
		}

		/**
		 * Filters the MCP discovery manifest before it is served.
		 *
		 * @param array $manifest The manifest, as a PHP array.
		 */
		$filtered = apply_filters( 'mmsar_mcp_manifest', $manifest );
		if ( is_array( $filtered ) ) {
			$manifest = $filtered;
		}

		mmsar_send_cache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'mcp.json' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
}
