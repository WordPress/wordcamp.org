<?php
/**
 * Tests for submitting an application.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

namespace WordCamp\Open_Horizons\Tests;

use WP_UnitTestCase;
use function WordCamp\Open_Horizons\get_application_window;
use function WordCamp\Open_Horizons\submit_application;
use const WordCamp\Open_Horizons\EVENTS_OPTION;

defined( 'WPINC' ) || die();

/**
 * @group open-horizons
 */
class Test_Application extends WP_UnitTestCase {
	/**
	 * Conversations FreeScout was asked to start.
	 *
	 * @var array[]
	 */
	protected array $mail = array();

	/**
	 * Captures FreeScout requests instead of sending them.
	 *
	 * @var \Closure
	 */
	protected \Closure $capture_mail;

	/**
	 * Configures FreeScout.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		defined( 'FREESCOUT_API_KEY' ) || define( 'FREESCOUT_API_KEY', 'test' );
		defined( 'FREESCOUT_SCHOLARSHIPS_MAILBOX_ID' ) || define( 'FREESCOUT_SCHOLARSHIPS_MAILBOX_ID', 7 );
	}

	/**
	 * A flagship WordCamp taking applications.
	 *
	 * @var int
	 */
	protected int $event_id;

	/**
	 * The applicant.
	 *
	 * @var \WP_User
	 */
	protected \WP_User $applicant;

	/**
	 * Sets up an open flagship event and an applicant, and captures mail.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->event_id  = $this->create_flagship( '+3 months' );
		$this->applicant = self::factory()->user->create_and_get( array( 'user_email' => 'applicant@example.org' ) );

		update_option( EVENTS_OPTION, array( $this->event_id ) );

		$this->mail         = array();
		$this->capture_mail = function ( mixed $response, array $args ): array {
			$this->mail[] = json_decode( $args['body'], true );

			return array(
				'response' => array( 'code' => 201 ),
				'body'     => wp_json_encode( array( 'id' => 123 ) ),
			);
		};

		add_filter( 'pre_http_request', $this->capture_mail, 10, 2 );
	}

	/**
	 * Stops capturing mail.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->capture_mail, 10 );

		parent::tear_down();
	}

	/**
	 * Creates a flagship WordCamp.
	 *
	 * @param string $starts When it starts, relative to now.
	 * @return int The `wordcamp` post ID.
	 */
	protected function create_flagship( string $starts ): int {
		$wordcamp_id = self::factory()->post->create(
			array(
				'post_type'  => 'wordcamp',
				'post_title' => 'WordCamp Asia 2027',
			)
		);

		update_post_meta( $wordcamp_id, 'Start Date (YYYY-mm-dd)', strtotime( $starts ) );

		return $wordcamp_id;
	}

