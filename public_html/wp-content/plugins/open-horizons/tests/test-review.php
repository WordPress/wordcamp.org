<?php
/**
 * Tests for reviewing applications: status changes and erasure.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

namespace WordCamp\Open_Horizons\Tests;

use WP_UnitTestCase;
use function WordCamp\Open_Horizons\erase_personal_data;
use const WordCamp\Open_Horizons\POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * @group open-horizons
 */
class Test_Review extends WP_UnitTestCase {
	/**
	 * Creates an application.
	 *
	 * @param string $status Its status.
	 * @return int The application's ID.
	 */
	protected function create_application( string $status ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => POST_TYPE,
				'post_status' => $status,
				'post_title'  => 'Ana Example',
				'post_name'   => 'ana-example',
				'meta_input'  => array(
					'_oh_applicant_email'   => 'ana@example.org',
					'_oh_applicant_user_id' => 0,
					'_oh_event_id'          => 42,
					'_oh_application'       => array( 'name' => 'Ana Example' ),
					'_oh_identity'          => array( 'groups' => array( 'lgbtiq' ) ),
					'_oh_identity_consent'  => 1700000000,
					'_oh_awarded_amount'    => '2500',
				),
			)
		);
	}

	/**
	 * A status change the workflow allows goes through and is logged; one it doesn't is refused, by whoever
	 * attempts it.
	 *
	 * @dataProvider data_status_changes
	 *
	 * @param string $from     The application's status.
	 * @param string $to       The status a save asks for.
	 * @param string $expected The status it ends up with.
	 */
	public function test_status_changes_follow_the_workflow( string $from, string $to, string $expected ): void {
		$post_id = $this->create_application( $from );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $to,
			)
		);

		$this->assertSame( $expected, get_post_status( $post_id ) );
		$this->assertSame(
			$expected !== $from ? array( $to ) : array(),
			array_column( array_filter( get_post_meta( $post_id, '_oh_status_change', false ), static fn( array $entry ): bool => $entry['from'] === $from ), 'to' )
		);
	}

	/**
	 * Allowed and refused status changes.
	 *
	 * @return array[]
	 */
	public function data_status_changes(): array {
		return array(
			'start reviewing'              => array( 'oh-received', 'oh-reviewing', 'oh-reviewing' ),
			'accept without a review'      => array( 'oh-received', 'oh-accepted', 'oh-received' ),
			'fund a rejected application'  => array( 'oh-rejected', 'oh-funded', 'oh-rejected' ),
			'publish it'                   => array( 'oh-reviewing', 'publish', 'oh-reviewing' ),
		);
	}

	/**
	 * Erasing removes everything about the applicant, and keeps what the program's numbers need.
	 */
	public function test_eraser_anonymizes_and_keeps_the_event_status_and_amount(): void {
		$post_id   = $this->create_application( 'oh-funded' );
		$applicant = self::factory()->user->create( array( 'user_email' => 'ana@example.org' ) );
		$reviewer  = self::factory()->user->create();

		update_post_meta( $post_id, '_oh_applicant_user_id', $applicant );
		$log = array(
			$applicant => 'oh-received',
			$reviewer  => 'oh-reviewing',
		);

		foreach ( $log as $user_id => $to ) {
			add_post_meta(
				$post_id,
				'_oh_status_change',
				array(
					'user_id' => $user_id,
					'time'    => 1,
					'from'    => 'new',
					'to'      => $to,
				)
			);
		}

		$result = erase_personal_data( 'ana@example.org' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$log_authors = array_column( get_post_meta( $post_id, '_oh_status_change', false ), 'user_id' );
		$this->assertNotContains( $applicant, $log_authors );
		$this->assertContains( $reviewer, $log_authors );
		$this->assertSame( 'Anonymized application', get_the_title( $post_id ) );
		$this->assertStringNotContainsString( 'ana', get_post_field( 'post_name', $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, '_oh_application', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_oh_identity', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_oh_applicant_email', true ) );
		$this->assertSame( 'oh-funded', get_post_status( $post_id ) );
		$this->assertSame( '42', get_post_meta( $post_id, '_oh_event_id', true ) );
		$this->assertSame( '2500', get_post_meta( $post_id, '_oh_awarded_amount', true ) );
	}

	/**
	 * The address typed into the form is unverified, so its owner's privacy request doesn't reach the application.
	 */
	public function test_eraser_ignores_the_typed_address(): void {
		$post_id = $this->create_application( 'oh-received' );

		$result = erase_personal_data( 'ana@example.org' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertSame( 'Ana Example', get_the_title( $post_id ) );
	}

	/**
	 * Saving a review screen loaded before another reviewer's rejection doesn't undo it.
	 */
	public function test_stale_review_screen_keeps_the_status(): void {
		$post_id = $this->create_application( 'oh-rejected' );

		// What the edit screen sends when it was loaded while the application was still being reviewed.
		wp_update_post(
			array(
				'ID'                   => $post_id,
				'post_status'          => 'oh-reviewing',
				'original_post_status' => 'oh-reviewing',
			)
		);

		$this->assertSame( 'oh-rejected', get_post_status( $post_id ) );
	}

	/**
	 * A reviewer's save leaves the application unowned, so deleting their account doesn't delete it.
	 */
	public function test_saving_keeps_the_application_unowned(): void {
		$post_id  = $this->create_application( 'oh-received' );
		$reviewer = self::factory()->user->create();

		// What core's edit screen sends, after swapping the empty author for the current user.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_author' => $reviewer,
			)
		);

		$this->assertSame( '0', get_post_field( 'post_author', $post_id ) );
	}
}
