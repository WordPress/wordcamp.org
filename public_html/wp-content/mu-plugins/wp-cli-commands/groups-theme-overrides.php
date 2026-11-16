<?php

/**
 * A one-time cleanup, to be deleted once it has been run across the groups network.
 *
 * Before #2379, organizers could switch a group site to another theme, and replace or drop the theme's shared
 * header, footer and locked blocks in the Site Editor (#2368). #2379 keeps that from happening again, but leaves
 * what was already done. This lists every group site's theme overrides, and resets the ones #2379 now protects:
 * the site's theme, its custom header and footer parts, and any custom template that has lost one of the theme's
 * locked blocks. Other templates, other parts and global styles are listed, and left alone.
 *
 * Self-contained on purpose: everything here goes when the file does, apart from the two lines that load it
 * in `bootstrap.php`.
 */

namespace WordCamp\Groups\Theme_Overrides\Cleanup;

use WP_CLI, WP_CLI_Command, WP_Post;

use function WP_CLI\Utils\format_items;

defined( 'WPINC' ) || die();

// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- the command and the work it does are one unit here, so that removing the cleanup is removing one file.

/**
 * The theme every group site runs.
 */
const THEME = 'groups-site';

/**
 * Template parts every group shares, which a site may not override.
 */
const SHARED_PARTS = array( 'header', 'footer' );

/**
 * Identify the blocks in some block markup.
 *
 * A block is identified by its name, plus its `slug` (a template part's, or the About excerpt's) or else its
 * first class, so that the header part isn't mistaken for the footer part, or one locked group for another.
 * Pattern references are resolved first, since the Site Editor saves a template with its patterns inlined.
 *
 * @param string $content     Block markup.
 * @param bool   $locked_only Only identify blocks locked against removal.
 *
 * @return string[]
 */
function get_block_keys( string $content, bool $locked_only ): array {
	$keys   = array();
	$blocks = resolve_pattern_blocks( parse_blocks( $content ) );

	while ( $blocks ) {
		$block  = array_shift( $blocks );
		$blocks = array_merge( $blocks, $block['innerBlocks'] );

		if ( ! $block['blockName'] || ( $locked_only && empty( $block['attrs']['lock']['remove'] ) ) ) {
			continue;
		}

		$key = $block['blockName'];

		if ( ! empty( $block['attrs']['slug'] ) && is_string( $block['attrs']['slug'] ) ) {
			$key .= ':' . $block['attrs']['slug'];
		} elseif ( ! empty( $block['attrs']['className'] ) && is_string( $block['attrs']['className'] ) ) {
			$key .= '.' . strtok( $block['attrs']['className'], ' ' );
		}

		$keys[ $key ] = true;
	}

	return array_keys( $keys );
}

/**
 * The markup of one of the theme's own templates, or '' if the theme has none by that name.
 *
 * Read from the theme's files rather than through `get_block_file_template()`, which only finds the active
 * theme's templates, and a site this cleans up may be on another theme.
 *
 * @param string $slug Template slug.
 */
function get_theme_template( string $slug ): string {
	$theme = wp_get_theme( THEME );

	foreach ( array_unique( array( $theme->get_stylesheet_directory(), $theme->get_template_directory() ) ) as $directory ) {
		$file = "{$directory}/templates/{$slug}.html";

		if ( is_readable( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local theme file.
			return (string) file_get_contents( $file );
		}
	}

	return '';
}

/**
 * The current site's custom templates and template parts for the theme.
 *
 * Uses the statuses core reads them in (`get_block_templates()`), so a trashed one doesn't count.
 *
 * @return WP_Post[]
 */
function get_overrides(): array {
	return get_posts(
		array(
			'post_type'        => array( 'wp_template', 'wp_template_part' ),
			'post_status'      => array( 'auto-draft', 'draft', 'publish' ),
			'posts_per_page'   => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- A handful of posts per site, in a one-off command.
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => THEME,
				),
			),
		)
	);
}

/**
 * The current site's global styles for the theme, if it has changed any.
 */
