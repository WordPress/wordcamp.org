<?php
/*
 * Find external references in published content whose domains no longer exist.
 *
 * Old WordCamp content links to and embeds a lot of third-party sites, and some of those domains eventually
 * lapse. Our content goes on referencing them, and nothing currently notices.
 *
 * These functions only report. Deciding what to do about a reference -- update it, unlink it, point it at an
 * archived copy -- needs a human looking at the post.
 *
 * This file intentionally registers no hooks, so the logic stays callable (and testable) on its own. The CLI
 * wrapper lives in `wp-cli-commands/dangling-hosts.php`.
 */

namespace WordCamp\Dangling_Hosts;

defined( 'WPINC' ) || die();

/**
 * Hosts we control, or that are operated by WordPress.org.
 *
 * These can't lapse out from under us, so there's no point spending a DNS lookup on them.
 */
const FIRST_PARTY_HOST_SUFFIXES = array(
	'wordcamp.org',
	'wordpress.org',
	'wordpress.com',
	'wordpress.net',
	'w.org',
	'wp.com',
	'gravatar.com',
);

/**
 * Public suffixes that are two labels deep.
 *
 * `get_registrable_domain()` needs to know that the registrable part of `foo.example.co.uk` is `example.co.uk`
 * and not `co.uk`. This is a pragmatic subset rather than the full Public Suffix List -- see that function for
 * why the shortcut is safe here.
 */
const MULTI_LABEL_PUBLIC_SUFFIXES = array(
	'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk',
	'co.jp', 'ne.jp', 'or.jp', 'ac.jp',
	'co.in', 'net.in', 'org.in',
	'com.au', 'net.au', 'org.au', 'edu.au',
	'com.br', 'net.br', 'org.br',
	'co.nz', 'net.nz', 'org.nz',
	'com.mx', 'com.ar', 'com.co', 'com.tr', 'com.cn', 'com.tw', 'com.sg', 'com.my', 'com.ph',
	'co.za', 'co.il', 'co.kr', 'co.id', 'co.th',
);

/**
 * Reference kinds, ordered by how much the referenced host contributes to the rendered page.
 *
 * A `script` or an `embed` is loaded and used as part of the page, where a `link` is only followed if somebody
 * clicks it, so the first two are worth reviewing first. `url` is a bare URL on its own line, which
 * `WP_Embed::autoembed()` turns into an `embed` when the post is rendered, so it belongs with them.
 */
const REFERENCE_KINDS = array( 'script', 'embed', 'iframe', 'img', 'link', 'url' );

/**
 * Pull every external reference out of a chunk of post content.
 *
 * Both raw `post_content` and cached oEmbed markup from postmeta go through here. Raw content usually holds a
 * bare URL where the rendered page will have an iframe, which is why `url` is one of the kinds -- looking only
 * for iframes would miss embeds that have never been rendered and cached.
 *
 * @param string $content
 *
 * @return array List of `array( 'host' => string, 'kind' => string, 'url' => string )`, deduplicated on
 *               host + kind. Relative URLs and first-party hosts are omitted.
 */
