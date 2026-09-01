<?php

namespace WordCamp\Budgets\Tests;

use WP_UnitTestCase, WP_Query;
use WCP_Payment_Request;
use WordCamp\Budgets\Reimbursement_Requests;

defined( 'WPINC' ) || die();

/**
 * Who can see the files attached to a budget request.
 *
 * Reimbursement and vendor payment requests carry attachments with bank details and invoices. `privacy.php`
 * scopes those to the organizer who uploaded them, the organizer who filed the request, and network admins.
 * These pin that boundary across the query shapes it has to survive.
 *
 * @group budgets
 */
class Test_Privacy extends WP_UnitTestCase {
	/** @var int The organizer who filed the requests and uploaded their attachments. */
	protected static $organizer_a;

	/** @var int Another organizer on the same site: `upload_files`, but not `manage_options`. */
	protected static $organizer_b;

	/** @var int A network admin, who reviews requests and so sees every file. */
	protected static $network_admin;

	/**
	 * @var int A volunteer who can file a request but can't edit anybody else's posts.
	 *
	 * The budget post types gate creation on `publish_posts`, which an Author has, while `edit_others_posts`,
	 * which decides who may edit somebody else's upload, is an Editor capability. This is the gap between them.
	 */
	protected static $volunteer;

	/** @var int Organizer A's vendor payment request. */
	protected static $payment_request_id;

	/** @var int The attachment on Organizer A's vendor payment request. */
	protected static $payment_file_id;

	/** @var int The attachment on Organizer A's reimbursement request. */
	protected static $reimbursement_file_id;

	/** @var int An ordinary attachment, unrelated to any budget request. */
	protected static $public_file_id;

	/** @var int An ordinary attachment on an ordinary post, which is most of the media on the network. */
	protected static $blog_post_file_id;

	/**
	 * Create the users and the posts they own.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$organizer_a   = $factory->user->create( array( 'role' => 'editor' ) );
		self::$organizer_b   = $factory->user->create( array( 'role' => 'editor' ) );
		self::$network_admin = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$volunteer     = $factory->user->create( array( 'role' => 'author' ) );

		grant_super_admin( self::$network_admin );

		/*
		 * The budget CPTs log an entry attributed to the current user whenever a request is saved, and read that
		 * user's ID unconditionally, so create the fixtures as the person who'd really be filing them.
		 */
		wp_set_current_user( self::$organizer_a );

		self::$payment_request_id = $factory->post->create( array(
			'post_type'   => WCP_Payment_Request::POST_TYPE,
			'post_author' => self::$organizer_a,
			'post_status' => 'draft',
		) );

		$reimbursement_id = $factory->post->create( array(
			'post_type'   => Reimbursement_Requests\POST_TYPE,
			'post_author' => self::$organizer_a,
			'post_status' => 'draft',
		) );

		self::$payment_file_id       = self::create_file( 'invoice-a1b2c3d4e5f6g7h8.pdf', self::$payment_request_id, self::$organizer_a );
		self::$reimbursement_file_id = self::create_file( 'receipt-h8g7f6e5d4c3b2a1.pdf', $reimbursement_id, self::$organizer_a );
		self::$public_file_id        = self::create_file( 'sponsor-logo.png', 0, self::$organizer_a );

		$blog_post               = $factory->post->create( array( 'post_author' => self::$organizer_a ) );
		self::$blog_post_file_id = self::create_file( 'header.png', $blog_post, self::$organizer_a );

