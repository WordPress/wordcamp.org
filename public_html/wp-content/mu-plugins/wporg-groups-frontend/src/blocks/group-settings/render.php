<?php
/**
 * Server-side rendering for the wporg/group-settings block.
 *
 * Renders the settings trigger button for users who can manage events.
 * On singular event pages, also renders an "Edit this event" button.
 * The React settings app mounts via the view script.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;

if ( ! current_user_can_manage_events() ) {
	return;
}

$event_post_id = ! empty( $block->context['postId'] )
	? (int) $block->context['postId']
	: ( get_the_ID() ?: get_queried_object_id() );

$is_single_event = $event_post_id
	&& 'gatherpress_event' === get_post_type( $event_post_id )
	&& is_singular( 'gatherpress_event' );

// Check if this is a new group with incomplete setup.
$description          = trim( (string) get_option( 'blogdescription', '' ) );
$default_descriptions = array_unique(
	array(
		'Just another WordPress site',
		__( 'Just another WordPress site', 'default' ),
	)
);
$needs_setup          = '' === $description || in_array( $description, $default_descriptions, true );
$can_manage_roles = current_user_can( 'promote_users' );

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-group-settings-block' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $is_single_event ) : ?>
		<button
			type="button"
			class="wporg-group-settings-block__edit wp-element-button"
			data-wporg-settings-open="events"
			data-wporg-settings-event-id="<?php echo (int) $event_post_id; ?>"
		>&#9998; <?php esc_html_e( 'Edit this event', 'wporg-groups-frontend' ); ?></button>
	<?php else : ?>
		<button
			type="button"
			class="wporg-group-settings-block__trigger<?php echo $needs_setup ? ' wporg-group-settings-block__trigger--setup' : ''; ?>"
			data-wporg-settings-open="<?php echo $needs_setup ? 'about' : ''; ?>"
		>
			<?php if ( $needs_setup ) : ?>
				<?php esc_html_e( 'Set up your group', 'wporg-groups-frontend' ); ?>
			<?php else : ?>
				<span class="dashicons dashicons-admin-generic"></span>
				<?php esc_html_e( 'Settings', 'wporg-groups-frontend' ); ?>
			<?php endif; ?>
		</button>
	<?php endif; ?>

	<div
		id="wporg-group-settings-root"
		data-site-name="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
		data-can-manage-roles="<?php echo esc_attr( $can_manage_roles ? 'true' : 'false' ); ?>"
	></div>
</div>
