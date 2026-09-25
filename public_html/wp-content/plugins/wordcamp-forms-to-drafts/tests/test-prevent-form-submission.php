<?php

namespace WordCamp\Forms_To_Drafts\Tests;

use WP_UnitTestCase;
use WordCamp_Forms_To_Drafts;
use Automattic\Jetpack\Forms\ContactForm\Contact_Form;

defined( 'WPINC' ) || die();

/**
 * Tests for the login gate in `WordCamp_Forms_To_Drafts::prevent_form_submission()`.
 *
 * The gate keys on the form source Jetpack has authenticated in the signed JWT,
 * not on the request-supplied `contact-form-id`. These cover that a gated form
 * (`call-for-speakers`) stays behind the login requirement for a logged-out
 * visitor, while ungated forms and logged-in visitors are left alone.
 *
 * @group wordcamp-forms-to-drafts
 */
class Test_Prevent_Form_Submission extends WP_UnitTestCase {
	/**
	 * The plugin instance under test.
	 *
	 * @var WordCamp_Forms_To_Drafts
	 */
	protected $plugin;

	/**
	 * ID of a page holding the login-gated Call for Speakers form.
	 *
	 * @var int
	 */
	protected $gated_page_id;

	/**
	 * ID of a page holding an ungated Call for Volunteers form.
	 *
	 * @var int
	 */
	protected $ungated_page_id;

	/**
	 * Set up a gated and an ungated form page, logged out by default.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( Contact_Form::class ) ) {
			$this->markTestSkipped( 'Jetpack contact-form classes are not loaded.' );
		}

		$this->plugin = $GLOBALS['wordcamp_forms_to_drafts'];

		$this->gated_page_id   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->ungated_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		update_post_meta( $this->gated_page_id, 'wcfd-key', 'call-for-speakers' );
		update_post_meta( $this->ungated_page_id, 'wcfd-key', 'call-for-volunteers' );

		wp_set_current_user( 0 );
	}

	/**
	 * Reset the request superglobal and queried post touched by the gate.
	 */
	public function tear_down() {
		unset(
			$_POST['contact-form-id'],
			$_POST['jetpack_contact_form_jwt'],
			$GLOBALS['post']
		);

		parent::tear_down();
	}

	/**
	 * Build a genuine signed JWT whose source is the given post.
	 *
	 * Jetpack derives the source from the post in scope while the form renders,
	 * so setting the global post is enough to bind the token to it.
	 *
	 * @param int $source_id Post the form lives on.
	 *
	 * @return string The signed JWT.
	 */
	protected function jwt_for_source( $source_id ) {
		global $post;

		$post = get_post( $source_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$form = new Contact_Form( array(), '[contact-field label="Name" type="name"/]' );

		return $form->get_jwt();
	}

	/**
	 * A logged-out visitor cannot submit the gated form. The posted
	 * `contact-form-id` does not influence the decision.
	 */
	public function test_blocks_gated_form_for_logged_out_visitor() {
		$_POST['jetpack_contact_form_jwt'] = $this->jwt_for_source( $this->gated_page_id );
		$_POST['contact-form-id']          = '0';

		$result = $this->plugin->prevent_form_submission( false );

		$this->assertWPError( $result );
		$this->assertSame( 'spam', $result->get_error_code() );
	}

	/**
	 * The gate ignores a posted `contact-form-id` that points somewhere other
	 * than the signed source; the signed source still decides.
	 */
	public function test_blocks_gated_form_regardless_of_posted_id() {
		$_POST['jetpack_contact_form_jwt'] = $this->jwt_for_source( $this->gated_page_id );
		$_POST['contact-form-id']          = (string) $this->ungated_page_id;

		$result = $this->plugin->prevent_form_submission( false );

		$this->assertWPError( $result );
	}

	/**
	 * An ungated form is left alone for a logged-out visitor.
	 */
	public function test_allows_ungated_form_for_logged_out_visitor() {
		$_POST['jetpack_contact_form_jwt'] = $this->jwt_for_source( $this->ungated_page_id );
		$_POST['contact-form-id']          = '0';

		$this->assertFalse( $this->plugin->prevent_form_submission( false ) );
	}

	/**
	 * A logged-in visitor may submit the gated form.
	 */
	public function test_allows_gated_form_for_logged_in_visitor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_POST['jetpack_contact_form_jwt'] = $this->jwt_for_source( $this->gated_page_id );
		$_POST['contact-form-id']          = '0';

		$this->assertFalse( $this->plugin->prevent_form_submission( false ) );
	}

	/**
	 * A submission already flagged as spam is passed through untouched.
	 */
	public function test_passes_through_when_already_spam() {
		$_POST['jetpack_contact_form_jwt'] = $this->jwt_for_source( $this->gated_page_id );

		$this->assertTrue( $this->plugin->prevent_form_submission( true ) );
	}

	/**
	 * Legacy submissions without a JWT still gate on the posted `contact-form-id`,
	 * which Jetpack validates against the current post before processing.
	 */
	public function test_falls_back_to_posted_id_without_jwt() {
		$_POST['contact-form-id'] = (string) $this->gated_page_id;

		$result = $this->plugin->prevent_form_submission( false );

		$this->assertWPError( $result );
	}

	/**
	 * An unverifiable token resolves to no known form, so the gate does not flag
	 * it. Jetpack rejects the same token before storing anything.
	 */
	public function test_allows_unverifiable_jwt() {
		$_POST['jetpack_contact_form_jwt'] = 'not.a.valid-token';
		$_POST['contact-form-id']          = '0';

		$this->assertFalse( $this->plugin->prevent_form_submission( false ) );
	}
}
