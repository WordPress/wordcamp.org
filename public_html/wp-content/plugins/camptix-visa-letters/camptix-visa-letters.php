<?php
/**
 * Plugin name: Camptix Visa Letters for WordCamp.org
 * Description: Generate visa invitation letters for attendees.
 * Version: 1.0.0
 * Author: WordCamp.org Contributors
 * Author URI: https://central.wordcamp.org/
 *
 * @package Camptix_Visa_Letters
 */

defined( 'ABSPATH' ) || exit;

define( 'CTX_VL_VER', '1.0.0' );
define( 'CTX_VL_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'CTX_VL_DIR', untrailingslashit( __DIR__ ) );
define( 'CTX_VL_ADMIN_URL', CTX_VL_URL . '/admin' );

/**
 * Encryption key for passport data, derived from the install's auth salt.
 *
 * @return string 32 raw bytes.
 */
function ctx_vl_encryption_key() {
	return hash( 'sha256', wp_salt( 'auth' ), true );
}

/**
 * Encrypt a value for storage. Already-encrypted values pass through unchanged.
 *
 * @param string $plain Plaintext value.
 * @return string Ciphertext with `ctxvl1:` prefix, or the input when empty.
 */
function ctx_vl_encrypt( $plain ) {
	$plain = (string) $plain;
	if ( '' === $plain || 0 === strpos( $plain, 'ctxvl1:' ) ) {
		return $plain;
	}

	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for libsodium ciphertext, not obfuscation.
	return 'ctxvl1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, ctx_vl_encryption_key() ) );
}

/**
 * Decrypt a stored value. Values without the `ctxvl1:` prefix are legacy
 * plaintext and are returned as-is.
 *
 * @param mixed $value Stored value.
 * @return mixed Plaintext, or '' when the ciphertext cannot be opened.
 */
function ctx_vl_decrypt( $value ) {
	if ( ! is_string( $value ) || 0 !== strpos( $value, 'ctxvl1:' ) ) {
		return $value;
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own ciphertext, strict mode.
	$raw = base64_decode( substr( $value, 7 ), true );
	if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
		return '';
	}

	$plain = sodium_crypto_secretbox_open(
		substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
		substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
		ctx_vl_encryption_key()
	);

	return false === $plain ? '' : $plain;
}

/**
 * Encrypt the sensitive fields of a visa letter metas array before storage.
 *
 * @param mixed $metas Metas array.
 * @return mixed
 */
function ctx_vl_seal_metas( $metas ) {
	if ( is_array( $metas ) && isset( $metas['passport_number'] ) ) {
		$metas['passport_number'] = ctx_vl_encrypt( $metas['passport_number'] );
	}
	return $metas;
}

/**
 * Decrypt the sensitive fields of a stored visa letter metas array.
 *
 * @param mixed $metas Metas array.
 * @return mixed
 */
function ctx_vl_open_metas( $metas ) {
	if ( is_array( $metas ) && isset( $metas['passport_number'] ) ) {
		$metas['passport_number'] = ctx_vl_decrypt( $metas['passport_number'] );
	}
	return $metas;
}

/**
 * Directory where letter PDFs are stored, outside the web-served uploads path.
 *
 * On ms-files networks (basedir ends in /files) a sibling directory is not
 * reachable through the /files/ rewrite. Elsewhere we fall back to a subdir
 * of uploads protected by .htaccess; either way, files are only served
 * through the authenticated download endpoint.
 *
 * @return string|false Absolute path, or false when uploads are unavailable.
 */
function ctx_vl_get_letters_dir() {
	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['basedir'] ) ) {
		return false;
	}

	if ( '/files' === substr( $upload_dir['basedir'], -6 ) ) {
		$letters_dirname = dirname( $upload_dir['basedir'] ) . '/camptix-visa-letters';
	} else {
		$letters_dirname = $upload_dir['basedir'] . '/camptix-visa-letters';
	}

	if ( ! file_exists( $letters_dirname ) ) {
		wp_mkdir_p( $letters_dirname );
	}
	if ( ! file_exists( $letters_dirname . '/index.php' ) ) {
		file_put_contents( $letters_dirname . '/index.php', "<?php // Silence is golden.\n" ); // @codingStandardsIgnoreLine
	}
	if ( ! file_exists( $letters_dirname . '/.htaccess' ) ) {
		file_put_contents( $letters_dirname . '/.htaccess', "Deny from all\n" ); // @codingStandardsIgnoreLine
	}

	return $letters_dirname;
}

