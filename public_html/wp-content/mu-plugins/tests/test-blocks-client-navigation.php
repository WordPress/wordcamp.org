<?php

namespace WordCamp\Tests;

use ReflectionProperty;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/blocks/blocks.php';

/**
 * Tests that the schedule blocks opt their page out of client-side navigation.
 *
 * @group blocks
 */
class Test_Blocks_Client_Navigation extends WP_UnitTestCase {
	/**
	 * Render as a front-end request, with an empty Interactivity API config.
	 */
	public function set_up() {
		parent::set_up();

		set_current_screen( 'front' );

		$config_data = new ReflectionProperty( wp_interactivity(), 'config_data' );
		$config_data->setAccessible( true );
		$config_data->setValue( wp_interactivity(), array() );
	}

	/**
	 * Render a block by name, the way `do_blocks()` would.
	 *
	 * @param string $block_name
	 *
	 * @return void
	 */
	protected function render( $block_name ) {
		render_block(
			array(
				'blockName'    => $block_name,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * The schedule block's React app can't mount into a swapped body.
	 *
	 * @covers \WordCamp\Blocks\disable_client_side_navigation
	 */
	public function test_schedule_block_disables_client_navigation() {
		$this->render( 'wordcamp/schedule' );

		$this->assertSame(
			array( 'clientNavigationDisabled' => true ),
			wp_interactivity_config( 'core/router' )
		);
	}

	/**
	 * The live schedule block loads a classic script handle, so it needs the same treatment.
	 *
	 * @covers \WordCamp\Blocks\disable_client_side_navigation
	 */
	public function test_live_schedule_block_disables_client_navigation() {
		$this->render( 'wordcamp/live-schedule' );

		$this->assertSame(
			array( 'clientNavigationDisabled' => true ),
			wp_interactivity_config( 'core/router' )
		);
	}

	/**
	 * Every other page keeps client-side navigation.
	 *
	 * @covers \WordCamp\Blocks\disable_client_side_navigation
	 */
	public function test_unrelated_block_leaves_client_navigation_alone() {
		$this->render( 'core/paragraph' );

		$this->assertSame( array(), wp_interactivity_config( 'core/router' ) );
	}
}
