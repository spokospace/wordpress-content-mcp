<?php
/**
 * Plugin Name:       Polo Blocks MCP
 * Description:       Block-level editing abilities for Gutenberg posts, exposed to AI agents through the MCP Adapter.
 * Version:           1.2.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Requires Plugins:  mcp-adapter
 * Author:            polo.blue
 * License:           GPL-2.0-or-later
 *
 * Blocks are addressed by a "path": indexes into the parsed block tree joined with dots.
 * "12" is the 13th top-level block, "12.0.3" is the 4th inner block of the 1st inner block of it.
 * Every write requires the content hash returned by a read, so a stale edit can never
 * overwrite changes made in the meantime. Every write is a normal post update, so
 * WordPress keeps a revision that can be restored from the editor.
 */

defined( 'ABSPATH' ) || exit;

final class Polo_Blocks_MCP {

	const CATEGORY = 'polo-blocks';

	/**
	 * Public address of the media library: link-images writes links to it.
	 */
	const PUBLIC_MEDIA_BASE = 'https://polo.blue/images';

	/**
	 * Blank-line separator matching the line endings of the post being edited.
	 *
	 * @var string
	 */
	private static $eol2 = "\n\n";

	/**
	 * Hooks everything up.
	 */
	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_action( 'mcp_adapter_init', array( __CLASS__, 'register_server' ) );
	}

	/**
	 * Registers the ability category (abilities without a registered category are silently dropped).
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Polo Blocks',
				'description' => 'Reading and editing Gutenberg blocks of posts.',
			)
		);
	}

	/**
	 * Registers a dedicated MCP server so every ability is a direct tool with its own schema.
	 * Endpoint: /wp-json/polo/blocks
	 *
	 * @param object $adapter The MCP adapter instance.
	 */
	public static function register_server( $adapter ): void {
		$adapter->create_server(
			'polo-blocks',
			'polo',
			'blocks',
			'Polo Blocks',
			'Read and edit Gutenberg blocks of polo.blue posts.',
			'1.2.1',
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			null,
			array_keys( self::abilities() )
		);
	}

	/**
	 * Registers all abilities.
	 */
	public static function register_abilities(): void {
		foreach ( self::abilities() as $name => $args ) {
			$args['category'] = self::CATEGORY;
			// Expose to MCP only, not to other ability clients.
			$args['meta']['mcp'] = array( 'public' => true );
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array>
	 */
	private static function abilities(): array {
		$post_id = array(
			'type'        => 'integer',
			'description' => 'Post or page ID.',
			'minimum'     => 1,
		);
		$path = array(
			'type'        => 'string',
			'description' => 'Block path from get-outline, e.g. "12" or "12.0.3".',
			'pattern'     => '^[0-9]+(\.[0-9]+)*$',
		);
		$hash = array(
			'type'        => 'string',
			'description' => 'content_hash from the latest read of this post. The write is refused if the post changed since.',
		);
		$markup = array(
			'type'        => 'string',
			'description' => 'Serialized block markup (one or more blocks with their <!-- wp:... --> comments).',
			'minLength'   => 1,
		);
		$dry_run = array(
			'type'        => 'boolean',
			'description' => 'Validate and return the result without saving.',
		);

		return array(
			'polo-blocks/find-posts'    => array(
				'label'               => 'Find posts',
				'description'         => 'Search posts and pages by title text, slug or ID. Returns IDs, titles, status and links.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array(
							'type'        => 'string',
							'description' => 'Text to search for in titles and content, a slug, or a numeric ID.',
						),
						'post_type' => array(
							'type' => 'string',
							'enum' => array( 'post', 'page', 'any' ),
						),
						'limit'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
					'required'   => array( 'search' ),
				),
				'execute_callback'    => array( __CLASS__, 'find_posts' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array( 'annotations' => self::read_only() ),
			),
			'polo-blocks/get-outline'   => array(
				'label'               => 'Get block outline',
				'description'         => 'List the blocks of a post as a tree of paths with block names and short text previews. Use it to find the path of the block to read or edit.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => $post_id,
						'max_depth' => array(
							'type'        => 'integer',
							'description' => 'How deep to list inner blocks (1 = top level only). Default 2.',
							'minimum'     => 1,
							'maximum'     => 10,
						),
						'from_path' => array_merge( $path, array( 'description' => 'Only list the subtree under this path.' ) ),
					),
					'required'   => array( 'post_id' ),
				),
				'execute_callback'    => array( __CLASS__, 'get_outline' ),
				'permission_callback' => array( __CLASS__, 'can_edit_input_post' ),
				'meta'                => array( 'annotations' => self::read_only() ),
			),
			'polo-blocks/get-blocks'    => array(
				'label'               => 'Get block markup',
				'description'         => 'Return the serialized markup of one or more blocks by path, plus the content_hash needed for writes.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => $post_id,
						'paths'   => array(
							'type'     => 'array',
							'items'    => $path,
							'minItems' => 1,
							'maxItems' => 20,
						),
					),
					'required'   => array( 'post_id', 'paths' ),
				),
				'execute_callback'    => array( __CLASS__, 'get_blocks' ),
				'permission_callback' => array( __CLASS__, 'can_edit_input_post' ),
				'meta'                => array( 'annotations' => self::read_only() ),
			),
			'polo-blocks/replace-block' => array(
				'label'               => 'Replace block',
				'description'         => 'Replace the block at a path with new markup (one or more blocks).',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => $post_id,
						'path'          => $path,
						'markup'        => $markup,
						'expected_hash' => $hash,
						'dry_run'       => $dry_run,
					),
					'required'   => array( 'post_id', 'path', 'markup', 'expected_hash' ),
				),
				'execute_callback'    => array( __CLASS__, 'replace_block' ),
				'permission_callback' => array( __CLASS__, 'can_edit_input_post' ),
				'meta'                => array( 'annotations' => self::write( false ) ),
			),
			'polo-blocks/insert-blocks' => array(
				'label'               => 'Insert blocks',
				'description'         => 'Insert new blocks before or after the block at a path.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => $post_id,
						'path'          => $path,
						'position'      => array(
							'type' => 'string',
							'enum' => array( 'before', 'after' ),
						),
						'markup'        => $markup,
						'expected_hash' => $hash,
						'dry_run'       => $dry_run,
					),
					'required'   => array( 'post_id', 'path', 'position', 'markup', 'expected_hash' ),
				),
				'execute_callback'    => array( __CLASS__, 'insert_blocks' ),
				'permission_callback' => array( __CLASS__, 'can_edit_input_post' ),
				'meta'                => array( 'annotations' => self::write( false ) ),
			),
			'polo-blocks/delete-block'  => array(
				'label'               => 'Delete block',
				'description'         => 'Delete the block at a path (a revision is kept).',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => $post_id,
						'path'          => $path,
						'expected_hash' => $hash,
						'dry_run'       => $dry_run,
					),
					'required'   => array( 'post_id', 'path', 'expected_hash' ),
				),
				'execute_callback'    => array( __CLASS__, 'delete_block' ),
				'permission_callback' => array( __CLASS__, 'can_edit_input_post' ),
				'meta'                => array( 'annotations' => self::write( true ) ),
			),
			'polo-blocks/move-blocks'   => array(
				'label'               => 'Move blocks',
				'description'         => 'Move one or more consecutive sibling blocks (starting at from_path) next to the block at target_path, keeping their markup unchanged. target_path is given as it is BEFORE the move. Use it to restructure content without re-sending it.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => $post_id,
						'from_path'     => array_merge( $path, array( 'description' => 'Path of the first block to move.' ) ),
						'count'         => array(
							'type'        => 'integer',
							'description' => 'How many consecutive sibling blocks to move (blank separators are not counted). Default 1.',
							'minimum'     => 1,
						),
						'target_path'   => array_merge( $path, array( 'description' => 'Block to move next to (path before the move).' ) ),
						'position'      => array(
							'type' => 'string',
							'enum' => array( 'before', 'after' ),
						),
						'expected_hash' => $hash,
						'dry_run'       => $dry_run,
					),
					'required'   => array( 'post_id', 'from_path', 'target_path', 'position', 'expected_hash' ),
				),
				'execute_callback'    => array( __CLASS__, 'move_blocks' ),
				'permission_callback' => array( __CLASS__, 'can_edit_input_post' ),
				'meta'                => array( 'annotations' => self::write( false ) ),
			),
			'polo-blocks/unwrap-block'  => array(
				'label'               => 'Unwrap block',
				'description'         => 'Replace a container block (e.g. a group) with its inner blocks, keeping their markup unchanged.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => $post_id,
						'path'          => $path,
						'expected_hash' => $hash,
						'dry_run'       => $dry_run,
					),
					'required'   => array( 'post_id', 'path', 'expected_hash' ),
				),
				'execute_callback'    => array( __CLASS__, 'unwrap_block' ),
				'permission_callback' => array( __CLASS__, 'can_edit_input_post' ),
				'meta'                => array( 'annotations' => self::write( false ) ),
			),
			'polo-blocks/link-images'   => array(
				'label'               => 'Link images to the full file',
				'description'         => 'Link every core/image block of a post that has no link (including images in galleries and columns) to its full-size file, so a lightbox can open it. Sets linkDestination "media" and wraps the <img> in <a href>. Skips images that already have a link outside the caption, figures with the "no-lightbox" class, and images whose full file cannot be told. Run with dry_run first to see which paths change.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => $post_id,
						'expected_hash' => $hash,
						'dry_run'       => $dry_run,
					),
					'required'   => array( 'post_id', 'expected_hash' ),
				),
				'execute_callback'    => array( __CLASS__, 'link_images' ),
				'permission_callback' => array( __CLASS__, 'can_edit_input_post' ),
				'meta'                => array( 'annotations' => self::write( false ) ),
			),
			'polo-blocks/validate-post' => array(
				'label'               => 'Validate post blocks',
				'description'         => 'Check a post for broken block structure: stray freeform HTML between blocks and unbalanced block comments.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => $post_id ),
					'required'   => array( 'post_id' ),
				),
				'execute_callback'    => array( __CLASS__, 'validate_post' ),
				'permission_callback' => array( __CLASS__, 'can_edit_input_post' ),
				'meta'                => array( 'annotations' => self::read_only() ),
			),
			'polo-blocks/find-media'    => array(
				'label'               => 'Find media',
				'description'         => 'Search the media library by file name or title. Returns attachment IDs, URLs and image sizes for use in image and video blocks.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array( 'type' => 'string' ),
						'mime'   => array(
							'type'        => 'string',
							'description' => 'MIME prefix filter, e.g. "image" or "video".',
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
					'required'   => array( 'search' ),
				),
				'execute_callback'    => array( __CLASS__, 'find_media' ),
				'permission_callback' => static function () {
					return current_user_can( 'upload_files' );
				},
				'meta'                => array( 'annotations' => self::read_only() ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Annotations and permissions
	 * ------------------------------------------------------------------- */

	private static function read_only(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	private static function write( bool $destructive ): array {
		return array(
			'readonly'    => false,
			'destructive' => $destructive,
			'idempotent'  => false,
		);
	}

	/**
	 * Permission check for abilities that take a post_id.
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public static function can_edit_input_post( $input = array() ): bool {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/* ---------------------------------------------------------------------
	 * Read abilities
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function find_posts( $input = array() ): array {
		$search = trim( (string) $input['search'] );
		$type   = isset( $input['post_type'] ) && 'any' !== $input['post_type'] ? $input['post_type'] : array( 'post', 'page' );
		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$args   = array(
			'post_type'      => $type,
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
		);

		if ( ctype_digit( $search ) ) {
			$args['p'] = (int) $search;
		} elseif ( false === strpos( $search, ' ' ) && get_page_by_path( $search, OBJECT, $type ) ) {
			$args['name'] = $search;
		} else {
			$args['s'] = $search;
		}

		$results = array();
		foreach ( get_posts( $args ) as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$results[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'modified' => $post->post_modified_gmt,
				'link'     => get_permalink( $post ),
				'lang'     => function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID ) : null,
			);
		}

		return array( 'posts' => $results );
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_outline( $input = array() ) {
		$post = self::load_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$blocks    = parse_blocks( $post->post_content );
		$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : 2;
		$prefix    = array();

		if ( ! empty( $input['from_path'] ) ) {
			$prefix = self::path_to_indexes( $input['from_path'] );
			$block  = self::block_at( $blocks, $prefix );
			if ( null === $block ) {
				return self::bad_path( $input['from_path'] );
			}
			$blocks = $block['innerBlocks'];
		}

		return array(
			'post_id'      => $post->ID,
			'title'        => get_the_title( $post ),
			'content_hash' => self::hash( $post->post_content ),
			'blocks'       => self::outline( $blocks, $prefix, $max_depth ),
		);
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_blocks( $input = array() ) {
		$post = self::load_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$blocks = parse_blocks( $post->post_content );
		$out    = array();
		foreach ( $input['paths'] as $path ) {
			$block = self::block_at( $blocks, self::path_to_indexes( $path ) );
			if ( null === $block ) {
				return self::bad_path( $path );
			}
			$out[] = array(
				'path'   => $path,
				'name'   => $block['blockName'],
				'markup' => serialize_block( $block ),
			);
		}

		return array(
			'post_id'      => $post->ID,
			'content_hash' => self::hash( $post->post_content ),
			'blocks'       => $out,
		);
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function validate_post( $input = array() ) {
		$post = self::load_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'post_id'      => $post->ID,
			'content_hash' => self::hash( $post->post_content ),
			'problems'     => self::find_problems( $post->post_content ),
		);
	}

	/**
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function find_media( $input = array() ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => (string) $input['search'],
			'posts_per_page' => isset( $input['limit'] ) ? (int) $input['limit'] : 10,
		);
		if ( ! empty( $input['mime'] ) ) {
			$args['post_mime_type'] = (string) $input['mime'];
		}

		$results = array();
		foreach ( get_posts( $args ) as $attachment ) {
			$item = array(
				'id'    => $attachment->ID,
				'title' => get_the_title( $attachment ),
				'mime'  => $attachment->post_mime_type,
				'url'   => wp_get_attachment_url( $attachment->ID ),
			);
			if ( wp_attachment_is_image( $attachment->ID ) ) {
				$item['sizes'] = array();
				foreach ( array( 'medium', 'large', 'full' ) as $size ) {
					$src = wp_get_attachment_image_src( $attachment->ID, $size );
					if ( $src ) {
						$item['sizes'][ $size ] = array(
							'url'    => $src[0],
							'width'  => $src[1],
							'height' => $src[2],
						);
					}
				}
			}
			$results[] = $item;
		}

		return array( 'media' => $results );
	}

	/* ---------------------------------------------------------------------
	 * Write abilities
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function replace_block( $input = array() ) {
		return self::mutate(
			$input,
			static function ( array $blocks, array $indexes, array $new_blocks ) {
				// Replace = put the first new block in place, then insert the rest after it.
				$first  = array_shift( $new_blocks );
				$blocks = self::set_block_at( $blocks, $indexes, $first );
				return $new_blocks ? self::insert_at( $blocks, $indexes, true, $new_blocks ) : $blocks;
			}
		);
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function insert_blocks( $input = array() ) {
		$after = isset( $input['position'] ) && 'after' === $input['position'];
		return self::mutate(
			$input,
			static function ( array $blocks, array $indexes, array $new_blocks ) use ( $after ) {
				return self::insert_at( $blocks, $indexes, $after, $new_blocks );
			}
		);
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function delete_block( $input = array() ) {
		return self::mutate(
			$input,
			static function ( array $blocks, array $indexes ) {
				return self::remove_at( $blocks, $indexes );
			},
			false
		);
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function move_blocks( $input = array() ) {
		$count  = isset( $input['count'] ) ? max( 1, (int) $input['count'] ) : 1;
		$after  = 'after' === $input['position'];
		$target = self::path_to_indexes( (string) $input['target_path'] );

		$input['path'] = $input['from_path'];

		return self::mutate(
			$input,
			static function ( array $blocks, array $from ) use ( $count, $after, $target ) {
				if ( null === self::block_at( $blocks, $target ) ) {
					return self::bad_path( implode( '.', $target ) );
				}

				// Collect the indexes of the blocks to move, skipping blank separators.
				$parent_path = array_slice( $from, 0, -1 );
				$siblings    = $parent_path ? self::block_at( $blocks, $parent_path )['innerBlocks'] : $blocks;
				$indexes     = array();
				for ( $i = end( $from ); $i < count( $siblings ) && count( $indexes ) < $count; $i++ ) {
					if ( ! self::is_separator( $siblings[ $i ] ) ) {
						$indexes[] = $i;
					}
				}
				if ( count( $indexes ) < $count ) {
					return new WP_Error( 'bad_count', sprintf( 'Only %d blocks available from %s.', count( $indexes ), implode( '.', $from ) ) );
				}

				// The target must not be one of the moved blocks or inside one.
				foreach ( $indexes as $i ) {
					$moved_path = array_merge( $parent_path, array( $i ) );
					if ( array_slice( $target, 0, count( $moved_path ) ) === $moved_path ) {
						return new WP_Error( 'bad_target', 'The target is inside the blocks being moved.' );
					}
				}

				// Mark the target so it can be found again after the removal shifts paths.
				$marker              = $blocks;
				$target_block        = self::block_at( $marker, $target );
				$target_block['attrs']['__polo_move_target'] = true;
				$blocks              = self::set_block_at( $blocks, $target, $target_block );

				$moved = array();
				foreach ( $indexes as $i ) {
					$moved[] = $siblings[ $i ];
				}
				foreach ( array_reverse( $indexes ) as $i ) {
					$blocks = self::remove_at( $blocks, array_merge( $parent_path, array( $i ) ) );
					if ( is_wp_error( $blocks ) ) {
						return $blocks;
					}
				}

				$new_target = self::find_marked( $blocks, array() );
				if ( null === $new_target ) {
					return new WP_Error( 'target_lost', 'Could not locate the target after removing the moved blocks.' );
				}
				$target_block = self::block_at( $blocks, $new_target );
				unset( $target_block['attrs']['__polo_move_target'] );
				$blocks = self::set_block_at( $blocks, $new_target, $target_block );

				return self::insert_at( $blocks, $new_target, $after, $moved );
			},
			false
		);
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function unwrap_block( $input = array() ) {
		return self::mutate(
			$input,
			static function ( array $blocks, array $indexes ) {
				$inner = array_values(
					array_filter(
						self::block_at( $blocks, $indexes )['innerBlocks'],
						static function ( $block ) {
							return ! self::is_separator( $block );
						}
					)
				);
				if ( ! $inner ) {
					return new WP_Error( 'no_inner_blocks', 'The block has no inner blocks to unwrap.' );
				}
				$first  = array_shift( $inner );
				$blocks = self::set_block_at( $blocks, $indexes, $first );
				return $inner ? self::insert_at( $blocks, $indexes, true, $inner ) : $blocks;
			},
			false
		);
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function link_images( $input = array() ) {
		$post = self::load_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$stale = self::check_hash( $post, $input );
		if ( $stale ) {
			return $stale;
		}

		$linked  = array();
		$skipped = array();
		$blocks  = self::link_image_blocks( parse_blocks( $post->post_content ), array(), $linked, $skipped );

		$result = array(
			'post_id' => $post->ID,
			'linked'  => $linked,
			'skipped' => $skipped,
		);
		if ( ! $linked ) {
			return $result + array(
				'saved'        => false,
				'content_hash' => self::hash( $post->post_content ),
			);
		}

		$saved = self::save( $post, serialize_blocks( $blocks ), ! empty( $input['dry_run'] ) );
		return is_wp_error( $saved ) ? $saved : $result + $saved;
	}

	/**
	 * Walks the tree and links every unlinked core/image block, recording what changed.
	 *
	 * @param array $blocks  Block list.
	 * @param int[] $prefix  Path of the parent.
	 * @param array $linked  Collects { path, href }.
	 * @param array $skipped Collects { path, reason } for unlinked images that were left alone.
	 */
	private static function link_image_blocks( array $blocks, array $prefix, array &$linked, array &$skipped ): array {
		foreach ( $blocks as $i => $block ) {
			$path = array_merge( $prefix, array( $i ) );
			if ( 'core/image' === $block['blockName'] ) {
				$outcome = self::link_image_block( $block );
				if ( is_array( $outcome ) ) {
					$blocks[ $i ] = $outcome['block'];
					$linked[]     = array(
						'path' => implode( '.', $path ),
						'href' => $outcome['href'],
					);
				} elseif ( '' !== $outcome ) {
					$skipped[] = array(
						'path'   => implode( '.', $path ),
						'reason' => $outcome,
					);
				}
				continue;
			}
			$blocks[ $i ]['innerBlocks'] = self::link_image_blocks( $block['innerBlocks'], $path, $linked, $skipped );
		}
		return $blocks;
	}

	/**
	 * Links one image block to its full file.
	 *
	 * @return array|string { block, href } when linked; '' when it already has a link; otherwise why it was skipped.
	 */
	private static function link_image_block( array $block ) {
		$html = $block['innerHTML'];

		// A link outside the caption means the image is already linked.
		if ( preg_match( '/<a\b/i', preg_replace( '#<figcaption\b.*?</figcaption>#is', '', $html ) ) ) {
			return '';
		}
		if ( preg_match( '/<figure\b[^>]*\bclass="([^"]*)"/i', $html, $figure ) && preg_match( '/(?:^|\s)no-lightbox(?:\s|$)/', $figure[1] ) ) {
			return 'no-lightbox';
		}
		// Image blocks have no inner blocks, so innerContent is the innerHTML as one string.
		if ( array( $html ) !== $block['innerContent'] ) {
			return 'unexpected markup';
		}
		if ( ! preg_match( '/<img\b[^>]*\bsrc="([^"]+)"[^>]*>/i', $html, $img, PREG_OFFSET_CAPTURE ) ) {
			return 'no img';
		}

		$href = self::full_image_url( isset( $block['attrs']['id'] ) ? (int) $block['attrs']['id'] : 0, html_entity_decode( $img[1][0] ) );
		if ( '' === $href ) {
			return 'full file unknown';
		}

		$tag  = $img[0][0];
		$html = substr_replace( $html, '<a href="' . esc_url( $href ) . '">' . $tag . '</a>', $img[0][1], strlen( $tag ) );

		$block['attrs']['linkDestination'] = 'media';
		$block['innerHTML']                = $html;
		$block['innerContent']             = array( $html );

		return array(
			'block' => $block,
			'href'  => $href,
		);
	}

	/**
	 * Full-size file of an image, on the public media address: the attachment URL (the "-scaled"
	 * file for big uploads), or the src without its -WxH size suffix when there is no attachment.
	 * '' when neither works.
	 */
	private static function full_image_url( int $id, string $src ): string {
		$full = $id ? wp_get_attachment_url( $id ) : false;
		if ( ! $full ) {
			$full = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)(?:\?.*)?$/', '$1', $src, 1, $count );
			if ( ! $count ) {
				return '';
			}
		}
		return self::public_media_url( $full );
	}

	/**
	 * Rewrites a media file URL to the public address polo.blue serves it from
	 * (https://polo.blue/images/YYYY/MM/file): links in content carry no subdomain. Media URLs come
	 * from the uploads base URL (https://blog.polo.blue/images) or the old img.polo.blue host;
	 * anything else is returned unchanged.
	 */
	private static function public_media_url( string $url ): string {
		$bases = array( wp_get_upload_dir()['baseurl'], 'https://img.polo.blue' );
		foreach ( $bases as $base ) {
			// Compare without the scheme: the uploads base URL may be stored as http.
			$base = preg_replace( '#^https?:#i', '', rtrim( $base, '/' ) ) . '/';
			$rest = preg_replace( '#^https?:#i', '', $url );
			if ( 0 === stripos( $rest, $base ) ) {
				return self::PUBLIC_MEDIA_BASE . '/' . substr( $rest, strlen( $base ) );
			}
		}
		return $url;
	}

	/**
	 * Finds the path of the block carrying the move marker.
	 *
	 * @return int[]|null
	 */
	private static function find_marked( array $blocks, array $prefix ) {
		foreach ( $blocks as $i => $block ) {
			$path = array_merge( $prefix, array( $i ) );
			if ( ! empty( $block['attrs']['__polo_move_target'] ) ) {
				return $path;
			}
			$found = self::find_marked( $block['innerBlocks'], $path );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Shared write flow: load, check hash, parse new markup, apply, validate, save.
	 *
	 * @param array    $input        Ability input.
	 * @param callable $apply        Receives (blocks, indexes, new_blocks) and returns new blocks or WP_Error.
	 * @param bool     $needs_markup Whether the ability takes markup.
	 * @return array|WP_Error
	 */
	private static function mutate( array $input, callable $apply, bool $needs_markup = true ) {
		$post = self::load_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$stale = self::check_hash( $post, $input );
		if ( $stale ) {
			return $stale;
		}

		self::$eol2 = false !== strpos( $post->post_content, "\r\n" ) ? "\r\n\r\n" : "\n\n";

		$blocks  = parse_blocks( $post->post_content );
		$indexes = self::path_to_indexes( $input['path'] );
		if ( null === self::block_at( $blocks, $indexes ) ) {
			return self::bad_path( $input['path'] );
		}

		$new_blocks = array();
		if ( $needs_markup ) {
			$new_blocks = self::parse_markup( (string) $input['markup'] );
			if ( is_wp_error( $new_blocks ) ) {
				return $new_blocks;
			}
		}

		$blocks = $apply( $blocks, $indexes, $new_blocks );
		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		return self::save( $post, serialize_blocks( $blocks ), ! empty( $input['dry_run'] ) );
	}

	/**
	 * Validates new content and saves it as a normal post update (which keeps a revision).
	 *
	 * @param WP_Post $post    Post being edited.
	 * @param string  $content New serialized content.
	 * @param bool    $dry_run Validate only.
	 * @return array|WP_Error
	 */
	private static function save( $post, string $content, bool $dry_run ) {
		$problems = self::find_problems( $content );
		if ( $problems ) {
			return new WP_Error( 'invalid_result', 'The edit would break the block structure: ' . implode( ' | ', $problems ) );
		}

		if ( $dry_run ) {
			return array(
				'dry_run'      => true,
				'saved'        => false,
				'content_hash' => self::hash( $content ),
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$saved     = get_post( $post->ID );
		$revisions = wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 1 ) );
		$filtered  = self::hash( $saved->post_content ) !== self::hash( $content );

		return array(
			'saved'        => true,
			'post_id'      => $post->ID,
			'content_hash' => self::hash( $saved->post_content ),
			'revision_id'  => $revisions ? (int) array_key_first( $revisions ) : null,
			// True when WordPress changed the content on save (e.g. HTML filtering for users without unfiltered_html).
			'content_filtered_on_save' => $filtered,
		);
	}

	/* ---------------------------------------------------------------------
	 * Block tree helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Parses incoming markup and refuses stray HTML outside block comments.
	 *
	 * @param string $markup Serialized blocks.
	 * @return array|WP_Error
	 */
	private static function parse_markup( string $markup ) {
		$blocks = array();
		foreach ( parse_blocks( $markup ) as $block ) {
			if ( null === $block['blockName'] ) {
				if ( '' === trim( $block['innerHTML'] ) ) {
					continue;
				}
				return new WP_Error( 'freeform_html', 'The markup has HTML outside block comments: ' . self::preview( $block['innerHTML'] ) );
			}
			$blocks[] = $block;
		}
		if ( ! $blocks ) {
			return new WP_Error( 'no_blocks', 'The markup contains no blocks.' );
		}
		return $blocks;
	}

	/**
	 * @param string $path Dotted path.
	 * @return int[]
	 */
	private static function path_to_indexes( string $path ): array {
		return array_map( 'intval', explode( '.', $path ) );
	}

	/**
	 * @param array $blocks  Block list.
	 * @param int[] $indexes Path indexes.
	 * @return array|null
	 */
	private static function block_at( array $blocks, array $indexes ) {
		$block = null;
		foreach ( $indexes as $i ) {
			if ( ! isset( $blocks[ $i ] ) ) {
				return null;
			}
			$block  = $blocks[ $i ];
			$blocks = $block['innerBlocks'];
		}
		return $block;
	}

	/**
	 * Replaces the block at a path.
	 */
	private static function set_block_at( array $blocks, array $indexes, array $new_block ): array {
		$i = array_shift( $indexes );
		if ( ! $indexes ) {
			$blocks[ $i ] = $new_block;
			return $blocks;
		}
		$blocks[ $i ]['innerBlocks'] = self::set_block_at( $blocks[ $i ]['innerBlocks'], $indexes, $new_block );
		return $blocks;
	}

	/**
	 * Inserts blocks next to the block at a path, keeping the parent's innerContent placeholders in sync.
	 *
	 * @return array|WP_Error
	 */
	private static function insert_at( array $blocks, array $indexes, bool $after, array $new_blocks ) {
		$target = array_pop( $indexes );

		if ( ! $indexes ) {
			// Top level: separate blocks with a blank line, as the editor does.
			$items = array();
			foreach ( $new_blocks as $block ) {
				$items[] = self::separator();
				$items[] = $block;
			}
			if ( ! $after ) {
				// Separator after the inserted blocks instead of before them.
				$items[] = array_shift( $items );
			}
			array_splice( $blocks, $after ? $target + 1 : $target, 0, $items );
			return $blocks;
		}

		$parent_path = $indexes;
		$parent      = self::block_at( $blocks, $parent_path );
		$slot        = self::placeholder_index( $parent['innerContent'], $target );
		if ( null === $slot ) {
			return new WP_Error( 'inner_content_mismatch', 'Could not locate the block inside its parent markup.' );
		}

		$content_items = array();
		foreach ( $new_blocks as $unused ) {
			$content_items[] = self::$eol2;
			$content_items[] = null;
		}
		if ( $after ) {
			array_splice( $parent['innerContent'], $slot + 1, 0, $content_items );
			array_splice( $parent['innerBlocks'], $target + 1, 0, $new_blocks );
		} else {
			// Placeholder first, then the separator.
			$content_items = array_reverse( $content_items );
			array_splice( $parent['innerContent'], $slot, 0, $content_items );
			array_splice( $parent['innerBlocks'], $target, 0, $new_blocks );
		}

		return self::set_block_at( $blocks, $parent_path, $parent );
	}

	/**
	 * Removes the block at a path, keeping the parent's innerContent placeholders in sync.
	 *
	 * @return array|WP_Error
	 */
	private static function remove_at( array $blocks, array $indexes ) {
		$target = array_pop( $indexes );

		if ( ! $indexes ) {
			$remove_from = $target;
			$count       = 1;
			// Drop the blank-line separator in front of the block too.
			if ( $target > 0 && self::is_separator( $blocks[ $target - 1 ] ) ) {
				$remove_from = $target - 1;
				$count       = 2;
			}
			array_splice( $blocks, $remove_from, $count );
			return $blocks;
		}

		$parent_path = $indexes;
		$parent      = self::block_at( $blocks, $parent_path );
		$slot        = self::placeholder_index( $parent['innerContent'], $target );
		if ( null === $slot ) {
			return new WP_Error( 'inner_content_mismatch', 'Could not locate the block inside its parent markup.' );
		}

		$content = $parent['innerContent'];
		$start   = $slot;
		$length  = 1;
		// Remove one whitespace-only separator next to the placeholder, never the parent's wrapper HTML.
		// array_key_exists, not isset: placeholders are null and isset() treats null as missing.
		if ( array_key_exists( $slot + 2, $content ) && self::is_blank( $content[ $slot + 1 ] ) && null === $content[ $slot + 2 ] ) {
			$length = 2;
		} elseif ( $slot >= 2 && self::is_blank( $content[ $slot - 1 ] ) && null === $content[ $slot - 2 ] ) {
			$start  = $slot - 1;
			$length = 2;
		}
		array_splice( $content, $start, $length );
		$parent['innerContent'] = $content;
		array_splice( $parent['innerBlocks'], $target, 1 );

		return self::set_block_at( $blocks, $parent_path, $parent );
	}

	/**
	 * Index in innerContent of the null placeholder that stands for the n-th inner block.
	 */
	private static function placeholder_index( array $inner_content, int $n ) {
		$seen = -1;
		foreach ( $inner_content as $i => $chunk ) {
			if ( null === $chunk && ++$seen === $n ) {
				return $i;
			}
		}
		return null;
	}

	private static function separator(): array {
		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => self::$eol2,
			'innerContent' => array( self::$eol2 ),
		);
	}

	private static function is_separator( array $block ): bool {
		return null === $block['blockName'] && self::is_blank( $block['innerHTML'] );
	}

	private static function is_blank( $chunk ): bool {
		return is_string( $chunk ) && '' === trim( $chunk );
	}

	/**
	 * Builds the outline tree, skipping blank separators.
	 */
	private static function outline( array $blocks, array $prefix, int $depth ): array {
		$out = array();
		foreach ( $blocks as $i => $block ) {
			if ( self::is_separator( $block ) ) {
				continue;
			}
			$path = array_merge( $prefix, array( $i ) );
			$item = array(
				'path' => implode( '.', $path ),
				'name' => $block['blockName'] ? $block['blockName'] : 'freeform',
				'text' => self::preview( self::block_text( $block ) ),
			);
			if ( $block['innerBlocks'] ) {
				$item['inner_count'] = count( $block['innerBlocks'] );
				if ( $depth > 1 ) {
					$item['inner'] = self::outline( $block['innerBlocks'], $path, $depth - 1 );
				}
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Text of a block including inner blocks, for previews.
	 */
	private static function block_text( array $block ): string {
		$text = $block['innerHTML'];
		foreach ( $block['innerBlocks'] as $inner ) {
			$text .= ' ' . self::block_text( $inner );
		}
		return $text;
	}

	private static function preview( string $html, int $length = 100 ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) );
		return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length ) . '…' : $text;
	}

	/**
	 * Structural problems: non-blank freeform HTML at any level and unbalanced block comments.
	 *
	 * @return string[]
	 */
	private static function find_problems( string $content ): array {
		$problems = array();

		// Same delimiter pattern as WP_Block_Parser: group 1 marks a closer, group 6 a self-closing (void) block.
		$pattern = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
		$opening = 0;
		$closing = 0;
		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( ! empty( $match['closer'] ) ) {
					++$closing;
				} elseif ( empty( $match['void'] ) ) {
					++$opening;
				}
			}
		}
		if ( $opening !== $closing ) {
			$problems[] = sprintf( 'Unbalanced block comments: %d opening, %d closing.', $opening, $closing );
		}

		self::collect_freeform( parse_blocks( $content ), array(), $problems );

		return $problems;
	}

	private static function collect_freeform( array $blocks, array $prefix, array &$problems ): void {
		foreach ( $blocks as $i => $block ) {
			$path = array_merge( $prefix, array( $i ) );
			if ( null === $block['blockName'] && ! self::is_blank( $block['innerHTML'] ) ) {
				$problems[] = sprintf( 'Freeform HTML at path %s: %s', implode( '.', $path ), self::preview( $block['innerHTML'], 80 ) );
			}
			self::collect_freeform( $block['innerBlocks'], $path, $problems );
		}
	}

	/* ---------------------------------------------------------------------
	 * Misc
	 * ------------------------------------------------------------------- */

	/**
	 * @return WP_Post|WP_Error
	 */
	private static function load_post( array $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}
		return $post;
	}

	/**
	 * Refuses a write when the post changed since the caller read it.
	 *
	 * @return WP_Error|null
	 */
	private static function check_hash( $post, array $input ) {
		if ( self::hash( $post->post_content ) === (string) $input['expected_hash'] ) {
			return null;
		}
		return new WP_Error(
			'stale_hash',
			'The post changed since it was read. Read it again (get-outline or get-blocks) and retry with the new content_hash.'
		);
	}

	private static function hash( string $content ): string {
		return md5( $content );
	}

	private static function bad_path( string $path ): WP_Error {
		return new WP_Error( 'bad_path', sprintf( 'No block at path %s. Use get-outline to find paths.', $path ) );
	}
}

Polo_Blocks_MCP::init();