/**
 * Loads WordCamp Docs PDF Generator.
 */
function ctx_vl_load_docs_pdf_generator() {
	if ( ! defined( 'WORDCAMP_DOCS__PLUGIN_DIR' ) ) {
		return;
	}//end if
	require_once WORDCAMP_DOCS__PLUGIN_DIR . 'classes/class-wordcamp-docs-pdf-generator.php';
}
add_action( 'init', 'ctx_vl_load_docs_pdf_generator' );

/**
 * Load visa letter addon.
 */
function load_camptix_visa_letters() {
	require plugin_dir_path( __FILE__ ) . 'includes/class-camptix-addon-visa-letters.php';
	camptix_register_addon( 'CampTix_Addon_Visa_Letters' );
	add_action( 'init', 'register_tix_visa_letter' );
}
add_action( 'camptix_load_addons', 'load_camptix_visa_letters' );

/**
 * Register visa letter CPT and custom statuses.
 */
function register_tix_visa_letter() {
	register_post_type(
		'tix_visa_letter',
		array(
			'label'        => __( 'Visa Letters', 'wordcamporg' ),
			'labels'       => array(
				'name'           => __( 'Visa Letters', 'wordcamporg' ),
				'singular_name'  => _x( 'Visa Letter', 'Post Type Singular Name', 'wordcamporg' ),
				'menu_name'      => __( 'Visa Letters', 'wordcamporg' ),
				'name_admin_bar' => __( 'Visa Letter', 'wordcamporg' ),
				'archives'       => __( 'Visa Letter Archives', 'wordcamporg' ),
				'attributes'     => __( 'Visa Letter Attributes', 'wordcamporg' ),
				'add_new_item'   => __( 'Add New Visa Letter', 'wordcamporg' ),
				'add_new'        => __( 'Add New', 'wordcamporg' ),
				'new_item'       => __( 'New Visa Letter', 'wordcamporg' ),
				'edit_item'      => __( 'Edit Visa Letter', 'wordcamporg' ),
				'update_item'    => __( 'Update Visa Letter', 'wordcamporg' ),
				'view_item'      => __( 'View Visa Letter', 'wordcamporg' ),
				'view_items'     => __( 'View Visa Letters', 'wordcamporg' ),
				'search_items'   => __( 'Search Visa Letters', 'wordcamporg' ),
			),
			'supports'     => array( 'title' ),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'edit.php?post_type=tix_ticket',
		)
	);

	register_post_status( 'cancelled',
		array(
			'label'                     => _x( 'Cancelled', 'post', 'wordcamporg' ),
			'public'                    => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Cancelled <span class="count">(%s)</span>', 'Cancelled <span class="count">(%s)</span>', 'wordcamporg' ),
		)
	);
}

/**
 * Register visa letter CPT custom update messages.
 */
function ctx_vl_set_updated_messages( $messages ) {

	$messages['tix_visa_letter'] = array(
		0  => '', // Unused. Messages start at index 1.
		1  => __( 'Visa letter updated.', 'wordcamporg' ),
		2  => __( 'Custom field updated.', 'wordcamporg' ),
		3  => __( 'Custom field deleted.', 'wordcamporg' ),
		4  => __( 'Visa letter updated.', 'wordcamporg' ),
		5  => __( 'Visa letter restored.', 'wordcamporg' ),
		6  => __( 'Visa letter saved.', 'wordcamporg' ),
		7  => __( 'Visa letter saved.', 'wordcamporg' ),
		8  => __( 'Visa letter submitted.', 'wordcamporg' ),
		9  => __( 'Visa letter saved.', 'wordcamporg' ),
		10 => __( 'Visa letter draft updated.', 'wordcamporg' ),
	);
	return $messages;
}
add_filter( 'post_updated_messages', 'ctx_vl_set_updated_messages' );

/**
 * Display custom post statuses.
 */
