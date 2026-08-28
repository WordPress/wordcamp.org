<?php

use cli\progress\Bar;
use function WordCamp\Dangling_Hosts\{ scan_network };
use const WordCamp\Dangling_Hosts\REFERENCE_KINDS;

defined( 'WP_CLI' ) || die();

/**
 * WordCamp.org: Audit published content for references to domains that no longer exist.
 */
class WordCamp_CLI_Dangling_Hosts extends WP_CLI_Command {
	/**
	 * Report external references in published content whose domains have lapsed.
	 *
	 * Walks the published posts and pages on each site, collects every third-party host they link to, embed,
	 * or load a script from, and checks whether those hosts still resolve. A host that doesn't resolve, and
	 * whose domain has no nameservers, is a domain somebody else can now register -- at which point our
	 * content is pointing at whatever they decide to put there.
	 *
	 * This only reports. What to do about a given reference depends on the post, so it needs a human.
	 *
	 * ## OPTIONS
	 *
	 * [--site=<site>]
	 * : Scan a single site, by ID or URL, instead of the whole network.
	 *
	 * [--post-types=<post-types>]
	 * : Comma-separated post types to scan.
	 * ---
	 * default: post,page
	 * ---
	 *
	 * [--kinds=<kinds>]
	 * : Comma-separated reference kinds to report: script, embed, iframe, img, link, url.
	 * Defaults to all of them.
	 *
	 * [--include-ok]
	 * : Also list references whose hosts resolve normally. Useful for seeing the full external surface.
	 *
	 * [--skip-verify]
	 * : Skip the RDAP confirmation of lapsed domains. Faster, and works without outbound HTTP, but a
	 * transient DNS failure can then look like a lapsed domain.
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
	 *     # Audit the whole network.
	 *     wp wc-dangling scan
	 *
	 *     # Audit one site.
	 *     wp wc-dangling scan --site=seattle.wordcamp.org/2020
	 *
	 *     # Only the kinds that execute or render something, as JSON.
	 *     wp wc-dangling scan --kinds=script,embed,url --format=json
	 *
	 *     # Every external host the network references, whether or not it still resolves.
	 *     wp wc-dangling scan --include-ok --format=csv
	 *
	 * @subcommand scan
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function scan( $args, $assoc_args ) {
		$format     = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$post_types = $this->parse_list( WP_CLI\Utils\get_flag_value( $assoc_args, 'post-types', 'post,page' ) );
		$kinds      = $this->parse_kinds( WP_CLI\Utils\get_flag_value( $assoc_args, 'kinds', '' ) );
		$blog_ids   = $this->parse_site( WP_CLI\Utils\get_flag_value( $assoc_args, 'site', '' ) );

		// A progress bar would interleave with the report itself in the machine-readable formats.
		$show_progress = in_array( $format, array( 'table', 'count' ), true );
		$notify        = null;

		if ( $show_progress ) {
			$site_count = $blog_ids ? count( $blog_ids ) : (int) get_sites( array( 'count' => true ) );
			$notify     = new Bar( 'Scanning sites', $site_count );
		}

		$references = scan_network(
			array(
				'blog_ids'   => $blog_ids,
				'post_types' => $post_types,
				'kinds'      => $kinds,
				'verify'     => ! WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-verify', false ),
				'include_ok' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'include-ok', false ),
				'progress'   => $notify ? function () use ( $notify ) {
					$notify->tick();
				} : null,
			)
		);

		if ( $notify ) {
			$notify->finish();
			WP_CLI::line();
		}

		if ( empty( $references ) ) {
			WP_CLI::success( 'No references to lapsed domains found.' );

			return;
		}

		$fields = array( 'status', 'kind', 'host', 'domain', 'permalink', 'url' );

		WP_CLI\Utils\format_items( $format, $references, $fields );

		$dangling = array_filter(
			$references,
			function ( $reference ) {
				return 'dangling' === $reference['status'];
			}
		);

		if ( empty( $dangling ) ) {
			return;
		}

		// `count` prints a bare number with no trailing newline, which would run into the message below.
		if ( 'count' === $format ) {
			WP_CLI::line();
		}

		/*
		 * Non-zero exit, so a scheduled run shows up as a failure instead of quietly succeeding with findings
		 * buried in its output.
		 */
		WP_CLI::error(
			sprintf(
				'%d reference(s) point at %d lapsed domain(s).',
				count( $dangling ),
				count( array_unique( wp_list_pluck( $dangling, 'domain' ) ) )
			)
		);
	}

	/**
	 * Split a comma-separated option into a trimmed list.
	 *
	 * @param string $value
	 *
	 * @return array
	 */
	protected function parse_list( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}

	/**
	 * Validate the `--kinds` option.
	 *
	 * @param string $value
	 *
	 * @return array
	 */
	protected function parse_kinds( $value ) {
		$kinds = $this->parse_list( $value );

		if ( empty( $kinds ) ) {
			return REFERENCE_KINDS;
		}

		$unknown = array_diff( $kinds, REFERENCE_KINDS );

		if ( $unknown ) {
			WP_CLI::error(
				sprintf(
					'Unknown reference kind(s): %s. Valid kinds are: %s.',
					implode( ', ', $unknown ),
					implode( ', ', REFERENCE_KINDS )
				)
			);
		}

		return $kinds;
	}

	/**
	 * Resolve the `--site` option to a list holding a single blog ID.
	 *
	 * `--url` is reserved by WP-CLI itself for choosing the site to bootstrap, which isn't what's wanted here
	 * -- the scan needs to bootstrap on the network and switch into each site -- so this takes its own option.
	 *
	 * @param string $value ID or URL.
	 *
	 * @return array Empty when no site was specified, meaning "scan the whole network".
	 */
	protected function parse_site( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$value = trim( $value );

		if ( ctype_digit( $value ) ) {
			$site = get_site( (int) $value );
		} else {
			$parsed = wp_parse_url( 0 === strpos( $value, 'http' ) ? $value : 'https://' . $value );
			$site   = get_site_by_path( $parsed['host'] ?? '', $parsed['path'] ?? '/' );
		}

		if ( ! $site ) {
			WP_CLI::error( sprintf( 'Could not find a site matching `%s`.', $value ) );
		}

		return array( (int) $site->blog_id );
	}
}
