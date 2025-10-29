<?php
/**
 * Add a fixed set of ticket types to every site, globally defined.
 */

namespace WordCamp\CampTix_Tweaks\Ticket_Types;
defined( 'WPINC' ) || die();

const META_KEY = 'tix_type';

require_once __DIR__ . '/remote.php';

add_action( 'camptix_init', __NAMESPACE__ . '\camptix_init' );

/**
 * Hook into WordPress and Camptix.
 */
function camptix_init() {
	register_post_meta(
		'tix_ticket',
		META_KEY,
		array(
			'single'            => true,
			'sanitize_callback' => __NAMESPACE__ . '\get_slug_from_value',
		)
	);

	add_action( 'camptix_add_meta_boxes', __NAMESPACE__ . '\add_types_meta_box' );
	add_action( 'save_post', __NAMESPACE__ . '\save_post' );

	add_filter( 'manage_edit-tix_ticket_columns', __NAMESPACE__ . '\manage_columns_filter', 9 );
	add_action( 'manage_tix_ticket_posts_custom_column', __NAMESPACE__ . '\manage_columns_output', 10, 2 );
}

/**
 * Get the list of ticket types.
 *
 * @return array[] List of associative arrays representing ticket-types.
 */
function get_types() {
	return apply_filters(
		'camptix_ticket_types',
		array(
			'default' => array(
				'slug' => 'default',
				'name' => __( 'Default', 'wordcamporg' ),
				'description' => __( 'Default, in-person attendee.', 'wordcamporg' ),
			),
		)
	);
}

/**
 * Match the given value to an existing type slug, return the slug, or "default" if not a match.
 */
function get_slug_from_value( $value ) {
	$types = get_types();
	if ( isset( $types[ $value ] ) ) {
		return $value;
	}
	return 'default';
}

/**
 * Get the type of a given ticket.
 *
 * @return array
 */
function get_type( $ticket_id ) {
	$types = get_types();
	$type = get_post_meta( $ticket_id, META_KEY, true );
	if ( ! $type ) {
		$type = 'default';
	}

	if ( isset( $types[ $type ] ) ) {
		return $types[ $type ];
	}

	return $types[ 'default' ];
}

/**
 * Get the slug for the type of a given ticket.
 *
 * @return string
 */
function get_type_slug( $ticket_id ) {
	$type = get_type( $ticket_id );
	return isset( $type['slug'] ) ? $type['slug'] : 'default';
}

/**
 * Add the Ticket Types metabox to ticket editor.
 */
function add_types_meta_box() {
	\add_meta_box( 'tix_ticket_types', __( 'Ticket Type', 'wordcamporg' ), __NAMESPACE__ . '\metabox_ticket_type', 'tix_ticket', 'normal', 'high' );
}

/**
 * Metabox callback for ticket type.
 */
function metabox_ticket_type() {
	global $camptix;
	$purchased = $camptix->get_purchased_tickets_count( get_the_ID() );
	$selected = get_type_slug( get_the_ID() );
	$types = get_types();
	?>
	<p>
		<?php esc_html_e( 'The type of ticket will determine how we set up the required questions. This cannot be changed once someone buys a ticket.', 'wordcamporg' ); ?>
	</p>
	<p>
		<ul>
		<?php foreach ( $types as $type ) : ?>
			<li><strong><?php echo esc_html( $type['name'] ); ?>:</strong> <?php echo esc_html( $type['description'] ); ?></li>
		<?php endforeach; ?>
		</ul>
	</p>
	<p>
		<label for="<?php echo esc_attr( META_KEY ); ?>"><?php esc_attr_e( 'Type:', 'wordcamporg' ); ?></label>
		<select
			id="<?php echo esc_attr( META_KEY ); ?>"
			name="<?php echo esc_attr( META_KEY ); ?>"
			<?php echo ( $purchased <= 0 ) ? '' : 'disabled="disabled"'; ?>
		>
			<?php foreach ( $types as $type ) : ?>
				<option value="<?php echo esc_attr( $type['slug'] ); ?>" <?php selected( $selected, $type['slug'] ); ?>>
					<?php echo esc_html( $type['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>

	<?php if ( $purchased > 0 ) : ?>
	<p>
		<?php esc_html_e( 'You can not change the type because one or more tickets have already been purchased.', 'wordcamporg' ); ?>
	</p>
	<?php endif; ?>

	<div class="clear"></div>
	<?php
}

/**
 * Callback on `save_post`, used to set our type meta.
 *
 * @param int $post_id
 */
function save_post( $post_id ) {
	if ( ! is_admin() ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || 'tix_ticket' !== get_post_type( $post_id ) ) {
		return;
	}

	// Stuff here is submittable via POST only.
	if ( ! isset( $_POST['action'] ) || 'editpost' != $_POST['action'] ) {
		return;
	}

	// Security check.
	$nonce_action = 'update-post_' . $post_id;
	check_admin_referer( $nonce_action );

	if ( isset( $_POST['tix_type'] ) ) {
		$value = filter_input( INPUT_POST, 'tix_type', FILTER_UNSAFE_RAW );
		update_post_meta( $post_id, META_KEY, $value );
	}
}

/**
 * Manage columns filter for ticket post type.
 *
 * @param array $columns
 */
function manage_columns_filter( $columns ) {
	$columns[ META_KEY ] = __( 'Type', 'wordcamporg' );
	return $columns;
}

/**
 * Manage columns action for ticket post type.
 *
 * @param string $column
 * @param int    $post_id
 */
function manage_columns_output( $column, $post_id ) {
	if ( META_KEY === $column ) {
		$selected = get_type( $post_id );
		echo esc_html( $selected['name'] );
	}
}