function ctx_vl_append_post_status_list() {

	global $post;
	$cancelled_selected = '';
	$status             = '';
	$cancelled          = __( 'cancelled', 'wordcamporg' );
	$cancelled_status   = _x( 'Cancelled', 'post', 'wordcamporg' );

	if ( 'tix_visa_letter' === $post->post_type ) {

		if ( 'cancelled' === $post->post_status ) {
			$cancelled_selected = ' selected=\"selected\"';
			$status             = $cancelled_status;
		}

		?>
		<script>
			jQuery( document ).ready( function($) {
				$( "select#post_status" ).append( "<option value=\"<?php echo esc_attr( $cancelled ); ?>\" <?php echo esc_attr( $cancelled_selected ); ?>><?php echo esc_html( $cancelled_status ); ?></option>" );
				<?php if ( ! empty( $status ) ) { ?>
					$( ".misc-pub-post-status #post-status-display" ).html( '<?php echo esc_html( $status ); ?>' );
				<?php } ?>
			});
		</script>
		<?php
	}
}
add_action( 'admin_footer-post.php', 'ctx_vl_append_post_status_list' );

/**
 * Show custom statuses on visa letters index.
 */
function ctx_vl_display_custom_statuses( $states, $post ) {
	$arg = get_query_var( 'post_status' );

	if ( 'cancelled' !== $arg ) {
		if ( 'cancelled' === $post->post_status ) {
			return array( _x( 'Cancelled', 'post', 'wordcamporg' ) );
		}
	}

	return $states;
}
add_filter( 'display_post_states', 'ctx_vl_display_custom_statuses', 10, 2 );

/**
 * Adding custom post status to Bulk and Quick Edit boxes: Status dropdown
 */
function ctx_vl_append_post_status_bulk_edit() {
	$screen = get_current_screen();
	if ( $screen && 'tix_visa_letter' !== $screen->post_type ) {
		return;
	}

	?>
	<script>
		jQuery( document ).ready( function($) {
			$( ".inline-edit-status select " ).append("<option value=\"<?php echo esc_attr( __( 'cancelled', 'wordcamporg' ) ); ?>\"><?php echo esc_html_x( 'Cancelled', 'post', 'wordcamporg' ); ?></option>" );
		});
	</script>
	<?php
}

add_action( 'admin_footer-edit.php', 'ctx_vl_append_post_status_bulk_edit' );

/**
 * Display a visa letter download button.
 *
 * @param object $post The post.
 */
function ctx_vl_letter_link( $post ) {

	if ( 'tix_visa_letter' !== $post->post_type ) {
		return false;
	}//end if

	$letter_number = get_post_meta( $post->ID, 'visa_letter_number', true );
	if ( empty( $letter_number ) ) {
		return false;
	}

	$letter_url = ctx_vl_get_letter_url( $post->ID );

	include CTX_VL_DIR . '/includes/views/visa-letter-download-button.php';
}
add_action( 'post_submitbox_misc_actions', 'ctx_vl_letter_link' );

/**
 * Register metabox on visa letters.
 *
 * @param object $post The post.
 */
function ctx_vl_register_letter_metabox( $post ) {

	$non_editable_statuses = array( 'publish', 'cancelled' );
	if ( in_array( $post->post_status, $non_editable_statuses, true ) ) {
		add_meta_box(
			'ctx_visa_letter_metabox',
			esc_html__( 'Info', 'wordcamporg' ),
			'ctx_vl_metabox_sent',
			'tix_visa_letter',
			'normal',
			'high'
		);
	} else {
		add_meta_box(
			'ctx_visa_letter_metabox',
			esc_html__( 'Info', 'wordcamporg' ),
			'ctx_vl_metabox_editable',
			'tix_visa_letter',
			'normal',
			'high'
		);
	}//end if
}
add_action( 'add_meta_boxes_tix_visa_letter', 'ctx_vl_register_letter_metabox' );

/**
 * Metabox for editable visa letter (not published).
 *
 * @param object $args The args.
 */
function ctx_vl_metabox_editable( $args ) {

	$metas = ctx_vl_open_metas( get_post_meta( $args->ID, 'visa_letter_metas', true ) );

	if ( ! is_array( $metas ) ) {
		$metas = array();
	}//end if

	wp_nonce_field( 'edit-visa-letter-' . get_current_user_id() . '-' . $args->ID, 'edit-visa-letter' );

	include CTX_VL_DIR . '/includes/views/editable-visa-letter-metabox.php';
}

