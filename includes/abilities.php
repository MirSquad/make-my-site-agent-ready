<?php
/**
 * WordPress Abilities API integration for Make My Site Agent-Ready.
 * Requires WP 6.9+ (Abilities API). Does nothing on older versions.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	return;
}

add_action( 'wp_abilities_api_categories_init', 'mmsar_register_ability_category' );
/**
 * Mmsar register ability category.
 *
 * @return void
 */
function mmsar_register_ability_category() {
	wp_register_ability_category(
		'make-my-site-agent-ready',
		array(
			'label'       => __( 'Make My Site Agent-Ready', 'make-my-site-agent-ready' ),
			'description' => __( 'Inspect plugin settings and trigger content regeneration.', 'make-my-site-agent-ready' ),
		)
	);
}

add_action( 'wp_abilities_api_init', 'mmsar_register_abilities' );
/**
 * Register the plugin's abilities with the WordPress Abilities API.
 *
 * @return void
 */
function mmsar_register_abilities() {

	wp_register_ability(
		'make-my-site-agent-ready/get-settings',
		array(
			'label'               => __( 'Get Settings', 'make-my-site-agent-ready' ),
			'description'         => __( 'Retrieve plugin settings: which features are enabled, enabled post types, and content root selector.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'features'           => array(
						'type'                 => 'object',
						'description'          => 'Which outputs this plugin is publishing. A feature set to false is switched off and its endpoint is not served.',
						'additionalProperties' => array( 'type' => 'boolean' ),
					),
					'enabled_post_types' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Post types for which markdown files are generated.',
					),
					'root_selector'      => array(
						'type'        => 'string',
						'description' => 'CSS selector used to extract content. Empty string means full post content.',
					),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function () {
				$features = array();
				foreach ( array_keys( mmsar_get_feature_keys() ) as $key ) {
					$features[ $key ] = mmsar_feature_enabled( $key );
				}
				return array(
					'features'           => $features,
					'enabled_post_types' => mmsar_get_enabled_post_types(),
					'root_selector'      => mmsar_get_root_selector(),
				);
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'make-my-site-agent-ready/list-endpoints',
		array(
			'label'               => __( 'List Endpoints', 'make-my-site-agent-ready' ),
			'description'         => __( 'List the endpoints published in this site\'s agent-facing documents (api-catalog, llms.txt, Agent Skills), showing which are managed on the settings page and which a plugin or theme registered in code, and where each one is actually appearing.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'endpoints' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'           => array( 'type' => 'string' ),
								'title'        => array( 'type' => 'string' ),
								'href'         => array( 'type' => 'string' ),
								'description'  => array( 'type' => 'string' ),
								'type'         => array( 'type' => 'string' ),
								'rel'          => array( 'type' => 'string' ),
								'methods'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'auth'         => array( 'type' => 'string' ),
								'managed_here' => array(
									'type'        => 'boolean',
									'description' => 'True when it is stored in this plugin\'s settings and can be changed with set-endpoint. False when a plugin or theme registered it in code, in which case it can only be changed by editing that plugin or theme.',
								),
								'published_in' => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => 'Documents it is actually appearing in right now. Empty when every document it asked for is switched off.',
								),
							),
						),
					),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function () {
				$stored_ids = wp_list_pluck( MMSAR_Registry::get_stored(), 'id' );
				$out        = array();
				foreach ( MMSAR_Registry::get_endpoints() as $endpoint ) {
					// Report where it is really appearing, not where it asked to appear: a document
					// whose feature is switched off publishes nothing regardless of the entry.
					$published = array();
					foreach ( $endpoint['surfaces'] as $surface ) {
						if ( mmsar_feature_enabled( $surface ) ) {
							$published[] = $surface;
						}
					}
					$out[] = array(
						'id'           => $endpoint['id'],
						'title'        => $endpoint['title'],
						'href'         => $endpoint['href'],
						'description'  => $endpoint['description'],
						'type'         => $endpoint['type'],
						'rel'          => $endpoint['rel'],
						'methods'      => $endpoint['methods'],
						'auth'         => $endpoint['auth'],
						'managed_here' => in_array( $endpoint['id'], $stored_ids, true ),
						'published_in' => $published,
					);
				}
				return array( 'endpoints' => $out );
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'make-my-site-agent-ready/set-endpoint',
		array(
			'label'               => __( 'Add or Update Endpoint', 'make-my-site-agent-ready' ),
			'description'         => __( 'Add an endpoint to this site\'s agent-facing documents, or update one already managed on the settings page. Passing an existing id updates that entry; omitting it creates a new one. Cannot change endpoints a plugin or theme registered in code.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'input_schema'        => array(
				'type'       => 'object',
				// title and href are required to create but not to update: marking them required
				// here would reject "change just the description on this id", which is the most
				// natural way to use this. The callback enforces them on the create path instead.
				'properties' => array(
					'id'          => array(
						'type'        => 'string',
						'description' => 'Id of an existing entry to update. Omit to create a new one. When updating, send only the fields you want changed.',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'Short human-readable name, e.g. "Contact form".',
					),
					'href'        => array(
						'type'        => 'string',
						'description' => 'Absolute http(s) URL of the endpoint.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'One sentence on what it does and anything a caller must do first. This is what an agent reads to decide whether to use it.',
					),
					'type'        => array(
						'type'        => 'string',
						'description' => 'Media type the endpoint really returns, e.g. "application/json". Leave out if unsure — a wrong type is worse than none, and an unstated one is simply omitted.',
					),
					'rel'         => array(
						'type'        => 'string',
						'enum'        => MMSAR_Registry::RELATIONS,
						'description' => 'api-catalog link relation. Defaults to "item". Use "service-desc" when fetching the URL returns a description of the API itself.',
					),
					'methods'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'HTTP methods it accepts, e.g. ["GET","POST"].',
					),
					'auth'        => array(
						'type'        => 'string',
						'description' => 'How to authenticate, e.g. "none".',
					),
					'surfaces'    => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => MMSAR_Registry::SURFACES,
						),
						'description' => 'Which documents to appear in. Defaults to all three.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'id'           => array( 'type' => 'string' ),
					'created'      => array( 'type' => 'boolean' ),
					'published_in' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function ( $input ) {
				$rows = MMSAR_Registry::get_stored();
				$id   = isset( $input['id'] ) ? sanitize_key( $input['id'] ) : '';

				// Refuse to shadow a code-registered endpoint. Writing a stored row with the same id
				// would not change the code entry, it would sit alongside it and be silently dropped
				// as a duplicate — so fail loudly instead of reporting a success that did nothing.
				if ( '' !== $id ) {
					$stored_ids = wp_list_pluck( $rows, 'id' );
					foreach ( MMSAR_Registry::get_endpoints() as $existing ) {
						if ( $existing['id'] === $id && ! in_array( $id, $stored_ids, true ) ) {
							return new WP_Error(
								'mmsar_endpoint_not_editable',
								__( 'That endpoint was registered in code by a plugin or theme, so it cannot be changed here. Edit the plugin or theme that added it.', 'make-my-site-agent-ready' ),
								array( 'status' => 409 )
							);
						}
					}
				}

				$fields = array();
				foreach ( array( 'title', 'href', 'description', 'type', 'rel', 'methods', 'auth', 'surfaces' ) as $key ) {
					if ( isset( $input[ $key ] ) ) {
						$fields[ $key ] = $input[ $key ];
					}
				}

				$created = true;
				if ( '' !== $id ) {
					foreach ( $rows as $index => $row ) {
						if ( isset( $row['id'] ) && $row['id'] === $id ) {
							// Merge, so a caller updating one field does not blank the others.
							$rows[ $index ] = array_merge( $row, $fields );
							$created        = false;
							break;
						}
					}
				}
				if ( $created ) {
					// An omitted `surfaces` means different things on the two routes into storage:
					// the settings form omits the key when every box is unticked (publish nowhere),
					// while a caller here simply did not express a preference. Fill in the documented
					// default explicitly, so the two callers cannot be confused for one another.
					if ( ! isset( $fields['surfaces'] ) ) {
						$fields['surfaces'] = MMSAR_Registry::SURFACES;
					}

					// Creating needs both; updating needs neither, since the stored row supplies them.
					if ( empty( $fields['title'] ) || empty( $fields['href'] ) ) {
						return new WP_Error(
							'mmsar_endpoint_incomplete',
							'' !== $id
								? __( 'No endpoint with that id is managed on the settings page. To create a new one, send a title and href (and omit the id).', 'make-my-site-agent-ready' )
								: __( 'A new endpoint needs both a title and an href.', 'make-my-site-agent-ready' ),
							array( 'status' => 400 )
						);
					}
					$fields['id'] = $id;
					$rows[]       = $fields;
				}

				// Same sanitizer the settings form uses, so both routes store identical shapes.
				$clean = MMSAR_Registry::sanitize_rows( $rows );
				update_option( MMSAR_Registry::OPTION, $clean );

				// Report the entry as saved, and separately whether it is fit to publish — a URL the
				// validator rejects is stored but never appears, and the caller must be told that.
				// A new row is appended last and sanitize_rows preserves order, so it is the tail;
				// an updated row is found by its id, which sanitize_rows keeps stable.
				$saved = null;
				if ( $created ) {
					$saved = $clean ? end( $clean ) : null;
				} else {
					foreach ( $clean as $row ) {
						if ( $row['id'] === $id ) {
							$saved = $row;
							break;
						}
					}
				}
				if ( ! $saved ) {
					return new WP_Error( 'mmsar_endpoint_not_saved', __( 'The endpoint could not be saved.', 'make-my-site-agent-ready' ), array( 'status' => 500 ) );
				}

				$published = array();
				$valid     = MMSAR_Registry::normalize( $saved );
				if ( $valid ) {
					foreach ( $valid['surfaces'] as $surface ) {
						if ( mmsar_feature_enabled( $surface ) ) {
							$published[] = $surface;
						}
					}
				}

				return array(
					'success'      => true,
					'id'           => $saved['id'],
					'created'      => $created,
					'published_in' => $published,
					'message'      => $published
						/* translators: %s: comma-separated list of document names */
						? sprintf( __( 'Saved. Now listed in: %s', 'make-my-site-agent-ready' ), implode( ', ', $published ) )
						: __( 'Saved, but not being published: it needs a valid http(s) URL and at least one document that is switched on.', 'make-my-site-agent-ready' ),
				);
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'make-my-site-agent-ready/delete-endpoint',
		array(
			'label'               => __( 'Delete Endpoint', 'make-my-site-agent-ready' ),
			'description'         => __( 'Remove an endpoint managed on the settings page, so it stops appearing in this site\'s agent-facing documents. Cannot remove endpoints a plugin or theme registered in code.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id' => array(
						'type'        => 'string',
						'description' => 'Id of the endpoint to remove, as returned by list-endpoints.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function ( $input ) {
				$id   = isset( $input['id'] ) ? sanitize_key( $input['id'] ) : '';
				$rows = MMSAR_Registry::get_stored();

				$remaining = array();
				$found     = false;
				foreach ( $rows as $row ) {
					if ( isset( $row['id'] ) && $row['id'] === $id ) {
						$found = true;
						continue;
					}
					$remaining[] = $row;
				}

				if ( ! $found ) {
					// Distinguish "no such endpoint" from "exists but is not yours to delete", so the
					// caller knows whether to fix the id or go and edit the plugin that owns it.
					foreach ( MMSAR_Registry::get_endpoints() as $existing ) {
						if ( $existing['id'] === $id ) {
							return new WP_Error(
								'mmsar_endpoint_not_editable',
								__( 'That endpoint was registered in code by a plugin or theme, so it cannot be removed here. Edit the plugin or theme that added it.', 'make-my-site-agent-ready' ),
								array( 'status' => 409 )
							);
						}
					}
					return new WP_Error(
						'mmsar_endpoint_not_found',
						__( 'No endpoint with that id is managed on the settings page.', 'make-my-site-agent-ready' ),
						array( 'status' => 404 )
					);
				}

				update_option( MMSAR_Registry::OPTION, $remaining );

				return array(
					'success' => true,
					'message' => __( 'Endpoint removed. It no longer appears in the agent-facing documents.', 'make-my-site-agent-ready' ),
				);
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'make-my-site-agent-ready/regenerate-files',
		array(
			'label'               => __( 'Regenerate Markdown Files', 'make-my-site-agent-ready' ),
			'description'         => __( 'Regenerate cached markdown for all published posts across all enabled post types. On large sites this may take several seconds.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function () {
				mmsar_bulk_generate();
				delete_transient( 'llmmd_llms_txt' );
				delete_transient( 'mmsar_llms_full_txt' );
				return array(
					'success' => true,
					'message' => __( 'Markdown files regenerated for all published content.', 'make-my-site-agent-ready' ),
				);
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);
}