function get_custom_global_styles(): ?WP_Post {
	$posts = get_posts(
		array(
			'post_type'        => 'wp_global_styles',
			'post_status'      => 'publish',
			'name'             => 'wp-global-styles-' . rawurlencode( THEME ),
			'posts_per_page'   => 1,
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);

	if ( ! $posts ) {
		return null;
	}

	// A site that opened Styles without changing anything has a post holding only these two keys.
	$config = json_decode( $posts[0]->post_content, true );
	unset( $config['version'], $config['isGlobalStylesUserThemeJSON'] );

	return array_filter( (array) $config ) ? $posts[0] : null;
}

/**
 * What the current site has overridden, and what `reset_site()` would reset.
 *
 * @return array {
 *     @type string   $theme         Active theme.
 *     @type string[] $templates     Custom template slugs.
 *     @type string[] $parts         Custom template part slugs.
 *     @type bool     $global_styles Whether the site has changed its global styles.
 *     @type string[] $reset         Why each override to reset is reset, keyed by post ID.
 * }
 */
function audit_site(): array {
	$audit = array(
		'theme'         => get_stylesheet(),
		'templates'     => array(),
		'parts'         => array(),
		'global_styles' => (bool) get_custom_global_styles(),
		'reset'         => array(),
	);

	foreach ( get_overrides() as $post ) {
		if ( 'wp_template_part' === $post->post_type ) {
			$audit['parts'][] = $post->post_name;

			if ( in_array( $post->post_name, SHARED_PARTS, true ) ) {
				$audit['reset'][ $post->ID ] = sprintf( 'the shared %s part', $post->post_name );
			}

			continue;
		}

		$audit['templates'][] = $post->post_name;

		// A template the theme doesn't have, such as one an organizer added, has nothing to lose.
		$missing = array_diff(
			get_block_keys( get_theme_template( $post->post_name ), true ),
			get_block_keys( $post->post_content, false )
		);

		if ( $missing ) {
			$audit['reset'][ $post->ID ] = sprintf( 'the %s template, missing %s', $post->post_name, implode( ', ', $missing ) );
		}
	}

	return $audit;
}

/**
 * Everything on the current site that `reset_site()` could change, to restore it from by hand.
 *
 * Covers every override, not just those reset, and the global styles.
 */
function get_backup(): array {
	$posts = get_overrides();

	$global_styles = get_custom_global_styles();
	if ( $global_styles ) {
		$posts[] = $global_styles;
	}

	return array(
		'site'    => home_url( '/' ),
		'blog_id' => get_current_blog_id(),
		'theme'   => get_stylesheet(),
		'date'    => gmdate( 'c' ),
		'posts'   => array_map(
			static function ( WP_Post $post ) {
				return array(
					'ID'                => $post->ID,
					'post_type'         => $post->post_type,
					'post_name'         => $post->post_name,
					'post_title'        => $post->post_title,
					'post_excerpt'      => $post->post_excerpt,
					'post_content'      => $post->post_content,
					'post_status'       => $post->post_status,
					'post_author'       => (int) $post->post_author,
					'post_date_gmt'     => $post->post_date_gmt,
					'post_modified_gmt' => $post->post_modified_gmt,
					'terms'             => array(
						'wp_theme'              => wp_get_object_terms( $post->ID, 'wp_theme', array( 'fields' => 'names' ) ),
						'wp_template_part_area' => wp_get_object_terms( $post->ID, 'wp_template_part_area', array( 'fields' => 'names' ) ),
					),
					'meta'              => get_post_meta( $post->ID ),
				);
			},
			$posts
		),
	);
}

/**
 * Put the current site back on the theme, and trash the overrides `audit_site()` says to reset.
 *
 * Trashed rather than deleted, so the Site Editor reads the theme's version again while the old one can still
 * be restored. Call `get_backup()` first.
 *
 * @return string[] What was done, or what couldn't be.
 */
function reset_site(): array {
	$done  = array();
	$audit = audit_site();

	if ( THEME !== $audit['theme'] ) {
		switch_theme( THEME );
		$done[] = sprintf( 'Switched from %s to %s.', $audit['theme'], THEME );
	}

	foreach ( $audit['reset'] as $post_id => $why ) {
		$done[] = wp_trash_post( $post_id )
			? sprintf( 'Trashed %s (post %d).', $why, $post_id )
			: sprintf( 'Could not trash %s (post %d).', $why, $post_id );
	}

	return $done;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WordCamp.org: list and reset group sites' overrides of the Groups theme.
 */
class Command extends WP_CLI_Command {
	/**
	 * List every group site's theme overrides, and what `reset` would reset.
	 *
	 * Only reads. Run it on the groups network's root site.
	 *
	 * ## OPTIONS
	 *
	 * [--needs-reset]
	 * : Only list the sites `reset` would change.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns: blog_id, url, theme, templates, parts, global_styles, reset.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp --url=events.wordpress.org/group/ wc-groups-theme-overrides scan
	 *     wp --url=events.wordpress.org/group/ wc-groups-theme-overrides scan --needs-reset --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function scan( $args, $assoc_args ) {
		$this->require_groups_root();

		$rows = array();

		foreach ( $this->get_group_site_ids() as $blog_id ) {
			switch_to_blog( $blog_id );

			$audit = audit_site();

			if ( ! isset( $assoc_args['needs-reset'] ) || THEME !== $audit['theme'] || $audit['reset'] ) {
				$rows[] = array(
					'blog_id'       => $blog_id,
					'url'           => home_url( '/' ),
					'theme'         => $audit['theme'],
					'templates'     => implode( ', ', $audit['templates'] ),
					'parts'         => implode( ', ', $audit['parts'] ),
					'global_styles' => $audit['global_styles'] ? 'yes' : '',
					'reset'         => implode( '; ', $audit['reset'] ),
				);
			}

			restore_current_blog();
		}

		$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) ) : array( 'blog_id', 'url', 'theme', 'templates', 'parts', 'global_styles', 'reset' );

		format_items( $assoc_args['format'] ?? 'table', $rows, $fields );
	}

	/**
	 * Back up and reset group sites' theme overrides.
	 *
	 * Puts each site back on the Groups theme, and trashes its custom header and footer parts and any custom
	 * template missing one of the theme's locked blocks. Everything else stays. Before changing a site, writes
	 * all its overrides and global styles to `<backup-dir>/<blog_id>.json`, and skips the site if that fails.
	 *
	 * Run it on the groups network's root site. Safe to re-run: a site with nothing to reset is left alone.
	 *
	 * ## OPTIONS
	 *
	 * [<blog_id>...]
	 * : Group sites to reset, by blog ID.
	 *
	 * [--all]
	 * : Reset every group site that needs it.
	 *
	 * [--backup-dir=<dir>]
	 * : An existing, writable directory for the backups. Required unless --dry-run.
	 *
	 * [--dry-run]
	 * : Report what would change, without backing up or changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --url=events.wordpress.org/group/ wc-groups-theme-overrides reset --all --dry-run
	 *     wp --url=events.wordpress.org/group/ wc-groups-theme-overrides reset 123 456 --backup-dir=$HOME/group-theme-backups
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function reset( $args, $assoc_args ) {
		$this->require_groups_root();

		$dry_run    = isset( $assoc_args['dry-run'] );
		$backup_dir = isset( $assoc_args['backup-dir'] ) ? untrailingslashit( $assoc_args['backup-dir'] ) : '';
		$group_ids  = $this->get_group_site_ids();

		if ( isset( $assoc_args['all'] ) ) {
			$blog_ids = $group_ids;
		} elseif ( $args ) {
			$blog_ids = array_map( 'absint', $args );
			$unknown  = array_diff( $blog_ids, $group_ids );

			if ( $unknown ) {
				WP_CLI::error( sprintf( 'Not group sites: %s.', implode( ', ', $unknown ) ) );
			}
		} else {
			WP_CLI::error( 'Pass the blog IDs to reset, or --all.' );
		}

		if ( ! $dry_run && ( ! $backup_dir || ! is_dir( $backup_dir ) || ! wp_is_writable( $backup_dir ) ) ) {
			WP_CLI::error( 'Pass --backup-dir with an existing, writable directory, or --dry-run.' );
		}

		$changed = 0;

		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );

			$url   = home_url( '/' );
			$audit = audit_site();

			if ( THEME === $audit['theme'] && ! $audit['reset'] ) {
				restore_current_blog();
				continue;
			}

			if ( $dry_run ) {
				++$changed;

				if ( THEME !== $audit['theme'] ) {
					WP_CLI::log( sprintf( '%s: would switch from %s to %s.', $url, $audit['theme'], THEME ) );
				}

				foreach ( $audit['reset'] as $post_id => $why ) {
					WP_CLI::log( sprintf( '%s: would trash %s (post %d).', $url, $why, $post_id ) );
				}

				restore_current_blog();
				continue;
			}

			$backup_file = "{$backup_dir}/{$blog_id}.json";

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A CLI backup on the host running it.
			if ( ! file_put_contents( $backup_file, wp_json_encode( get_backup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) {
				WP_CLI::warning( sprintf( '%s: could not write %s, so the site was left alone.', $url, $backup_file ) );
				restore_current_blog();
				continue;
			}

			foreach ( reset_site() as $line ) {
				WP_CLI::log( sprintf( '%s: %s', $url, $line ) );
			}

			++$changed;
			restore_current_blog();
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d of %d sites would change. Run without --dry-run to apply.', $changed, count( $blog_ids ) ) );
		} else {
			WP_CLI::success( sprintf( '%d of %d sites reset, with backups in %s.', $changed, count( $blog_ids ), $backup_dir ) );
		}
	}

	/**
	 * Stop unless running on the groups network's root site.
	 */
	private function require_groups_root() {
		if ( GROUPS_ROOT_BLOG_ID !== get_current_blog_id() ) {
			WP_CLI::error( 'Run this on the groups network root site, e.g. --url=events.wordpress.org/group/.' );
		}
	}

	/**
	 * Every group site on the network, apart from the root, which isn't a group.
	 *
	 * @return int[]
	 */
	private function get_group_site_ids() {
		return array_values(
			array_diff(
				array_map(
					'intval',
					get_sites(
						array(
							'network_id' => GROUPS_NETWORK_ID,
							'fields'     => 'ids',
							'number'     => 0,
							'deleted'    => 0,
						)
					)
				),
				array( GROUPS_ROOT_BLOG_ID )
			)
		);
	}
}

WP_CLI::add_command( 'wc-groups-theme-overrides', __NAMESPACE__ . '\Command' );
