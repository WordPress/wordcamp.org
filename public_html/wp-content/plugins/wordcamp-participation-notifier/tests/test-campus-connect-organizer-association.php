<?php

namespace WordCamp\Participation_Notifier\Tests;

use ReflectionClass;
use ReflectionMethod;
use WordCamp_Participation_Notifier;
use WP_Post;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Ongoing Campus Connect organizer association / usermeta prefix behaviour.
 *
 * @group wordcamp-participation-notifier
 */
class Test_Campus_Connect_Organizer_Association extends WP_UnitTestCase {

	/**
	 * @var WordCamp_Participation_Notifier
	 */
	protected $notifier;

	/**
	 * Create a notifier without running the constructor (avoids Profiles require).
	 */
	public function set_up() {
		parent::set_up();

		$reflection     = new ReflectionClass( WordCamp_Participation_Notifier::class );
		$this->notifier = $reflection->newInstanceWithoutConstructor();

		register_post_type( 'wcb_organizer', array( 'public' => true ) );
		register_post_type( 'wcb_speaker', array( 'public' => true ) );
		register_post_type( 'wordcamp', array( 'public' => true ) );
	}

	/**
	 * @covers WordCamp_Participation_Notifier::get_organizer_association
	 * @covers WordCamp_Participation_Notifier::is_campus_connect_event
	 */
	public function test_organizer_association_is_campus_connect_when_subtype_matches() {
		$wordcamp       = new WP_Post( (object) array( 'ID' => 1 ) );
		$wordcamp->meta = array( 'event_subtype' => array( 'campusconnect' ) );

		$this->assertSame(
			'campus-connect-organizer',
			$this->invoke( 'get_organizer_association', array( $wordcamp ) )
		);
		$this->assertTrue( $this->invoke( 'is_campus_connect_event', array( $wordcamp ) ) );
	}

	/**
	 * @covers WordCamp_Participation_Notifier::get_organizer_association
	 * @covers WordCamp_Participation_Notifier::is_campus_connect_event
	 */
	public function test_organizer_association_defaults_to_wordcamp() {
		$wordcamp       = new WP_Post( (object) array( 'ID' => 1 ) );
		$wordcamp->meta = array( 'event_subtype' => array( 'wordcamp' ) );

		$this->assertSame(
			'wordcamp-organizer',
			$this->invoke( 'get_organizer_association', array( $wordcamp ) )
		);
		$this->assertFalse( $this->invoke( 'is_campus_connect_event', array( $wordcamp ) ) );

		$this->assertSame(
			'wordcamp-organizer',
			$this->invoke( 'get_organizer_association', array( false ) )
		);
		$this->assertFalse( $this->invoke( 'is_campus_connect_event', array( false ) ) );
	}

	/**
	 * @covers WordCamp_Participation_Notifier::get_organizer_meta_key_prefix
	 */
	public function test_organizer_meta_key_prefixes_are_association_specific() {
		$this->assertSame(
			WordCamp_Participation_Notifier::CAMPUS_CONNECT_ORGANIZER_META_PREFIX,
			$this->invoke( 'get_organizer_meta_key_prefix', array( 'campus-connect-organizer' ) )
		);

		$this->assertSame(
			WordCamp_Participation_Notifier::WORDCAMP_ORGANIZER_META_PREFIX,
			$this->invoke( 'get_organizer_meta_key_prefix', array( 'wordcamp-organizer' ) )
		);
	}

	/**
	 * @covers WordCamp_Participation_Notifier::get_user_meta_key_prefix
	 */
	public function test_speaker_meta_key_prefix_unchanged() {
		$speaker = self::factory()->post->create_and_get(
			array(
				'post_type' => 'wcb_speaker',
			)
		);

		$this->assertSame(
			'wc_wcb_speaker_',
			$this->invoke( 'get_user_meta_key_prefix', array( $speaker ) )
		);
	}

	/**
	 * @covers WordCamp_Participation_Notifier::get_organizer_association
	 */
	public function test_campus_connect_association_slug_is_used_for_organizer_payloads() {
		$wordcamp       = new WP_Post( (object) array(
			'ID' => 10,
			'post_type' => 'wordcamp',
		) );
		$wordcamp->meta = array( 'event_subtype' => array( 'campusconnect' ) );

		// get_post_association_payload() assigns this slug for wcb_organizer posts.
		$this->assertSame(
			'campus-connect-organizer',
			$this->invoke( 'get_organizer_association', array( $wordcamp ) )
		);
	}

	/**
	 * Call a protected/private method on the notifier.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 *
	 * @return mixed
	 */
	protected function invoke( $method, array $args = array() ) {
		$reflection = new ReflectionMethod( WordCamp_Participation_Notifier::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $this->notifier, $args );
	}
}
