<?php

namespace WordCamp\Sponsor_Agreements\Tests;

use WP_UnitTestCase, WP_UnitTest_Factory, WP_REST_Request, WP_REST_Server;

use function WordCamp\Sponsor_Agreements\is_agreement;
use function WordCamp\Sponsor_Agreements\make_agreement_private;
use function WordCamp\Sponsor_Agreements\add_csprn_to_filename;
use function WordCamp\Sponsor_Agreements\rename_agreement_file;
use function WordCamp\Sponsor_Agreements\secure_agreement;

use const WordCamp\Sponsor_Agreements\NEEDS_RENAME_META_KEY;
use const WordCamp\Sponsor_Agreements\UPLOAD_PARAM;

defined( 'WPINC' ) || die();

/**
 * Who can reach a sponsorship agreement.
 *
 * Organizers attach the agreement from the Sponsor screen, and the Media modal stores it as an ordinary
 * attachment on a sponsor. These pin who it stays readable for once it's there.
 *
 * @group mu-plugins
 * @group sponsor-agreements
 */
class Test_Sponsor_Agreements extends WP_UnitTestCase {
	/** @var int An organizer, who is an Editor on a WordCamp site. */
	protected static $organizer;

	/** @var int A volunteer who can write posts but isn't trusted with anyone else's. */
	protected static $volunteer;

