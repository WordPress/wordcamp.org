<?php

namespace WordCamp\Budgets\Tests;

use PHPUnit\Framework\Assert;
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

	/** @var int A third organizer, who neither filed the request under test nor uploaded onto it. */
	protected static $organizer_c;

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
		self::$organizer_c   = $factory->user->create( array( 'role' => 'editor' ) );
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

		self::$payment_file_id       = self::create_legacy_file( 'invoice-a1b2c3d4e5f6g7h8.pdf', self::$payment_request_id, self::$organizer_a );
		self::$reimbursement_file_id = self::create_legacy_file( 'receipt-h8g7f6e5d4c3b2a1.pdf', $reimbursement_id, self::$organizer_a );
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
	 * The same, without the hook that marks a file on insert, for the rows the tests below model from before
	 * that hook existed.
	 *
	 * @param string $filename
	 * @param int    $parent_id
	 * @param int    $author_id
	 *
	 * @return int
	 */
	protected static function create_legacy_file( $filename, $parent_id, $author_id ) {
		$mark_on_insert = 'WordCamp\Budgets\Privacy\mark_budget_file_upload';

		remove_action( 'add_attachment', $mark_on_insert );

		try {
			return self::create_file( $filename, $parent_id, $author_id );
		} finally {
			add_action( 'add_attachment', $mark_on_insert );
		}
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
		$own_file = self::create_legacy_file( 'quote-9z8y7x6w5v4u3t2s.pdf', self::$payment_request_id, self::$organizer_b );

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

		/*
		 * Warm everything the check reads that isn't the guard: the user and their roles, the post and its
		 * parent, and the meta the marker lives in. The Media Library screens prime all of it for their own
		 * results, so this is the state the guard really runs in.
		 */
		current_user_can( 'edit_post', self::$blog_post_file_id );
		_prime_post_caches( array( $ordinary_file, $ordinary_post ), false, true );

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
	 * The exporters stay out of every request that isn't running the personal data tools.
	 *
	 * WordPress.org's erasure preflight crawls the network over REST and runs whatever exporters each site
	 * offers. Anything that answers with data stops the erasure with a `has_exportable` warning, and budget
	 * requests are kept for accounting, so the eraser is a stub and that warning has nothing to clear it --
	 * every former organizer's erasure request would sit in the DPO queue for good.
	 *
	 * @dataProvider data_screens_and_the_exporters_they_see
	 *
	 * @param string $screen    The screen to run the filter under. `front` is the shape the preflight
	 *                          arrives in; `dashboard` is the personal data tools themselves.
	 * @param array  $expected  The exporter keys the filter should add.
	 */
	public function test_exporters_are_offered_to_the_personal_data_tools_only( $screen, array $expected ) {
		set_current_screen( $screen );

		$exporters = \WordCamp\Budgets\Privacy\register_personal_data_exporters( array() );

		set_current_screen( 'front' );

		$this->assertSame( $expected, array_keys( $exporters ) );
	}

	/**
	 * @return array
	 */
	public function data_screens_and_the_exporters_they_see() {
		return array(
			'a front-end request, as the preflight arrives' => array( 'front', array() ),
			'the admin, where the tools run'                => array(
				'dashboard',
				array( 'wcb-reimbursements', 'wcb-vendor-payments' ),
			),
		);
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

	/**
	 * Write a file to the uploads directory.
	 *
	 * `Database_TestCase` truncates `sitemeta`, so `check_upload_mimes()` has no `upload_filetypes` to read and
	 * falls back to its image-only default, which the network doesn't run with in production.
	 *
	 * @param string $filename
	 * @param string $bits
	 *
	 * @return array The `wp_upload_bits()` result.
	 */
	protected static function upload_bits( $filename, $bits ) {
		$allow_pdfs = static function ( $mimes ) {
			$mimes['pdf'] = 'application/pdf';

			return $mimes;
		};

		add_filter( 'upload_mimes', $allow_pdfs );

		try {
			$upload = wp_upload_bits( $filename, null, $bits );
		} finally {
			remove_filter( 'upload_mimes', $allow_pdfs );
		}

		Assert::assertEmpty( $upload['error'], 'The fixture upload failed: ' . $upload['error'] );

		return $upload;
	}

	/**
	 * File a request with a real file on disk attached to it.
	 *
	 * The cascade tests below assert the file itself is gone, not just the row, so these need an upload that
	 * exists rather than the bare rows `create_file()` makes.
	 *
	 * @param int    $author_id   The organizer filing the request.
	 * @param int    $uploader_id The organizer whose upload sits on it.
	 * @param string $post_status
	 * @param string $post_date
	 *
	 * @return array The request ID, the attachment ID, and the path of the file on disk.
	 */
	protected static function create_request_with_file( $author_id, $uploader_id, $post_status = 'draft', $post_date = '' ) {
		$request_id = self::create_request( $author_id, $post_status, $post_date );

		$upload = self::upload_bits( 'receipt-' . wp_generate_password( 16, false, false ) . '.pdf', 'receipt' );

		$file_id = self::factory()->attachment->create_object( array(
			'file'           => $upload['file'],
			'post_parent'    => $request_id,
			'post_author'    => $uploader_id,
			'post_mime_type' => 'application/pdf',
		) );

		return array( $request_id, $file_id, get_attached_file( $file_id ) );
	}

	/**
	 * Move a request to the trash.
	 *
	 * As a network admin, because `set_request_status()` holds a requester-editable request within the statuses
	 * a requester may set, and `trash` isn't one of them.
	 *
	 * @param int $request_id
	 */
	protected static function trash_request( $request_id ) {
		wp_set_current_user( self::$network_admin );

		wp_trash_post( $request_id );

		Assert::assertSame( 'trash', get_post_status( $request_id ), 'The request under test was never trashed.' );
	}

	/**
	 * Deleting a request takes its files with it, including the ones its deleter can't see.
	 *
	 * One organizer uploading the receipts and another filing the request is a supported way to work, and the
	 * rules above hide that upload from a third organizer. Deleting has to reach it anyway, because the file
	 * has no reader left once the request is gone.
	 */
	public function test_deleting_a_request_deletes_its_files() {
		list( $request_id, $file_id, $path ) = self::create_request_with_file( self::$organizer_a, self::$organizer_a );

		wp_set_current_user( self::$organizer_b );

		$this->assertTrue( current_user_can( 'delete_post', $request_id ) );
		$this->assertNotContains( $file_id, $this->get_visible_attachment_ids() );

		wp_delete_post( $request_id, true );

		$this->assertNull( get_post( $file_id ) );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * A file that survives its deletion is logged, since Core unlinks silently and a WP-CLI run can lack write
	 * access to uploads. Simulated through the `wp_delete_file` filter, which skips the unlink when it returns ''.
	 */
	public function test_a_file_left_behind_by_a_deletion_is_logged() {
		list( $request_id, $file_id, $path ) = self::create_request_with_file( self::$organizer_a, self::$organizer_a );

		$keep_files = '__return_empty_string';
		add_filter( 'wp_delete_file', $keep_files );

		try {
			$logged = self::capture_log( fn() => wp_delete_post( $request_id, true ) );
		} finally {
			remove_filter( 'wp_delete_file', $keep_files );
		}

		$this->assertNull( get_post( $file_id ) );
		$this->assertFileExists( $path );
		$this->assertStringContainsString( 'budget_file_not_deleted', $logged );
		$this->assertStringContainsString( (string) $file_id, $logged );

		wp_delete_file( $path );
	}

	/**
	 * Run something with the error log pointed at a file this test can read.
	 *
	 * @param callable $callback
	 *
	 * @return string Whatever was logged.
	 */
	protected static function capture_log( $callback ) {
		$log      = get_temp_dir() . 'budget-log-' . wp_generate_password( 8, false, false ) . '.log';
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- scoped to this test.

		try {
			$callback();
		} finally {
			ini_set( 'error_log', $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring what was there.
		}

		if ( ! is_file( $log ) ) {
			return '';
		}

		$contents = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file this test wrote.

		wp_delete_file( $log );

		return $contents;
	}

	/**
	 * Trashing isn't deleting: the files stay attached, so restoring the request brings them back with it.
	 */
	public function test_trashing_a_request_keeps_its_files_attached() {
		list( $request_id, $file_id, $path ) = self::create_request_with_file( self::$organizer_a, self::$organizer_a );

		self::trash_request( $request_id );

		$this->assertSame( $request_id, (int) get_post( $file_id )->post_parent );
		$this->assertFileExists( $path );

		wp_untrash_post( $request_id );

		$this->assertSame( $request_id, (int) get_post( $file_id )->post_parent );
		$this->assertFileExists( $path );
	}

	/**
	 * Cron empties the trash once `EMPTY_TRASH_DAYS` has passed, with nobody logged in.
	 */
	public function test_emptying_the_trash_deletes_a_requests_files() {
		list( $request_id, $file_id, $path ) = self::create_request_with_file( self::$organizer_a, self::$organizer_a );

		self::trash_request( $request_id );
		update_post_meta( $request_id, '_wp_trash_meta_time', time() - ( ( EMPTY_TRASH_DAYS + 1 ) * DAY_IN_SECONDS ) );

		wp_set_current_user( 0 );
		wp_scheduled_delete();

		$this->assertNull( get_post( $file_id ) );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * The Files metabox uploads against the auto-draft, so a request opened and abandoned can carry files that
	 * only the weekly auto-draft purge ever reaches.
	 */
	public function test_purging_auto_drafts_deletes_a_requests_files() {
		list( , $file_id, $path ) = self::create_request_with_file(
			self::$organizer_a,
			self::$organizer_a,
			'auto-draft',
			gmdate( 'Y-m-d H:i:s', time() - ( 8 * DAY_IN_SECONDS ) )
		);

		wp_set_current_user( 0 );
		wp_delete_auto_drafts();

		$this->assertNull( get_post( $file_id ) );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * Only the budget post types cascade. `before_delete_post` fires for every post on the network, and Core
	 * reparents an ordinary post's attachments rather than deleting them.
	 */
	public function test_deleting_an_ordinary_post_leaves_its_files_alone() {
		wp_set_current_user( self::$organizer_a );

		$post_id = self::factory()->post->create( array( 'post_author' => self::$organizer_a ) );

		$upload = self::upload_bits( 'header-' . wp_generate_password( 16, false, false ) . '.png', 'image' );

		$file_id = self::factory()->attachment->create_object( array(
			'file'           => $upload['file'],
			'post_parent'    => $post_id,
			'post_author'    => self::$organizer_a,
			'post_mime_type' => 'image/png',
		) );

		$path = get_attached_file( $file_id );

		wp_delete_post( $post_id, true );

		$this->assertInstanceOf( 'WP_Post', get_post( $file_id ) );
		$this->assertFileExists( $path );
	}

	/**
	 * Upload a file the way `wp_ajax_upload_attachment()` does, against the post the request names.
	 *
	 * `post_id` is the field that route sends, and both the naming and the status are decided from it, so the
	 * superglobal is what has to be in place rather than anything about the file.
	 *
	 * @param int    $parent_id The post the upload names.
	 * @param string $filename
	 *
	 * @return array The attachment ID and the name its file ended up with.
	 */
	protected static function upload_file_against( $parent_id, $filename = 'receipt.pdf' ) {
		$_REQUEST['post_id'] = $parent_id;

		try {
			$upload = self::upload_bits( $filename, 'receipt' );

			$file_id = wp_insert_attachment(
				array(
					'post_mime_type' => 'application/pdf',
					'post_title'     => 'receipt',
					'post_parent'    => $parent_id,
				),
				$upload['file']
			);
		} finally {
			unset( $_REQUEST['post_id'] );
		}

		return array( $file_id, wp_basename( $upload['file'] ) );
	}

	/**
	 * File a budget request.
	 *
	 * @param int    $author_id
	 * @param string $post_status
	 * @param string $post_date
	 *
	 * @return int
	 */
	protected static function create_request( $author_id, $post_status = 'draft', $post_date = '' ) {
		wp_set_current_user( $author_id );

		return self::factory()->post->create( array_filter( array(
			'post_type'   => Reimbursement_Requests\POST_TYPE,
			'post_author' => $author_id,
			'post_status' => $post_status,
			'post_date'   => $post_date,
		) ) );
	}

	/**
	 * A file uploaded onto a request is stored as the request's own, whatever happens to the request later.
	 *
	 * @dataProvider data_request_statuses_files_are_uploaded_against
	 *
	 * @param string $request_status
	 */
	public function test_upload_onto_a_request_is_marked_and_private( $request_status ) {
		$request_id = self::create_request( self::$organizer_a, $request_status );

		list( $file_id, $filename ) = self::upload_file_against( $request_id );

		$this->assertSame( 'private', get_post( $file_id )->post_status );
		$this->assertTrue( \WordCamp\Budgets\Privacy\is_budget_file( $file_id ) );
		$this->assertMatchesRegularExpression( '/^receipt-[A-Za-z0-9]{16}\.pdf$/', $filename );
	}

	/**
	 * The statuses a request has while files are being uploaded onto it.
	 *
	 * The Files metabox uploads against the `auto-draft` the editor opens with, before the request has ever
	 * been saved, so that shape carries files as often as a saved draft does.
	 *
	 * @return array
	 */
	public function data_request_statuses_files_are_uploaded_against() {
		return array(
			'a saved draft'          => array( 'draft' ),
			'an unsaved auto-draft'  => array( 'auto-draft' ),
		);
	}

	/**
	 * An insert from PHP is stored the same way, though it sends none of the parameters the upload routes do.
	 */
	public function test_an_insert_onto_a_request_is_marked_and_private() {
		$request_id = self::create_request( self::$organizer_a );

		$file_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'receipt',
				'post_parent'    => $request_id,
				'post_author'    => self::$organizer_a,
			),
			'receipt-2r3s4t5u6v7w8x9y.pdf'
		);

		$this->assertSame( 'private', get_post( $file_id )->post_status );
		$this->assertTrue( \WordCamp\Budgets\Privacy\is_budget_file( $file_id ) );
	}

	/**
	 * Uploading onto anything else is left exactly as Core does it.
	 */
	public function test_upload_onto_an_ordinary_post_is_untouched() {
		wp_set_current_user( self::$organizer_a );

		$post_id = self::factory()->post->create( array( 'post_author' => self::$organizer_a ) );

		list( $file_id, $filename ) = self::upload_file_against( $post_id, 'header.pdf' );

		// Read off the row: `get_post_status()` resolves `inherit` to whatever status the parent has.
		$this->assertSame( 'inherit', get_post( $file_id )->post_status );
		$this->assertFalse( \WordCamp\Budgets\Privacy\is_budget_file( $file_id ) );
		// Not an exact name: Core still deduplicates against whatever is already in the uploads directory.
		$this->assertDoesNotMatchRegularExpression( '/-[A-Za-z0-9]{16}\.pdf$/', $filename );
	}

	/**
	 * The Files metabox attaches a file that was uploaded to the Media Library instead, and that makes it the
	 * request's own too.
	 */
	public function test_attaching_an_existing_file_marks_it() {
		wp_set_current_user( self::$organizer_b );

		$own_request = self::create_request( self::$organizer_b );
		$unattached  = self::create_file( 'receipt-4u5v6w7x8y9z1a2b.pdf', 0, self::$organizer_b );

		\WordCamp_Budgets::attach_existing_files(
			$own_request,
			array( 'wcb_existing_files_to_attach' => wp_json_encode( array( $unattached ) ) )
		);

		$this->assertSame( $own_request, (int) get_post( $unattached )->post_parent );
		$this->assertSame( 'private', get_post( $unattached )->post_status );
		$this->assertTrue( \WordCamp\Budgets\Privacy\is_budget_file( $unattached ) );
	}

	/**
	 * The Media Library's Attach action writes `post_parent` with a direct `UPDATE`, so none of the post hooks
	 * fire for it and the row it leaves behind in the cache still says the file is unattached.
	 */
	public function test_the_media_library_attach_action_marks_a_file() {
		wp_set_current_user( self::$organizer_b );

		$own_request = self::create_request( self::$organizer_b );
		$unattached  = self::create_file( 'receipt-5v6w7x8y9z1a2b3c.pdf', 0, self::$organizer_b );

		self::media_library_attach_action( $unattached, $own_request );

		$this->assertSame( $own_request, (int) get_post( $unattached )->post_parent );
		$this->assertSame( 'private', get_post( $unattached )->post_status );
		$this->assertTrue( \WordCamp\Budgets\Privacy\is_budget_file( $unattached ) );
	}

	/**
	 * Core reads `media` through an `(array)` cast, so `upload.php?action=attach&media=123` attaches one file.
	 */
	public function test_the_attach_action_marks_a_file_posted_as_a_single_id() {
		wp_set_current_user( self::$organizer_b );

		$own_request = self::create_request( self::$organizer_b );
		$unattached  = self::create_file( 'receipt-7h8i9j1k2l3m4n5o.pdf', 0, self::$organizer_b );

		self::media_library_attach_action( $unattached, $own_request, 'attach', 'scalar' );

		$this->assertSame( 'private', get_post( $unattached )->post_status );
		$this->assertTrue( \WordCamp\Budgets\Privacy\is_budget_file( $unattached ) );
	}

	/**
	 * The parent an attach writes over is only readable before `upload.php` dispatches the action, so a file
	 * whose previous parent nobody captured is left as it is rather than guessed at.
	 */
	public function test_an_uncaptured_attach_leaves_a_file_unmarked() {
		wp_set_current_user( self::$organizer_b );

		$own_request = self::create_request( self::$organizer_b );
		$unattached  = self::create_file( 'receipt-2c3d4e5f6g7h8i9j.pdf', 0, self::$organizer_b );

		self::media_library_attach_action( $unattached, $own_request, 'attach', false );

		$this->assertSame( $own_request, (int) get_post( $unattached )->post_parent );
		$this->assertSame( 'inherit', get_post( $unattached )->post_status );
		$this->assertFalse( \WordCamp\Budgets\Privacy\is_budget_file( $unattached ) );
	}

	/**
	 * Move a file the way the Media Library's Attach and Detach actions do: the screen load that reads the
	 * parent, a direct `UPDATE`, then the hook, then the cache.
	 *
	 * @param int    $attachment_id
	 * @param int    $parent_id
	 * @param string $action        Either `attach` or `detach`.
	 * @param mixed  $capture       Whether `load-upload.php` runs first, as it does on a real request; `scalar`
	 *                              posts `media` as a single ID rather than the list the modal sends.
	 */
	protected static function media_library_attach_action( $attachment_id, $parent_id, $action = 'attach', $capture = true ) {
		global $wpdb;

		if ( $capture ) {
			self::capture_attach_action( 'scalar' === $capture ? $attachment_id : array( $attachment_id ), $parent_id );
		}

		$wpdb->update( $wpdb->posts, array( 'post_parent' => $parent_id ), array( 'ID' => $attachment_id ) );

		/*
		 * Core writes that parent with a direct `UPDATE` and nothing reads the row back before the hook, so
		 * whatever is left in the cache is incidental. Cleared here, so the hook can't be relying on it.
		 */
		clean_post_cache( $attachment_id );

		do_action( 'wp_media_attach_action', $action, $attachment_id, $parent_id );
		clean_attachment_cache( $attachment_id );
	}

	/**
	 * Load `upload.php` with the request the find-posts modal posts, which is where the previous parent is read.
	 *
	 * @param int|int[] $media
	 * @param int       $parent_id
	 */
	protected static function capture_attach_action( $media, $parent_id ) {
		$_REQUEST['media']         = $media;
		$_REQUEST['found_post_id'] = $parent_id;

		try {
			do_action( 'load-upload.php' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's own name for it.
		} finally {
			unset( $_REQUEST['media'], $_REQUEST['found_post_id'] );
		}
	}

	/**
	 * A file that already sits on another post is left as it is, whichever route writes the new parent.
	 *
	 * Correcting an ordinary attachment's parent shouldn't take it out of the posts it is already used in,
	 * which the `private` status would.
	 *
	 * @dataProvider data_reparenting_routes
	 *
	 * @param string $route
	 */
	public function test_attaching_a_file_from_another_post_leaves_it_unmarked( $route ) {
		wp_set_current_user( self::$organizer_b );

		$blog_post   = self::factory()->post->create( array( 'post_author' => self::$organizer_b ) );
		$file_id     = self::create_file( 'diagram-1q2r3s4t5u6v7w8x.png', $blog_post, self::$organizer_b );
		$own_request = self::create_request( self::$organizer_b );

		if ( 'update' === $route ) {
			wp_update_post( array(
				'ID'          => $file_id,
				'post_parent' => $own_request,
			) );
		} else {
			self::media_library_attach_action( $file_id, $own_request );
		}

		$this->assertSame( $own_request, (int) get_post( $file_id )->post_parent );
		$this->assertSame( 'inherit', get_post( $file_id )->post_status );
		$this->assertFalse( \WordCamp\Budgets\Privacy\is_budget_file( $file_id ) );
	}

	/**
	 * The two ways an attachment's parent is written.
	 *
	 * @return array
	 */
	public function data_reparenting_routes() {
		return array(
			'through `wp_update_post()`'               => array( 'update' ),
			'through the Media Library Attach action'  => array( 'attach_action' ),
		);
	}

	/**
	 * Detaching keeps both the marker and the status. A marked file with no parent and `inherit` reads as
	 * ordinary public media to Core.
	 */
	public function test_detaching_a_marked_file_keeps_it_marked_and_private() {
		$own_request = self::create_request( self::$organizer_b );
		$file_id     = self::create_file( 'receipt-9o0p1q2r3s4t5u6v.pdf', $own_request, self::$organizer_b );

		\WordCamp\Budgets\Privacy\mark_budget_file( $file_id );

		self::media_library_attach_action( $file_id, 0, 'detach' );

		$this->assertSame( 0, (int) get_post( $file_id )->post_parent );
		$this->assertSame( 'private', get_post( $file_id )->post_status );
		$this->assertTrue( \WordCamp\Budgets\Privacy\is_budget_file( $file_id ) );
	}

	/**
	 * A marked file with no request left to sit on keeps its readers: whoever uploaded it, and network admins.
	 *
	 * This is the case the marker is for. The rules used to read the parent, so a file that had been detached
	 * or left behind by a deleted request was ordinary media to them.
	 */
	public function test_a_marked_file_with_no_parent_is_still_scoped_to_its_uploader() {
		$detached = self::create_file( 'receipt-6w7x8y9z1a2b3c4d.pdf', 0, self::$organizer_a );

		\WordCamp\Budgets\Privacy\mark_budget_file( $detached );

		wp_set_current_user( self::$organizer_b );

		$this->assertNotContains( $detached, $this->get_visible_attachment_ids( self::any_status() ) );
		$this->assertFalse( current_user_can( 'read_post', $detached ) );
		$this->assertEmpty( self::prepare_for_js( $detached ) );

		wp_set_current_user( self::$organizer_a );

		$this->assertContains( $detached, $this->get_visible_attachment_ids( self::any_status() ) );
		$this->assertTrue( current_user_can( 'read_post', $detached ) );

		wp_set_current_user( self::$network_admin );

		$this->assertTrue( current_user_can( 'read_post', $detached ) );
	}

	/**
	 * `private` maps `read_post` to `read_private_posts`, which every organizer has, so the rules decide that
	 * capability as well -- and they have to leave the requester holding it.
	 */
	public function test_a_marked_file_on_a_request_is_readable_by_the_requester() {
		$their_request = self::create_request( self::$organizer_b );

		list( $file_id ) = self::upload_file_against( $their_request );

		wp_set_current_user( self::$organizer_b );

		$this->assertTrue( current_user_can( 'read_post', $file_id ) );

		wp_set_current_user( self::$organizer_c );

		$this->assertFalse( current_user_can( 'read_post', $file_id ) );
	}

	/**
	 * A file uploaded before any of this still has nothing but its parent to say what it is, so the rules go
	 * on reading that. Drop this once every file carries the marker.
	 */
	public function test_an_unmarked_file_on_a_request_is_still_scoped_by_its_parent() {
		$request_id = self::create_request( self::$organizer_a );
		$file_id    = self::create_legacy_file( 'receipt-7x8y9z1a2b3c4d5e.pdf', $request_id, self::$organizer_a );

		$this->assertSame( 'inherit', get_post( $file_id )->post_status );
		$this->assertFalse( \WordCamp\Budgets\Privacy\is_budget_file( $file_id ) );

		wp_set_current_user( self::$organizer_b );

		$this->assertNotContains( $file_id, $this->get_visible_attachment_ids( self::any_status() ) );
		$this->assertFalse( current_user_can( 'read_post', $file_id ) );
	}

	/**
	 * A trashed request is still a request, so the files on it stay scoped to it.
	 *
	 * Trashing is how a request is taken back, and the files come back with it, so the parent lookup asks what
	 * type a post is rather than which status it holds.
	 */
	public function test_an_unmarked_file_on_a_trashed_request_is_still_scoped_by_its_parent() {
		$request_id = self::create_request( self::$organizer_a );
		$file_id    = self::create_legacy_file( 'receipt-8y9z1a2b3c4d5e6f.pdf', $request_id, self::$organizer_a );

		self::trash_request( $request_id );

		wp_set_current_user( self::$organizer_b );

		$this->assertNotContains( $file_id, $this->get_visible_attachment_ids( self::any_status() ) );
		$this->assertFalse( current_user_can( 'read_post', $file_id ) );

		wp_set_current_user( self::$organizer_a );

		$this->assertTrue( current_user_can( 'read_post', $file_id ) );
	}

	/**
	 * The Files metabox shows a requester every file on their request, whatever role they hold.
	 *
	 * `WP_Query` only returns another user's `private` post to someone with `read_private_posts`, which an
	 * Author doesn't have, so the metabox names the statuses it wants and leaves the decision to the rules above.
	 */
	public function test_files_metabox_shows_a_volunteer_requester_a_co_organizers_upload() {
		wp_set_current_user( self::$volunteer );

		$request_id = self::factory()->post->create( array(
			'post_type'   => Reimbursement_Requests\POST_TYPE,
			'post_author' => self::$volunteer,
			'post_status' => 'draft',
		) );

		$uploaded_by_a = self::create_file( 'receipt-5k6l7m8n9o0p1q2r.pdf', $request_id, self::$organizer_a );
		\WordCamp\Budgets\Privacy\mark_budget_file( $uploaded_by_a );

		$this->assertSame( 'private', get_post( $uploaded_by_a )->post_status );
		$this->assertFalse( current_user_can( 'read_private_posts' ) );

		$listed = wp_list_pluck( \WordCamp_Budgets::get_attached_files( get_post( $request_id ) ), 'ID' );

		$this->assertContains( $uploaded_by_a, $listed );
		$this->assertTrue( \WordCamp_Budgets::can_submit_request( get_post( $request_id ) ) );

		wp_set_current_user( self::$organizer_b );

		$this->assertNotContains( $uploaded_by_a, wp_list_pluck( \WordCamp_Budgets::get_attached_files( get_post( $request_id ) ), 'ID' ) );
	}

	/**
	 * Ask for both statuses an attachment on this site can have, so a query result says who sees a file rather
	 * than which status it carries.
	 *
	 * @return array
	 */
	protected static function any_status() {
		return array( 'post_status' => array( 'inherit', 'private' ) );
	}

	/**
	 * The query vars the Media Library grid and the media modal run, once the filters have had them.
	 *
	 * Both go through `wp_ajax_query_attachments()`, which asks for `inherit` and adds `private` only for
	 * `read_private_posts` -- so on its own it shows an Author none of their own payment files.
	 *
	 * @return array
	 */
	protected static function media_library_query_args() {
		return apply_filters(
			'ajax_query_attachments_args',
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);
	}

	/**
	 * A requester who is only an Author sees the files on their own request in the Media Library, and no more
	 * `private` media than they could see before.
	 */
	public function test_media_library_shows_a_volunteer_requester_the_files_on_their_request() {
		$request_id = self::create_request( self::$volunteer );

		$their_own     = self::create_file( 'receipt-6l7m8n9o0p1q2r3s.pdf', $request_id, self::$volunteer );
		$uploaded_by_a = self::create_file( 'receipt-7m8n9o0p1q2r3s4t.pdf', $request_id, self::$organizer_a );

		foreach ( array( $their_own, $uploaded_by_a ) as $file_id ) {
			\WordCamp\Budgets\Privacy\mark_budget_file( $file_id );
		}

		// A `private` attachment that is nobody's payment file: sponsorship agreements are stored this way.
		$someone_elses = self::create_file( 'agreement-8n9o0p1q2r3s4t5u.pdf', 0, self::$organizer_a );
		wp_update_post( array(
			'ID'          => $someone_elses,
			'post_status' => 'private',
		) );

		wp_set_current_user( self::$volunteer );

		$visible = $this->get_visible_attachment_ids( self::media_library_query_args() );

		$this->assertFalse( current_user_can( 'read_private_posts' ) );
		$this->assertContains( $their_own, $visible );
		$this->assertContains( $uploaded_by_a, $visible );
		$this->assertNotContains( $someone_elses, $visible );

		wp_set_current_user( self::$organizer_b );

		$this->assertContains(
			$someone_elses,
			$this->get_visible_attachment_ids( self::media_library_query_args() )
		);
	}

	/**
	 * Run a query whose results a `posts_pre_query` filter supplies, as jetpack-search does. The clauses are
	 * built and thrown away, so the pass over the results is the only one that runs.
	 *
	 * @param int[] $attachment_ids
	 *
	 * @return int[]
	 */
	protected static function supplied_results( array $attachment_ids ) {
		$supply = fn() => array_map( 'get_post', $attachment_ids );

		add_filter( 'posts_pre_query', $supply );

		try {
			$query = new WP_Query( array_merge(
				array(
					'post_type'        => 'attachment',
					'suppress_filters' => false,
				),
				self::any_status()
			) );
		} finally {
			remove_filter( 'posts_pre_query', $supply );
		}

		return array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );
	}

	/**
	 * The pass over the results answers the `private` gate as well, and to the same answer the clauses give.
	 *
	 * A requester who is only an Author asks for `private` outright, which is what makes `WP_Query` skip its
	 * own gate, so the files on their own request come back and a stranger's `private` media doesn't.
	 */
	public function test_supplied_results_answer_the_private_gate() {
		$request_id = self::create_request( self::$volunteer );

		$their_own     = self::create_file( 'receipt-0p1q2r3s4t5u6v7w.pdf', $request_id, self::$volunteer );
		$uploaded_by_a = self::create_file( 'receipt-1q2r3s4t5u6v7w8x.pdf', $request_id, self::$organizer_a );

		foreach ( array( $their_own, $uploaded_by_a ) as $file_id ) {
			\WordCamp\Budgets\Privacy\mark_budget_file( $file_id );
		}

		// A `private` attachment that is nobody's payment file: sponsorship agreements are stored this way.
		$someone_elses = self::create_file( 'agreement-9o0p1q2r3s4t5u6v.pdf', 0, self::$organizer_a );
		wp_update_post( array(
			'ID'          => $someone_elses,
			'post_status' => 'private',
		) );

		$supplied = array( $someone_elses, $their_own, $uploaded_by_a );

		wp_set_current_user( self::$volunteer );

		$visible = self::supplied_results( $supplied );

		$this->assertNotContains( $someone_elses, $visible );
		$this->assertContains( $their_own, $visible );
		$this->assertContains( $uploaded_by_a, $visible );

		wp_set_current_user( self::$organizer_b );

		$this->assertContains( $someone_elses, self::supplied_results( $supplied ) );
	}

	/**
	 * A network admin reviews every request, so none of the rules above may cost them a file.
	 *
	 * The control on the `private` status: a marked file with no parent left is the shape the status changes
	 * most, and it is still theirs to read.
	 */
	public function test_a_network_admin_keeps_every_payment_file() {
		$detached = self::create_file( 'receipt-3d4e5f6g7h8i9j0k.pdf', 0, self::$organizer_a );

		\WordCamp\Budgets\Privacy\mark_budget_file( $detached );

		wp_set_current_user( self::$network_admin );

		$visible = $this->get_visible_attachment_ids( self::any_status() );

		$this->assertContains( $detached, $visible );
		$this->assertContains( self::$payment_file_id, $visible );
		$this->assertContains( self::$reimbursement_file_id, $visible );
		$this->assertTrue( current_user_can( 'read_post', $detached ) );
	}

	/**
	 * Run the media details the admin hands to JavaScript through the filter that redacts them.
	 *
	 * `wp_ajax_get_attachment()` resolves an attachment by ID and checks `upload_files` alone, so this is the
	 * whole of what stands between it and the file's URL.
	 *
	 * @param int $attachment_id
	 *
	 * @return array
	 */
	protected static function prepare_for_js( $attachment_id ) {
		return apply_filters(
			'wp_prepare_attachment_for_js',
			array(
				'id'  => $attachment_id,
				'url' => wp_get_attachment_url( $attachment_id ),
			),
			get_post( $attachment_id ),
			false
		);
	}
}
