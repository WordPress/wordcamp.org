<?php

namespace WordCamp\WCPT\Tests;
use WP_UnitTestCase;
use WordPress_Community\Applications\WordCamp_Application;

defined( 'WPINC' ) || die();

/**
 * @group wcpt
 * @group wcpt-application
 */
class Test_WordCamp_Application extends WP_UnitTestCase {
	/**
	 * The submitted location has to reach `post_title` as text, not as markup.
	 *
	 * `sanitize_text_field()` does not manage that on its own: it is built on `strip_tags()`, which
	 * only treats `<` as a tag opener when a letter, `/`, `!` or `?` follows it, while the
	 * `wp_filter_kses()` that core puts on `title_save_pre` also accepts whitespace there and
	 * rebuilds the element. See `wcorg_sanitize_plain_text()`.
	 *
	 * @covers WordPress_Community\Applications\WordCamp_Application::create_post
	 */
	public function test_create_post_does_not_store_markup_in_the_title() {
		// A user without `unfiltered_html`, so `title_save_pre` filters through kses.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$application = new WordCamp_Application();
		$data        = $application->get_default_application_values();

		$data['q_1079103_wordcamp_location'] = 'Portland< code >" data-x="< /code >Oregon';
		$data['q_4236565_wporg_username']    = 'nobody-with-this-name';

		$post_id = $application->create_post( $data );

		$this->assertNotWPError( $post_id );

		$title = get_post( $post_id )->post_title;

		$this->assertStringNotContainsString( '<code>', $title );
		$this->assertFalse(
			( new \WP_HTML_Tag_Processor( $title ) )->next_tag(),
			"Stored title read back as markup: $title"
		);
	}
}
