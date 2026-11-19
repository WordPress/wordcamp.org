<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;
use function WordCamp\Blocks\Speakers\get_speaker_sessions;
use function WordCamp\Blocks\Speakers\render;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/blocks/source/blocks/speakers/controller.php';

/**
 * Tests that the Speakers block lists a speaker's sessions before they have a time.
 *
 * @group blocks
 */
class Test_Speakers_Block_Session_Times extends WP_UnitTestCase {
	/**
	 * The speaker on every session.
	 *
	 * @var int
	 */
	protected $speaker_id;

	/**
	 * Create a speaker.
	 */
	public function set_up() {
		parent::set_up();

		$this->speaker_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'publish',
		) );
	}

	/**
	 * Create a published session with the test speaker on it.
	 *
	 * @param string $title The session title.
	 * @param int    $time  The session time, or 0 for none yet.
	 *
	 * @return int
	 */
	protected function add_session( string $title, int $time = 0 ): int {
		$session_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_session',
			'post_status' => 'publish',
			'post_title'  => $title,
		) );

		add_post_meta( $session_id, '_wcpt_speaker_id', $this->speaker_id );

		if ( $time ) {
			add_post_meta( $session_id, '_wcpt_session_time', $time );
		}

		return $session_id;
	}

	/**
	 * Sessions are listed in time order, and the ones without a time after them.
	 */
	public function test_sessions_without_a_time_are_listed_last() {
		$untimed = $this->add_session( 'Untimed' );
		$later   = $this->add_session( 'Later', strtotime( '2026-11-22 14:00:00' ) );
		$earlier = $this->add_session( 'Earlier', strtotime( '2026-11-22 10:00:00' ) );

		$sessions = get_speaker_sessions( array( $this->speaker_id ) );

		$this->assertSame(
			array( $earlier, $later, $untimed ),
			wp_list_pluck( $sessions[ $this->speaker_id ] ?? array(), 'ID' )
		);
	}

	/**
	 * A session without a time shows its title, without a date or time.
	 */
	public function test_session_without_a_time_shows_only_its_title() {
		$this->add_session( 'Untimed session' );

		$html = render( array(
			'mode'         => 'all',
			'show_session' => true,
		) );

		$this->assertStringContainsString( 'Untimed session', $html );
		$this->assertStringNotContainsString( 'wordcamp-speakers__session-info', $html );
	}
}