/**
 * Metabox for published visa letters.
 *
 * @param object $args The args.
 */
function ctx_vl_metabox_sent( $args ) {

	$metas = ctx_vl_open_metas( get_post_meta( $args->ID, 'visa_letter_metas', true ) );

	if ( ! is_array( $metas ) ) {
		$metas = array();
	}//end if
	$txn_id = $metas['transaction_id'] ?? '';

	include CTX_VL_DIR . '/includes/views/sent-visa-letter-metabox.php';
}

/**
 * Save visa letter metabox.
 *
 * @param int $post_id The post ID.
 */
function ctx_vl_save_letter_details( $post_id ) {
	if ( ! isset( $_POST['edit-visa-letter'], $_POST['user_ID'], $_POST['post_ID'], $_POST['visa_letter_metas'] ) ) {
		return;
	}//end if

	check_admin_referer( 'edit-visa-letter-' . absint( $_POST['user_ID'] ) . '-' . absint( $_POST['post_ID'] ), 'edit-visa-letter' );

	$default_metas = array(
		'email'              => '',
		'first_name'         => '',
		'last_name'          => '',
		'passport_country'   => '',
		'passport_number'    => '',
		'date_of_birth'      => '',
		'nationality'        => '',
		'mailing_address'    => '',
	);

	$metas = wp_parse_args( $_POST['visa_letter_metas'], $default_metas );

	$final_metas = array(
		'email'              => sanitize_email( $metas['email'] ),
		'first_name'         => sanitize_text_field( $metas['first_name'] ),
		'last_name'          => sanitize_text_field( $metas['last_name'] ),
		'passport_country'   => sanitize_text_field( $metas['passport_country'] ),
		'passport_number'    => sanitize_text_field( $metas['passport_number'] ),
		'date_of_birth'      => sanitize_text_field( $metas['date_of_birth'] ),
		'nationality'        => sanitize_text_field( $metas['nationality'] ),
		'mailing_address'    => sanitize_textarea_field( $metas['mailing_address'] ),
	);

	update_post_meta( $post_id, 'visa_letter_metas', ctx_vl_seal_metas( $final_metas ) );
}
add_action( 'save_post_tix_visa_letter', 'ctx_vl_save_letter_details', 10, 2 );

/**
 * Mark a visa letter as draft when incomplete.
 *
 * @param int $letter_id The visa letter id.
 */
function ctx_vl_mark_incomplete_as_draft( $letter_id ) {
	if ( wp_is_post_revision( $letter_id ) || wp_is_post_autosave( $letter_id ) ) {
		return;
	}

	if ( 'tix_visa_letter' !== get_post_type( $letter_id ) ) {
		return;
	}

	if ( in_array( get_post_status( $letter_id ), array( 'trash', 'pending' ), true ) ) {
		return;
	}

	if ( CampTix_Addon_Visa_Letters::is_letter_incomplete( $letter_id ) ) {
		remove_action( 'save_post', 'ctx_vl_mark_incomplete_as_draft' );
		wp_update_post(
			array(
				'ID'          => $letter_id,
				'post_status' => 'draft',
			)
		);
		add_action( 'save_post', 'ctx_vl_mark_incomplete_as_draft' );
	}
}
add_action( 'save_post', 'ctx_vl_mark_incomplete_as_draft' );

/**
 * Assign a visa letter number.
 *
 * @param int $letter_id The visa letter id.
 */
function ctx_vl_assign_letter_number( $letter_id ) {
	if ( wp_is_post_revision( $letter_id ) || wp_is_post_autosave( $letter_id ) ) {
		return;
	}

	if ( 'tix_visa_letter' !== get_post_type( $letter_id ) ) {
		return;
	}

	if ( ! get_post_meta( $letter_id, 'visa_letter_number', true ) ) {
		$number = CampTix_Addon_Visa_Letters::create_letter_number();
		update_post_meta( $letter_id, 'visa_letter_number', $number );
	}
}
add_action( 'save_post', 'ctx_vl_assign_letter_number' );

