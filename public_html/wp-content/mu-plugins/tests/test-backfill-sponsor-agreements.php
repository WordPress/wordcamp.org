<?php

namespace WordCamp\Sponsor_Agreements\Backfill\Tests;

use WP_UnitTestCase;

use function WordCamp\Sponsor_Agreements\Backfill\get_public_agreement_ids;
use function WordCamp\Sponsor_Agreements\Backfill\describe_rename;
use function WordCamp\Sponsor_Agreements\Backfill\rename_agreement_file;
use function WordCamp\Sponsor_Agreements\is_agreement;
use function WordCamp\Sponsor_Agreements\make_agreement_private;

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
	 * @param string $mime_type
	 *
	 * @return array The sponsor ID and the attachment ID.
	 */
	protected function create_legacy_agreement( $filename = 'sponsorship-agreement-acme-signed.pdf', $mime_type = 'application/pdf' ) {
		$sponsor_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
		) );

		$agreement_id = self::factory()->attachment->create_object( array(
			'file'           => $filename,
			'post_parent'    => $sponsor_id,
			'post_status'    => 'inherit',
			'post_mime_type' => $mime_type,
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
	 * A file already on disk gets the name new uploads are given.
	 */
	public function test_an_existing_agreement_is_renamed() {
		$uploads  = wp_upload_dir();
		$old_path = trailingslashit( $uploads['path'] ) . 'sponsorship-agreement-acme-signed.pdf';

		file_put_contents( $old_path, '%PDF-1.4' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture on the local disk.

		list( , $agreement_id ) = $this->create_legacy_agreement( $old_path );

		try {
			$this->assertSame(
				array(
					'renamed'     => 1,
					'left_behind' => 0,
				),
				rename_agreement_file( $agreement_id )
			);

			$new_path = get_attached_file( $agreement_id );

			$this->assertFileDoesNotExist( $old_path );
			$this->assertFileExists( $new_path );
			$this->assertMatchesRegularExpression(
				'/^sponsorship-agreement-acme-signed-[A-Za-z0-9]{16}\.pdf$/',
				wp_basename( $new_path )
			);

			// The metabox resolves the file through the attachment, so the link follows the rename.
			$this->assertStringEndsWith( wp_basename( $new_path ), wp_get_attachment_url( $agreement_id ) );
		} finally {
			foreach ( array( $old_path, get_attached_file( $agreement_id ) ) as $path ) {
				if ( $path && is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}
	}

	/**
	 * An attachment whose file has already gone is reported rather than treated as renamed.
	 */
	public function test_renaming_a_missing_file_reports_failure() {
		list( , $agreement_id ) = $this->create_legacy_agreement();

		$this->assertSame( 0, rename_agreement_file( $agreement_id )['renamed'] );
	}

	/**
	 * Put an attachment's files on disk and record them in its metadata.
	 *
	 * Builds the shape by hand rather than through `wp_generate_attachment_metadata()`, so that the test
	 * doesn't depend on an image library or on which sizes the site happens to register.
	 *
	 * @param int      $attachment_id
	 * @param string   $attached_name The file `_wp_attached_file` points at.
	 * @param string[] $size_names    The generated sizes.
	 * @param string   $original_name The full-size original, when Core scaled the upload down.
	 *
	 * @return string The directory the files are in.
	 */
	protected function create_files_on_disk( $attachment_id, $attached_name, $size_names = array(), $original_name = '' ) {
		$uploads   = wp_upload_dir();
		$directory = trailingslashit( $uploads['path'] );
		$metadata  = array( 'file' => _wp_relative_upload_path( $directory . $attached_name ) );

		$names = array_merge( array( $attached_name ), $size_names, array_filter( array( $original_name ) ) );

		foreach ( $names as $name ) {
			file_put_contents( $directory . $name, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fixtures on the local disk.
		}

		foreach ( $size_names as $index => $name ) {
			$metadata['sizes'][ 'size-' . $index ] = array( 'file' => $name );
		}

		if ( $original_name ) {
			$metadata['original_image'] = $original_name;
		}

		update_attached_file( $attachment_id, $directory . $attached_name );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $directory;
	}

	/**
	 * Remove whatever an attachment's files are called now.
	 *
	 * @param string $directory
	 * @param string $pattern
	 */
	protected function delete_files_on_disk( $directory, $pattern ) {
		foreach ( glob( $directory . $pattern ) as $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * A photo of a signed page is the other thing the metabox asks for, and Core scales a large one down.
	 *
	 * `_wp_attached_file` then points at the `-scaled` copy, while the full-size original and every
	 * generated size are named after `original_image`. All of them are legible copies of the document, so
	 * all of them have to move.
	 */
	public function test_a_scaled_image_moves_every_file_it_generated() {
		list( , $agreement_id ) = $this->create_legacy_agreement( 'signed-agreement-photo-scaled.jpg', 'image/jpeg' );

		$sizes     = array(
			'signed-agreement-photo-150x150.jpg',
			'signed-agreement-photo-1024x1024.jpg',
			'signed-agreement-photo-2048x2048.jpg',
		);
		$directory = $this->create_files_on_disk(
			$agreement_id,
			'signed-agreement-photo-scaled.jpg',
			$sizes,
			'signed-agreement-photo.jpg'
		);

		try {
			// The scaled copy, the full-size original and all three sizes.
			$this->assertSame(
				array(
					'renamed'     => 5,
					'left_behind' => 0,
				),
				rename_agreement_file( $agreement_id )
			);

			$metadata = wp_get_attachment_metadata( $agreement_id );

			// One new base name, shared by the scaled copy, the original and every size.
			$this->assertMatchesRegularExpression(
				'/^signed-agreement-photo-[A-Za-z0-9]{16}-scaled\.jpg$/',
				wp_basename( get_attached_file( $agreement_id ) )
			);
			$this->assertMatchesRegularExpression(
				'/^signed-agreement-photo-[A-Za-z0-9]{16}\.jpg$/',
				$metadata['original_image']
			);

			foreach ( $metadata['sizes'] as $size => $details ) {
				$this->assertMatchesRegularExpression(
					'/^signed-agreement-photo-[A-Za-z0-9]{16}-\d+x\d+\.jpg$/',
					$details['file'],
					"Size {$size} kept the name it had."
				);
				$this->assertFileExists( $directory . $details['file'] );
			}

			$this->assertFileExists( wp_get_original_image_path( $agreement_id ) );

			// Nothing is left behind under a name that was handed out.
			foreach ( array_merge( $sizes, array( 'signed-agreement-photo.jpg', 'signed-agreement-photo-scaled.jpg' ) ) as $old_name ) {
				$this->assertFileDoesNotExist( $directory . $old_name );
			}
		} finally {
			$this->delete_files_on_disk( $directory, 'signed-agreement-photo*' );
		}
	}

	/**
	 * Two registered sizes can name the same file, and the second one has to follow the first.
	 */
	public function test_sizes_that_share_a_file_stay_in_step() {
		list( , $agreement_id ) = $this->create_legacy_agreement( 'shared-size.jpg', 'image/jpeg' );

		$directory = $this->create_files_on_disk(
			$agreement_id,
			'shared-size.jpg',
			array( 'shared-size-300x300.jpg', 'shared-size-300x300.jpg' )
		);

		try {
			// The file behind both sizes is moved once, and counted once.
			$this->assertSame(
				array(
					'renamed'     => 2,
					'left_behind' => 0,
				),
				rename_agreement_file( $agreement_id )
			);

			$sizes = wp_list_pluck( wp_get_attachment_metadata( $agreement_id )['sizes'], 'file' );

			$this->assertCount( 1, array_unique( $sizes ) );
			$this->assertFileExists( $directory . reset( $sizes ) );
		} finally {
			$this->delete_files_on_disk( $directory, 'shared-size*' );
		}
	}

	/**
	 * A file that can't be moved is reported, rather than counted as done.
	 */
	public function test_a_size_left_behind_is_reported() {
		list( , $agreement_id ) = $this->create_legacy_agreement( 'stubborn.jpg', 'image/jpeg' );

		$directory = $this->create_files_on_disk( $agreement_id, 'stubborn.jpg', array( 'stubborn-150x150.jpg' ) );

		// A size Core would never have named this way, standing in for one that can't be derived.
		$metadata                            = wp_get_attachment_metadata( $agreement_id );
		$metadata['sizes']['size-0']['file'] = 'unrelated-150x150.jpg';
		wp_update_attachment_metadata( $agreement_id, $metadata );
		file_put_contents( $directory . 'unrelated-150x150.jpg', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture on the local disk.

		try {
			$this->assertSame(
				array(
					'renamed'     => 1,
					'left_behind' => 1,
				),
				rename_agreement_file( $agreement_id )
			);

			// What could move still moved, and the metadata names each file by what it's actually called.
			$this->assertMatchesRegularExpression(
				'/^stubborn-[A-Za-z0-9]{16}\.jpg$/',
				wp_basename( get_attached_file( $agreement_id ) )
			);
			$this->assertSame(
				'unrelated-150x150.jpg',
				wp_get_attachment_metadata( $agreement_id )['sizes']['size-0']['file']
			);
			$this->assertFileExists( $directory . 'unrelated-150x150.jpg' );
		} finally {
			$this->delete_files_on_disk( $directory, 'stubborn*' );
			$this->delete_files_on_disk( $directory, 'unrelated-*' );
		}
	}

	/**
	 * What the report says about each of those outcomes.
	 *
	 * @dataProvider data_rename_descriptions
	 *
	 * @param array  $outcome
	 * @param bool   $dry_run
	 * @param bool   $skip_rename
	 * @param string $expected
	 */
	public function test_describe_rename( $outcome, $dry_run, $skip_rename, $expected ) {
		$this->assertSame( $expected, describe_rename( $outcome, $dry_run, $skip_rename ) );
	}

	/**
	 * @return array
	 */
	public function data_rename_descriptions() {
		$moved   = array(
			'renamed'     => 9,
			'left_behind' => 0,
		);
		$partial = array(
			'renamed'     => 1,
			'left_behind' => 8,
		);
		$nothing = array(
			'renamed'     => 0,
			'left_behind' => 0,
		);

		return array(
			'every file moved'  => array( $moved, false, false, '9 files' ),
			'a single file'     => array(
				array(
					'renamed'     => 1,
					'left_behind' => 0,
				),
				false,
				false,
				'1 file',
			),
			'some left behind'  => array( $partial, false, false, '1 moved, 8 left' ),
			'file already gone' => array( $nothing, false, false, 'file missing' ),
			'no outcome given'  => array( array(), false, false, 'file missing' ),
			'dry run'           => array( $nothing, true, false, 'pending' ),
			'renaming skipped'  => array( array(), false, true, 'skipped' ),
		);
	}

	/**
	 * An empty `original_image` falls back to the attached file, the way the metadata read below assumes.
	 */
	public function test_an_empty_original_image_falls_back_to_the_attached_file() {
		list( , $agreement_id ) = $this->create_legacy_agreement( 'blank-original.jpg', 'image/jpeg' );

		$directory = $this->create_files_on_disk( $agreement_id, 'blank-original.jpg', array( 'blank-original-150x150.jpg' ) );

		$metadata                   = wp_get_attachment_metadata( $agreement_id );
		$metadata['original_image'] = '';
		wp_update_attachment_metadata( $agreement_id, $metadata );

		try {
			$this->assertSame( 2, rename_agreement_file( $agreement_id )['renamed'] );

			$this->assertMatchesRegularExpression(
				'/^blank-original-[A-Za-z0-9]{16}\.jpg$/',
				wp_basename( get_attached_file( $agreement_id ) )
			);
			$this->assertMatchesRegularExpression(
				'/^blank-original-[A-Za-z0-9]{16}-150x150\.jpg$/',
				wp_get_attachment_metadata( $agreement_id )['sizes']['size-0']['file']
			);
		} finally {
			$this->delete_files_on_disk( $directory, 'blank-original*' );
		}
	}

	/**
	 * Two sizes naming one file that can't be moved report one file left behind, not two.
	 */
	public function test_a_shared_file_left_behind_is_counted_once() {
		list( , $agreement_id ) = $this->create_legacy_agreement( 'counted-once.jpg', 'image/jpeg' );

		$directory = $this->create_files_on_disk( $agreement_id, 'counted-once.jpg', array( 'a.jpg', 'a.jpg' ) );

		// Neither size can be derived from the attached file's base, so neither moves.
		try {
			$this->assertSame(
				array(
					'renamed'     => 1,
					'left_behind' => 1,
				),
				rename_agreement_file( $agreement_id )
			);
		} finally {
			$this->delete_files_on_disk( $directory, 'counted-once*' );
			$this->delete_files_on_disk( $directory, 'a.jpg' );
		}
	}

	/**
	 * A file that won't move doesn't stop the ones named after it, and all of them are counted.
	 *
	 * The derivatives take their name from `original_image`, not from the attached file, so a stuck
	 * attached file leaves them movable -- and the count is what tells the operator how much is left.
	 */
	public function test_a_stuck_attached_file_still_reports_every_other_one() {
		list( , $agreement_id ) = $this->create_legacy_agreement( 'unrelated-scaled.jpg', 'image/jpeg' );

		$directory = $this->create_files_on_disk(
			$agreement_id,
			'unrelated-scaled.jpg',
			array( 'photo-150x150.jpg', 'photo-300x300.jpg' ),
			'photo.jpg'
		);

		try {
			// The original and its two sizes move; the attached file, named from another base, doesn't.
			$this->assertSame(
				array(
					'renamed'     => 3,
					'left_behind' => 1,
				),
				rename_agreement_file( $agreement_id )
			);

			$metadata = wp_get_attachment_metadata( $agreement_id );

			$this->assertSame( 'unrelated-scaled.jpg', wp_basename( get_attached_file( $agreement_id ) ) );
			$this->assertFileExists( $directory . 'unrelated-scaled.jpg' );

			foreach ( $metadata['sizes'] as $details ) {
				$this->assertMatchesRegularExpression( '/^photo-[A-Za-z0-9]{16}-\d+x\d+\.jpg$/', $details['file'] );
				$this->assertFileExists( $directory . $details['file'] );
			}

			$this->assertMatchesRegularExpression( '/^photo-[A-Za-z0-9]{16}\.jpg$/', $metadata['original_image'] );
			$this->assertFileDoesNotExist( $directory . 'photo.jpg' );
		} finally {
			$this->delete_files_on_disk( $directory, 'photo*' );
			$this->delete_files_on_disk( $directory, 'unrelated-scaled*' );
		}
	}

	/**
	 * A name that merely starts with the base isn't one of this attachment's files.
	 *
	 * Core's derivatives are `<base>-<width>x<height>.<ext>` and `<base>-scaled.<ext>`, so the separator
	 * is the test. Without it `photobooth-150x150.jpg` would be spliced rather than rebased.
	 */
	public function test_a_name_that_only_shares_a_prefix_is_left_alone() {
		list( , $agreement_id ) = $this->create_legacy_agreement( 'photo.jpg', 'image/jpeg' );

		$directory = $this->create_files_on_disk(
			$agreement_id,
			'photo.jpg',
			array( 'photo-150x150.jpg', 'photobooth-150x150.jpg' )
		);

		try {
			// The attached file and its real size move; the neighbour is counted, not renamed.
			$this->assertSame(
				array(
					'renamed'     => 2,
					'left_behind' => 1,
				),
				rename_agreement_file( $agreement_id )
			);

			$sizes = wp_list_pluck( wp_get_attachment_metadata( $agreement_id )['sizes'], 'file' );

			$this->assertContains( 'photobooth-150x150.jpg', $sizes );
			$this->assertFileExists( $directory . 'photobooth-150x150.jpg' );

			foreach ( array_map( 'wp_basename', glob( $directory . 'photo*' ) ) as $name ) {
				$this->assertDoesNotMatchRegularExpression(
					'/^photo-[A-Za-z0-9]{16}booth/',
					$name,
					'The neighbour was spliced instead of left alone.'
				);
			}
		} finally {
			$this->delete_files_on_disk( $directory, 'photo*' );
		}
	}
}