function extract_references( $content ) {
	if ( ! is_string( $content ) || '' === trim( $content ) ) {
		return array();
	}

	$references = array();

	// `src` on a tag we care about. The tag name decides the kind, except that a Core oEmbed iframe is
	// distinguished from any other iframe by its class.
	if ( preg_match_all( '#<(script|iframe|img)\b[^>]*>#i', $content, $tags ) ) {
		foreach ( $tags[0] as $index => $tag ) {
			$url = get_attribute_value( $tag, 'src' );

			if ( ! $url ) {
				continue;
			}

			$kind = strtolower( $tags[1][ $index ] );

			if ( 'iframe' === $kind && false !== stripos( $tag, 'wp-embedded-content' ) ) {
				$kind = 'embed';
			}

			add_reference( $references, $url, $kind );
		}
	}

	// Anchors.
	if ( preg_match_all( '#<a\b[^>]*>#i', $content, $anchors ) ) {
		foreach ( $anchors[0] as $anchor ) {
			$url = get_attribute_value( $anchor, 'href' );

			if ( $url ) {
				add_reference( $references, $url, 'link' );
			}
		}
	}

	/*
	 * A URL alone on its own line. That's what Core's autoembed looks for, and it's how most of these
	 * references are actually stored -- the iframe only exists in the rendered output.
	 *
	 * The `wp:embed` block stores its URL as a JSON attribute rather than on its own line, so match that
	 * shape too.
	 */
	if ( preg_match_all( '#^\s*(https?://[^\s<>"\']+)\s*$#im', $content, $bare ) ) {
		foreach ( $bare[1] as $url ) {
			add_reference( $references, $url, 'url' );
		}
	}

	if ( preg_match_all( '#"url"\s*:\s*"(https?://[^"\\\\]+)"#i', $content, $block_attrs ) ) {
		foreach ( $block_attrs[1] as $url ) {
			add_reference( $references, $url, 'url' );
		}
	}

	return array_values( $references );
}

/**
 * Read one attribute out of an HTML start tag.
 *
 * Deliberately not a DOM parse -- this runs over a lot of old, frequently malformed content, and a regex that
 * simply finds nothing is a better failure mode here than a parser that throws.
 *
 * @param string $tag
 * @param string $attribute
 *
 * @return string Empty string when the attribute isn't present.
 */
function get_attribute_value( $tag, $attribute ) {
	$pattern = sprintf( '#\b%s\s*=\s*("([^"]*)"|\'([^\']*)\')#i', preg_quote( $attribute, '#' ) );

	if ( ! preg_match( $pattern, $tag, $matches ) ) {
		return '';
	}

	$value = isset( $matches[3] ) && '' !== $matches[3] ? $matches[3] : $matches[2];

	return trim( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );
}

/**
 * Normalize a URL to a host and record it, unless it's one we don't care about.
 *
 * @param array  $references Accumulator, keyed on host + kind so a host repeated throughout a post is only
 *                           reported once. Passed by reference.
 * @param string $url
 * @param string $kind
 */
function add_reference( array &$references, $url, $kind ) {
	$url = trim( $url );

	// Protocol-relative. Everything downstream wants a scheme.
	if ( str_starts_with( $url, '//' ) ) {
		$url = 'https:' . $url;
	}

	$host = wp_parse_url( $url, PHP_URL_HOST );

	// Relative URLs, `mailto:`, `#anchor`, and anything malformed enough that there's no host to check.
	if ( ! $host ) {
		return;
	}

	$host = strtolower( $host );

	if ( ! is_scannable_host( $host ) || is_first_party_host( $host ) ) {
		return;
	}

	$key = $host . '|' . $kind;

	if ( ! isset( $references[ $key ] ) ) {
		$references[ $key ] = array(
			'host' => $host,
			'kind' => $kind,
			'url'  => $url,
		);
	}
}

/**
 * Is this host a name somebody could have registered?
 *
 * Two decades of hand-written content contain hrefs that never described a real host: a comma or a closing
 * parenthesis swept in from the surrounding prose, a stray space, an IP literal typed during a migration.
 * None of those is a registration, so none of them can lapse. Reporting one as a lapsed domain states
 * something untrue about it, and buries the references that do need attention.
 *
 * Checking the shape here also keeps the malformed ones out of the DNS and RDAP lookups behind the report,
 * which is where the scan spends nearly all of its time.
 *
 * @param string $host
 *
 * @return bool
 */
function is_scannable_host( $host ) {
	// The maximum length of a fully qualified name, per RFC 1035.
	if ( strlen( $host ) > 253 ) {
		return false;
	}

	$labels = explode( '.', $host );

	// A single label is `localhost` or an intranet name, not something anybody registers.
	if ( count( $labels ) < 2 ) {
		return false;
	}

	foreach ( $labels as $label ) {
		if ( ! preg_match( '#^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$#', $label ) ) {
			return false;
		}
	}

	/*
	 * A last label that isn't alphabetic means an IP literal, which is an address rather than a registration.
	 * `xn--` is spelled out because a punycode TLD carries digits and hyphens that a plain alphabetic test
	 * would reject.
	 */
	return (bool) preg_match( '#^(?:[a-z]{2,}|xn--[a-z0-9-]+)$#', end( $labels ) );
}

