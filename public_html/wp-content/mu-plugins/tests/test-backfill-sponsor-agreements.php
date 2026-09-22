<?php

namespace WordCamp\Sponsor_Agreements\Backfill\Tests;

use WP_UnitTestCase;

use function WordCamp\Sponsor_Agreements\Backfill\get_public_agreement_ids;
use function WordCamp\Sponsor_Agreements\is_agreement;
use function WordCamp\Sponsor_Agreements\make_agreement_private;

use const WordCamp\Sponsor_Agreements\AGREEMENT_MARKER_META_KEY;
use const WordCamp\Sponsor_Agreements\NEEDS_RENAME_META_KEY;

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
		register_post_type( 'mes', array( 'public' => true ) );
	}

	/**
	 * Attach a file to a sponsor and name it as the agreement, without the meta hook seeing it.
	 *
	 * This is the shape of a row written before `sponsor-agreements.php` existed, and the only shape the
	 * migration has to deal with.
	 *
	 * @param string $filename
	 * @param string $post_type
	 * @param string $meta_key
	 *
	 * @return array The sponsor ID and the attachment ID.
	 */
	protected function create_legacy_agreement(
		$filename = 'sponsorship-agreement-acme-signed.pdf',
		$post_type = 'wcb_sponsor',
		$meta_key = '_wcpt_sponsor_agreement'
	) {
		$sponsor_id = self::factory()->post->create( array(
			'post_type'   => $post_type,
			'post_status' => 'publish',
		) );

		$agreement_id = self::factory()->attachment->create_object( array(
			'file'           => $filename,
			'post_parent'    => $sponsor_id,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		add_post_meta( $sponsor_id, $meta_key, $agreement_id );
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
	 * A PDF uploaded against a sponsor is an agreement whether or not the sponsor went on to record it.
	 */
	public function test_an_unrecorded_pdf_on_a_sponsor_is_reported() {
		$sponsor_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
		) );

		$unrecorded_id = self::factory()->attachment->create_object( array(
			'file'           => 'sponsorship-agreement-acme-signed.pdf',
			'post_parent'    => $sponsor_id,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		$page_pdf_id = self::factory()->attachment->create_object( array(
			'file'           => 'schedule.pdf',
			'post_parent'    => self::factory()->post->create(),
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		$public = get_public_agreement_ids();

		$this->assertContains( $unrecorded_id, $public );
		$this->assertNotContains( $page_pdf_id, $public, 'A PDF on an ordinary post is not an agreement.' );
	}

	/**
	 * Central stores its sponsors under another post type and meta key.
	 */
	public function test_a_central_agreement_is_reported_and_migrated() {
		list( , $agreement_id ) = $this->create_legacy_agreement(
			'sponsorship-agreement-acme-signed.pdf',
			'mes',
			'mes_sponsor_agreement'
		);

		$this->assertContains( $agreement_id, get_public_agreement_ids() );
		$this->assertTrue( make_agreement_private( $agreement_id ) );
		$this->assertSame( 'private', get_post_status( $agreement_id ) );
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
	 * The migration records that the files it acted on still need renaming.
	 *
	 * Nothing else says so once they are no longer `inherit`, and it is what a later rename pass reads.
	 */
	public function test_the_migration_records_what_still_needs_renaming() {
		list( , $legacy_id ) = $this->create_legacy_agreement();

		$sponsor_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
		) );
		$uploads    = wp_upload_dir();
		$directory  = trailingslashit( $uploads['path'] );

		// A real file: the hook renames on record, and an attachment with no file is itself recorded.
		file_put_contents( $directory . 'recent-agreement.pdf', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture on the local disk.

		$recent_id = self::factory()->attachment->create_object( array(
			'file'           => $directory . 'recent-agreement.pdf',
			'post_parent'    => $sponsor_id,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		update_attached_file( $recent_id, $directory . 'recent-agreement.pdf' );

		try {
			// The hook covers this one as it's attached, so the migration never sees it.
			update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $recent_id );

			$this->assertContains( $legacy_id, get_public_agreement_ids() );
			$this->assertNotContains( $recent_id, get_public_agreement_ids() );

			// What `Command::backfill()` does for each ID the query returns.
			make_agreement_private( $legacy_id );
			update_post_meta( $legacy_id, NEEDS_RENAME_META_KEY, 1 );

			$this->assertTrue( is_agreement( $legacy_id ) );
			$this->assertTrue( is_agreement( $recent_id ) );

			$this->assertSame( '1', get_post_meta( $legacy_id, NEEDS_RENAME_META_KEY, true ) );
			$this->assertSame( '', get_post_meta( $recent_id, NEEDS_RENAME_META_KEY, true ) );
		} finally {
			foreach ( glob( $directory . 'recent-agreement*' ) as $path ) {
				wp_delete_file( $path );
			}
		}
	}
}
