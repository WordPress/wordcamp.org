<?php
defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/trait-wordcamp-root-blog.php';

/**
 * @covers CampTix_Require_Login
 */
class Test_Camptix_Require_Login_Addon extends \WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::create_wordcamp_root_blog( $factory );
	}

	/**
	 * Tears down the shared fixtures created in wpSetUpBeforeClass().
	 */
	public static function wpTearDownAfterClass() {
		self::delete_wordcamp_root_blog();
	}

	/**
	 * Locate a running addon instance from $camptix->addons_loaded.
	 *
	 * @param string $class_name
	 *
	 * @return CampTix_Addon
	 */
	protected function get_addon( $class_name ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		foreach ( $camptix->addons_loaded as $addon ) {
			if ( $addon instanceof $class_name ) {
				return $addon;
			}
		}

		$this->fail( "$class_name was not loaded." );
	}

	/**
	 * Create a published attendee with the given meta.
	 *
	 * @param string $first_name
	 * @param string $last_name
	 * @param string $email
	 * @param string $username
	 *
	 * @return int
	 */
	protected function create_attendee( $first_name, $last_name, $email, $username ) {
		$attendee_id = self::factory()->post->create( array(
			'post_type'   => 'tix_attendee',
			'post_status' => 'publish',
			'post_title'  => "$first_name $last_name",
		) );

		update_post_meta( $attendee_id, 'tix_first_name', $first_name );
		update_post_meta( $attendee_id, 'tix_last_name', $last_name );
		update_post_meta( $attendee_id, 'tix_email', $email );
		update_post_meta( $attendee_id, 'tix_username', $username );

		return $attendee_id;
	}

	/**
	 * Render [camptix_attendees], bypassing the cache.
	 *
	 * @return string
	 */
	protected function render_attendees_shortcode() {
		/** @var CampTix_Addon_Shortcodes $shortcodes */
		$shortcodes = $this->get_addon( 'CampTix_Addon_Shortcodes' );

		return $shortcodes->get_attendees_shortcode_content( $shortcodes->sanitize_attendees_atts( array() ), true );
	}

	/**
	 * A confirmed attendee is listed. This keeps the "hidden" tests below from passing vacuously.
	 *
	 * @covers CampTix_Require_Login::hide_unconfirmed_attendees
	 */
	public function test_attendees_shortcode_shows_confirmed_attendee() {
		$this->create_attendee( 'Confirmed', 'Person', 'confirmed@example.org', 'confirmed-person' );

		$this->assertStringContainsString( '<span class="tix-first">Confirmed</span>', $this->render_attendees_shortcode() );
	}

	/**
	 * An additional attendee who hasn't confirmed yet is not listed.
	 *
	 * @covers CampTix_Require_Login::hide_unconfirmed_attendees
	 */
	public function test_attendees_shortcode_hides_unconfirmed_attendee() {
		$this->create_attendee( 'Confirmed', 'Person', 'confirmed@example.org', 'confirmed-person' );
		$this->create_attendee( 'Unconfirmed', 'Person', 'unconfirmed@example.org', CampTix_Require_Login::UNCONFIRMED_USERNAME );

		$content = $this->render_attendees_shortcode();

		$this->assertStringContainsString( '<span class="tix-first">Confirmed</span>', $content );
		$this->assertStringNotContainsString( '<span class="tix-first">Unconfirmed</span>', $content );
	}

	/**
	 * An unknown attendee is not listed, even when their row was stored with a real username.
	 *
	 * That's the case for the buyer's own row, which gets the buyer's username at checkout.
	 *
	 * @see https://github.com/WordPress/wordcamp.org/issues/2114
	 *
	 * @covers CampTix_Require_Login::hide_unconfirmed_attendees
	 */
	public function test_attendees_shortcode_hides_unknown_attendee_with_real_username() {
		$this->create_attendee( 'Confirmed', 'Person', 'confirmed@example.org', 'confirmed-person' );
		$this->create_attendee( 'Unknown', 'Attendee', CampTix_Require_Login::UNKNOWN_ATTENDEE_EMAIL, 'buyer-username' );

		$content = $this->render_attendees_shortcode();

		$this->assertStringContainsString( '<span class="tix-first">Confirmed</span>', $content );
		$this->assertStringNotContainsString( '<span class="tix-first">Unknown</span>', $content );
	}

	/**
	 * Every attendee on an order is returned, not only the first batch of 200.
	 *
	 * @covers CampTix_Require_Login::get_attendees_by_access_token
	 */
	public function test_get_attendees_by_access_token_pages_past_the_first_batch() {
		$attendee_args = array(
			'post_type'   => 'tix_attendee',
			'post_status' => 'publish',
		);

		foreach ( self::factory()->post->create_many( 201, $attendee_args ) as $attendee_id ) {
			update_post_meta( $attendee_id, 'tix_access_token', 'bigorder' );
		}

		$other_order = self::factory()->post->create( $attendee_args );
		update_post_meta( $other_order, 'tix_access_token', 'otherorder' );

		/** @var CampTix_Require_Login $addon */
		$addon     = $this->get_addon( 'CampTix_Require_Login' );
		$attendees = $addon->get_attendees_by_access_token( 'bigorder' );

		$this->assertCount( 201, $attendees );
		$this->assertCount( 201, array_unique( wp_list_pluck( $attendees, 'ID' ) ) );
		$this->assertNotContains( $other_order, wp_list_pluck( $attendees, 'ID' ) );
	}

	/**
	 * The buyer-facing status label is escaped, even when a translation contains markup.
	 *
	 * @covers CampTix_Require_Login::show_buyer_attendee_status_instead_of_edit_link
	 */
	public function test_buyer_status_label_is_escaped() {
		$attendee_id = $this->create_attendee( 'Confirmed', 'Person', 'confirmed@example.org', 'confirmed-person' );

		$inject_markup = function ( $translation, $text, $context ) {
			return ( 'Status: Confirmed' === $text && 'WordCamp ticket status.' === $context ) ? '<b>Status: Confirmed</b>' : $translation;
		};
		add_filter( 'gettext_with_context', $inject_markup, 10, 3 );

		/** @var CampTix_Require_Login $addon */
		$addon   = $this->get_addon( 'CampTix_Require_Login' );
		$content = $addon->show_buyer_attendee_status_instead_of_edit_link( '', get_post( $attendee_id ) );

		remove_filter( 'gettext_with_context', $inject_markup, 10 );

		$this->assertStringNotContainsString( '<b>', $content );
		$this->assertStringContainsString( '&lt;b&gt;Status:&nbsp;Confirmed&lt;/b&gt;', $content );
	}

	/**
	 * The status help is a native disclosure, so it works with a keyboard, a screen reader and touch.
	 *
	 * @covers CampTix_Require_Login::show_buyer_attendee_status_instead_of_edit_link
	 */
	public function test_buyer_status_help_is_a_disclosure() {
		$attendee_id = $this->create_attendee( 'Unconfirmed', 'Person', 'unconfirmed@example.org', CampTix_Require_Login::UNCONFIRMED_USERNAME );

		/** @var CampTix_Require_Login $addon */
		$addon   = $this->get_addon( 'CampTix_Require_Login' );
		$content = $addon->show_buyer_attendee_status_instead_of_edit_link( '', get_post( $attendee_id ) );

		$this->assertMatchesRegularExpression( '#<details class="tix-status-help"><summary[^>]*>.+</summary><span class="tix-status-help__text">This ticket is fully paid for\.[^<]+</span></details>#', $content );
		$this->assertStringNotContainsString( 'title=', $content );
		$this->assertStringNotContainsString( 'tabindex=', $content );
	}
}
