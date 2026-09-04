<?php

namespace WordCamp\Sponsor_Agreements\Backfill\Tests;

use WP_UnitTestCase;

use function WordCamp\Sponsor_Agreements\Backfill\get_public_agreement_ids;
use function WordCamp\Sponsor_Agreements\is_agreement;
use function WordCamp\Sponsor_Agreements\make_agreement_private;

use const WordCamp\Sponsor_Agreements\AGREEMENT_MARKER_META_KEY;
use const WordCamp\Sponsor_Agreements\Backfill\BACKFILLED_META_KEY;

defined( 'WPINC' ) || die();

/**
 * The one-time migration behind `wp wc-sponsor-agreements`.
 *
 * Goes when `wp-cli-commands/backfill-sponsor-agreements.php` does. `Test_Sponsor_Agreements` covers what
 * stays.
 *
 * @group mu-plugins
 * @group sponsor-agreements
 */
class Test_Backfill_Sponsor_Agreements extends WP_UnitTestCase {
	/**
	 * The command file only loads under WP-CLI, so pull in the part of it that isn't the command.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		require_once dirname( __DIR__ ) . '/wp-cli-commands/backfill-sponsor-agreements.php';
	}

	/**
	 * Register the sponsor post type, which lives in a plugin this suite doesn't load.
	 *
	 * No matching `tear_down()`: `WP_UnitTestCase` resets the registered post types itself before each
	 * test, and unregistering by hand here takes rewrite rules and registered meta with it.
	 */
	public function set_up() {
		parent::set_up();

		register_post_type( 'wcb_sponsor', array( 'public' => true ) );
	}

	/**
	 * Attach a file to a sponsor and name it as the agreement, without the meta hook seeing it.
	 *
	 * This is the shape of a row written before `sponsor-agreements.php` existed, and the only shape the
	 * migration has to deal with.
	 *
	 * @param string $filename
	 *
	 * @return array The sponsor ID and the attachment ID.
	 */
	protected function create_legacy_agreement( $filename = 'sponsorship-agreement-acme-signed.pdf' ) {
		$sponsor_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
		) );

		$agreement_id = self::factory()->attachment->create_object( array(
			'file'           => $filename,
			'post_parent'    => $sponsor_id,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		add_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $agreement_id );
		wp_update_post( array(
			'ID'          => $agreement_id,
			'post_status' => 'inherit',
		) );

		return array( $sponsor_id, $agreement_id );
	}

	/**
	 * The migration finds the files it's for, and nothing else.
	 */
	public function test_legacy_agreements_are_reported_and_migrated() {
		list( $sponsor_id, $agreement_id ) = $this->create_legacy_agreement();

		$logo_id = self::factory()->attachment->create_object( array(
			'file'           => 'acme-logo.png',
			'post_parent'    => $sponsor_id,
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
		) );

		$public = get_public_agreement_ids();

		$this->assertContains( $agreement_id, $public );
		$this->assertNotContains( $logo_id, $public, 'A sponsor logo is meant to be public.' );

		$this->assertTrue( make_agreement_private( $agreement_id ) );
		$this->assertSame( 'private', get_post_status( $agreement_id ) );
		$this->assertTrue( is_agreement( $agreement_id ) );
		$this->assertNotContains( $agreement_id, get_public_agreement_ids() );
	}

	/**
	 * Naming a site explicitly is what lets `scan` ask this of a whole network from one process.
	 */
	public function test_the_query_can_be_asked_of_a_named_site() {
		list( , $agreement_id ) = $this->create_legacy_agreement();

		$this->assertContains( $agreement_id, get_public_agreement_ids( get_current_blog_id() ) );
	}

	/**
	 * The mark stays on the attachment after the migration has changed its status.
	 */
	public function test_a_migrated_agreement_stays_findable_by_its_mark() {
		list( , $agreement_id ) = $this->create_legacy_agreement();

		make_agreement_private( $agreement_id );

		$marked = get_posts( array(
			'post_type'   => 'attachment',
			'post_status' => 'any',
			'fields'      => 'ids',
			'numberposts' => -1,
			'meta_key'    => AGREEMENT_MARKER_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture, on a table with a handful of rows.
		) );

		$this->assertContains( $agreement_id, array_map( 'intval', $marked ) );
	}

	/**
	 * Running twice does nothing the second time.
	 */
	public function test_the_migration_is_safe_to_re_run() {
		list( , $agreement_id ) = $this->create_legacy_agreement();

		$this->assertTrue( make_agreement_private( $agreement_id ) );
		$this->assertSame( array(), get_public_agreement_ids() );
		$this->assertFalse( make_agreement_private( $agreement_id ), 'A second pass reported a change it did not make.' );
		$this->assertSame( 'private', get_post_status( $agreement_id ) );
	}

	/**
	 * The migration records which attachments it acted on.
	 *
	 * Those are the ones uploaded before `obscure_sponsor_file_names()` existed, and nothing else says so
	 * once they are no longer `inherit`.
	 */
	public function test_the_migration_records_what_it_acted_on() {
		list( , $legacy_id ) = $this->create_legacy_agreement();

		$sponsor_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
		) );
		$recent_id  = self::factory()->attachment->create_object( array(
			'file'           => 'agreement-aBcDeFgHiJkLmNoP.pdf',
			'post_parent'    => $sponsor_id,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		// The hook covers this one as it's attached, so the migration never sees it.
		update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $recent_id );

		$this->assertContains( $legacy_id, get_public_agreement_ids() );
		$this->assertNotContains( $recent_id, get_public_agreement_ids() );

		// What `Command::backfill()` does for each ID the query returns.
		make_agreement_private( $legacy_id );
		update_post_meta( $legacy_id, BACKFILLED_META_KEY, 1 );

		$this->assertTrue( is_agreement( $legacy_id ) );
		$this->assertTrue( is_agreement( $recent_id ) );

		$this->assertSame( '1', get_post_meta( $legacy_id, BACKFILLED_META_KEY, true ) );
		$this->assertSame( '', get_post_meta( $recent_id, BACKFILLED_META_KEY, true ) );
	}
}
