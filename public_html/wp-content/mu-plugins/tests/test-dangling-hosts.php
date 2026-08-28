<?php

namespace WordCamp\Dangling_Hosts\Tests;
use WP_UnitTest_Factory;
use WordCamp\Tests\Database_TestCase;

use function WordCamp\Dangling_Hosts\{
	check_host, extract_references, get_registrable_domain, is_first_party_host, scan_network, scan_site
};

defined( 'WPINC' ) || die();

/*
 * The `WordPress.WP.EnqueuedResources` sniff fires on the `<script src=...>` samples below. They're strings of
 * content being fed to the parser, not scripts this file outputs, so there's nothing to enqueue.
 *
 * phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
 */

/**
 * @group mu-plugins
 * @group dangling-hosts
 */
class Test_Dangling_Hosts extends Database_TestCase {
	/**
	 * DNS answers to hand back instead of making real lookups, keyed by host.
	 *
	 * @var array
	 */
	protected $stubbed_hosts = array();

	/**
	 * Replace the DNS lookups for the duration of each test.
	 *
	 * Every test in here would otherwise be at the mercy of whatever the resolver says today, and asserting on
	 * a real domain's registration status would make the suite fail the moment somebody registered it.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->stubbed_hosts = array();

		add_filter( 'wcorg_dangling_hosts_pre_check_host', array( $this, 'stub_check_host' ), 10, 3 );
	}

	/**
	 * Remove the DNS stub.
	 */
	public function tearDown(): void {
		remove_filter( 'wcorg_dangling_hosts_pre_check_host', array( $this, 'stub_check_host' ), 10 );

		parent::tearDown();
	}

	/**
	 * Return the canned status for a host, if the test registered one.
	 *
	 * @param array|null $result
	 * @param string     $host
	 * @param string     $domain
	 *
	 * @return array|null
	 */
	public function stub_check_host( $result, $host, $domain ) {
		if ( ! isset( $this->stubbed_hosts[ $host ] ) ) {
			return $result;
		}

		return array(
			'host'   => $host,
			'domain' => $domain,
			'status' => $this->stubbed_hosts[ $host ],
		);
	}