	/**
	 * A complete application.
	 *
	 * @param array $overrides Answers to change.
	 * @return array
	 */
	protected function get_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'event'              => (string) $this->event_id,
				'name'               => 'Ana Example',
				'email'              => 'ana@example.org',
				'city'               => 'Lagos',
				'country'            => 'NG',
				'role'               => 'speaker',
				'role_confirmed'     => '1',
				'contributions'      => 'Polyglots and Training.',
				'contributing_since' => '1-5',
				'current_work'       => 'Translating Learn courses.',
				'goals'              => 'Mentor new translators.',
				'impact'             => 'It started my career.',
				'benefit'            => 'Meeting the Polyglots team in person.',
				'sharing'            => 'A talk at my local meetup.',
				'support'            => array( 'travel', 'visa' ),
				'travel_documents'   => 'visa',
			),
			$overrides
		);
	}

	/**
	 * A complete application is stored as received, and the applicant is told it arrived: at their account's
	 * address, not the one typed into the form, so the form can't email strangers.
	 */
	public function test_submit_stores_the_application_and_confirms_it(): void {
		wp_update_post(
			array(
				'ID'         => $this->event_id,
				'post_title' => "WordCamp Asia's 2027",
			)
		);

		$post_id = submit_application( $this->applicant, $this->get_input() );

		$this->assertIsInt( $post_id );
		$this->assertSame( 'oh-received', get_post_status( $post_id ) );
		$this->assertSame( $this->event_id, (int) get_post_meta( $post_id, '_oh_event_id', true ) );
		$this->assertSame( $this->applicant->ID, (int) get_post_meta( $post_id, '_oh_applicant_user_id', true ) );
		$this->assertSame( array( 'travel', 'visa' ), get_post_meta( $post_id, '_oh_application', true )['support'] );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 7, $this->mail[0]['mailboxId'] );
		$this->assertSame( 'applicant@example.org', $this->mail[0]['customer']['email'] );
		$this->assertSame( 'We received your Open Horizons Scholarship application for WordCamp Asia’s 2027', $this->mail[0]['subject'] );
		$this->assertSame( 'closed', $this->mail[0]['status'] );
		$this->assertSame( array( 'https://freescout.wordpress.net/conversation/123' ), get_post_meta( $post_id, '_oh_freescout_links', true ) );
	}

	/**
	 * The closing date applicants are told is the last day they can still apply.
	 */
	public function test_window_closes_at_the_end_of_the_date_shown(): void {
		update_post_meta( $this->event_id, 'Start Date (YYYY-mm-dd)', strtotime( '2027-03-30' ) );

		$closes = get_application_window( $this->event_id )['closes'];

		$this->assertSame( '2027-01-29', wp_date( 'Y-m-d', $closes ) );
		$this->assertSame( '2027-01-30', wp_date( 'Y-m-d', $closes + 1 ) );
	}

	/**
	 * Only picked events, inside their window, take applications.
	 *
	 * @dataProvider data_events_not_taking_applications
	 *
	 * @param string $starts  When the event starts, relative to now.
	 * @param bool   $is_picked Whether reviewers picked it.
	 */
	public function test_submit_refuses_events_not_taking_applications( string $starts, bool $is_picked ): void {
		$event_id = $this->create_flagship( $starts );

		if ( $is_picked ) {
			update_option( EVENTS_OPTION, array( $this->event_id, $event_id ) );
		}

		$result = submit_application( $this->applicant, $this->get_input( array( 'event' => (string) $event_id ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'oh_event_closed', $result->get_error_code() );
		$this->assertCount( 0, $this->mail );
	}

	/**
	 * Events that don't take applications.
	 *
	 * @return array[]
	 */
	public function data_events_not_taking_applications(): array {
		return array(
			'window open, not picked'   => array( '+3 months', false ),
			'picked, window not open'   => array( '+6 months', true ),
			'picked, window closed'     => array( '+1 month', true ),
		);
	}

	/**
	 * An applicant can apply for an event only once.
	 */
	public function test_submit_refuses_a_second_application_for_the_same_event(): void {
		submit_application( $this->applicant, $this->get_input() );

		$result = submit_application( $this->applicant, $this->get_input() );

		$this->assertWPError( $result );
		$this->assertSame( 'oh_duplicate', $result->get_error_code() );
	}

	/**
	 * Identity & Background answers are only kept with the applicant's explicit consent.
	 *
	 * @dataProvider data_identity_consent
	 *
	 * @param bool $consent Whether the applicant consented.
	 */
	public function test_identity_answers_are_only_kept_with_consent( bool $consent ): void {
		$input = $this->get_input(
			array(
				'identity'         => array( 'groups' => array( 'lgbtiq', 'limited-access' ) ),
				'identity_consent' => $consent ? '1' : '',
			)
		);

		$post_id = submit_application( $this->applicant, $input );

		$this->assertSame( $consent ? array( 'lgbtiq', 'limited-access' ) : null, get_post_meta( $post_id, '_oh_identity', true )['groups'] ?? null );
		$this->assertSame( $consent, (bool) get_post_meta( $post_id, '_oh_identity_consent', true ) );
	}

	/**
	 * With and without consent.
	 *
	 * @return array[]
	 */
	public function data_identity_consent(): array {
		return array(
			'consent'    => array( true ),
			'no consent' => array( false ),
		);
	}
}
