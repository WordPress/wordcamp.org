<?php

namespace WordCamp\Sponsor_Agreements\Tests;

use WP_UnitTestCase, WP_UnitTest_Factory, WP_REST_Request, WP_REST_Server;

use function WordCamp\Sponsor_Agreements\is_agreement;
use function WordCamp\Sponsor_Agreements\make_agreement_private;
use function WordCamp\Sponsor_Agreements\add_csprn_to_filename;
use function WordCamp\Sponsor_Agreements\rename_agreement_file;

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
}
