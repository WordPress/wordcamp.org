<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Regression coverage for the single-event details card's zone dividers.
 *
 * The card stacks up to four zones — the date, the RSVP control, the venue
 * (or the online-event line), and "Add to calendar". Everything below the
 * RSVP is optional: GatherPress renders `gatherpress/venue`,
 * `gatherpress/online-event` and `gatherpress/add-to-calendar` as an empty
 * string when the event has no venue, isn't online, or has no calendar links.
 *
 * The card used to separate those zones with standalone `core/separator`
 * blocks and hide the stragglers from CSS (`hr:last-child`, and an
 * `hr:has(+ *:empty:last-child)` companion). That could only ever hide the
 * *last* divider: with all three optional zones empty, the first `<hr>` was
 * left hanging under the RSVP button with nothing beneath it, because the
 * second `<hr>` was still in the DOM behind `display: none` and kept it from
 * matching `:last-child`.
 *
 * The dividers are now a `border-top` on the zone itself
 * (`.groups-site-event-zone`), which makes the dangling case unrepresentable
 * — a divider cannot outlive the block that draws it. These tests pin both
 * halves of that: the template carries no separator blocks to strand, and an
 * event with no details renders no zones at all.
 *
 * @group groups
 */
class Test_Groups_Site_Event_Info_Card extends Groups_TestCase {

	const TEMPLATE = SUT_WP_CONTENT_DIR . 'themes/groups-site/templates/single-event.html';

	/**
	 * The block name of each optional zone, in template order.
	 */
	const OPTIONAL_ZONES = array(
		'gatherpress/venue',
		'gatherpress/online-event',
		'gatherpress/add-to-calendar',
	);

	/**
	 * Create a published event with no venue and no online-event term, so
	 * every optional zone of the details card renders empty.
	 */
	private function create_event_without_details(): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => 'Event Without Details',
			)
		);

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * Find the details-card group inside a parsed block tree.
	 *
	 * @param array $blocks Parsed blocks to walk.
	 *
	 * @return array|null The card block, or null when it isn't present.
	 */
	private function find_info_card( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			$class_name = $block['attrs']['className'] ?? '';

			if ( 'core/group' === $block['blockName'] && str_contains( $class_name, 'groups-site-event-info-card' ) ) {
				return $block;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = $this->find_info_card( $block['innerBlocks'] );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Parse `single-event.html` and return the details-card block.
	 */
	private function get_info_card_block(): array {
		$template = file_get_contents( self::TEMPLATE );

		$this->assertNotFalse( $template, 'Could not read single-event.html.' );

		$card = $this->find_info_card( parse_blocks( $template ) );

		$this->assertNotNull( $card, 'single-event.html no longer contains .groups-site-event-info-card.' );

		return $card;
	}

	/**
	 * The dangling-divider regression: an event with no venue, no online
	 * link and no calendar output renders a card with no zones — and so no
	 * divider stranded under the RSVP button.
	 */
	public function test_info_card_renders_no_zone_divider_when_all_optional_zones_are_empty() {
		$event_id = $this->create_event_without_details();

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );

		$output = render_block( $this->get_info_card_block() );

		// The card itself still rendered — otherwise the assertions below
		// would pass on an empty string.
		$this->assertStringContainsString( 'groups-site-event-info-card', $output );
		$this->assertStringContainsString( 'Date and time', $output );

		$this->assertStringNotContainsString( 'groups-site-event-zone', $output );
		$this->assertStringNotContainsString( '<hr', $output );
	}

	/**
	 * The structural half: the card separates its zones with a border on the
	 * zone, never with a sibling `core/separator` that can outlive it.
	 */
	public function test_info_card_contains_no_standalone_separator_blocks() {
		$card = $this->get_info_card_block();

		$separators = array_filter(
			$card['innerBlocks'],
			static function ( array $block ): bool {
				return 'core/separator' === $block['blockName'];
			}
		);

		$this->assertSame(
			array(),
			$separators,
			'The details card is back to standalone separators; a divider can now outlive its zone.'
		);
	}

	/**
	 * Every optional zone carries the class that draws its divider, so a
	 * zone added without one doesn't silently lose its boundary.
	 */
	public function test_optional_zones_carry_the_zone_class() {
		$card = $this->get_info_card_block();

		$zone_classes = array();

		foreach ( $card['innerBlocks'] as $block ) {
			if ( in_array( $block['blockName'], self::OPTIONAL_ZONES, true ) ) {
				$zone_classes[ $block['blockName'] ] = $block['attrs']['className'] ?? '';
			}
		}

		$this->assertSame(
			self::OPTIONAL_ZONES,
			array_keys( $zone_classes ),
			'The details card no longer holds the expected optional zones.'
		);

		foreach ( $zone_classes as $block_name => $class_name ) {
			$this->assertStringContainsString(
				'groups-site-event-zone',
				$class_name,
				"{$block_name} is missing the groups-site-event-zone class that draws its divider."
			);
		}
	}
}
