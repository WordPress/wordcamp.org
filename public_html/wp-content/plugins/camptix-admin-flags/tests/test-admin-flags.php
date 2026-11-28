<?php
/**
 * Tests for the Admin Flags add-on.
 *
 * @package CampTix_Admin_Flags
 */

defined( 'WPINC' ) || die();

/**
 * @covers CampTix_Admin_Flags_Addon
 */
class Test_CampTix_Admin_Flags extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * The flags the add-on had before a test changed them.
	 *
	 * @var array
	 */
	protected $original_flags;

	/**
	 * Saving an attendee calls get_wordcamp_post(), which switches to the root blog.
	 *
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
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_flags = $this->get_flags();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->set_flags( $this->original_flags );
		unset( $GLOBALS['typenow'] );

		parent::tear_down();
	}

	/**
	 * Locate the running add-on instance from $camptix->addons_loaded.
	 *
	 * @return CampTix_Admin_Flags_Addon
	 */
	protected function get_addon() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		foreach ( $camptix->addons_loaded as $addon ) {
			if ( $addon instanceof CampTix_Admin_Flags_Addon ) {
				return $addon;
			}
		}

		$this->fail( 'CampTix_Admin_Flags_Addon was not loaded.' );
	}

	/**
	 * Read the add-on's configured flags.
	 *
	 * @return array
	 */
	protected function get_flags() {
		return ( function () {
			return $this->flags;
		} )->call( $this->get_addon() );
	}

	/**
	 * Replace the add-on's configured flags.
	 *
	 * @param array $flags Flag labels, keyed by slug.
	 */
	protected function set_flags( $flags ) {
		( function () use ( $flags ) {
			$this->flags = $flags;
		} )->call( $this->get_addon() );
	}

	/**
	 * Saved labels keep their text but not markup.
	 */
	public function test_saved_labels_are_plain_text() {
		$output = $this->get_addon()->validate_options(
			array(),
			array( 'camptix-admin-flags-data' => "vip: <b>VIP</b> guests\nspeaker:  Speaker  " )
		);

		$this->assertSame(
			array(
				'vip'     => 'VIP guests',
				'speaker' => 'Speaker',
			),
			$output['camptix-admin-flags-data-parsed']
		);
		$this->assertSame( "vip: VIP guests\nspeaker: Speaker", $output['camptix-admin-flags-data'] );
	}

	/**
	 * The filter links above the Attendees list print each label as text.
	 */
	public function test_filter_links_print_labels_as_text() {
		$attendee_id = self::factory()->post->create( array( 'post_type' => 'tix_attendee' ) );
		add_post_meta( $attendee_id, 'camptix-admin-flag', 'vip' );

		$this->set_flags( array( 'vip' => 'Fish & <b>Chips</b>' ) );
		$GLOBALS['typenow'] = 'tix_attendee';

		$views = $this->get_addon()->add_custom_filters( array() );

		$this->assertArrayHasKey( 'vip', $views );
		$this->assertStringContainsString( 'Fish &amp; &lt;b&gt;Chips&lt;/b&gt; <span class="count">(1)</span>', $views['vip'] );
		$this->assertStringNotContainsString( '<b>', $views['vip'] );
	}

	/**
	 * The Notify screen offers each configured flag as a segment value (#1511).
	 */
	public function test_notify_segment_fields_list_flags() {
		$this->set_flags( array(
			'sponsor' => 'Sponsor',
			'speaker' => 'Speaker',
		) );

		$fields = $this->get_addon()->notify_segment_fields( array() );

		$this->assertSame( array( 'admin_flag' ), wp_list_pluck( $fields, 'option_value' ) );
		$this->assertSame( array( 'sponsor', 'speaker' ), wp_list_pluck( $fields[0]['values'], 'value' ) );
		$this->assertSame( array( 'Sponsor', 'Speaker' ), wp_list_pluck( $fields[0]['values'], 'caption' ) );
	}

	/**
	 * Without configured flags, there's nothing to segment by.
	 */
	public function test_notify_segment_fields_skip_without_flags() {
		$this->set_flags( array() );

		$this->assertSame( array(), $this->get_addon()->notify_segment_fields( array() ) );
	}

	/**
	 * A Notify segment by admin flag matches attendees with that flag, including those with several flags.
	 */
	public function test_notify_segment_matches_flagged_attendees() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		add_filter( 'camptix_notify_segment_query', array( $this->get_addon(), 'notify_segment_query' ), 10, 2 );

		$flags_by_attendee = array(
			'sponsor' => array( 'sponsor' ),
			'both'    => array( 'sponsor', 'speaker' ),
			'speaker' => array( 'speaker' ),
			'none'    => array(),
		);

		$ids = array();
		foreach ( $flags_by_attendee as $name => $flags ) {
			$ids[ $name ] = self::factory()->post->create( array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'publish',
			) );
			foreach ( $flags as $flag ) {
				add_post_meta( $ids[ $name ], 'camptix-admin-flag', $flag );
			}
		}

		$condition = function ( $flag ) {
			return array(
				'field' => 'admin_flag',
				'op'    => 'is',
				'value' => $flag,
			);
		};

		$this->assertSame(
			array( $ids['sponsor'], $ids['both'] ),
			$camptix->get_segment( 'and', array( $condition( 'sponsor' ) ) )
		);
		$this->assertSame(
			array( $ids['both'] ),
			$camptix->get_segment( 'and', array( $condition( 'sponsor' ), $condition( 'speaker' ) ) )
		);
		$this->assertSame(
			array( $ids['sponsor'], $ids['both'], $ids['speaker'] ),
			$camptix->get_segment( 'or', array( $condition( 'sponsor' ), $condition( 'speaker' ) ) )
		);
	}
}
