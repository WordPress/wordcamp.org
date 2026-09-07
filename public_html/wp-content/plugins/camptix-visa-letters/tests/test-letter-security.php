<?php
/**
 * Tests for how visa letter PDFs are stored and served.
 *
 * A letter contains a passport number, a date of birth and a home address, so the
 * storage location and the download gate are the feature's security boundary.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Security
 */
class Test_CampTix_Visa_Letters_Security extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;
	use Visa_Letter_Fixtures;

	/**
	 * Directory tree created to simulate the ms-files layout, removed on teardown.
	 *
	 * @var string
	 */
	protected $simulated_uploads = '';

	/**
	 * The letter template calls get_wordcamp_post(), which switches to the root blog.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::create_wordcamp_root_blog( $factory );
	}

	/**
	 * Tears down the shared fixtures created in wpSetUpBeforeClass().
	 */
	public static function wpTearDownAfterClass() {
		self::delete_wordcamp_root_blog();
	}

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_up_visa_fixtures();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		if ( $this->simulated_uploads && is_dir( $this->simulated_uploads ) ) {
			$this->remove_tree( $this->simulated_uploads );
		}

		$this->tear_down_visa_fixtures();
		parent::tear_down();
	}

	/**
	 * Recursively delete a directory created by a test.
	 *
	 * @param string $dir Directory path.
	 */
	protected function remove_tree( $dir ) {
		foreach ( glob( $dir . '/{,.}*', GLOB_BRACE ) as $path ) {
			if ( in_array( basename( $path ), array( '.', '..' ), true ) ) {
				continue;
			}

			is_dir( $path ) ? $this->remove_tree( $path ) : wp_delete_file( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup; WP_Filesystem is not bootstrapped here.
		rmdir( $dir );
	}

	/**
	 * Point wp_upload_dir() at an ms-files style basedir, as wordcamp.org subsites use.
	 *
	 * @return string The simulated served basedir.
	 */
	protected function simulate_ms_files_uploads() {
		$this->simulated_uploads = get_temp_dir() . 'ctx-vl-msfiles-' . wp_generate_password( 6, false, false );
		$basedir                 = $this->simulated_uploads . '/blogs.dir/99/files';

		wp_mkdir_p( $basedir );

		add_filter(
			'upload_dir',
			function ( $dirs ) use ( $basedir ) {
				$dirs['basedir'] = $basedir;
				$dirs['baseurl'] = 'https://example.org/files';

				return $dirs;
			},
			99
		);

		return $basedir;
	}

	/**
	 * On the ms-files layout the letters directory must be a sibling of the served path.
	 *
	 * Every subsite's uploads are served through the `/files/` rewrite, so a letters
	 * directory *inside* that basedir would be publicly fetchable.
	 */
	public function test_letters_directory_sits_outside_the_served_uploads_path() {
		$basedir     = $this->simulate_ms_files_uploads();
		$letters_dir = ctx_vl_get_letters_dir();

		$this->assertNotFalse( $letters_dir );
		$this->assertStringStartsNotWith(
			$basedir,
			$letters_dir,
			'The letters directory must not be inside the web-served uploads path.'
		);
		$this->assertSame( dirname( $basedir ) . '/camptix-visa-letters', $letters_dir );
	}

	/**
	 * A recorded PDF lands in the letters directory, not in the served uploads path.
	 */
	public function test_recorded_pdf_is_not_written_to_the_served_uploads_path() {
		$basedir = $this->simulate_ms_files_uploads();

		list( , $letter_id ) = $this->make_paid_letter( 'storage' );
		$this->assertNotEmpty( $letter_id );

		$filename = $this->attach_stub_pdf( $letter_id );

		$this->assertFileExists( ctx_vl_get_letters_dir() . '/' . $filename );
		$this->assertFileDoesNotExist( $basedir . '/camptix-visa-letters/' . $filename );
	}

	/**
	 * The letter URL is the authenticated endpoint, never a direct file URL.
	 */
	public function test_letter_url_is_the_authenticated_endpoint() {
		list( , $letter_id ) = $this->make_paid_letter( 'url' );
		$this->attach_stub_pdf( $letter_id );

		$url = ctx_vl_get_letter_url( $letter_id );

		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=ctx_vl_download', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
		$this->assertStringNotContainsString( '.pdf', $url );
	}

	/**
	 * A letter with no document has no URL at all.
	 */
	public function test_letter_without_a_document_has_no_url() {
		list( , $letter_id ) = $this->make_paid_letter( 'nodoc' );

		$this->assertSame( '', get_post_meta( $letter_id, 'visa_letter_document', true ) );
		$this->assertFalse( ctx_vl_get_letter_url( $letter_id ) );
	}

	/**
	 * The download endpoint refuses a request without a valid nonce.
	 */
	public function test_download_endpoint_requires_a_nonce() {
		list( , $letter_id ) = $this->make_paid_letter( 'nononce' );
		$this->attach_stub_pdf( $letter_id );

		$_GET['letter_id'] = $letter_id;

		$this->expectException( 'WPDieException' );
		ctx_vl_download_letter();
	}

	/**
	 * With a valid nonce, the endpoint still requires the capability.
	 */
	public function test_download_endpoint_requires_the_capability() {
		list( , $letter_id ) = $this->make_paid_letter( 'nocap' );
		$this->attach_stub_pdf( $letter_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_GET['letter_id']     = $letter_id;
		$_REQUEST['letter_id'] = $letter_id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'ctx_vl_download_' . $letter_id );

		$this->assertFalse( current_user_can( 'edit_post', $letter_id ) );

		$this->expectException( 'WPDieException' );
		ctx_vl_download_letter();
	}

	/**
	 * A pre-1.1.0 PDF in the served uploads dir is moved out the first time it is read.
	 *
	 * Letters issued by the prototype were publicly fetchable. Rather than needing a
	 * migration script, the getter relocates them on access.
	 */
	public function test_legacy_pdf_is_migrated_out_of_the_served_uploads_path() {
		$basedir = $this->simulate_ms_files_uploads();

		list( , $letter_id ) = $this->make_paid_letter( 'legacy' );

		$filename    = 'legacy-letter.pdf';
		$legacy_dir  = $basedir . '/camptix-visa-letters';
		$legacy_path = $legacy_dir . '/' . $filename;

		wp_mkdir_p( $legacy_dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture, WP_Filesystem is not bootstrapped here.
		file_put_contents( $legacy_path, '%PDF-1.4 legacy' );
		update_post_meta( $letter_id, 'visa_letter_document', $filename );

		$this->assertFileExists( $legacy_path );

		$resolved = ctx_vl_get_letter( $letter_id );

		$this->assertSame( ctx_vl_get_letters_dir() . '/' . $filename, $resolved );
		$this->assertFileExists( $resolved );
		$this->assertFileDoesNotExist( $legacy_path, 'The publicly served copy must be gone.' );
	}

	/**
	 * Every plugin directory carries an index.php, so a loose directory listing shows nothing.
	 */
	public function test_every_plugin_directory_is_index_hardened() {
		$plugin_dir = dirname( __DIR__ );
		$missing    = array();

		foreach ( array( '', '/includes', '/includes/views', '/admin', '/admin/js', '/admin/css', '/admin/images' ) as $dir ) {
			if ( ! file_exists( $plugin_dir . $dir . '/index.php' ) ) {
				$missing[] = $dir ? $dir : '/';
			}
		}

		$this->assertSame( array(), $missing, 'Missing index.php in: ' . implode( ', ', $missing ) );
	}

	/**
	 * An unusable uploads directory is a clean no-op, not a warning storm.
	 */
	public function test_unusable_uploads_directory_records_no_document() {
		list( , $letter_id ) = $this->make_paid_letter( 'nobasedir' );

		add_filter(
			'upload_dir',
			function ( $dirs ) {
				$dirs['basedir'] = '';
				$dirs['baseurl'] = '';

				return $dirs;
			},
			99
		);

		$this->assertFalse( ctx_vl_get_letters_dir() );
		$this->assertFalse( CampTix_Addon_Visa_Letters::create_letter_document( $letter_id ) );
		$this->assertSame( '', get_post_meta( $letter_id, 'visa_letter_document', true ) );
	}
}
