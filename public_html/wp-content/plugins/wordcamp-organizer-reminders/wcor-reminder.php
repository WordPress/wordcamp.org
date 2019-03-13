<?php

/**
 * A Custom post type to store the body of the reminder e-mails
 * @package WordCampOrganizerReminders
 */

// move reminder to new screen, w/out adding email by status
// add email by status
// move inline html to external view files
// any other remaining comments
// phpcs

class WCOR_Reminder {
	const AUTOMATED_POST_TYPE_SLUG = 'organizer-reminder';
	const MANUAL_POST_TYPE_SLUG    = 'manual-reminder';
	const REQUIRED_CAPABILITY      = 'manage_options';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init',                              array( $this, 'register_post_type' ) );
		add_action( 'admin_init',                        array( $this, 'add_meta_boxes' ) );
		add_action( 'admin_menu',                        array( $this, 'register_menu_pages' ) );
		add_action( 'add_meta_boxes',                    array( $this, 'replace_meta_boxes' ) );
		add_action( 'save_post_' . self::AUTOMATED_POST_TYPE_SLUG, array( $this, 'save_post' ), 10, 2 );
	}

	/**
	 * Registers the Reminder post type
	 */
	public function register_post_type() {
		$automated_labels = array(
			'name'               => 'Automated Reminders',
			'singular_name'      => 'Automated Reminder',
			'add_new'            => 'Add New Automated',
			'add_new_item'       => 'Add New Automated Reminder',
			'edit'               => 'Edit',
			'edit_item'          => 'Edit Automated Reminder',
			'new_item'           => 'New Automated Reminder',
			'view'               => 'View Automated Reminders',
			'view_item'          => 'View Automated Reminder',
			'search_items'       => 'Search Automated Reminders',
			'not_found'          => 'No automated reminders',
			'not_found_in_trash' => 'No automated reminders',
			'parent'             => 'Parent Automated Reminder',
		);

		$automated_params = array(
			'labels'              => $automated_labels,
			'singular_label'      => 'Automated Reminder',
			'public'              => true,
			// ^ should be false? don't want these accessible on front end etc
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => 'organizer-reminders',
			'show_in_nav_menus'   => false,
			'hierarchical'        => false,
			'capability_type'     => 'post',
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title', 'editor', 'author', 'revisions' ),
		);

		register_post_type( self::AUTOMATED_POST_TYPE_SLUG, $automated_params );

		$manual_labels = array(
			'name'               => 'Manual Reminders',
			'singular_name'      => 'Manual Reminder',
			'add_new'            => 'Add New Manual',
			'add_new_item'       => 'Add New Manual Reminder',
			'edit'               => 'Edit',
			'edit_item'          => 'Edit Manual Reminder',
			'new_item'           => 'New Manual Reminder',
			'view'               => 'View Manual Reminders',
			'view_item'          => 'View Manual Reminder',
			'search_items'       => 'Search Manual Reminders',
			'not_found'          => 'No manual reminders',
			'not_found_in_trash' => 'No manual reminders',
			'parent'             => 'Parent Manual Reminder',
		);

		$manual_params = array(
			'labels'              => $manual_labels,
			'singular_label'      => 'Manual Reminder',
			'public'              => true,	// should be false? don't want these accessible on front end etc
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			// wanna change some of this prolly, some of the defaults don't make sense b/c don't want it to be editable, don't want to create new ones, etc
			// need to setup map_meta_cap or has_cap to return false for current_user_can(edit_post, ID of a manual), but want them to be able to view it in the back end, not view on the front end. maybe just have a metabox to show the deets?
			// why is public true in both of these types?
			// replace status metabox with a custom one that just has a send button.
				// oh, maybe let people save as drafts, and edit posts that are drafts, but not edit ones that are sent
				// maybe just use JS to change the label in the DOM, rather than replacing w/ new button?
			'show_ui'             => true,
			'show_in_menu'        => 'organizer-reminders',
			'show_in_nav_menus'   => false,
			'hierarchical'        => false,
			'capability_type'     => 'post',
			'has_archive'         => false,
			'rewrite'             => false,
			'show_in_rest'        => true,
				//for G, make sure posts won't be accessible to unauth'd users, even if they have author role on central. should need self::REQUIRED_CAPABILITY
			'query_var'           => false,
			'supports'            => array( 'title', 'editor', 'author', 'revisions' ),
			// make this post type disabled in the _CLASSIC_ editor, so it can only be used in gutenberg, and make it gutenberg comapt from the beginning, include any JS customizations needed
		);

		register_post_type( self::MANUAL_POST_TYPE_SLUG,    $manual_params    );


		// when open edior, there's a block already added to new post
		// it has a dropdown of titles
		// when you select a title, it should you the first N chars of post, and a "use as template" button
		// when you click button, it removes itself, and adds the title/content of that template to the post, as G blocks

		// restrict which blocks can be chosen
		// add template block by default
	}

	/**
	 * Adds meta boxes for the custom post type
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'wcor_reminder_details',
			'Reminder Details',
			array( $this, 'markup_reminder_details' ),
			self::AUTOMATED_POST_TYPE_SLUG,
			'normal',
			'high'
		);

		add_meta_box(
			'wcor_manually_send',
			__( 'Manually Send', 'wordcamporg' ),
			array( $this, 'markup_manually_send' ),
			self::AUTOMATED_POST_TYPE_SLUG,
			'side'
		);

		add_meta_box(
			'wcor_available_placeholders',
			'Available Placeholders',
			array( $this, 'render_available_placeholders' ),
			self::AUTOMATED_POST_TYPE_SLUG,
			'side'
		);

		add_meta_box(
			'wcorg_manual_templates',
			'Templates',
			array( $this, 'render_manual_templates' ),
			self::MANUAL_POST_TYPE_SLUG,
			'normal', // want it above the title, if that's possible. will 'advanced' let you? might need to use css to re-order. maybe grid or something.
			'high'
		);
	}

	/**
	 * Builds the markup for the Reminder Details metabox
	 *
	 * @param object $post
	 */
	public function markup_reminder_details( $post ) {
		$send_where              = get_post_meta( $post->ID, 'wcor_send_where' );

		require_once( __DIR__ . '/views/metabox-reminder-details.php' );
	}

	/**
	 * Builds the markup for the Available Placeholders metabox.
	 */
	public function render_available_placeholders() {
		require_once( __DIR__ . '/views/metabox-placeholders.php' );
	}

	// phpdoc
	public function render_manual_templates() {
		require_once( __DIR__ . '/views/metabox-manual-templates.php' );
	}

	/**
	 * Builds the markup for the Manually Send metabox
	 *
	 * @param object $post
	 */
	public function markup_manually_send( $post ) {
		?>

		<p><?php _e( 'Check the box below and save the post to manually send this message to the assigned recipient(s), using the data from the selected WordCamp.', 'wordcamporg' ); ?></p>

		<p><?php _e( 'It will be sent immediately, regardless of when it is scheduled to be sent automatically, and regardless of whether or not it has already been sent automatically.', 'wordcamporg' ); ?></p>

		<p>
			<?php echo get_wordcamp_dropdown( 'wcor_manually_send_wordcamp' ); ?>
		</p>

		<p>
			<input id="wcor_manually_send_checkbox" name="wcor_manually_send" type="checkbox">
			<label for="wcor_manually_send_checkbox"><?php _e( 'Manually send this e-mail', 'wordcamporg' ); ?></label>
		</p>

		<?php
	}

	/**
	 * Register new admin pages
	 */
	public function register_menu_pages() {
		add_menu_page(
			'Organizer Reminders',
			'Organizer Reminders',
			self::REQUIRED_CAPABILITY,
			'organizer-reminders',
			'',
			'dashicons-email-alt',
			30
		);
	}

	/**
	 * Replace Core's Publish metabox with a custom one.
	 */
	function replace_meta_boxes() {
		remove_meta_box( 'submitdiv', self::MANUAL_POST_TYPE_SLUG, 'side' );

		add_meta_box(
			'submitdiv',
			esc_html__( 'Status', 'wordcamporg' ),
			array( $this, 'render_status_metabox' ),
			self::MANUAL_POST_TYPE_SLUG,
			'side',
			'high'
		);

		// move this higer, next to add_meta_boxes()? or is it more logical here?

		// hmmmm, maybe should use gutenberg instead?
		// block template to only allow paragraph blocks. also want to remove html controls from the paragraph toolbar though, is that possible?
			// maybe simpler and better to allow html emails instead? still restrict to basic stuff that works in emails
		// would also want to remove the `preview`, `publish` ettc buttons and replace w/ a `send` button

		// show placeholders -- make DRY w/ automated
		// select2 box for titles -- or does G already have that built in? make it shared component if custom
		// g controls/inspector/whatever for who it should be sent to (individual camp, or all camps in status)
			// make list of who can receive DRY w/ automated

		// require selecting who should receive etc before sending
		// maybe some kind of pre-publish checks before sending
		// allow previewing email w/ html stripped?
		// only allow P blocks, no html - file bug report if still doesn't let you remove those 2 others
	}

	/**
	 * Render the Status metabox
	 *
	 * @param WP_Post $post The invoice post
	 */
	public function render_status_metabox( $post ) {
		return;
		wp_nonce_field( 'status', 'status_nonce' );

		$delete_text = EMPTY_TRASH_DAYS ? esc_html__( 'Move to Trash' ) : esc_html__( 'Delete Permanently' );
		$wordcamp    = get_wordcamp_post();

		// todo update all this

		/*
		 * We can't use current_user_can( 'edit_post', N ) in this case, because the restriction only applies when
		 * submitting the edit form, not when viewing the post. We also want to allow editing by plugins, but not
		 * always through the UI. So, instead, we simulate get the same result in a different way.
		 *
		 * Network admins can edit submitted invoices in order to correct them before they're sent to QuickBooks, but
		 * not even network admins can edit them once they've been created in QuickBooks, because then our copy of the
		 * invoice would no longer match QuickBooks.
		 *
		 * This intentionally only prevents editing through the UI; we still want plugins to be able to edit the
		 * invoice, so that the status can be updated to paid, etc.
		 */
		$allowed_edit_statuses = array( 'auto-draft', 'draft' );

		if ( current_user_can( 'manage_network' ) ) {
			$allowed_edit_statuses[] = 'wcbsi_submitted';
		}

		$allowed_submit_statuses         = true; //WordCamp_Loader::get_after_contract_statuses();
		$current_user_can_edit_request   = true; //in_array( $post->post_status, $allowed_edit_statuses, true );
		$current_user_can_submit_request = true; // $wordcamp && in_array( $wordcamp->post_status, $allowed_submit_statuses, true );

		$submit_text = 'hi';

		require_once( __DIR__ . '/views/metabox-manual-status.php' );
	}

	/**
	 * Checks to make sure the conditions for saving post meta are met
	 *
	 * @param int $post_id
	 * @param WP_Post $post
	 */
	public function save_post( $post_id, $post ) {
		$ignored_actions = array( 'trash', 'untrash', 'restore' );

		if ( isset( $_GET['action'] ) && in_array( $_GET['action'], $ignored_actions ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts', $post_id ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $post->ID ) || $post->post_status == 'auto-draft' ) {
			return;
		}

		$this->save_post_meta( $post, $_POST );
//		$this->send_manual_email( $post, $_POST );
	}

	/**
	 * Saves the meta data for the reminder post
	 *
	 * @param WP_Post $post
	 * @param array $new_meta
	 */
	protected function save_post_meta( $post, $new_meta ) {
		$send_where_whitelist = array( 'wcor_send_organizers', 'wcor_send_sponsor_wrangler', 'wcor_send_budget_wrangler', 'wcor_send_venue_wrangler', 'wcor_send_speaker_wrangler', 'wcor_send_food_wrangler', 'wcor_send_swag_wrangler', 'wcor_send_volunteer_wrangler', 'wcor_send_printing_wrangler', 'wcor_send_design_wrangler', 'wcor_send_website_wrangler', 'wcor_send_social_wrangler', 'wcor_send_a_v_wrangler', 'wcor_send_party_wrangler', 'wcor_send_travel_wrangler', 'wcor_send_safety_wrangler', 'wcor_send_mes', 'wcor_send_camera_wrangler', 'wcor_send_custom' );

		delete_post_meta( $post->ID, 'wcor_send_where' );
		if ( isset( $new_meta['wcor_send_where'] ) ) {
			foreach( $new_meta['wcor_send_where'] as $send_where ) {
				if ( in_array( $send_where, $send_where_whitelist ) ) {
					add_post_meta( $post->ID, 'wcor_send_where', $send_where );
				}
			}
		}

		if ( isset( $new_meta['wcor_send_custom_address'] ) && is_email( $new_meta['wcor_send_custom_address'] ) ) {
			update_post_meta( $post->ID, 'wcor_send_custom_address', sanitize_email( $new_meta['wcor_send_custom_address'] ) );
		}

		if ( isset( $new_meta['wcor_send_when'] ) ) {
			if ( in_array( $new_meta['wcor_send_when'], array( 'wcor_send_before', 'wcor_send_after', 'wcor_send_after_pending', 'wcor_send_trigger' ) ) ) {
				update_post_meta( $post->ID, 'wcor_send_when', $new_meta['wcor_send_when'] );
			}
		}

		if ( isset( $new_meta['wcor_send_days_before'] ) ) {
			update_post_meta( $post->ID, 'wcor_send_days_before', absint( $new_meta['wcor_send_days_before'] ) );
		}

		if ( isset( $new_meta['wcor_send_days_after'] ) ) {
			update_post_meta( $post->ID, 'wcor_send_days_after', absint( $new_meta['wcor_send_days_after'] ) );
		}

		if ( isset( $new_meta['wcor_send_days_after_pending'] ) ) {
			update_post_meta( $post->ID, 'wcor_send_days_after_pending', absint( $new_meta['wcor_send_days_after_pending'] ) );
		}

		if ( isset( $new_meta['wcor_which_trigger'] ) ) {
			if ( in_array( $new_meta['wcor_which_trigger'], array_merge( array( 'null' ), array_keys( $GLOBALS['WCOR_Mailer']->triggers ) ) ) ) {
				update_post_meta( $post->ID, 'wcor_which_trigger', $new_meta['wcor_which_trigger'] );
			}
		}
	}

	// move manual stuff to separate file, and then rename this to automated? maybe would have a common file too? see how the functions break down. maybe it's enough to have views separated

	/**
	 * Sends an e-mail manually.
	 *
	 * This provides a way to send e-mails at will, regardless of the time or trigger that the e-mail is normally
	 * associated with, and regardless of whether or not the e-mail has already been sent to the recipient.
	 *
	 * @param array   $form_values
	 */
	protected function send_manual_email( $form_values ) {
		/** @var $WCOR_Mailer WCOR_Mailer */
		global $WCOR_Mailer;

		return array(
			'success' => array(
				'yay',
			),

			'warning' => array(
				'huh', 'foo?'
			),

			'error' => array(
				'doh'
			),
		);

		if ( empty( $form_values['wcor_manually_send'] ) || 'on' != $form_values['wcor_manually_send'] || in_array( $form_values['wcor_manually_send_wordcamp'], array( 'instructions', 'spacer' ) ) ) {
			return;
			// edit ?
		}

		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			return;
		}
		// verify nonce too

		// so maybe we'd have a loop here that'd go through each camp in the chosen status, and call the mail function?
		$wordcamp = get_post( $form_values['wcor_manually_send_wordcamp'] );
		$WCOR_Mailer->send_manual_email( $email, $wordcamp );


		// hmmm, maybe this should just return true/false? or true/WP_Error? and caller should format that raw data for displaying as error msgs?
	}
}
