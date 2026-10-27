<?php
/**
 * Tests for the vetting workflow: status changes, what's computed when read, and approval.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WP_UnitTestCase;
use function WordCamp\Sponsor_Vetting\approve_sponsorship;
use function WordCamp\Sponsor_Vetting\get_vetting_state;
use function WordCamp\Sponsor_Vetting\link_sponsorship;
use function WordCamp\Sponsor_Vetting\register_types;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;
use const WordCamp\Sponsor_Vetting\GOOD_FAITH_DAYS;
use const WordCamp\Sponsor_Vetting\SPONSORSHIP_POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * @group sponsor-vetting
 */
class Test_Workflow extends WP_UnitTestCase {
	/**
	 * Registers the post types, as `init` does on Central.
	 */
	public function set_up(): void {
		parent::set_up();

		register_types();
	}

	/**
	 * Creates a company.
	 *
	 * @param string   $status      Its status.
	 * @param int|null $last_vetted When it was last vetted, if not when it got its status.
	 * @param array    $meta        Other meta.
	 * @return int The company's ID.
	 */
	protected function create_company( string $status, ?int $last_vetted = null, array $meta = array() ): int {
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => $status,
				'post_title'  => 'Acme',
				'meta_input'  => $meta,
			)
		);

		// Set afterwards: entering Compliant or Not Eligible stamps the date.
		if ( null !== $last_vetted ) {
			update_post_meta( $company_id, '_sv_last_vetted', $last_vetted );
		}

		return $company_id;
	}

	/**
	 * Creates a pending sponsorship.
	 *
	 * @param int $company_id Its company, or 0.
	 * @return int The sponsorship's ID.
	 */
	protected function create_sponsorship( int $company_id ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => SPONSORSHIP_POST_TYPE,
				'post_status' => 'svs-pending',
				'post_parent' => $company_id,
			)
		);
	}

	/**
	 * A status change the workflow allows goes through and is logged; one it doesn't, or one from a screen loaded
	 * before someone else changed the status, is refused.
	 *
	 * @dataProvider data_status_changes
	 *
	 * @param string $from     The company's status.
	 * @param array  $save     What a save asks for.
	 * @param string $expected The status it ends up with.
	 */
	public function test_company_status_changes_follow_the_workflow( string $from, array $save, string $expected ): void {
		$company_id = $this->create_company( $from );

		wp_update_post( array( 'ID' => $company_id ) + $save );

		$this->assertSame( $expected, get_post_status( $company_id ) );
		$this->assertSame(
			$expected !== $from ? array( $expected ) : array(),
			array_column( wp_list_filter( get_post_meta( $company_id, '_sv_status_change', false ), array( 'from' => $from ) ), 'to' )
		);
	}

	/**
	 * Allowed and refused status changes.
	 *
	 * @return array[]
	 */
	public function data_status_changes(): array {
		return array(
			'allowed'               => array( 'sv-in-progress', array( 'post_status' => 'sv-compliance-req' ), 'sv-compliance-req' ),
			'not in the workflow'   => array( 'sv-compliance-req', array( 'post_status' => 'sv-needs-vetting' ), 'sv-compliance-req' ),
			'a core status'         => array( 'sv-compliant', array( 'post_status' => 'publish' ), 'sv-compliant' ),
			'from a stale screen'   => array(
				'sv-in-progress',
				array(
					'post_status'          => 'sv-compliant',
					'original_post_status' => 'sv-needs-vetting',
				),
				'sv-in-progress',
			),
		);
	}

	/**
	 * Re-vetting dates and Not Eligible periods are worked out when the company is read.
	 *
	 * @dataProvider data_vetting_states
	 *
	 * @param string $status   The company's status.
	 * @param string $vetted   When it was last vetted, relative to now.
	 * @param array  $meta     Its other meta.
	 * @param array  $expected The state's flags that matter here.
	 */
	public function test_vetting_state_is_computed_on_read( string $status, string $vetted, array $meta, array $expected ): void {
		$company_id = $this->create_company( $status, (int) strtotime( $vetted ), $meta );

		$this->assertSame( $expected, array_intersect_key( get_vetting_state( $company_id ), $expected ) );
	}

	/**
	 * Companies and where their vetting stands.
	 *
	 * @return array[]
	 */
	public function data_vetting_states(): array {
		return array(
			'compliant, vetted 11 months ago'    => array(
				'sv-compliant',
				'-11 months',
				array(),
				array(
					'approvable'    => true,
					'needs_requeue' => false,
				),
			),
			'compliant, vetted 13 months ago'    => array(
				'sv-compliant',
				'-13 months',
				array(),
				array(
					'approvable'    => false,
					'expired'       => true,
					'needs_requeue' => true,
				),
			),
			'not eligible until next month'      => array(
				'sv-not-eligible',
				'-1 month',
				array( '_sv_not_eligible_until' => gmdate( 'Y-m-d', strtotime( '+1 month' ) ) ),
				array(
					'not_eligible_active' => true,
					'needs_requeue'       => false,
				),
			),
			'not eligible until last month'      => array(
				'sv-not-eligible',
				'-3 months',
				array( '_sv_not_eligible_until' => gmdate( 'Y-m-d', strtotime( '-1 month' ) ) ),
				array(
					'not_eligible_active' => false,
					'needs_requeue'       => true,
				),
			),
			'good faith due yesterday, not done' => array(
				'sv-compliance-req',
				'-1 month',
				array( '_sv_good_faith_due' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ),
				array( 'good_faith_overdue' => true ),
			),
		);
	}

	/**
	 * A sponsorship is approved only when its company is Compliant within the re-vetting threshold, or through
	 * the Good Faith action while its company has been asked for changes; any other save leaves it pending.
	 *
	 * @dataProvider data_approvals
	 *
	 * @param string $company_status The company's status, or '' for a sponsorship without one.
	 * @param string $vetted         When the company was last vetted, relative to now.
	 * @param bool   $good_faith     Whether to approve it in good faith.
	 * @param bool   $approved       Whether it ends up approved.
	 */
	public function test_sponsorship_is_approved_only_for_vetted_companies( string $company_status, string $vetted, bool $good_faith, bool $approved ): void {
		$company_id     = '' === $company_status ? 0 : $this->create_company( $company_status, (int) strtotime( $vetted ) );
		$sponsorship_id = $this->create_sponsorship( $company_id );
		$approver_id    = self::factory()->user->create();

		wp_set_current_user( $approver_id );

		if ( $good_faith ) {
			approve_sponsorship( $sponsorship_id, true );
		} else {
			wp_update_post(
				array(
					'ID'          => $sponsorship_id,
					'post_status' => 'svs-approved',
				)
			);
		}

		$this->assertSame( $approved ? 'svs-approved' : 'svs-pending', get_post_status( $sponsorship_id ) );
		$this->assertSame( $approved ? (string) $approver_id : '', get_post_meta( $sponsorship_id, '_sv_approved_by', true ) );
		$this->assertSame(
			$approved && $good_faith ? gmdate( 'Y-m-d', time() + GOOD_FAITH_DAYS * DAY_IN_SECONDS ) : '',
			$company_id ? get_post_meta( $company_id, '_sv_good_faith_due', true ) : ''
		);
	}

	/**
	 * Companies, and whether their sponsorship gets approved.
	 *
	 * @return array[]
	 */
	public function data_approvals(): array {
		return array(
			'compliant, vetted this year'      => array( 'sv-compliant', '-1 month', false, true ),
			'compliant, vetted 13 months ago'  => array( 'sv-compliant', '-13 months', false, false ),
			'asked for changes'                => array( 'sv-compliance-req', '-1 month', false, false ),
			'asked for changes, in good faith' => array( 'sv-compliance-req', '-1 month', true, true ),
			'in progress, in good faith'       => array( 'sv-in-progress', '-1 month', true, false ),
			'no company'                       => array( '', '-1 month', false, false ),
		);
	}

	/**
	 * An approved sponsorship can't move to another company: its approval was for the first company's vetting.
	 */
	public function test_an_approved_sponsorship_keeps_its_company(): void {
		$company_id     = $this->create_company( 'sv-compliant', (int) strtotime( '-1 month' ) );
		$other_id       = $this->create_company( 'sv-needs-vetting' );
		$sponsorship_id = $this->create_sponsorship( $company_id );

		$this->assertTrue( approve_sponsorship( $sponsorship_id ) );
		$this->assertWPError( link_sponsorship( $sponsorship_id, $other_id ) );
		$this->assertSame( $company_id, (int) get_post_field( 'post_parent', $sponsorship_id ) );
	}

	/**
	 * A Good Faith approval covers its own sponsorship only: the company's next one still needs Compliant, or a
	 * Good Faith approval of its own, which keeps the deadline the first one set.
	 */
	public function test_good_faith_approval_never_carries_over(): void {
		$company_id = $this->create_company( 'sv-compliance-req', (int) strtotime( '-1 month' ) );
		$first_id   = $this->create_sponsorship( $company_id );
		$second_id  = $this->create_sponsorship( $company_id );

		$this->assertTrue( approve_sponsorship( $first_id, true ) );

		wp_update_post(
			array(
				'ID'          => $second_id,
				'post_status' => 'svs-approved',
			)
		);

		$this->assertSame( 'svs-pending', get_post_status( $second_id ) );

		update_post_meta( $company_id, '_sv_good_faith_due', '2026-01-15' );

		$this->assertTrue( approve_sponsorship( $second_id, true ) );
		$this->assertSame( '2026-01-15', get_post_meta( $company_id, '_sv_good_faith_due', true ) );
	}
}