	/**
	 * Create a published post whose content reaches the database verbatim.
	 *
	 * The suite defines `DISALLOW_UNFILTERED_HTML`, so `wp_insert_post()` runs the content through kses and
	 * strips tags like `<script>`. Old imported content can still contain them, and the scanner needs to
	 * report them, so the fixture writes the column directly rather than going through the editor's
	 * sanitization.
	 *
	 * @param string $content
	 *
	 * @return int Post ID.
	 */
	protected function create_published_post( $content ) {
		global $wpdb;

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		return $post_id;
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\extract_references
	 *
	 * @dataProvider data_extract_references
	 *
	 * @param string $content
	 * @param array  $expected List of `host|kind` pairs.
	 */
	public function test_extract_references( $content, $expected ) {
		$actual = array_map(
			function ( $reference ) {
				return $reference['host'] . '|' . $reference['kind'];
			},
			extract_references( $content )
		);

		sort( $actual );
		sort( $expected );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for `test_extract_references()`.
	 *
	 * @return array
	 */
	public function data_extract_references() {
		return array(
			'script source' => array(
				'<script src="https://cdn.example.net/player.js"></script>',
				array( 'cdn.example.net|script' ),
			),

			'core oembed iframe is distinguished from a plain one' => array(
				'<iframe class="wp-embedded-content" src="https://blog.example.net/post/embed/#?secret=abc"></iframe>',
				array( 'blog.example.net|embed' ),
			),

			'plain iframe' => array(
				'<iframe src="https://video.example.net/watch/123"></iframe>',
				array( 'video.example.net|iframe' ),
			),

			'image' => array(
				'<img src="https://images.example.net/photo.jpg" alt="" />',
				array( 'images.example.net|img' ),
			),

			'anchor' => array(
				'<a href="https://example.net/article">Read more</a>',
				array( 'example.net|link' ),
			),

			'bare url on its own line is what autoembed acts on' => array(
				"Some text\n\nhttps://blog.example.net/a-post/\n\nMore text",
				array( 'blog.example.net|url' ),
			),

			'embed block attribute' => array(
				'<!-- wp:embed {"url":"https://blog.example.net/a-post/","type":"rich"} /-->',
				array( 'blog.example.net|url' ),
			),

			'protocol relative urls are normalized' => array(
				'<script src="//cdn.example.net/a.js"></script>',
				array( 'cdn.example.net|script' ),
			),

			'relative urls have no host to check' => array(
				'<a href="/local/page">Local</a><img src="../uploads/a.png" />',
				array(),
			),

			'non http schemes are ignored' => array(
				'<a href="mailto:someone@example.net">Mail</a><a href="#section">Jump</a>',
				array(),
			),

			'first party hosts are skipped' => array(
				'<a href="https://central.wordcamp.org/">Central</a>
				 <img src="https://secure.gravatar.com/avatar/abc" />
				 <script src="https://s0.wp.com/a.js"></script>',
				array(),
			),

			'a host repeated in the same kind is only reported once' => array(
				'<a href="https://example.net/one">One</a><a href="https://example.net/two">Two</a>',
				array( 'example.net|link' ),
			),

			'the same host in different kinds is reported per kind' => array(
				'<a href="https://example.net/a">A</a><img src="https://example.net/b.png" />',
				array( 'example.net|link', 'example.net|img' ),
			),

			'single quoted attributes' => array(
				"<img src='https://images.example.net/photo.jpg' />",
				array( 'images.example.net|img' ),
			),

			'entity encoded attribute values' => array(
				'<a href="https://example.net/a?x=1&amp;y=2">Link</a>',
				array( 'example.net|link' ),
			),

			'uppercase host is normalized' => array(
				'<a href="https://EXAMPLE.NET/a">Link</a>',
				array( 'example.net|link' ),
			),

			'empty content' => array( '', array() ),

			'content with no references' => array( '<p>Just some words.</p>', array() ),
		);
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\is_first_party_host
	 *
	 * @dataProvider data_is_first_party_host
	 *
	 * @param string $host
	 * @param bool   $expected
	 */
	public function test_is_first_party_host( $host, $expected ) {
		$this->assertSame( $expected, is_first_party_host( $host ) );
	}

	/**
	 * Test cases for `test_is_first_party_host()`.
	 *
	 * @return array
	 */
	public function data_is_first_party_host() {
		return array(
			'apex'                     => array( 'wordcamp.org', true ),
			'subdomain'                => array( 'seattle.wordcamp.org', true ),
			'deep subdomain'           => array( '2020.seattle.wordcamp.org', true ),
			'sibling org'              => array( 'make.wordpress.org', true ),
			'third party'              => array( 'example.net', false ),

			/*
			 * The suffix has to match on a label boundary. A domain someone else registered that merely ends
			 * in our name is not ours.
			 */
			'lookalike is not matched' => array( 'notwordcamp.org', false ),
		);
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\get_registrable_domain
	 *
	 * @dataProvider data_get_registrable_domain
	 *
	 * @param string $host
	 * @param string $expected
	 */
	public function test_get_registrable_domain( $host, $expected ) {
		$this->assertSame( $expected, get_registrable_domain( $host ) );
	}

	/**
	 * Test cases for `test_get_registrable_domain()`.
	 *
	 * @return array
	 */
	public function data_get_registrable_domain() {
		return array(
			'apex'                    => array( 'example.net', 'example.net' ),
			'subdomain'               => array( 'www.example.net', 'example.net' ),
			'deep subdomain'          => array( 'a.b.c.example.net', 'example.net' ),
			'multi label suffix'      => array( 'www.example.co.uk', 'example.co.uk' ),
			'multi label suffix apex' => array( 'example.com.au', 'example.com.au' ),
			'uppercase'               => array( 'WWW.EXAMPLE.NET', 'example.net' ),
			'trailing dot'            => array( 'www.example.net.', 'example.net' ),
		);
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\check_host
	 *
	 * Verify the stub short-circuits the real lookups, so the rest of the suite can rely on it.
	 */
	public function test_check_host_uses_injected_result() {
		$this->stubbed_hosts = array( 'cdn.example.net' => 'dangling' );

		$actual = check_host( 'cdn.example.net' );

		$this->assertSame( 'dangling', $actual['status'] );
		$this->assertSame( 'example.net', $actual['domain'] );
		$this->assertSame( 'cdn.example.net', $actual['host'] );
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\scan_site
	 *
	 * A reference that only exists in the raw post content should be found.
	 */
	public function test_scan_site_finds_references_in_post_content() {
		$post_id = $this->create_published_post(
			'<a href="https://example.net/article">Read more</a>'
		);

		$references = scan_site( get_current_blog_id() );
		$hosts      = wp_list_pluck( $references, 'host' );

		$this->assertContains( 'example.net', $hosts );

		foreach ( $references as $reference ) {
			if ( 'example.net' === $reference['host'] ) {
				$this->assertSame( $post_id, $reference['post_id'] );
				$this->assertSame( 'link', $reference['kind'] );
			}
		}
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\scan_site
	 *
	 * The iframe for a classic-editor embed lives in the oEmbed cache rather than in the post, so scanning
	 * `post_content` alone would miss the host it points at.
	 */
	public function test_scan_site_finds_references_in_cached_oembed_markup() {
		$post_id = $this->create_published_post(
			'No links here.'
		);

		add_post_meta(
			$post_id,
			'_oembed_1234567890abcdef',
			'<blockquote class="wp-embedded-content"><a href="https://blog.example.net/a-post/">A post</a></blockquote>' .
			'<iframe class="wp-embedded-content" src="https://blog.example.net/a-post/embed/#?secret=abc"></iframe>'
		);

		$references = scan_site( get_current_blog_id() );
		$found      = array();

		foreach ( $references as $reference ) {
			if ( 'blog.example.net' === $reference['host'] ) {
				$found[] = $reference['kind'];
			}
		}

		$this->assertContains( 'embed', $found );
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\scan_site
	 *
	 * Unpublished content isn't served to anyone, so it shouldn't generate findings.
	 */
	public function test_scan_site_ignores_unpublished_posts() {
		self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_type'    => 'post',
				'post_content' => '<a href="https://draft-only.example.net/x">Link</a>',
			)
		);

		$hosts = wp_list_pluck( scan_site( get_current_blog_id() ), 'host' );

		$this->assertNotContains( 'draft-only.example.net', $hosts );
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\scan_network
	 *
	 * Only lapsed and unresolvable hosts are reported by default; a host that resolves is noise.
	 */
	public function test_scan_network_reports_only_problem_hosts_by_default() {
		$this->create_published_post(
			'<a href="https://gone.example.net/a">Gone</a>' .
					'<a href="https://live.example.net/b">Live</a>'
		);

		$this->stubbed_hosts = array(
			'gone.example.net' => 'dangling',
			'live.example.net' => 'ok',
		);

		$hosts = wp_list_pluck(
			scan_network(
				array(
					'blog_ids' => array( get_current_blog_id() ),
					'verify'   => false,
				)
			),
			'host'
		);

		$this->assertContains( 'gone.example.net', $hosts );
		$this->assertNotContains( 'live.example.net', $hosts );
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\scan_network
	 *
	 * `--include-ok` is how someone reviews the whole external surface, not just the broken parts.
	 */
	public function test_scan_network_can_include_healthy_hosts() {
		$this->create_published_post(
			'<a href="https://live.example.net/b">Live</a>'
		);

		$this->stubbed_hosts = array( 'live.example.net' => 'ok' );

		$references = scan_network(
			array(
				'blog_ids'   => array( get_current_blog_id() ),
				'verify'     => false,
				'include_ok' => true,
			)
		);

		$this->assertContains( 'live.example.net', wp_list_pluck( $references, 'host' ) );
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\scan_network
	 *
	 * A subdomain that stopped resolving under a domain that's still registered needs different follow-up
	 * than a lapsed registration, so the two must not be conflated.
	 */
	public function test_scan_network_separates_unresolved_from_dangling() {
		$this->create_published_post(
			'<a href="https://gone.example.net/a">Lapsed</a>' .
					'<a href="https://missing.example.org/b">Missing subdomain</a>'
		);

		$this->stubbed_hosts = array(
			'gone.example.net'    => 'dangling',
			'missing.example.org' => 'unresolved',
		);

		$statuses = array();

		$references = scan_network(
			array(
				'blog_ids' => array( get_current_blog_id() ),
				'verify'   => false,
			)
		);

		foreach ( $references as $reference ) {
			$statuses[ $reference['host'] ] = $reference['status'];
		}

		$this->assertSame( 'dangling', $statuses['gone.example.net'] );
		$this->assertSame( 'unresolved', $statuses['missing.example.org'] );
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\scan_network
	 *
	 * The riskiest findings have to be at the top, because that's the order somebody triages them in.
	 */
	public function test_scan_network_sorts_worst_findings_first() {
		$this->create_published_post(
			'<a href="https://gone.example.net/a">Link</a>' .
					'<script src="https://gone.example.net/a.js"></script>' .
					'<a href="https://missing.example.org/b">Missing</a>'
		);

		$this->stubbed_hosts = array(
			'gone.example.net'    => 'dangling',
			'missing.example.org' => 'unresolved',
		);

		$references = scan_network(
			array(
				'blog_ids' => array( get_current_blog_id() ),
				'verify'   => false,
			)
		);

		$this->assertSame( 'script', $references[0]['kind'] );
		$this->assertSame( 'dangling', $references[0]['status'] );
		$this->assertSame( 'unresolved', end( $references )['status'] );
	}

	/**
	 * @covers WordCamp\Dangling_Hosts\scan_network
	 *
	 * `--kinds` narrows the report to the reference types worth acting on.
	 */
	public function test_scan_network_filters_by_kind() {
		$this->create_published_post(
			'<a href="https://gone.example.net/a">Link</a>' .
					'<script src="https://gone.example.net/a.js"></script>'
		);

		$this->stubbed_hosts = array( 'gone.example.net' => 'dangling' );

		$kinds = wp_list_pluck(
			scan_network(
				array(
					'blog_ids' => array( get_current_blog_id() ),
					'kinds'    => array( 'script' ),
					'verify'   => false,
				)
			),
			'kind'
		);

		$this->assertSame( array( 'script' ), $kinds );
	}
}

// phpcs:enable
