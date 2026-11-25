<?php
/**
 * Server-side rendering for the wporg/event-manage block.
 *
 * Renders create/edit event buttons for users who can manage events.
 * The React modal app mounts via the view script.
 *
 * @package WordCamp\Groups\Frontend
 */

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;
use function WordCamp\Groups\Frontend\Check_In\is_open as check_in_is_open;
use function WordCamp\Groups\Frontend\REST\current_user_can_copy_event;
use function WordCamp\Groups\Frontend\REST\current_user_can_edit_event;

if ( ! current_user_can_manage_events() ) {
	return;
}

$event_post_id = ! empty( $block->context['postId'] )
	? (int) $block->context['postId']
	: ( get_the_ID() ?: get_queried_object_id() );

$block_mode = $attributes['mode'] ?? 'auto';

$is_single_event = $event_post_id
	&& 'gatherpress_event' === get_post_type( $event_post_id )
	&& is_singular( 'gatherpress_event' );

$show_edit   = false;
$show_create = false;

if ( 'edit' === $block_mode ) {
	$show_edit = true;
} elseif ( 'create' === $block_mode ) {
	$show_create = true;
} elseif ( $is_single_event ) {
	$show_edit = true;
} else {
	$show_create = true;
}

if ( $show_edit && ( ! $event_post_id || ! current_user_can_edit_event( $event_post_id ) ) ) {
	$show_edit = false;
}

$can_copy = $is_single_event && current_user_can_copy_event( $event_post_id );

/*
 * Check-in (#2130) is offered from shortly before the start, when there's
 * someone to check in, and stays for past events so the list can be caught
 * up afterwards. On a recurring series it's for the date being viewed, which
 * the recurring-events integration supplies through the filter.
 */
$show_check_in       = false;
$check_in_recurrence = '';

if ( $show_edit && $is_single_event && $event_post_id ) {
	$check_in_event = new Event( $event_post_id );
	$show_check_in  = ( new Rsvp( $event_post_id ) )->is_enabled()
		&& check_in_is_open( $check_in_event );

	$check_in_recurrence = (string) apply_filters( 'wporg_groups_frontend_current_recurrence_id', '', $event_post_id );
}

if ( $show_create ) :
	$wrapper_attributes = get_block_wrapper_attributes(
		array( 'class' => 'wp-block-buttons wporg-event-manage' )
	);
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="wp-block-button">
			<button
				type="button"
				class="wp-block-button__link wp-element-button"
				data-wporg-groups-modal="create"
			>+ <?php esc_html_e( 'Create event', 'wordcamporg' ); ?></button>
		</div>

		<div id="wporg-groups-event-modal-root"></div>
	</div>
	<?php
	return;
endif;

/*
 * The organizer's actions on an event, as small links rather than full
 * buttons: reaching people on the first row, changing the event on the
 * second. Everyone edits in the event modal, the same one that creates
 * events (#2424).
 */
$people_tools = array();
$event_tools  = array();

if ( $show_edit ) {
	$people_tools[] = array(
		'icon'  => 'envelope',
		'label' => __( 'Message all members', 'wordcamporg' ),
		'attrs' => array(
			'data-wporg-groups-modal'    => 'message-all',
			'data-wporg-groups-event-id' => $event_post_id,
		),
	);
	$people_tools[] = array(
		'icon'  => 'envelope',
		'label' => __( 'Message attendees', 'wordcamporg' ),
		'attrs' => array(
			'data-wporg-groups-modal'    => 'message-attendees',
			'data-wporg-groups-event-id' => $event_post_id,
		),
	);
}

if ( $show_check_in ) {
	$people_tools[] = array(
		'icon'  => 'check',
		'label' => __( 'Check in attendees', 'wordcamporg' ),
		'attrs' => array(
			'data-wporg-groups-modal'          => 'check-in',
			'data-wporg-groups-event-id'       => $event_post_id,
			'data-wporg-groups-recurrence-id'  => $check_in_recurrence,
		),
	);
}

if ( $show_edit ) {
	$event_tools[] = array(
		'icon'  => 'pencil',
		'label' => __( 'Edit this event', 'wordcamporg' ),
		'attrs' => array(
			'data-wporg-groups-modal'    => 'edit',
			'data-wporg-groups-event-id' => $event_post_id,
		),
	);
}

if ( $can_copy ) {
	$event_tools[] = array(
		'icon'  => 'copy',
		'label' => __( 'Copy this event', 'wordcamporg' ),
		'attrs' => array(
			'data-wporg-groups-modal'    => 'copy',
			'data-wporg-groups-event-id' => $event_post_id,
		),
	);
}

$tool_rows = array_filter( array( $people_tools, $event_tools ) );

if ( ! $tool_rows ) {
	return;
}

// Paths from @wordpress/icons.
$tool_icons = array(
	'pencil'   => 'm19 7-3-3-8.5 8.5-1 4 4-1L19 7Zm-7 11.5H5V20h7v-1.5Z',
	'copy'     => 'M5.625 5.5h9.75c.069 0 .125.056.125.125v9.75a.125.125 0 0 1-.125.125h-9.75a.125.125 0 0 1-.125-.125v-9.75c0-.069.056-.125.125-.125ZM4 5.625C4 4.728 4.728 4 5.625 4h9.75C16.273 4 17 4.728 17 5.625v9.75c0 .898-.727 1.625-1.625 1.625h-9.75A1.625 1.625 0 0 1 4 15.375v-9.75Zm14.5 11.656v-9H20v9C20 18.8 18.77 20 17.251 20H6.25v-1.5h11.001c.69 0 1.249-.528 1.249-1.219Z',
	'envelope' => 'M3 7c0-1.1.9-2 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Zm2-.5h14c.3 0 .5.2.5.5v1L12 13.5 4.5 7.9V7c0-.3.2-.5.5-.5Zm-.5 3.3V17c0 .3.2.5.5.5h14c.3 0 .5-.2.5-.5V9.8L12 15.4 4.5 9.8Z',
	'check'    => 'M16.5 7.5 10 13.9l-2.5-2.4-1 1 3.5 3.6 7.5-7.6z',
);

$tools_label_id     = wp_unique_id( 'wporg-event-manage-label-' );
$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'           => 'wporg-event-manage wporg-event-manage--tools',
		'role'            => 'group',
		'aria-labelledby' => $tools_label_id,
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<p class="wporg-event-manage__label" id="<?php echo esc_attr( $tools_label_id ); ?>">
		<?php esc_html_e( 'Organizer tools', 'wordcamporg' ); ?>
	</p>

	<?php foreach ( $tool_rows as $tools ) : ?>
	<div class="wporg-event-manage__tools">
		<?php foreach ( $tools as $tool ) : ?>
			<button
				type="button"
				class="wporg-event-manage__tool"
				<?php foreach ( $tool['attrs'] as $name => $value ) : ?>
					<?php echo esc_attr( $name ); ?>="<?php echo esc_attr( (string) $value ); ?>"
				<?php endforeach; ?>
			>
				<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
					<path fill-rule="evenodd" clip-rule="evenodd" d="<?php echo esc_attr( $tool_icons[ $tool['icon'] ] ); ?>"></path>
				</svg>
				<?php echo esc_html( $tool['label'] ); ?>
			</button>
		<?php endforeach; ?>
	</div>
	<?php endforeach; ?>

	<div id="wporg-groups-event-modal-root"></div>
</div>