		wp_set_current_user( 0 );
	}

	/**
	 * Attach a file to a post.
	 *
	 * @param string $filename
	 * @param int    $parent_id
	 * @param int    $author_id
	 *
	 * @return int
	 */
	protected static function create_file( $filename, $parent_id, $author_id ) {
		return self::factory()->attachment->create_object( array(
			'file'           => $filename,
			'post_parent'    => $parent_id,
			'post_author'    => $author_id,
			'post_mime_type' => 'application/pdf',
		) );
	}

	/**
	 * Collect the attachment IDs the current user gets back from a query.
	 *
	 * @param array $args Extra `get_posts()` arguments.
	 *
	 * @return int[]
	 */
	protected function get_visible_attachment_ids( array $args = array() ) {
		$attachments = get_posts( array_merge(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => -1,
			),
			$args
		) );

		// A `fields => ids` query returns bare IDs rather than posts.
		if ( $attachments && ! is_object( reset( $attachments ) ) ) {
			return array_map( 'intval', $attachments );
		}

		return array_map( 'intval', wp_list_pluck( $attachments, 'ID' ) );
	}

	/**
	 * The query shapes the guard has to survive, and why each one is here.
	 *
	 * @return array
	 */
	public function data_attachment_queries() {
		return array(
			// `get_posts()` defaults to `suppress_filters => true`, which skips every query filter.
			'suppressed filters'  => array( array() ),

			// What the Media Library and the REST endpoints ask for.
			'filters applied'     => array( array( 'suppress_filters' => false ) ),

			// Returns before `the_posts`, and an ID is one `wp_get_attachment_url()` from a URL.
			'bare IDs'            => array( array( 'fields' => 'ids' ) ),
		);
	}

	/**
	 * Another organizer's payment files are absent, and ordinary media is still there.
	 *
	 * @dataProvider data_attachment_queries
	 *
	 * @param array $args
	 */
	public function test_queries_hide_others_payment_files( array $args ) {
		wp_set_current_user( self::$organizer_b );

		$visible = $this->get_visible_attachment_ids( $args );

		$this->assertNotContains( self::$payment_file_id, $visible );
		$this->assertNotContains( self::$reimbursement_file_id, $visible );
		$this->assertContains( self::$public_file_id, $visible );
	}

	/**
	 * `get_children()` asks for `post_type => 'any'`, which includes attachments.
	 */
	public function test_any_post_type_query_hides_others_payment_files() {
		wp_set_current_user( self::$organizer_b );

		$children = get_children( array(
			'post_parent' => self::$payment_request_id,
			'post_type'   => 'any',
			'post_status' => 'any',
		) );

		$this->assertArrayNotHasKey( self::$payment_file_id, $children );
	}

	/**
	 * An attachment permalink names no post type, and is the one case where `WP_Query` narrows an unnamed post
	 * type to `attachment`. It's also the only such case the guard opts into, so pin it.
	 */
	public function test_attachment_permalink_hides_others_payment_files() {
		wp_set_current_user( self::$organizer_b );

		$query = new WP_Query( array( 'attachment_id' => self::$payment_file_id ) );

		$this->assertCount( 0, $query->posts );
	}

	/**
	 * `post_type` is what decides whether a suppressed query needs the guards, and a `pre_get_posts` callback is
	 * free to set it after the fact. Running last on that hook is what catches this shape.
	 */
	public function test_guard_applies_when_a_later_callback_names_the_post_type() {
		wp_set_current_user( self::$organizer_b );

		$applied = false;

		// Only the outer query, so the lookups the guards run themselves are left alone.
		$name_attachments = function ( $wp_query ) use ( &$applied ) {
			if ( $applied ) {
				return;
			}

			$applied = true;

			$wp_query->set( 'post_type', 'attachment' );
		};

		add_action( 'pre_get_posts', $name_attachments, 100 );

		try {
			$visible = $this->get_visible_attachment_ids( array( 'post_type' => 'post' ) );
		} finally {
			remove_action( 'pre_get_posts', $name_attachments, 100 );
		}

		$this->assertTrue( $applied, 'The callback under test never ran.' );
		$this->assertContains( self::$public_file_id, $visible );
		$this->assertNotContains( self::$payment_file_id, $visible );
	}

	/**
	 * The flip side: an ordinary front-end query names no post type either, and `WP_Query` narrows those to
	 * `post`. The guard has to stay out of them, or it lands on nearly every query on the site.
	 */
	public function test_guard_skips_queries_that_cannot_return_attachments() {
		wp_set_current_user( self::$organizer_b );

		$home_shaped = new WP_Query( array( 'posts_per_page' => 10 ) );

		$this->assertStringNotContainsString( 'budget_request', $home_shaped->request );
	}

	/**
	 * `WP_Query` caches result sets, keyed partly on the assembled SQL. The guard puts the current user's ID into
	 * that SQL, which is what keeps one organizer's cached result from being served to another. Run the permissive
	 * user first so a wrong key would hand their result set to the restricted one.
	 */
	public function test_query_cache_is_not_shared_between_users() {
		wp_set_current_user( self::$network_admin );
		$this->assertContains( self::$payment_file_id, $this->get_visible_attachment_ids() );

		wp_set_current_user( self::$organizer_b );
		$this->assertNotContains( self::$payment_file_id, $this->get_visible_attachment_ids() );

		// And the other way around, in case only one direction is keyed correctly.
		wp_set_current_user( self::$organizer_a );
		$this->assertContains( self::$payment_file_id, $this->get_visible_attachment_ids() );
	}

	/**
	 * `found_posts` has to agree with the results, or the Media Library grid stops offering "Load more" as soon
	 * as a page comes back short. That's what excluding the files in SQL rather than in the results buys.
	 */
	public function test_found_posts_matches_the_visible_results() {
		wp_set_current_user( self::$organizer_b );

		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 100,
		) );

		$this->assertSame( count( $query->posts ), (int) $query->found_posts );
	}

	/**
	 * `wp.getMediaItem` resolves an attachment by ID and runs no query, so the query guards can't reach it.
	 */
	public function test_xmlrpc_media_item_redacts_others_payment_files() {
		wp_set_current_user( self::$organizer_b );

		$attachment = get_post( self::$payment_file_id );
		$prepared   = apply_filters(
			'xmlrpc_prepare_media_item',
			array(
				'attachment_id' => (string) $attachment->ID,
				'link'          => wp_get_attachment_url( $attachment->ID ),
			),
			$attachment,
			'thumbnail'
		);

		$this->assertEmpty( $prepared['link'] ?? '' );
	}

	/**
	 * Attachments are searchable, so a front-end search runs over them with no post type named -- and nobody is
	 * logged in for most of those.
	 */
	public function test_logged_out_search_hides_payment_files() {
		wp_set_current_user( 0 );

		$query = new WP_Query( array(
			's'              => 'invoice-a1b2c3d4e5f6g7h8',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		) );

		$visible = array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );

		$this->assertNotContains( self::$payment_file_id, $visible );
	}

	/**
	 * Uploading onto someone else's request doesn't cost you sight of your own file.
	 */
	public function test_uploader_sees_own_file_on_another_organizers_request() {
		$own_file = self::create_file( 'quote-9z8y7x6w5v4u3t2s.pdf', self::$payment_request_id, self::$organizer_b );

		wp_set_current_user( self::$organizer_b );

		$visible = $this->get_visible_attachment_ids();

		$this->assertContains( $own_file, $visible );
		$this->assertNotContains( self::$payment_file_id, $visible );
	}

	/**
	 * The rules read the attachment's parent, so nobody they hide a file from may edit it.
	 *
	 * That capability is what the media REST endpoints and the Media Library's Attach action check before they
	 * write `post_parent`, and moving a file onto a request of your own is what would otherwise make it visible.
	 */
	public function test_others_payment_files_cannot_be_edited() {
		wp_set_current_user( self::$organizer_b );

		$this->assertFalse( current_user_can( 'edit_post', self::$payment_file_id ) );
		$this->assertFalse( current_user_can( 'edit_post', self::$reimbursement_file_id ) );
	}

	/**
	 * Deletion is left where it was. Withholding it isn't needed to keep the parent from being rewritten, and
	 * the guard runs on every site on the network, so it takes no more than it has a reason to.
	 */
	public function test_deletion_rights_are_left_alone() {
		wp_set_current_user( self::$organizer_b );

		$this->assertTrue( current_user_can( 'delete_post', self::$payment_file_id ) );
	}

	/**
	 * The guard is about payment files, so it has to leave the rest of the Media Library alone.
	 *
	 * An Editor edits other people's uploads as a matter of course, and an organizer is an Editor here. This is
	 * every attachment on every site that has no budget requests at all, so it's the case that has to stay put.
	 *
	 * @dataProvider data_ordinary_attachments
	 *
	 * @param string $file_property
	 */
	public function test_ordinary_media_is_still_editable( $file_property ) {
		wp_set_current_user( self::$organizer_b );

		$this->assertTrue( current_user_can( 'edit_post', self::${$file_property} ) );
	}

	/**
	 * Attachments that aren't payment files, in both the shapes the guard has to tell apart.
	 *
	 * @return array
	 */
	public function data_ordinary_attachments() {
		return array(
			'attached to nothing'            => array( 'public_file_id' ),
			'attached to an ordinary post'   => array( 'blog_post_file_id' ),
		);
	}

	/**
	 * Ordinary media has to reach a "yes" without a lookup, because every attachment on the network goes through
	 * this guard.
	 *
	 * `map_meta_cap` runs once per attachment on the Media Library screens. Letting ordinary media reach
	 * `get_hidden_payment_file_ids()` would put a query behind every row of every list, on sites that have never
	 * filed a budget request.
	 */
	public function test_ordinary_media_reaches_a_verdict_without_a_query() {
		global $wpdb;

		wp_set_current_user( self::$organizer_b );

		/*
		 * A fresh post and attachment, so the lookup can't be answered from the result `WP_Query` cached for an
		 * earlier one -- measuring a repeat check would pass whether the guard ran the query or not.
		 */
		$ordinary_post = self::factory()->post->create( array( 'post_author' => self::$organizer_a ) );
		$ordinary_file = self::create_file( 'diagram.png', $ordinary_post, self::$organizer_a );

		// Warm everything the check reads that isn't the guard: the user and their roles, the post and its parent.
		current_user_can( 'edit_post', self::$blog_post_file_id );
		_prime_post_caches( array( $ordinary_file, $ordinary_post ), false, false );

		$before = $wpdb->num_queries;

		$this->assertTrue( current_user_can( 'edit_post', $ordinary_file ) );
		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * The people the file isn't hidden from keep editing it.
	 *
	 * @dataProvider data_users_who_see_payment_files
	 *
	 * @param string $user_property
	 */
	public function test_payment_files_stay_editable_for_the_people_who_see_them( $user_property ) {
		wp_set_current_user( self::${$user_property} );

		$this->assertTrue( current_user_can( 'edit_post', self::$payment_file_id ) );
	}

	/**
	 * The requester sees files other people uploaded onto their request, so they can edit those too.
	 */
	public function test_requester_can_edit_a_file_uploaded_onto_their_request() {
		// Before the request is created: the budget CPTs log the save against the current user, and read its ID
		// unconditionally, so every one of these has to be filed by somebody.
		wp_set_current_user( self::$organizer_b );

		$their_request = self::factory()->post->create( array(
			'post_type'   => WCP_Payment_Request::POST_TYPE,
			'post_author' => self::$organizer_b,
			'post_status' => 'draft',
		) );

		$uploaded_by_a = self::create_file( 'quote-2s3t4u5v6w7x8y9z.pdf', $their_request, self::$organizer_a );

		$this->assertTrue( current_user_can( 'edit_post', $uploaded_by_a ) );
	}

	/**
	 * `map_meta_cap` is asked about a named user as often as about the current one.
	 */
	public function test_guard_answers_for_the_named_user_not_the_current_one() {
		wp_set_current_user( self::$organizer_a );

		$this->assertFalse( user_can( self::$organizer_b, 'edit_post', self::$payment_file_id ) );
		$this->assertTrue( user_can( self::$organizer_a, 'edit_post', self::$payment_file_id ) );
	}

	/**
	 * `wp_update_post()` checks no capabilities, so the field the Files metabox posts is checked where it's read.
	 *
	 * The metabox only offers files that are unattached or already on this request, but it builds that list in
	 * the browser, and the field arrives as whatever was submitted.
	 */
	public function test_existing_files_field_leaves_another_requests_file_alone() {
		wp_set_current_user( self::$organizer_b );

		$their_request = self::factory()->post->create( array(
			'post_type'   => Reimbursement_Requests\POST_TYPE,
			'post_author' => self::$organizer_b,
			'post_status' => 'draft',
		) );

		\WordCamp_Budgets::attach_existing_files(
			$their_request,
			array( 'wcb_existing_files_to_attach' => wp_json_encode( array( self::$payment_file_id ) ) )
		);

		$this->assertSame(
			self::$payment_request_id,
			(int) get_post( self::$payment_file_id )->post_parent
		);
	}

	/**
	 * The files the metabox really does offer still get attached.
	 */
	public function test_existing_files_field_attaches_an_unattached_file() {
		wp_set_current_user( self::$organizer_b );

		$own_request = self::factory()->post->create( array(
			'post_type'   => Reimbursement_Requests\POST_TYPE,
			'post_author' => self::$organizer_b,
			'post_status' => 'draft',
		) );

		$unattached = self::create_file( 'receipt-3t4u5v6w7x8y9z1a.pdf', 0, self::$organizer_b );

		\WordCamp_Budgets::attach_existing_files(
			$own_request,
			array( 'wcb_existing_files_to_attach' => wp_json_encode( array( $unattached ) ) )
		);

		$this->assertSame( $own_request, (int) get_post( $unattached )->post_parent );
	}

	/**
	 * The field holds whatever JSON the browser sent, which isn't always a list of IDs.
	 *
	 * @dataProvider data_malformed_existing_files_fields
	 *
	 * @param string $field
	 */
	public function test_existing_files_field_survives_malformed_input( $field ) {
		wp_set_current_user( self::$organizer_b );

		$own_request = self::factory()->post->create( array(
			'post_type'   => Reimbursement_Requests\POST_TYPE,
			'post_author' => self::$organizer_b,
			'post_status' => 'draft',
		) );

		\WordCamp_Budgets::attach_existing_files( $own_request, array( 'wcb_existing_files_to_attach' => $field ) );

		$this->assertSame( self::$payment_request_id, (int) get_post( self::$payment_file_id )->post_parent );
	}

	/**
	 * Shapes `json_decode()` returns that aren't a list of attachment IDs.
	 *
	 * @return array
	 */
	public function data_malformed_existing_files_fields() {
		return array(
			'not JSON'          => array( 'not json at all' ),
			'a bare number'     => array( '5' ),
			'a string'          => array( '"5"' ),
			'a list of objects' => array( '[{"ID":5}]' ),
			'a list of strings' => array( '["not an id"]' ),
		);
	}

	/**
	 * `privacy.php` can't reference the `POST_TYPE` constants, because it loads on requests where the files that
	 * define them don't. Fail loudly if a slug is ever renamed on one side only.
	 */
	public function test_budget_request_post_types_match_the_constants() {
		$this->assertSame(
			array( Reimbursement_Requests\POST_TYPE, WCP_Payment_Request::POST_TYPE ),
			\WordCamp\Budgets\Privacy\get_budget_request_post_types()
		);
	}

	/**
	 * The duplicated slugs above are only worth anything if nothing in `privacy.php` reaches for the originals.
	 *
	 * `reimbursement-request.php` and `payment-request.php` load in the admin only, so any reference to a symbol
	 * they define is a fatal on every front-end, REST, and XML-RPC request -- which is how the personal data
	 * exporters brought down `wp-json/wporg/v1/data-erase-preflight`. The admin loads both files, so a runtime
	 * test can't see this; read the source instead.
	 */
	public function test_privacy_does_not_reference_admin_only_symbols() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/privacy.php' );

		$admin_only_symbols = array(
			'Reimbursement_Requests\\POST_TYPE',
			'WCP_Payment_Request',
			'WordCamp_Budgets',
			'Sponsor_Invoices',
		);

		foreach ( $admin_only_symbols as $symbol ) {
			$this->assertStringNotContainsString(
				$symbol,
				$source,
				"`privacy.php` loads outside the admin, so it can't reference `$symbol`."
			);
		}
	}

	/**
	 * The people who are supposed to see the files.
	 *
	 * @return array
	 */
	public function data_users_who_see_payment_files() {
		return array(
			'the organizer who filed the requests' => array( 'organizer_a' ),
			'a network admin'                      => array( 'network_admin' ),
		);
	}

	/**
	 * @dataProvider data_users_who_see_payment_files
	 *
	 * @param string $user_property
	 */
	public function test_payment_files_stay_visible_to_their_audience( $user_property ) {
		wp_set_current_user( self::${$user_property} );

		$visible = $this->get_visible_attachment_ids();

		$this->assertContains( self::$payment_file_id, $visible );
		$this->assertContains( self::$reimbursement_file_id, $visible );
	}

	/**
	 * Attaching a file writes its `post_parent`, which is the field the rules above read.
	 *
	 * So it takes the capability any other route to that field takes, and the guard on `edit_post` decides it
	 * here as well -- rather than the field being a way around the guard.
	 */
	public function test_existing_files_field_leaves_a_file_the_caller_cant_edit_alone() {
		wp_set_current_user( self::$volunteer );

		$their_request = self::factory()->post->create( array(
			'post_type'   => Reimbursement_Requests\POST_TYPE,
			'post_author' => self::$volunteer,
			'post_status' => 'draft',
		) );

		$someone_elses = self::create_file( 'notes-9z8y7x6w5v4u3t2s.pdf', 0, self::$organizer_a );

		$this->assertFalse( current_user_can( 'edit_post', $someone_elses ) );

		\WordCamp_Budgets::attach_existing_files(
			$their_request,
			array( 'wcb_existing_files_to_attach' => wp_json_encode( array( $someone_elses ) ) )
		);

		$this->assertSame( 0, (int) get_post( $someone_elses )->post_parent );
	}

	/**
	 * An organizer who may edit the file still attaches it, so the rule doesn't cost the shared-upload case.
	 *
	 * One organizer uploading the receipts and another filing the request is a supported way to work: the rules
	 * above show the file to both of them.
	 */
	public function test_existing_files_field_attaches_a_co_organizers_unattached_file() {
		wp_set_current_user( self::$organizer_b );

		$own_request = self::factory()->post->create( array(
			'post_type'   => Reimbursement_Requests\POST_TYPE,
			'post_author' => self::$organizer_b,
			'post_status' => 'draft',
		) );

		$uploaded_by_a = self::create_file( 'receipt-2s3t4u5v6w7x8y9z.pdf', 0, self::$organizer_a );

		\WordCamp_Budgets::attach_existing_files(
			$own_request,
			array( 'wcb_existing_files_to_attach' => wp_json_encode( array( $uploaded_by_a ) ) )
		);

		$this->assertSame( $own_request, (int) get_post( $uploaded_by_a )->post_parent );
	}

	/**
	 * The helper is public and writes to whatever request it's handed, so it asks about that request too.
	 */
	public function test_existing_files_field_ignores_a_request_the_caller_cant_edit() {
		wp_set_current_user( self::$volunteer );

		$unattached = self::create_file( 'receipt-1a2b3c4d5e6f7g8h.pdf', 0, self::$volunteer );

		$this->assertFalse( current_user_can( 'edit_post', self::$payment_request_id ) );

		\WordCamp_Budgets::attach_existing_files(
			self::$payment_request_id,
			array( 'wcb_existing_files_to_attach' => wp_json_encode( array( $unattached ) ) )
		);

		$this->assertSame( 0, (int) get_post( $unattached )->post_parent );
	}
}
