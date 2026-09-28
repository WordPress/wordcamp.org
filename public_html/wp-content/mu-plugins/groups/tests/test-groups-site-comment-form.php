<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Regression coverage for the theme's compact discussion composer.
 *
 * `single-event.html` and `single.html` render the same "Discussion" section
 * and share one block of comment styling, including a rule that hides
 * `#reply-title` — core's heading wrapper, which is also where core nests
 * `#cancel-comment-reply-link`. These tests pin both post types to the
 * compact defaults (which empty that wrapper, see
 * `WordCamp\Groups\Site\compact_comment_form_defaults()`) and pin the
 * reply/cancel pair to a form the reader can actually get back out of.
 *
 * @group groups
 */
class Test_Groups_Site_Comment_Form extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	/**
	 * Load the theme's comment-form filters; `groups-site` isn't the active
	 * theme in this suite, so nothing else pulls them in.
	 *
	 * @param \WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		require_once self::THEME_DIR . 'functions.php';
	}

	/**
	 * Threading has to be on for core to print a Cancel reply link at all,
	 * and the composer needs a logged-in author because the groups network
	 * forces `comment_registration`.
	 *
	 * The theme's own filters are re-added here because `WP_UnitTestCase`
	 * snapshots the hook table once per process and restores that snapshot
	 * after every test — anything `wpSetUpBeforeClass()` registers survives
	 * only the class's first test. `add_filter()` is a no-op for a callback
	 * already at the same priority.
	 */
	protected function setUp(): void {
		parent::setUp();

		add_filter( 'comment_form_defaults', 'WordCamp\\Groups\\Site\\compact_comment_form_defaults' );
		add_filter( 'comment_reply_link_args', 'WordCamp\\Groups\\Site\\compact_comment_reply_link_args' );

		update_option( 'thread_comments', 1 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
	}

	/**
	 * Create a published, comment-open post of the given type.
	 *
	 * @param string $post_type The post type to create.
	 */
	private function create_commentable_post( string $post_type ): int {
		return self::factory()->post->create(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'post_title'     => 'Something worth discussing',
				'comment_status' => 'open',
			)
		);
	}

	/**
	 * Visit a post's singular view.
	 *
	 * Addressed by query string rather than by permalink: this fixture has
	 * no flushed rewrite rules, so a pretty permalink resolves to a 404 and
	 * `is_singular()` — which the composer's guard reads — stays false.
	 *
	 * @param int   $post_id   The post to visit.
	 * @param array $query_args Extra query args, e.g. `replytocom`.
	 */
	private function go_to_singular( int $post_id, array $query_args = array() ): void {
		$this->go_to(
			add_query_arg(
				array_merge(
					array(
						'p'         => $post_id,
						'post_type' => get_post_type( $post_id ),
					),
					$query_args
				),
				home_url()
			)
		);
	}

	/**
	 * Render `core/post-comments-form` the way its template does — through
	 * the block, with the post context the template's `core/comments` wrapper
	 * would have supplied.
	 *
	 * @param int    $post_id   The post being discussed.
	 * @param string $post_type That post's type.
	 */
	private function render_comment_form( int $post_id, string $post_type ): string {
		$block = new \WP_Block(
			array(
				'blockName'   => 'core/post-comments-form',
				'attrs'       => array(),
				'innerBlocks' => array(),
			),
			array(
				'postId'   => $post_id,
				'postType' => $post_type,
			)
		);

		return $block->render();
	}

	/**
	 * Both singulars that render a discussion get the same composer.
	 *
	 * @dataProvider data_compact_composer_post_types
	 *
	 * @param string $post_type The post type under test.
	 */
	public function test_compact_composer_applies_to_discussion_singulars( string $post_type ) {
		$post_id = $this->create_commentable_post( $post_type );

		$this->go_to_singular( $post_id );

		$output = $this->render_comment_form( $post_id, $post_type );

		// The placeholder is the composer's only visible prompt, so it has to
		// survive alongside a real, screen-reader-only label.
		$this->assertStringContainsString( 'placeholder="Add a comment&hellip;"', $output );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="comment">', $output );
		$this->assertStringContainsString( 'id="comment"', $output );

		// The heading wrapper is what the stylesheet hides; if core is still
		// printing it, it's taking the Cancel reply link down with it.
		$this->assertStringNotContainsString( 'id="reply-title"', $output );
		$this->assertStringNotContainsString( 'Leave a Reply', $output );
		$this->assertStringNotContainsString( 'logged-in-as', $output );
	}

	/**
	 * Data provider: the post types that use the compact composer.
	 */
	public function data_compact_composer_post_types(): array {
		return array(
			'group news post' => array( 'post' ),
			'event'           => array( 'gatherpress_event' ),
		);
	}

	/**
	 * Reply → Cancel. `?replytocom=` is the server-side half of what
	 * `comment-reply.js` does in the browser: the form is aimed at a parent
	 * comment, and the way back out has to be visible and outside the hidden
	 * heading.
	 *
	 * @dataProvider data_compact_composer_post_types
	 *
	 * @param string $post_type The post type under test.
	 */
	public function test_replying_leaves_a_visible_cancel_reply_link( string $post_type ) {
		$post_id = $this->create_commentable_post( $post_type );

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => 'The comment being replied to.',
			)
		);

		$this->go_to_singular( $post_id, array( 'replytocom' => $comment_id ) );

		$output = $this->render_comment_form( $post_id, $post_type );

		$this->assertStringContainsString( 'id="cancel-comment-reply-link"', $output );

		// Core adds the inline style only while the form sits at the bottom
		// of the page; in reply mode the link is on screen.
		$this->assertDoesNotMatchRegularExpression(
			'/id="cancel-comment-reply-link"[^>]*style="display:none;"/',
			$output
		);

		// Not nested inside the heading the stylesheet hides.
		$this->assertStringNotContainsString( 'id="reply-title"', $output );

		// And the field the Cancel link resets is aimed at the right parent.
		// Core prints these hidden inputs with single quotes.
		$this->assertMatchesRegularExpression(
			'/name=[\'"]comment_parent[\'"][^>]*value=[\'"]' . $comment_id . '[\'"]/',
			$output
		);
	}

	/**
	 * The composer is scoped to the two singulars that render a discussion.
	 * Everything else keeps core's own form — including the `#reply-title`
	 * wrapper, which is fine there because no other template in this theme
	 * renders a comment form for the stylesheet to hide it in.
	 */
	public function test_other_post_types_keep_cores_comment_form() {
		$page_id = $this->create_commentable_post( 'page' );

		$this->go_to_singular( $page_id );

		$defaults = apply_filters( 'comment_form_defaults', array( 'title_reply_before' => '<h3 id="reply-title" class="comment-reply-title">' ) );

		$this->assertSame( '<h3 id="reply-title" class="comment-reply-title">', $defaults['title_reply_before'] );
	}
}