	/**
	 * Register the sponsor post type with the arguments `wc-post-types` gives it in production.
	 *
	 * No matching `tear_down()`: `WP_UnitTestCase` resets the registered post types itself before each
	 * test, and unregistering by hand here takes rewrite rules and registered meta with it.
	 *
	 * That plugin isn't loaded for this suite, and these two are what the behaviour under test follows
	 * from. `Test_WC_Post_Types` covers the registration itself.
	 */
	public function set_up() {
		parent::set_up();

		register_post_type(
			'wcb_sponsor',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'sponsors',
			)
		);
	}

	/**
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$organizer = $factory->user->create( array( 'role' => 'editor' ) );
		self::$volunteer = $factory->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Create a published sponsor.
	 *
	 * @return int
	 */
	protected function create_sponsor() {
		return self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
		) );
	}

	/**
	 * Attach a file to a sponsor, the way the Media modal does: `inherit`, parented to the sponsor.
	 *
	 * @param string $filename
	 * @param int    $sponsor_id
	 *
	 * @return int
	 */
	protected function create_file( $filename, $sponsor_id ) {
		return self::factory()->attachment->create_object( array(
			'file'           => $filename,
			'post_parent'    => $sponsor_id,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );
	}

	/**
	 * Attach a file to a sponsor and record it as that sponsor's agreement.
	 *
	 * @param int    $sponsor_id
	 * @param string $meta_key
	 *
	 * @return int The attachment ID.
	 */
	protected function attach_agreement( $sponsor_id, $meta_key = '_wcpt_sponsor_agreement' ) {
		$agreement_id = $this->create_file( 'sponsorship-agreement-acme-signed.pdf', $sponsor_id );

		update_post_meta( $sponsor_id, $meta_key, $agreement_id );

		return $agreement_id;
	}

	/**
	 * Run something with the error log pointed at a file this test can read.
	 *
	 * Written into the uploads directory the test environment already owns, rather than the system's
	 * temporary directory.
	 *
	 * @param callable $callback
	 *
	 * @return string Whatever was logged.
	 */
	protected function capture_log( $callback ) {
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] );
		$log      = trailingslashit( $uploads['basedir'] ) . 'agreement-log-' . wp_generate_password( 8, false, false ) . '.log';
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- scoped to this test.

		try {
			$callback();
		} finally {
			ini_set( 'error_log', $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring what was there.
		}

		// Nothing logged means the file was never created.
		if ( ! is_file( $log ) ) {
			return '';
		}

		$contents = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file this test wrote.

		wp_delete_file( $log );

		return $contents;
	}

	/**
	 * Put a file on disk and attach it to a sponsor, as an upload from that sponsor's screen.
	 *
	 * @param string   $filename
	 * @param string   $directory
	 * @param int|null $sponsor_id Defaults to a new sponsor, for the tests that don't need to name it.
	 *
	 * @return int The attachment ID.
	 */
	protected function create_upload_on_disk( $filename, $directory, $sponsor_id = null ) {
		file_put_contents( $directory . $filename, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture on the local disk.

		$attachment_id = self::factory()->attachment->create_object( array(
			'file'           => $directory . $filename,
			'post_parent'    => $sponsor_id ?? $this->create_sponsor(),
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		update_attached_file( $attachment_id, $directory . $filename );

		return $attachment_id;
	}

	/**
	 * Ask the Media REST route for one attachment, as the current user.
	 *
	 * @param int $attachment_id
	 *
	 * @return int The HTTP status code.
	 */
	protected function request_media_item( $attachment_id ) {
		$server = rest_get_server();

		$response = $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/media/' . $attachment_id ) );

		return $response->get_status();
	}

	/**
	 * The IDs the Media collection route hands the current user.
	 *
	 * @return int[]
	 */
	protected function request_media_collection() {
		$server = rest_get_server();

		$response = $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/media' ) );

		return array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
	}

	/**
	 * Attaching an agreement gives it the `private` status.
	 *
	 * @dataProvider data_agreement_meta_keys
	 *
	 * @param string $meta_key
	 */
	public function test_attaching_an_agreement_makes_it_private( $meta_key ) {
		$agreement_id = $this->attach_agreement( $this->create_sponsor(), $meta_key );

		$this->assertSame( 'private', get_post_status( $agreement_id ) );
	}

	/**
	 * The two meta keys an agreement is stored under, on an event site and on central.
	 *
	 * @return array
	 */
	public function data_agreement_meta_keys() {
		return array(
			'event site' => array( '_wcpt_sponsor_agreement' ),
			'central'    => array( 'mes_sponsor_agreement' ),
		);
	}

	/**
	 * Replacing one agreement with another covers the new file too.
	 */
	public function test_replacing_an_agreement_makes_the_new_file_private() {
		$sponsor_id = $this->create_sponsor();

		$this->attach_agreement( $sponsor_id );

		$replacement_id = $this->create_file( 'sponsorship-agreement-acme-countersigned.pdf', $sponsor_id );
		update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $replacement_id );

		$this->assertSame( 'private', get_post_status( $replacement_id ) );
	}

	/**
	 * The single Media route resolves by ID and runs no query, so the status is what answers it.
	 */
	public function test_anonymous_rest_read_is_denied() {
		$agreement_id = $this->attach_agreement( $this->create_sponsor() );

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request_media_item( $agreement_id ) );
	}

	/**
	 * The Media collection route, which is the other way in.
	 */
	public function test_anonymous_rest_collection_omits_the_agreement() {
		$sponsor_id   = $this->create_sponsor();
		$agreement_id = $this->attach_agreement( $sponsor_id );
		$logo_id      = $this->create_file( 'acme-logo.png', $sponsor_id );

		wp_set_current_user( 0 );

		$visible = $this->request_media_collection();

		$this->assertNotContains( $agreement_id, $visible );
		$this->assertContains( $logo_id, $visible, 'A sponsor logo is meant to be public.' );
	}

	/**
	 * Attachments are searchable, and a front-end search names no post type.
	 */
	public function test_anonymous_search_omits_the_agreement() {
		$agreement_id = $this->attach_agreement( $this->create_sponsor() );

		wp_set_current_user( 0 );

		$found = get_posts( array(
			's'              => 'sponsorship-agreement-acme-signed',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$this->assertNotContains( $agreement_id, array_map( 'intval', $found ) );
	}

	/**
	 * Organizers keep the access the workflow needs.
	 */
	public function test_organizer_can_still_read_the_agreement() {
		$agreement_id = $this->attach_agreement( $this->create_sponsor() );

		wp_set_current_user( self::$organizer );

		$this->assertSame( 200, $this->request_media_item( $agreement_id ) );
	}

	/**
	 * Someone who can write on the site but isn't an organizer doesn't have it.
	 */
	public function test_volunteer_cannot_read_the_agreement() {
		$agreement_id = $this->attach_agreement( $this->create_sponsor() );

		wp_set_current_user( self::$volunteer );

		$this->assertSame( 403, $this->request_media_item( $agreement_id ) );
	}

	/**
	 * The Sponsor Agreement metabox links straight to the file, so the link has to survive the status.
	 */
	public function test_the_agreement_url_still_resolves() {
		$agreement_id = $this->attach_agreement( $this->create_sponsor() );

		$this->assertNotEmpty( wp_get_attachment_url( $agreement_id ) );
	}

	/**
	 * The sponsor itself is meant to be public, and stays that way.
	 */
	public function test_the_sponsor_remains_public() {
		$sponsor_id = $this->create_sponsor();

		$this->attach_agreement( $sponsor_id );

		$this->assertSame( 'publish', get_post_status( $sponsor_id ) );
	}

	/**
	 * A file that's already private reports that it needed no change.
	 */
	public function test_securing_an_agreement_twice_is_a_no_op() {
		$agreement_id = $this->attach_agreement( $this->create_sponsor() );

		$this->assertFalse( make_agreement_private( $agreement_id ) );
	}

	/**
	 * `mes_sponsor_agreement` isn't protected meta, so the Custom Fields box will write it to any post that
	 * supports them. Only a sponsor names its own agreement.
	 */
	public function test_meta_on_an_ordinary_post_leaves_the_attachment_alone() {
		$post_id       = self::factory()->post->create();
		$attachment_id = $this->create_file( 'header.png', $post_id );

		update_post_meta( $post_id, 'mes_sponsor_agreement', $attachment_id );

		$this->assertSame( 'inherit', get_post( $attachment_id )->post_status );
		$this->assertFalse( is_agreement( $attachment_id ) );
	}

	/**
	 * `absint()` reads an array as `1`, which is another attachment entirely.
	 */
	public function test_a_non_scalar_meta_value_is_ignored() {
		$sponsor_id    = $this->create_sponsor();
		$attachment_id = $this->create_file( 'header.png', $sponsor_id );

		update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', array( $attachment_id ) );

		$this->assertSame( 'inherit', get_post( $attachment_id )->post_status );
	}

	/**
	 * The mark and the status stay with the file when it's detached.
	 */
	public function test_the_agreement_stays_marked_when_it_is_detached() {
		$agreement_id = $this->attach_agreement( $this->create_sponsor() );

		wp_update_post( array(
			'ID'          => $agreement_id,
			'post_parent' => 0,
		) );

		$this->assertTrue( is_agreement( $agreement_id ) );
		$this->assertSame( 'private', get_post_status( $agreement_id ) );
	}

	/**
	 * `wp.getMediaItem` takes an ID and runs no query, so the status doesn't reach it.
	 */
	public function test_xmlrpc_redacts_the_agreement_from_a_volunteer() {
		$agreement_id = $this->attach_agreement( $this->create_sponsor() );

		wp_set_current_user( self::$volunteer );

		$this->assertEmpty( $this->prepare_media_item( $agreement_id )['link'] ?? '' );

		wp_set_current_user( self::$organizer );

		$this->assertNotEmpty( $this->prepare_media_item( $agreement_id )['link'] ?? '' );
	}

	/**
	 * Run an attachment through the XML-RPC media struct filter.
	 *
	 * @param int $attachment_id
	 *
	 * @return array
	 */
	protected function prepare_media_item( $attachment_id ) {
		$attachment = get_post( $attachment_id );

		return apply_filters(
			'xmlrpc_prepare_media_item',
			array(
				'attachment_id' => (string) $attachment->ID,
				'link'          => wp_get_attachment_url( $attachment->ID ),
			),
			$attachment,
			'thumbnail'
		);
	}

	/**
	 * `wp_ajax_get_attachment()` takes an ID and runs no query either.
	 */
	public function test_the_admin_js_details_are_redacted_for_a_volunteer() {
		$agreement_id = $this->attach_agreement( $this->create_sponsor() );

		wp_set_current_user( self::$volunteer );

		$this->assertEmpty( wp_prepare_attachment_for_js( $agreement_id ) );

		wp_set_current_user( self::$organizer );

		$this->assertNotEmpty( wp_prepare_attachment_for_js( $agreement_id ) );
	}

	/**
	 * Ordinary media keeps working for everyone, on the routes that resolve by ID.
	 */
	public function test_ordinary_media_is_left_alone_on_the_id_routes() {
		$logo_id = $this->create_file( 'acme-logo.png', $this->create_sponsor() );

		wp_set_current_user( self::$volunteer );

		$this->assertNotEmpty( $this->prepare_media_item( $logo_id )['link'] ?? '' );
		$this->assertNotEmpty( wp_prepare_attachment_for_js( $logo_id ) );
	}

	/**
	 * Attach a real file to a sponsor and record it as the agreement.
	 *
	 * @param string   $filename
	 * @param string[] $size_names
	 * @param string   $original_name The full-size original, when Core scaled the upload down.
	 *
	 * @return array The attachment ID and the directory its files are in.
	 */
	protected function attach_agreement_on_disk( $filename, $size_names = array(), $original_name = '' ) {
		$sponsor_id = $this->create_sponsor();
		$uploads    = wp_upload_dir();
		$directory  = trailingslashit( $uploads['path'] );
		$metadata   = array( 'file' => _wp_relative_upload_path( $directory . $filename ) );

		foreach ( array_merge( array( $filename ), $size_names, array_filter( array( $original_name ) ) ) as $name ) {
			file_put_contents( $directory . $name, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fixtures on the local disk.
		}

		foreach ( $size_names as $index => $name ) {
			$metadata['sizes'][ 'size-' . $index ] = array( 'file' => $name );
		}

		if ( $original_name ) {
			$metadata['original_image'] = $original_name;
		}

		$agreement_id = self::factory()->attachment->create_object( array(
			'file'           => $directory . $filename,
			'post_parent'    => $sponsor_id,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		update_attached_file( $agreement_id, $directory . $filename );
		wp_update_attachment_metadata( $agreement_id, $metadata );

		// The hook runs here, so the file is named as it's recorded.
		update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $agreement_id );

		return array( $agreement_id, $directory );
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
	 * Recording a file as an agreement gives it a name it wasn't uploaded under.
	 */
	public function test_an_agreement_is_renamed_when_it_is_recorded() {
		list( $agreement_id, $directory ) = $this->attach_agreement_on_disk( 'sponsorship-agreement-acme-signed.pdf' );

		try {
			$this->assertMatchesRegularExpression(
				'/^sponsorship-agreement-acme-signed-[A-Za-z0-9]{16}\.pdf$/',
				wp_basename( get_attached_file( $agreement_id ) )
			);
			$this->assertFileDoesNotExist( $directory . 'sponsorship-agreement-acme-signed.pdf' );

			// The metabox resolves the file through the attachment, so the link follows the rename.
			$this->assertStringEndsWith(
				wp_basename( get_attached_file( $agreement_id ) ),
				wp_get_attachment_url( $agreement_id )
			);
		} finally {
			$this->delete_files_on_disk( $directory, 'sponsorship-agreement-acme-signed*' );
		}
	}

	/**
	 * A file uploaded elsewhere and then picked from the Media Library is covered too.
	 */
	public function test_an_agreement_picked_from_the_library_is_renamed() {
		$sponsor_id = $this->create_sponsor();
		$uploads    = wp_upload_dir();
		$directory  = trailingslashit( $uploads['path'] );

		file_put_contents( $directory . 'library-agreement.pdf', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture on the local disk.

		// Uploaded through Media > Add New, so it has no parent and no suffix.
		$agreement_id = self::factory()->attachment->create_object( array(
			'file'           => $directory . 'library-agreement.pdf',
			'post_parent'    => 0,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );
		update_attached_file( $agreement_id, $directory . 'library-agreement.pdf' );

		update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $agreement_id );

		try {
			$this->assertSame( 'private', get_post_status( $agreement_id ) );
			$this->assertMatchesRegularExpression(
				'/^library-agreement-[A-Za-z0-9]{16}\.pdf$/',
				wp_basename( get_attached_file( $agreement_id ) )
			);
		} finally {
			$this->delete_files_on_disk( $directory, 'library-agreement*' );
		}
	}

	/**
	 * A scaled photo of a signed page moves every file Core derived from it.
	 */
	public function test_a_scaled_image_moves_every_file_it_generated() {
		$sizes = array(
			'signed-photo-150x150.jpg',
			'signed-photo-1024x1024.jpg',
			'signed-photo-2048x2048.jpg',
		);

		list( $agreement_id, $directory ) = $this->attach_agreement_on_disk(
			'signed-photo-scaled.jpg',
			$sizes,
			'signed-photo.jpg'
		);

		try {
			$metadata = wp_get_attachment_metadata( $agreement_id );

			$this->assertMatchesRegularExpression(
				'/^signed-photo-[A-Za-z0-9]{16}-scaled\.jpg$/',
				wp_basename( get_attached_file( $agreement_id ) )
			);
			$this->assertMatchesRegularExpression( '/^signed-photo-[A-Za-z0-9]{16}\.jpg$/', $metadata['original_image'] );

			foreach ( $metadata['sizes'] as $size => $details ) {
				$this->assertMatchesRegularExpression(
					'/^signed-photo-[A-Za-z0-9]{16}-\d+x\d+\.jpg$/',
					$details['file'],
					"Size {$size} kept the name it had."
				);
				$this->assertFileExists( $directory . $details['file'] );
			}

			foreach ( array_merge( $sizes, array( 'signed-photo.jpg', 'signed-photo-scaled.jpg' ) ) as $old_name ) {
				$this->assertFileDoesNotExist( $directory . $old_name );
			}
		} finally {
			$this->delete_files_on_disk( $directory, 'signed-photo*' );
		}
	}

	/**
	 * A name that merely starts with the base isn't one of this attachment's files.
	 */
	public function test_a_name_that_only_shares_a_prefix_is_left_alone() {
		list( $agreement_id, $directory ) = $this->attach_agreement_on_disk(
			'photo.jpg',
			array( 'photo-150x150.jpg', 'photobooth-150x150.jpg' )
		);

		try {
			$sizes = wp_list_pluck( wp_get_attachment_metadata( $agreement_id )['sizes'], 'file' );

			$this->assertContains( 'photobooth-150x150.jpg', $sizes );
			$this->assertFileExists( $directory . 'photobooth-150x150.jpg' );

			foreach ( array_map( 'wp_basename', glob( $directory . 'photo*' ) ) as $name ) {
				$this->assertDoesNotMatchRegularExpression( '/^photo-[A-Za-z0-9]{16}booth/', $name );
			}
		} finally {
			$this->delete_files_on_disk( $directory, 'photo*' );
		}
	}

	/**
	 * A sponsor's other uploads keep the names they were given.
	 */
	public function test_a_sponsor_logo_is_not_renamed() {
		$sponsor_id = $this->create_sponsor();
		$uploads    = wp_upload_dir();
		$directory  = trailingslashit( $uploads['path'] );

		file_put_contents( $directory . 'acme-logo.png', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture on the local disk.

		$logo_id = self::factory()->attachment->create_object( array(
			'file'           => $directory . 'acme-logo.png',
			'post_parent'    => $sponsor_id,
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
		) );
		update_attached_file( $logo_id, $directory . 'acme-logo.png' );

		try {
			$this->assertSame( 'acme-logo.png', wp_basename( get_attached_file( $logo_id ) ) );
			$this->assertFileExists( $directory . 'acme-logo.png' );
		} finally {
			$this->delete_files_on_disk( $directory, 'acme-logo*' );
		}
	}

	/**
	 * Naming the same file on a second sponsor doesn't suffix it twice.
	 */
	public function test_an_agreement_is_only_renamed_once() {
		list( $agreement_id, $directory ) = $this->attach_agreement_on_disk( 'shared-agreement.pdf' );

		try {
			$renamed = wp_basename( get_attached_file( $agreement_id ) );

			update_post_meta( $this->create_sponsor(), '_wcpt_sponsor_agreement', $agreement_id );

			$this->assertSame( $renamed, wp_basename( get_attached_file( $agreement_id ) ) );
		} finally {
			$this->delete_files_on_disk( $directory, 'shared-agreement*' );
		}
	}

	/**
	 * The extension is trimmed off the end, not wherever it happens to appear.
	 */
	public function test_a_name_that_repeats_its_extension_keeps_both_copies() {
		$this->assertMatchesRegularExpression(
			'/^agreement\.pdf-[A-Za-z0-9]{16}\.pdf$/',
			add_csprn_to_filename( 'agreement.pdf.pdf', '.pdf' )
		);
	}

	/**
	 * An upload marked before the sponsor is saved is covered from the moment it lands.
	 *
	 * The Sponsor Agreement modal marks its own uploads, so a file that is uploaded and then abandoned --
	 * the organizer removes it again, or never presses Update -- doesn't sit on a published sponsor as an
	 * ordinary attachment.
	 */
	public function test_an_upload_is_secured_before_the_sponsor_is_saved() {
		$uploads      = wp_upload_dir();
		$directory    = trailingslashit( $uploads['path'] );
		$agreement_id = $this->create_upload_on_disk( 'just-uploaded.pdf', $directory );

		try {
			// No sponsor meta is written: this is the upload being marked, not the sponsor being saved.
			secure_agreement( $agreement_id );

			$this->assertSame( 'private', get_post_status( $agreement_id ) );
			$this->assertTrue( is_agreement( $agreement_id ) );
			$this->assertMatchesRegularExpression(
				'/^just-uploaded-[A-Za-z0-9]{16}\.pdf$/',
				wp_basename( get_attached_file( $agreement_id ) )
			);

			wp_set_current_user( 0 );

			$this->assertSame( 401, $this->request_media_item( $agreement_id ) );
		} finally {
			$this->delete_files_on_disk( $directory, 'just-uploaded*' );
		}
	}

	/**
	 * Saving the sponsor afterwards doesn't suffix the file a second time.
	 */
	public function test_saving_the_sponsor_after_an_upload_changes_nothing() {
		$sponsor_id   = $this->create_sponsor();
		$uploads      = wp_upload_dir();
		$directory    = trailingslashit( $uploads['path'] );
		$agreement_id = $this->create_upload_on_disk( 'marked-first.pdf', $directory, $sponsor_id );

		try {
			secure_agreement( $agreement_id );

			$marked = wp_basename( get_attached_file( $agreement_id ) );

			update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $agreement_id );

			$this->assertSame( $marked, wp_basename( get_attached_file( $agreement_id ) ) );
		} finally {
			$this->delete_files_on_disk( $directory, 'marked-first*' );
		}
	}

	/**
	 * An upload the modal never marked is noted, because saving the sponsor otherwise hides that.
	 */
	public function test_an_unmarked_upload_is_logged() {
		$sponsor_id   = $this->create_sponsor();
		$agreement_id = $this->create_file( 'unmarked.pdf', $sponsor_id );

		$logged = $this->capture_log( function () use ( $sponsor_id, $agreement_id ) {
			update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $agreement_id );
		} );

		$this->assertStringContainsString( 'sponsor_agreement_upload_not_marked', $logged );
		$this->assertSame( 'private', get_post_status( $agreement_id ), 'The file was left unsecured.' );
	}

	/**
	 * A file chosen from the Media Library had no upload to mark, so it says nothing.
	 */
	public function test_a_library_choice_is_not_logged() {
		$sponsor_id   = $this->create_sponsor();
		$agreement_id = $this->create_file( 'from-the-library.pdf', 0 );

		$logged = $this->capture_log( function () use ( $sponsor_id, $agreement_id ) {
			update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $agreement_id );
		} );

		$this->assertStringNotContainsString( 'sponsor_agreement_upload_not_marked', $logged );
	}

	/**
	 * An upload the modal did mark is already an agreement, so saving the sponsor says nothing either.
	 */
	public function test_a_marked_upload_is_not_logged() {
		$sponsor_id   = $this->create_sponsor();
		$agreement_id = $this->create_file( 'marked.pdf', $sponsor_id );

		secure_agreement( $agreement_id );

		$logged = $this->capture_log( function () use ( $sponsor_id, $agreement_id ) {
			update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $agreement_id );
		} );

		$this->assertStringNotContainsString( 'sponsor_agreement_upload_not_marked', $logged );
	}

	/**
	 * Put the request into the shape `wp_ajax_upload_attachment()` gives it.
	 *
	 * @param int  $sponsor_id The parent the upload names.
	 * @param bool $marked     Whether the modal marked this upload as an agreement.
	 */
	protected function start_upload_request( $sponsor_id, $marked = true ) {
		$_REQUEST['action']  = 'upload-attachment';
		$_REQUEST['post_id'] = $sponsor_id;

		if ( $marked ) {
			$_POST[ UPLOAD_PARAM ] = 1;
		}
	}

	/**
	 * Leave the superglobals as they were found.
	 */
	protected function end_upload_request() {
		unset( $_REQUEST['action'], $_REQUEST['post_id'], $_POST[ UPLOAD_PARAM ] );
	}

	/**
	 * Create an attachment the way `media_handle_upload()` does, so the upload hooks really run.
	 *
	 * @param string $filename
	 * @param int    $sponsor_id
	 * @param string $mime_type
	 *
	 * @return int
	 */
	protected function upload_attachment( $filename, $sponsor_id, $mime_type = 'application/pdf' ) {
		$uploads   = wp_upload_dir();
		$directory = trailingslashit( $uploads['path'] );
		$name      = wp_unique_filename( $directory, $filename );

		file_put_contents( $directory . $name, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture on the local disk.

		return wp_insert_attachment(
			array(
				'post_title'     => $filename,
				'post_parent'    => $sponsor_id,
				'post_mime_type' => $mime_type,
				'post_status'    => 'inherit',
			),
			$directory . $name,
			$sponsor_id
		);
	}

	/**
	 * A marked upload is named, hidden and marked without anything having to move afterwards.
	 */
	public function test_a_marked_upload_is_secured_as_it_arrives() {
		$sponsor_id = $this->create_sponsor();
		$uploads    = wp_upload_dir();
		$directory  = trailingslashit( $uploads['path'] );

		wp_set_current_user( self::$organizer );
		$this->start_upload_request( $sponsor_id );

		try {
			$agreement_id = $this->upload_attachment( 'signed-agreement.pdf', $sponsor_id );

			$this->assertMatchesRegularExpression(
				'/^signed-agreement-[A-Za-z0-9]{16}\.pdf$/',
				wp_basename( get_attached_file( $agreement_id ) )
			);
			$this->assertSame( 'private', get_post( $agreement_id )->post_status );
			$this->assertTrue( is_agreement( $agreement_id ) );

			// It was never written under the name it arrived with.
			$this->assertFileDoesNotExist( $directory . 'signed-agreement.pdf' );
		} finally {
			$this->end_upload_request();
			$this->delete_files_on_disk( $directory, 'signed-agreement*' );
		}
	}

	/**
	 * A PDF is an agreement whether or not the modal marked it.
	 *
	 * This is what stands between an organizer and an exposed contract when the marking fails, which is
	 * otherwise silent for an upload that is then abandoned.
	 */
	public function test_an_unmarked_pdf_on_a_sponsor_is_still_an_agreement() {
		$sponsor_id = $this->create_sponsor();
		$uploads    = wp_upload_dir();
		$directory  = trailingslashit( $uploads['path'] );

		wp_set_current_user( self::$organizer );
		$this->start_upload_request( $sponsor_id, false );

		try {
			$agreement_id = $this->upload_attachment( 'unmarked-contract.pdf', $sponsor_id );

			$this->assertSame( 'private', get_post( $agreement_id )->post_status );
			$this->assertTrue( is_agreement( $agreement_id ) );
			$this->assertMatchesRegularExpression(
				'/^unmarked-contract-[A-Za-z0-9]{16}\.pdf$/',
				wp_basename( get_attached_file( $agreement_id ) )
			);
		} finally {
			$this->end_upload_request();
			$this->delete_files_on_disk( $directory, 'unmarked-contract*' );
		}
	}

	/**
	 * An unmarked image is a logo, and is left as one.
	 *
	 * The gap this leaves -- an image agreement whose marking failed -- is what
	 * `report_unmarked_upload()` is for.
	 */
	public function test_an_unmarked_image_on_a_sponsor_is_left_alone() {
		$sponsor_id = $this->create_sponsor();
		$uploads    = wp_upload_dir();
		$directory  = trailingslashit( $uploads['path'] );

		wp_set_current_user( self::$organizer );
		$this->start_upload_request( $sponsor_id, false );

		try {
			$logo_id = $this->upload_attachment( 'acme-logo.png', $sponsor_id, 'image/png' );

			$this->assertSame( 'inherit', get_post( $logo_id )->post_status );
			$this->assertFalse( is_agreement( $logo_id ) );
			$this->assertSame( 'acme-logo.png', wp_basename( get_attached_file( $logo_id ) ) );
		} finally {
			$this->end_upload_request();
			$this->delete_files_on_disk( $directory, 'acme-logo*' );
		}
	}

	/**
	 * A marked image is an agreement, which is the whole of what the field is for.
	 */
	public function test_a_marked_image_on_a_sponsor_is_an_agreement() {
		$sponsor_id = $this->create_sponsor();
		$uploads    = wp_upload_dir();
		$directory  = trailingslashit( $uploads['path'] );

		wp_set_current_user( self::$organizer );
		$this->start_upload_request( $sponsor_id );

		try {
			$agreement_id = $this->upload_attachment( 'signed-page.jpg', $sponsor_id, 'image/jpeg' );

			$this->assertSame( 'private', get_post( $agreement_id )->post_status );
			$this->assertTrue( is_agreement( $agreement_id ) );
		} finally {
			$this->end_upload_request();
			$this->delete_files_on_disk( $directory, 'signed-page*' );
		}
	}

	/**
	 * The requests this has to stay out of, and why each one is here.
	 *
	 * @dataProvider data_requests_the_upload_hooks_decline
	 *
	 * @param string $action      The action Core dispatched on.
	 * @param string $parent_type The post type the upload names as its parent.
	 * @param string $role        The role of the user uploading.
	 */
	public function test_the_upload_hooks_decline_other_requests( $action, $parent_type, $role ) {
		$parent_id = 'wcb_sponsor' === $parent_type
			? $this->create_sponsor()
			: self::factory()->post->create();

		$uploads   = wp_upload_dir();
		$directory = trailingslashit( $uploads['path'] );

		wp_set_current_user( 'editor' === $role ? self::$organizer : self::$volunteer );

		$this->start_upload_request( $parent_id );
		$_REQUEST['action'] = $action;

		try {
			$attachment_id = $this->upload_attachment( 'untouched.pdf', $parent_id );

			$this->assertSame( 'inherit', get_post( $attachment_id )->post_status );
			$this->assertFalse( is_agreement( $attachment_id ) );
			$this->assertSame( 'untouched.pdf', wp_basename( get_attached_file( $attachment_id ) ) );
		} finally {
			$this->end_upload_request();
			$this->delete_files_on_disk( $directory, 'untouched*' );
		}
	}

	/**
	 * @return array
	 */
	public function data_requests_the_upload_hooks_decline() {
		return array(
			// The field only means anything in the request that creates the attachment.
			'not an upload' => array( 'query-attachments', 'wcb_sponsor', 'editor' ),

			// A PDF on anything else is somebody's ordinary media.
			'not a sponsor' => array( 'upload-attachment', 'post', 'editor' ),

			// `upload_files` is an Author capability; editing somebody else's sponsor is not.
			'cannot edit the sponsor' => array( 'upload-attachment', 'wcb_sponsor', 'volunteer' ),
		);
	}

	/**
	 * A rename that leaves a file behind says so, since the name it kept may already be out there.
	 */
	public function test_a_rename_that_leaves_a_file_behind_is_logged() {
		$sponsor_id = $this->create_sponsor();
		$uploads    = wp_upload_dir();
		$directory  = trailingslashit( $uploads['path'] );

		file_put_contents( $directory . 'stubborn.pdf', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture on the local disk.
		file_put_contents( $directory . 'unrelated-150x150.jpg', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture on the local disk.

		$agreement_id = self::factory()->attachment->create_object( array(
			'file'           => $directory . 'stubborn.pdf',
			'post_parent'    => 0,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		update_attached_file( $agreement_id, $directory . 'stubborn.pdf' );

		// A size Core would never have named this way, standing in for one that can't be derived.
		$metadata = array(
			'file'  => _wp_relative_upload_path( $directory . 'stubborn.pdf' ),
			'sizes' => array( 'thumbnail' => array( 'file' => 'unrelated-150x150.jpg' ) ),
		);

		wp_update_attachment_metadata( $agreement_id, $metadata );

		$logged = $this->capture_log( function () use ( $sponsor_id, $agreement_id ) {
			update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $agreement_id );
		} );

		try {
			$this->assertStringContainsString( 'sponsor_agreement_files_not_renamed', $logged );

			/*
			 * The migration can't see this one -- it is already `private`, which is the only thing that
			 * query looks for -- so joining the rename work list is what keeps it findable.
			 */
			$this->assertSame( '1', get_post_meta( $agreement_id, NEEDS_RENAME_META_KEY, true ) );

			// What could move still moved.
			$this->assertMatchesRegularExpression(
				'/^stubborn-[A-Za-z0-9]{16}\.pdf$/',
				wp_basename( get_attached_file( $agreement_id ) )
			);
			$this->assertFileExists( $directory . 'unrelated-150x150.jpg' );
		} finally {
			$this->delete_files_on_disk( $directory, 'stubborn*' );
			$this->delete_files_on_disk( $directory, 'unrelated-*' );
		}
	}

	/**
	 * A rename that moves everything says nothing.
	 */
	public function test_a_clean_rename_is_not_logged() {
		$sponsor_id = $this->create_sponsor();
		$uploads    = wp_upload_dir();
		$directory  = trailingslashit( $uploads['path'] );

		file_put_contents( $directory . 'quiet.pdf', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture on the local disk.

		$agreement_id = self::factory()->attachment->create_object( array(
			'file'           => $directory . 'quiet.pdf',
			'post_parent'    => 0,
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		) );

		update_attached_file( $agreement_id, $directory . 'quiet.pdf' );

		$logged = $this->capture_log( function () use ( $sponsor_id, $agreement_id ) {
			update_post_meta( $sponsor_id, '_wcpt_sponsor_agreement', $agreement_id );
		} );

		try {
			$this->assertStringNotContainsString( 'sponsor_agreement_files_not_renamed', $logged );
			$this->assertSame( '', get_post_meta( $agreement_id, NEEDS_RENAME_META_KEY, true ) );
		} finally {
			$this->delete_files_on_disk( $directory, 'quiet*' );
		}
	}
}
