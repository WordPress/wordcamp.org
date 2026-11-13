<?php

namespace WordCamp\Script_Translations\Tests;
use WP_UnitTestCase;
use function WordCamp\Script_Translations\{ appears_in_source, get_script_path };

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group script-translations
 */
class Test_Script_Translations extends WP_UnitTestCase {
	const HANDLE  = 'wcorg-script-translations-test';
	const FIXTURE = __DIR__ . '/fixtures/script-translations';

	/** @var string Copy of the fixture script under `WP_CONTENT_DIR`, where scripts are resolved. */
	protected $script_dir;

	/**
	 * Copy the fixture script into `wp-content` and load the fixture translations.
	 */
	public function set_up() {
		parent::set_up();

		$this->script_dir = WP_CONTENT_DIR . '/wcorg-script-translations-test';
		wp_mkdir_p( $this->script_dir );
		copy( self::FIXTURE . '/view.js', $this->script_dir . '/view.js' );

		wp_register_script( self::HANDLE, content_url( 'wcorg-script-translations-test/view.js' ), array(), '1', true );
		load_textdomain( 'wordcamporg', self::FIXTURE . '/wordcamporg-es_ES.mo' );
	}

	/**
	 * Remove the copied script, the handle, and the translations.
	 */
	public function tear_down() {
		wp_deregister_script( self::HANDLE );
		unload_textdomain( 'wordcamporg' );
		wp_delete_file( $this->script_dir . '/view.js' );
		rmdir( $this->script_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

		parent::tear_down();
	}

	/**
	 * Decode the translations Core would print for the test script.
	 */
	protected function get_locale_data(): array {
		$json = load_script_textdomain( self::HANDLE, 'wordcamporg' );

		$this->assertIsString( $json, 'The script should get translations.' );

		return json_decode( $json, true )['locale_data']['messages'];
	}

	/**
	 * @covers WordCamp\Script_Translations\load_from_mofile
	 */
	public function test_script_gets_translations_for_its_strings() {
		$messages = $this->get_locale_data();

		$this->assertSame( array( 'Enviar mensaje' ), $messages['Send message'] );
		$this->assertSame( array( 'Cancelar' ), $messages["abort current action\4Cancel"] );
		$this->assertSame( array( 'Está lleno' ), $messages['It’s full'] );
		$this->assertSame( array( 'Regístrate →' ), $messages['Sign up →'], 'Escaped non-ASCII literals should match.' );
		$this->assertSame( array( 'No te lo pierdas' ), $messages["Don't miss it"], 'Single-quoted literals should match.' );
		$this->assertArrayNotHasKey( 'Not in the script', $messages, 'Strings the script does not use should be left out.' );
	}

	/**
	 * @covers WordCamp\Script_Translations\build_json
	 */
	public function test_plurals_are_keyed_by_singular() {
		$messages = $this->get_locale_data();

		$this->assertSame( array( '%d asistente', '%d asistentes' ), $messages['%d attendee'] );
		$this->assertSame( array( '%d evento', '%d eventos' ), $messages['%d event'] );
		$this->assertSame( 'nplurals=2; plural=n != 1;', $messages['']['plural-forms'] );
		$this->assertSame( 'es_ES', $messages['']['lang'] );
	}

	/**
	 * @covers WordCamp\Script_Translations\load_from_mofile
	 */
	public function test_nothing_without_loaded_translations() {
		unload_textdomain( 'wordcamporg' );

		$this->assertFalse( load_script_textdomain( self::HANDLE, 'wordcamporg' ) );
	}

	/**
	 * @covers WordCamp\Script_Translations\load_from_mofile
	 */
	public function test_other_domains_are_untouched() {
		$this->assertFalse( load_script_textdomain( self::HANDLE, 'default' ) );
	}

	/**
	 * @covers WordCamp\Script_Translations\load_from_mofile
	 */
	public function test_nothing_when_no_strings_match() {
		file_put_contents( $this->script_dir . '/view.js', 'window.nothingToTranslate = true;' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertFalse( load_script_textdomain( self::HANDLE, 'wordcamporg' ) );
	}

	/**
	 * @covers WordCamp\Script_Translations\get_script_path
	 */
	public function test_script_path_stays_inside_wp_content() {
		$this->assertSame( realpath( $this->script_dir . '/view.js' ), get_script_path( self::HANDLE ) );

		wp_register_script( 'wcorg-remote', 'https://cdn.example.org/wp-content/wcorg-script-translations-test/view.js', array(), '1', true );
		wp_register_script( 'wcorg-traversal', content_url( '../wp-includes/js/jquery/jquery.js' ), array(), '1', true );
		wp_register_script( 'wcorg-missing', content_url( 'wcorg-script-translations-test/missing.js' ), array(), '1', true );

		$this->assertSame( '', get_script_path( 'wcorg-remote' ), 'Scripts on another host should be ignored.' );
		$this->assertSame( '', get_script_path( 'wcorg-traversal' ), 'Paths outside wp-content should be ignored.' );
		$this->assertSame( '', get_script_path( 'wcorg-missing' ) );
		$this->assertSame( '', get_script_path( 'not-registered' ) );

		wp_deregister_script( 'wcorg-remote' );
		wp_deregister_script( 'wcorg-traversal' );
		wp_deregister_script( 'wcorg-missing' );
	}

	/**
	 * @covers WordCamp\Script_Translations\appears_in_source
	 */
	public function test_appears_in_source_needs_a_whole_literal() {
		$this->assertTrue( appears_in_source( 'Send message', '__("Send message","wordcamporg")' ) );
		$this->assertFalse( appears_in_source( 'Send', '__("Send message","wordcamporg")' ), 'A prefix of a literal is not a match.' );
		$this->assertFalse( appears_in_source( '', '""' ) );
	}
}