/**
 * Generate the visa letter document.
 *
 * @param int $letter_id The visa letter id.
 */
function ctx_vl_generate_letter_document( $letter_id ) {
	if ( wp_is_post_revision( $letter_id ) || wp_is_post_autosave( $letter_id ) ) {
		return;
	}

	if ( 'tix_visa_letter' !== get_post_type( $letter_id ) ) {
		return;
	}

	if ( ! in_array( get_post_status( $letter_id ), array( 'publish', 'cancelled' ), true ) ) {
		return;
	}

	CampTix_Addon_Visa_Letters::create_letter_document( $letter_id );
}
add_action( 'save_post', 'ctx_vl_generate_letter_document' );

/**
 * Remove the visa letter document in drafts.
 *
 * @param int $letter_id The visa letter id.
 */
function ctx_vl_remove_letter_document_in_draft( $letter_id ) {
	if ( wp_is_post_revision( $letter_id ) || wp_is_post_autosave( $letter_id ) ) {
		return;
	}

	if ( 'tix_visa_letter' !== get_post_type( $letter_id ) ) {
		return;
	}

	if ( ! in_array( get_post_status( $letter_id ), array( 'draft', 'trash' ), true ) ) {
		return;
	}

	CampTix_Addon_Visa_Letters::delete_letter_document( $letter_id );
}
add_action( 'save_post', 'ctx_vl_remove_letter_document_in_draft' );

/**
 * Visa letter form generator.
 */
function ctx_vl_letter_form( $order, $options ) {

	if ( empty( $options['visa-letter-active'] ) ) {
		return;
	}

	include CTX_VL_DIR . '/includes/views/visa-letter-form.php';
}
add_action( 'camptix_form_attendee_after_registration_information', 'ctx_vl_letter_form', 10, 2 );

/**
 * Resolve the path to a visa letter's PDF, without ending the request.
 *
 * Quiet counterpart to `ctx_vl_get_letter()`, so callers on the payment-completion
 * path can degrade rather than `wp_die()` on an attendee who has already paid.
 *
 * @param int $letter_id The visa letter id.
 * @return string|false Full path to the PDF, or false when there is no readable file.
 */