/**
 * Is this a host that WordPress.org or WordCamp.org controls?
 *
 * @param string $host
 *
 * @return bool
 */
function is_first_party_host( $host ) {
	$host = strtolower( ltrim( $host, '.' ) );

	foreach ( FIRST_PARTY_HOST_SUFFIXES as $suffix ) {
		if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Reduce a host to the domain that somebody would actually register.
 *
 * `www.example.com` and `cdn.example.com` are both `example.com`; whether they resolve is a question about
 * that one registration.
 *
 * This uses a short list of known two-label public suffixes rather than the full Public Suffix List. Getting it
 * wrong means returning a suffix like `co.uk` instead of `example.co.uk`, which makes the NS lookup in
 * `check_host()` succeed and the host get reported as `unresolved` rather than `dangling` -- the cautious
 * direction, and one the RDAP step would catch anyway.
 *
 * @param string $host
 *
 * @return string
 */
function get_registrable_domain( $host ) {
	$host   = strtolower( trim( $host, '. ' ) );
	$labels = explode( '.', $host );
	$count  = count( $labels );

	if ( $count <= 2 ) {
		return $host;
	}

	$last_two = implode( '.', array_slice( $labels, -2 ) );

	if ( in_array( $last_two, MULTI_LABEL_PUBLIC_SUFFIXES, true ) ) {
		return implode( '.', array_slice( $labels, -3 ) );
	}

	return $last_two;
}

/**
 * Work out whether a host still exists.
 *
 * Two stages, because "doesn't resolve" and "isn't registered" aren't the same thing and only the second one is
 * worth acting on. A subdomain of a live domain can stop resolving for all sorts of mundane reasons, and nobody
 * else can claim it. A domain with no nameservers at all is a different situation.
 *
 * @param string $host
 *
 * @return array The `host` that was checked, its registrable `domain`, and a `status` of 'ok' (resolves),
 *               'unresolved' (doesn't resolve, but the domain is registered), or 'dangling' (the domain
 *               itself has no nameservers).
 */
function check_host( $host ) {
	$host   = strtolower( $host );
	$domain = get_registrable_domain( $host );

	/**
	 * Short-circuit the DNS lookups for a host.
	 *
	 * Exists so tests don't depend on the network. Return an array shaped like `check_host()`'s return value
	 * to take over, or `null` to let the normal lookups run.
	 *
	 * @param array|null $result
	 * @param string     $host
	 * @param string     $domain
	 */
	$pre = apply_filters( 'wcorg_dangling_hosts_pre_check_host', null, $host, $domain );

	if ( is_array( $pre ) ) {
		return $pre;
	}

	if ( host_resolves( $host ) ) {
		return array(
			'host'   => $host,
			'domain' => $domain,
			'status' => 'ok',
		);
	}

	// Nothing at the host, so the question becomes whether the registration behind it still exists.
	$nameservers = dns_records( $domain, DNS_NS );

	return array(
		'host'   => $host,
		'domain' => $domain,
		'status' => empty( $nameservers ) ? 'dangling' : 'unresolved',
	);
}

/**
 * Does the host have an address (directly or through a CNAME)?
 *
 * Each record type is queried separately rather than as one combined bitmask. `dns_get_record()` fails the
 * whole call when any type in the mask errors out -- asking for `DNS_A | DNS_CNAME` on a host that has an
 * address but no CNAME returns `false`, not the address -- which would make every host look unresolvable.
 * Querying one at a time and stopping at the first answer also means the usual case costs a single lookup.
 *
 * @param string $host
 *
 * @return bool
 */
function host_resolves( $host ) {
	foreach ( array( DNS_A, DNS_AAAA, DNS_CNAME ) as $type ) {
		if ( ! empty( dns_records( $host, $type ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Look up DNS records, without letting a lookup failure become a PHP warning.
 *
 * `dns_get_record()` emits a warning for NXDOMAIN and some resolver errors, and PHPUnit is configured to turn
 * warnings into test failures, so suppression here is deliberate rather than lazy.
 *
 * @param string $host
 * @param int    $type One of the `DNS_*` constants.
 *
 * @return array
 */
function dns_records( $host, $type ) {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is an expected result here, not an error.
	$records = @dns_get_record( $host, $type );

	return is_array( $records ) ? $records : array();
}

/**
 * Confirm with the registry that a domain really has no registration.
 *
 * DNS not answering is suggestive but not conclusive -- a resolver hiccup mid-scan looks identical to a domain
 * that lapsed. RDAP is authoritative, and only ever runs against the handful of candidates that reached
 * 'dangling', so it costs almost nothing.
 *
 * @param string $domain
 *
 * @return bool True only when the registry positively reports no such registration. Anything ambiguous (a
 *              timeout, a rate limit, an unexpected status) returns false, so an inconclusive answer downgrades
 *              the finding rather than asserting something we haven't confirmed.
 */
function verify_unregistered( $domain ) {
	$response = wp_remote_get(
		'https://rdap.org/domain/' . rawurlencode( $domain ),
		array(
			'timeout'     => 15,
			'redirection' => 5,
			'headers'     => array( 'Accept' => 'application/rdap+json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return false;
	}

	return 404 === wp_remote_retrieve_response_code( $response );
}

/**
 * Collect the external references in one site's published content.
 *
 * Reads `post_content` straight out of the database rather than going through `WP_Query`, because this only
 * needs two columns and may run across a thousand sites.
 *
 * Cached oEmbed markup is scanned as well. Core stores the provider's HTML in `_oembed_{hash}` postmeta, and
 * that markup is what actually reaches the page -- for a classic-editor embed it's the only place the iframe
 * exists at all.
 *
 * @param int   $blog_id
 * @param array $args Optional. `post_types` limits which post types are read; defaults to `post` and `page`.
 *
 * @return array List of references, each with `host`, `kind`, `url`, `post_id`, and `permalink`.
 */
function scan_site( $blog_id, array $args = array() ) {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array( 'post_types' => array( 'post', 'page' ) )
	);

	$switched = false;

	if ( get_current_blog_id() !== (int) $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$references = array();

	try {
		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $args['post_types'] ) ) );

		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s, and $wpdb->posts is not user input.
		$sql = "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( $placeholders )";

		$posts = $wpdb->get_results( $wpdb->prepare( $sql, $post_types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		foreach ( $posts as $post ) {
			collect_references( $references, extract_references( $post->post_content ), (int) $post->ID, $blog_id );
		}

		// Cached oEmbed markup, which usually isn't present in `post_content`.
		$oembed_meta = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_oembed\_%'"
		);

		foreach ( $oembed_meta as $meta ) {
			if ( ! is_string( $meta->meta_value ) ) {
				continue;
			}

			collect_references( $references, extract_references( $meta->meta_value ), (int) $meta->post_id, $blog_id );
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return array_values( $references );
}

/**
 * Merge a post's references into a site-level accumulator.
 *
 * Keyed on host + kind + post so the same host appearing in both `post_content` and its cached oEmbed markup
 * only gets reported once.
 *
 * @param array $accumulator Passed by reference.
 * @param array $references
 * @param int   $post_id
 * @param int   $blog_id
 */
function collect_references( array &$accumulator, array $references, $post_id, $blog_id ) {
	foreach ( $references as $reference ) {
		$key = $reference['host'] . '|' . $reference['kind'] . '|' . $post_id;

		if ( isset( $accumulator[ $key ] ) ) {
			continue;
		}

		$reference['post_id']   = $post_id;
		$reference['blog_id']   = (int) $blog_id;
		$reference['site']      = home_url();
		$reference['permalink'] = get_permalink( $post_id );

		$accumulator[ $key ] = $reference;
	}
}

/**
 * Scan sites and report which of their external references point at hosts that no longer exist.
 *
 * Each distinct host is checked once no matter how many sites reference it, which is what keeps this
 * proportional to the number of domains rather than the number of posts.
 *
 * @param array $args Optional. `blog_ids` limits the scan to specific sites, defaulting to the whole network;
 *                     `post_types` defaults to `post` and `page`; `kinds` limits which reference kinds are
 *                     reported, defaulting to all of `REFERENCE_KINDS`; `verify` confirms lapsed domains
 *                     against RDAP, default true; `include_ok` also reports hosts that resolve, default
 *                     false; `progress` is called with each blog ID as it's scanned.
 *
 * @return array List of references, each with the fields from `scan_site()` plus `status` and `domain`.
 *               'dangling' entries are listed first, then 'unresolved', then 'ok'.
 */
function scan_network( array $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'blog_ids'   => array(),
			'post_types' => array( 'post', 'page' ),
			'kinds'      => REFERENCE_KINDS,
			'verify'     => true,
			'include_ok' => false,
			'progress'   => null,
		)
	);

	$blog_ids = $args['blog_ids'];

	if ( empty( $blog_ids ) ) {
		$blog_ids = get_sites(
			array(
				'number'   => 0,
				'fields'   => 'ids',
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);
	}

	$references = array();

	foreach ( $blog_ids as $blog_id ) {
		foreach ( scan_site( (int) $blog_id, array( 'post_types' => $args['post_types'] ) ) as $reference ) {
			if ( in_array( $reference['kind'], $args['kinds'], true ) ) {
				$references[] = $reference;
			}
		}

		if ( is_callable( $args['progress'] ) ) {
			call_user_func( $args['progress'], $blog_id );
		}
	}

	// One check per distinct host, however many references share it.
	$checked = array();

	foreach ( $references as $reference ) {
		if ( ! isset( $checked[ $reference['host'] ] ) ) {
			$checked[ $reference['host'] ] = check_host( $reference['host'] );
		}
	}

	/*
	 * Ask the registry about the candidates. A domain that DNS couldn't answer for but that RDAP says is
	 * registered gets downgraded to 'unresolved' -- still worth a look, but not the thing we're hunting.
	 */
	if ( $args['verify'] ) {
		$verified = array();

		foreach ( $checked as $host => $result ) {
			if ( 'dangling' !== $result['status'] ) {
				continue;
			}

			$domain = $result['domain'];

			if ( ! isset( $verified[ $domain ] ) ) {
				$verified[ $domain ] = verify_unregistered( $domain );
			}

			if ( ! $verified[ $domain ] ) {
				$checked[ $host ]['status'] = 'unresolved';
			}
		}
	}

	$results = array();

	foreach ( $references as $reference ) {
		$result = $checked[ $reference['host'] ];

		if ( 'ok' === $result['status'] && ! $args['include_ok'] ) {
			continue;
		}

		$reference['status'] = $result['status'];
		$reference['domain'] = $result['domain'];

		$results[] = $reference;
	}

	usort( $results, __NAMESPACE__ . '\compare_references' );

	return $results;
}

/**
 * Sort the findings most worth reviewing to the top.
 *
 * Status first, then reference kind, since a stale script source is more worth looking at than a stale link on
 * the same domain.
 *
 * @param array $a
 * @param array $b
 *
 * @return int
 */
function compare_references( $a, $b ) {
	$status_order = array(
		'dangling'   => 0,
		'unresolved' => 1,
		'ok'         => 2,
	);

	$by_status = $status_order[ $a['status'] ] <=> $status_order[ $b['status'] ];

	if ( 0 !== $by_status ) {
		return $by_status;
	}

	$by_kind = array_search( $a['kind'], REFERENCE_KINDS, true ) <=> array_search( $b['kind'], REFERENCE_KINDS, true );

	if ( 0 !== $by_kind ) {
		return $by_kind;
	}

	return strcmp( $a['host'], $b['host'] );
}
