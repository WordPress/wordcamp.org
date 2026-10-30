<?php

use function CampTix\Badge_Generator\{ get_gravatar_hash, normalize_website, get_website_label, parse_gravatar_profile_response };
use const CampTix\Badge_Generator\GRAVATAR_PROFILE_CACHE_KEY;

defined( 'WPINC' ) || die();

require_once WP_PLUGIN_DIR . '/camptix/tests/trait-wordcamp-root-blog.php';

/**
 * Tests for the hometown, website, and company fields on badges.
 *
 * @group camptix-badge-generator
 */
class Test_Badge_Fields extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * Provision central when the harness points at a blog the test install lacks.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		if ( ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
			self::create_wordcamp_root_blog( $factory );
		}
	}

	/**
	 * Remove the root blog this class provisioned.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$wordcamp_root_blog_id ) {
			self::delete_wordcamp_root_blog();

			self::$wordcamp_root_blog_id = null;
		}
	}

	/**
	 * @var array Question IDs keyed by field.
	 */
	protected $questions = array();

	/**
	 * Map a question to each field.
	 */
	public function set_up() {
		parent::set_up();

		foreach ( array( 'hometown', 'website', 'company' ) as $field ) {
			$this->questions[ $field ] = self::factory()->post->create( array(
				'post_type'  => 'tix_question',
				'post_title' => ucfirst( $field ),
			) );
		}

		update_option( 'cbg_field_questions', $this->questions );
	}

	/**
	 * Create an attendee.
	 *
	 * @param array $answers Answers keyed by field.
	 * @param array $profile Their cached Gravatar profile.
	 *
	 * @return WP_Post
	 */
	protected function make_attendee( $answers = array(), $profile = array() ) {
		$email       = wp_generate_password( 8, false ) . '@example.org';
		$attendee_id = self::factory()->post->create( array(
			'post_type'   => 'tix_attendee',
			'post_status' => 'publish',
		) );

		$tix_questions = array();

		foreach ( $answers as $field => $answer ) {
			$tix_questions[ $this->questions[ $field ] ] = $answer;
		}

		update_post_meta( $attendee_id, 'tix_email', $email );
		update_post_meta( $attendee_id, 'tix_questions', $tix_questions );

		// Seeding the cache keeps the tests from calling the Gravatar API.
		set_site_transient( GRAVATAR_PROFILE_CACHE_KEY . get_gravatar_hash( $email ), $profile, HOUR_IN_SECONDS );

		return get_post( $attendee_id );
	}

	/**
	 * @covers \CampTix\Badge_Generator\get_badge_field
	 */
	public function test_gravatar_profile_wins_over_question() {
		$attendee = $this->make_attendee(
			array( 'hometown' => 'Answered City' ),
			array( 'location' => 'Gravatar City' )
		);

		$this->assertSame( 'Gravatar City', $attendee->hometown );
	}

	/**
	 * An employer is on the badge only when the attendee gave it for this event.
	 *
	 * @covers \CampTix\Badge_Generator\get_badge_field
	 */
	public function test_company_never_comes_from_gravatar() {
		$profile = array(
			'location' => 'Gravatar City', 'company' => 'Gravatar Co',
		);

		$this->assertSame( '', $this->make_attendee( array(), $profile )->company, 'Skipped the question.' );
		$this->assertSame( 'Answered Co', $this->make_attendee( array( 'company' => 'Answered Co' ), $profile )->company );
	}

	/**
	 * @covers \CampTix\Badge_Generator\get_badge_field
	 */
	public function test_question_fills_gaps_in_gravatar_profile() {
		$attendee = $this->make_attendee(
			array( 'hometown' => ' Answered City ' ),
			array( 'location' => '' )
		);

		$this->assertSame( 'Answered City', $attendee->hometown );
	}

	/**
	 * @covers \CampTix\Badge_Generator\get_badge_field
	 */
	public function test_gravatar_profiles_can_be_turned_off() {
		update_option( 'cbg_use_gravatar_profiles', \CampTix\Badge_Generator\HTML\sanitize_checkbox( false ) );

		$attendee = $this->make_attendee( array(), array( 'location' => 'Gravatar City' ) );

		$this->assertSame( '', $attendee->hometown );
	}

	/**
	 * @covers \CampTix\Badge_Generator\get_badge_field
	 */
	public function test_unmapped_fields_are_empty() {
		update_option( 'cbg_field_questions', array() );

		$attendee = $this->make_attendee( array(
			'hometown' => 'Answered City', 'website' => 'example.org',
		) );

		$this->assertSame( '', $attendee->hometown );
		$this->assertSame( '', $attendee->website );
	}

	/**
	 * @covers \CampTix\Badge_Generator\add_dynamic_post_meta
	 */
	public function test_website_comes_from_question() {
		$attendee = $this->make_attendee( array( 'website' => 'www.Example.org/blog/' ) );

		$this->assertSame( 'https://www.Example.org/blog/', $attendee->website );
		$this->assertSame( 'Example.org/blog', $attendee->website_label );
	}

	/**
	 * Data provider for test_normalize_website().
	 *
	 * @return array
	 */
	public function data_normalize_website() {
		return array(
			'empty'            => array( '', '' ),
			'bare domain'      => array( 'example.org', 'https://example.org' ),
			'http kept'        => array( 'http://example.org/', 'http://example.org/' ),
			'not a domain'     => array( 'n/a', '' ),
			'javascript'       => array( 'javascript:alert(1)', '' ),
			'other scheme'     => array( 'ftp://example.org', '' ),
			'just a word'      => array( 'Nope', '' ),
		);
	}

	/**
	 * @covers \CampTix\Badge_Generator\normalize_website
	 * @dataProvider data_normalize_website
	 *
	 * @param string $website
	 * @param string $expected
	 */
	public function test_normalize_website( $website, $expected ) {
		$this->assertSame( $expected, normalize_website( $website ) );
	}

	/**
	 * @covers \CampTix\Badge_Generator\get_website_label
	 */
	public function test_website_label() {
		$this->assertSame( 'example.org', get_website_label( 'https://www.example.org/' ) );
		$this->assertSame( 'example.org/a/b', get_website_label( 'http://example.org/a/b' ) );
	}

	/**
	 * @covers \CampTix\Badge_Generator\parse_gravatar_profile_response
	 */
	public function test_parse_gravatar_profile_response() {
		$body = wp_json_encode( array(
			'location'     => ' Skopje ',
			'company'      => 'Not used on badges',
			'display_name' => 'Not needed',
		) );

		$this->assertSame( array( 'location' => 'Skopje' ), parse_gravatar_profile_response( 200, $body ) );
		$this->assertSame(
			array( 'location' => '' ),
			parse_gravatar_profile_response( 200, wp_json_encode( array( 'location' => array( 'not a string' ) ) ) )
		);

		$this->assertSame( array(), parse_gravatar_profile_response( 404, '' ), 'No profile.' );
		$this->assertNull( parse_gravatar_profile_response( 429, '' ), 'Rate limited.' );
		$this->assertNull( parse_gravatar_profile_response( 0, '' ), 'Request failed.' );
		$this->assertNull( parse_gravatar_profile_response( 200, 'not json' ), 'Bad body.' );
	}

	/**
	 * Company goes last, and attendee-provided values are escaped.
	 *
	 * @covers \CampTix\Badge_Generator\add_dynamic_post_meta
	 */
	public function test_badge_shows_fields_in_order() {
		$attendee = $this->make_attendee(
			array(
				'hometown' => 'Skopje',
				'website'  => 'example.org',
				'company'  => '<script>Acme</script>',
			)
		);

		update_post_meta( $attendee->ID, 'tix_username', 'janedoe' );

		$allowed_html = array();

		ob_start();
		require dirname( __DIR__ ) . '/views/html-badges/template-part-badge-contents.php';
		$html = ob_get_clean();

		$username = strpos( $html, '@janedoe' );
		$hometown = strpos( $html, 'Skopje' );
		$website  = strpos( $html, 'example.org' );
		$company  = strpos( $html, '&lt;script&gt;Acme' );

		$this->assertNotFalse( $username );
		$this->assertGreaterThan( $username, $hometown );
		$this->assertGreaterThan( $hometown, $website );
		$this->assertGreaterThan( $website, $company );
		$this->assertStringNotContainsString( '<script>', $html );
	}
}