function ctx_vl_locate_letter( $letter_id ) {
	$letter_document = get_post_meta( $letter_id, 'visa_letter_document', true );
	$letters_dirname = ctx_vl_get_letters_dir();

	if ( empty( $letter_document ) || ! $letters_dirname ) {
		return false;
	}

	$path = $letters_dirname . '/' . basename( $letter_document );

	// Letters generated before 1.1.0 live in the web-served uploads dir; move them out.
	if ( ! file_exists( $path ) ) {
		$upload_dir  = wp_upload_dir();
		$legacy_path = ! empty( $upload_dir['basedir'] ) ? $upload_dir['basedir'] . '/camptix-visa-letters/' . basename( $letter_document ) : '';
		if ( $legacy_path && $legacy_path !== $path && file_exists( $legacy_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- private directory outside WP_Filesystem's scope.
			rename( $legacy_path, $path );
		}
	}

	return file_exists( $path ) ? $path : false;
}

/**
 * Recovers a path for a PDF visa letter, or ends the request when there is none.
 *
 * Used by the download endpoint, where no file means there is nothing to serve.
 *
 * @param int $letter_id The visa letter id.
 * @return string Full path to the PDF.
 */
function ctx_vl_get_letter( $letter_id ) {
	$path = ctx_vl_locate_letter( $letter_id );

	if ( ! $path ) {
		wp_die( esc_html__( 'Visa letter document does not exist.', 'wordcamporg' ) );
	}

	return $path;
}

/**
 * URL of the authenticated download endpoint for a PDF visa letter.
 *
 * @param int $letter_id The visa letter id.
 */
function ctx_vl_get_letter_url( $letter_id ) {

	$letter_document = get_post_meta( $letter_id, 'visa_letter_document', true );
	if ( empty( $letter_document ) ) {
		return false;
	}

	return wp_nonce_url(
		add_query_arg(
			array(
				'action'    => 'ctx_vl_download',
				'letter_id' => absint( $letter_id ),
			),
			admin_url( 'admin-post.php' )
		),
		'ctx_vl_download_' . absint( $letter_id )
	);
}

/**
 * Stream a visa letter PDF to an authorized user.
 */
function ctx_vl_download_letter() {
	$letter_id = isset( $_GET['letter_id'] ) ? absint( $_GET['letter_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! $letter_id || 'tix_visa_letter' !== get_post_type( $letter_id ) ) {
		wp_die( esc_html__( 'Visa letter document does not exist.', 'wordcamporg' ), 404 );
	}

	check_admin_referer( 'ctx_vl_download_' . $letter_id );

	if ( ! current_user_can( 'edit_post', $letter_id ) ) {
		wp_die( esc_html__( 'You are not allowed to download this visa letter.', 'wordcamporg' ), 403 );
	}

	$path = ctx_vl_get_letter( $letter_id );

	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path ); // @codingStandardsIgnoreLine
	exit;
}
add_action( 'admin_post_ctx_vl_download', 'ctx_vl_download_letter' );

/**
 * Registers the personal data exporter for visa letters.
 *
 * @param array $exporters
 *
 * @return array
 */
function ctx_vl_register_data_exporter( $exporters ) {
	$exporters['camptix-visa-letter'] = array(
		'exporter_friendly_name' => __( 'CampTix Visa Letter Data', 'wordcamporg' ),
		'callback'               => 'ctx_vl_data_exporter',
	);

	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'ctx_vl_register_data_exporter' );

/**
 * Finds and exports visa letter data associated with an email address.
 *
 * @param string $email_address
 * @param int    $page
 *
 * @return array
 */
function ctx_vl_data_exporter( $email_address, $page ) {
	$page = (int) $page;

	$data_to_export = array();

	$post_query = ctx_vl_get_letter_posts( $email_address, $page );

	foreach ( (array) $post_query->posts as $post ) {
		$letter_data_to_export = array();

		$letter_number = get_post_meta( $post->ID, 'visa_letter_number', true );
		$letter_metas  = get_post_meta( $post->ID, 'visa_letter_metas', true );

		if ( ! is_array( $letter_metas ) ) {
			$letter_metas = array();
		}

		foreach ( $letter_metas as $key => $value ) {

			switch ( $key ) {
				case 'email':
					$label = __( 'Email', 'wordcamporg' );
					break;

				case 'first_name':
					$label = __( 'First Name', 'wordcamporg' );
					break;

				case 'last_name':
					$label = __( 'Last Name', 'wordcamporg' );
					break;

				case 'passport_country':
					$label = __( 'Passport Country', 'wordcamporg' );
					break;

				case 'nationality':
					$label = __( 'Nationality', 'wordcamporg' );
					break;

				case 'mailing_address':
					$label = __( 'Mailing Address', 'wordcamporg' );
					break;

				case 'entry_date':
					$label = __( 'Date of Entry into Canada', 'wordcamporg' );
					break;

				case 'exit_date':
					$label = __( 'Date of Exit from Canada', 'wordcamporg' );
					break;

				case 'accommodation':
					$label = __( 'Accommodation', 'wordcamporg' );
					break;

				// Deliberately exclude passport_number and date_of_birth from export.
				default:
					continue 2;
			}

			if ( ! empty( $value ) ) {
				$letter_data_to_export[] = array(
					'name'  => $label,
					'value' => $value,
				);
			}
		}

		if ( ! empty( $letter_number ) ) {
			$letter_data_to_export[] = array(
				'name'  => __( 'Visa Letter Number', 'wordcamporg' ),
				'value' => $letter_number,
			);
		}

		if ( ! empty( $letter_data_to_export ) ) {
			$data_to_export[] = array(
				'group_id'    => 'camptix-visa-letter',
				'group_label' => __( 'CampTix Visa Letter Data', 'wordcamporg' ),
				'item_id'     => "camptix-visa-letter-{$post->ID}",
				'data'        => $letter_data_to_export,
			);
		}
	}

	$done = $post_query->max_num_pages <= $page;

	return array(
		'data' => $data_to_export,
		'done' => $done,
	);
}

/**
 * Registers the personal data eraser for visa letters.
 *
 * @param array $erasers
 *
 * @return array
 */
function ctx_vl_register_data_eraser( $erasers ) {
	$erasers['camptix-visa-letter'] = array(
		'eraser_friendly_name' => __( 'CampTix Visa Letter Data', 'wordcamporg' ),
		'callback'             => 'ctx_vl_data_eraser',
	);

	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'ctx_vl_register_data_eraser' );

/**
 * Erase the personal data held by one visa letter.
 *
 * Blanks the personal fields, anonymizes the stored email, deletes the
 * generated PDF (it contains all of the personal data), anonymizes the
 * letter title, and stamps the `_ctx_vl_erased` marker so retention runs
 * skip the letter. The letter reference number and transaction ID are
 * retained for record-keeping.
 *
 * @param int $letter_id The visa letter ID.
 */
function ctx_vl_erase_letter( $letter_id ) {
	$letter_number = get_post_meta( $letter_id, 'visa_letter_number', true );
	$metas         = get_post_meta( $letter_id, 'visa_letter_metas', true );
	if ( ! is_array( $metas ) ) {
		$metas = array();
	}

	$metas['email']            = function_exists( 'wp_privacy_anonymize_data' ) ? wp_privacy_anonymize_data( 'email', $metas['email'] ?? '' ) : '';
	$metas['first_name']       = '';
	$metas['last_name']        = '';
	$metas['passport_country'] = '';
	$metas['passport_number']  = '';
	$metas['date_of_birth']    = '';
	$metas['nationality']      = '';
	$metas['mailing_address']  = '';
	$metas['entry_date']       = '';
	$metas['exit_date']        = '';
	$metas['accommodation']    = '';

	update_post_meta( $letter_id, 'visa_letter_metas', $metas );

	// The generated PDF contains every personal field — remove it.
	CampTix_Addon_Visa_Letters::delete_letter_document( $letter_id );

	// The title embeds the attendee name and email. Detach the save_post
	// handlers that would re-draft the now-incomplete letter or try to
	// regenerate a PDF from the blanked data.
	remove_action( 'save_post', 'ctx_vl_mark_incomplete_as_draft' );
	remove_action( 'save_post', 'ctx_vl_generate_letter_document' );
	wp_update_post(
		array(
			'ID'         => $letter_id,
			// translators: %s: visa letter reference number.
			'post_title' => sprintf( __( 'Visa Letter #%s (erased)', 'wordcamporg' ), $letter_number ),
		)
	);
	add_action( 'save_post', 'ctx_vl_mark_incomplete_as_draft' );
	add_action( 'save_post', 'ctx_vl_generate_letter_document' );

	update_post_meta( $letter_id, '_ctx_vl_erased', time() );
}

/**
 * Erases visa letter personal data associated with an email address.
 * (See ctx_vl_erase_letter() for what erasure covers per letter.)
 *
 * @param string $email_address
 * @param int    $page
 *
 * @return array
 */
function ctx_vl_data_eraser( $email_address, $page ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $page is part of core's eraser callback signature; erased items leave the query, so this always works the first page.
	$number        = 20;
	$items_removed = 0;

	// Erased items stop matching the email query, so the matching set
	// shrinks each round — always take the first page.
	$post_query = ctx_vl_get_letter_posts( $email_address, 1 );

	foreach ( (array) $post_query->posts as $post ) {
		ctx_vl_erase_letter( $post->ID );
		++$items_removed;
	}

	// Remove the staging copies stored on attendees during checkout.
	$attendee_query = new WP_Query(
		array(
			'posts_per_page' => $number,
			'post_type'      => 'tix_attendee',
			'post_status'    => 'any',
			'meta_key'       => 'visa_letter_metas',
			'meta_compare'   => 'LIKE',
			'meta_value'     => $email_address,
		)
	);

	foreach ( (array) $attendee_query->posts as $attendee ) {
		delete_post_meta( $attendee->ID, 'visa_letter_metas' );
		++$items_removed;
	}

	$messages = array();
	if ( $items_removed ) {
		$messages[] = __( 'Visa letter reference numbers and transaction IDs were retained for record-keeping.', 'wordcamporg' );
	}

	return array(
		'items_removed'  => $items_removed,
		'items_retained' => (bool) $items_removed,
		'messages'       => $messages,
		'done'           => $post_query->found_posts <= $number && $attendee_query->found_posts <= $number,
	);
}

/**
 * Keep the daily retention cron in sync with the retention setting.
 */
function ctx_vl_retention_schedule() {
	$opt       = get_option( 'camptix_options' );
	$enabled   = ! empty( $opt['visa-letter-retention'] );
	$scheduled = wp_next_scheduled( 'ctx_vl_retention_cron' );

	if ( $enabled && ! $scheduled ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ctx_vl_retention_cron' );
	} elseif ( ! $enabled && $scheduled ) {
		wp_unschedule_event( $scheduled, 'ctx_vl_retention_cron' );
	}
}
add_action( 'init', 'ctx_vl_retention_schedule' );

/**
 * Retention purge: once the event has been over for the configured number
 * of days, erase the personal data of every letter on the site and remove
 * the visa staging metas from attendees. Runs even when visa letter
 * requests have since been deactivated.
 *
 * @return int Number of items erased.
 */
function ctx_vl_retention_purge() {
	$batch = 100;
	$opt   = get_option( 'camptix_options' );
	if ( empty( $opt['visa-letter-retention'] ) ) {
		return 0;
	}

	$end = 0;
	if ( function_exists( 'get_wordcamp_post' ) ) {
		$wordcamp = get_wordcamp_post();
		if ( $wordcamp ) {
			$end = (int) ( $wordcamp->meta['End Date (YYYY-mm-dd)'][0] ?? 0 );
			if ( ! $end ) {
				// Single-day camps leave the End Date empty.
				$end = (int) ( $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ?? 0 );
			}
		}
	}

	/**
	 * Filters the event end timestamp the retention cutoff is based on.
	 *
	 * @param int $end Unix timestamp of the event end, 0 if unknown.
	 */
	$end = (int) apply_filters( 'ctx_vl_event_end_timestamp', $end );
	if ( ! $end ) {
		return 0;
	}

	$days = isset( $opt['visa-letter-retention-days'] ) ? absint( $opt['visa-letter-retention-days'] ) : 30;
	if ( time() < $end + $days * DAY_IN_SECONDS ) {
		return 0;
	}

	$erased = 0;

	$letters = get_posts(
		array(
			'post_type'      => 'tix_visa_letter',
			'post_status'    => 'any',
			'posts_per_page' => $batch,
			'fields'         => 'ids',
			'meta_query'     => array( // @codingStandardsIgnoreLine
				array(
					'key'     => '_ctx_vl_erased',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);
	foreach ( $letters as $letter_id ) {
		ctx_vl_erase_letter( $letter_id );
		++$erased;
	}

	$attendees = get_posts(
		array(
			'post_type'      => 'tix_attendee',
			'post_status'    => 'any',
			'posts_per_page' => $batch,
			'fields'         => 'ids',
			'meta_query'     => array( // @codingStandardsIgnoreLine
				array(
					'key'     => 'visa_letter_metas',
					'compare' => 'EXISTS',
				),
			),
		)
	);
	foreach ( $attendees as $attendee_id ) {
		delete_post_meta( $attendee_id, 'visa_letter_metas' );
		++$erased;
	}

	if ( $erased ) {
		global $camptix;
		if ( $camptix && is_callable( array( $camptix, 'log' ) ) ) {
			$camptix->log( sprintf( 'Visa letter retention purge erased personal data from %d item(s).', $erased ) );
		}

		// A full batch means there may be more — follow up shortly instead
		// of waiting a day.
		if ( count( $letters ) === $batch || count( $attendees ) === $batch ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'ctx_vl_retention_cron' );
		}
	}

	return $erased;
}
add_action( 'ctx_vl_retention_cron', 'ctx_vl_retention_purge' );

/**
 * Get the list of visa letter posts related to a particular email address.
 *
 * @param string $email_address
 * @param int    $page
 *
 * @return WP_Query
 */
function ctx_vl_get_letter_posts( $email_address, $page ) {
	$number = 20;

	return new WP_Query(
		array(
			'posts_per_page' => $number,
			'paged'          => $page,
			'post_type'      => 'tix_visa_letter',
			'post_status'    => 'any',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_key'       => 'visa_letter_metas',
			'meta_compare'   => 'LIKE',
			'meta_value'     => $email_address,
		)
	);
}
