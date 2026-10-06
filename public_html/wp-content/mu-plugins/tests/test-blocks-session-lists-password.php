<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;
use function WordCamp\Blocks\Sessions\get_session_speakers;
use function WordCamp\Blocks\Speakers\get_speaker_sessions;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/blocks/source/blocks/sessions/controller.php';
require_once dirname( __DIR__ ) . '/blocks/source/blocks/speakers/controller.php';

/**
 * Tests that the Sessions and Speakers list blocks don't pair a speaker with a
 * password-protected session, which withholds its speakers.
 *
 * @group blocks
 */
class Test_Session_Lists_Password_Block extends WP_UnitTestCase {
	/**
	 * The speaker on both sessions.
	 *
	 * @var int
	 */
	protected $speaker_id;

	/**
	 * A public session.
	 *
	 * @var int
	 */
	protected $public_id;

	/**
	 * A password-protected session.
	 *
	 * @var int
	 */
	protected $protected_id;

	/**
	 * Create a speaker on one public and one password-protected session.
	 */
	public function set_up() {
		parent::set_up();

		$this->speaker_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'publish',
		) );

		$this->public_id    = $this->add_session( '' );
		$this->protected_id = $this->add_session( 'secret-pass' );

		wp_set_current_user( 0 );
	}

	/**
	 * Create a published session with the test speaker on it.
	 *
	 * @param string $password The session's password, if any.
	 *
	 * @return int
	 */
	protected function add_session( string $password ): int {
		$session_id = self::factory()->post->create( array(
			'post_type'     => 'wcb_session',
			'post_status'   => 'publish',
			'post_password' => $password,
		) );

		add_post_meta( $session_id, '_wcpt_speaker_id', $this->speaker_id );
		add_post_meta( $session_id, '_wcpt_session_time', time() );

		return $session_id;
	}

	/**
	 * The Sessions block lists no speakers for a protected session.
	 */
	public function test_sessions_block_lists_no_speakers_for_protected_session() {
		$speakers = get_session_speakers( array( $this->public_id, $this->protected_id ) );

		$this->assertNotEmpty( $speakers[ $this->public_id ] ?? array() );
		$this->assertArrayNotHasKey( $this->protected_id, $speakers );
	}

	/**
	 * The Speakers block doesn't list a protected session under its speaker.
	 */
	public function test_speakers_block_does_not_list_protected_session() {
		$sessions = get_speaker_sessions( array( $this->speaker_id ) );

		$this->assertSame(
			array( $this->public_id ),
			wp_list_pluck( $sessions[ $this->speaker_id ] ?? array(), 'ID' )
		);
	}
}
