<?php
defined( 'WPINC' ) || die();

/**
 * Renders the status metabox for events. Will display current status of the event. If user has edit_post access then will display dropdown of all available statuses for this post.
 *
 * @param Event_Admin $event_admin Event admin object. Must have methods `get_valid_status_transitions` and `get_post_statuses`
 * @param WP_Post     $post Post object
 * @param string      $event_type Type of event. Could be 'wordcamp' or 'wp_meetup'.
 * @param string      $label Label to display. Could be 'WordCamp' or 'Meetup' as of now.
 * @param string      $edit_capability Name of the capability which allows to edit the event
 */
function render_event_metabox( $event_admin, $post, $event_type, $label, $edit_capability ) {
	$wcpt = get_post_type_object( $event_type );

	$event_subtype  = $post->event_subtype ?: $event_type;
	$event_subtypes = $event_admin->get_event_subtypes();
	?>

	<div id="submitpost" class="wcb submitbox">
		<div id="minor-publishing">
			<div id="misc-publishing-actions">
			<div class="misc-pub-section misc-pub-post-status">
					<label>
						<?php echo esc_html( $label ); ?> Type:

						<?php if ( current_user_can( $edit_capability ) ) : ?>

							<span id="post-status-display">
							<select name="event_subtype">
								<?php
								foreach ( $event_subtypes as $key => $event_subtype_label ) {
									printf(
										'<option %s value="%s">%s</option>',
										selected( $event_subtype, $key, false ),
										esc_attr( $key ),
										esc_html( $event_subtype_label )
									);
								}
								?>
							</select>
						</span>

						<?php else : ?>

							<span id="post-status-display">
							<?php echo esc_html( $event_subtypes[ $event_subtype ] ); ?>
						</span>

						<?php endif; ?>
					</label>
				</div>
				<div class="misc-pub-section misc-pub-post-status">
					<label>
						<?php echo esc_html( $label ); ?> Status:

						<?php if ( current_user_can( $edit_capability ) ) : ?>

							<span id="post-status-display">
							<select name="post_status">
								<?php
								$transitions   = $event_admin->get_valid_status_transitions( $post->post_status );
								$post_statuses = $event_admin->get_post_statuses();

								// If the current status is not in the list (e.g. a mid-transition state),
								// add it so saving never silently mutates the status.
								if ( ! array_key_exists( $post->post_status, $post_statuses ) ) {
									$current_obj                         = get_post_status_object( $post->post_status );
									$post_statuses[ $post->post_status ] = $current_obj->label ?? $post->post_status;
								}
								?>
								<?php foreach ( $post_statuses as $key => $post_status_label ) : ?>
									<?php
									$is_current = $post->post_status === $key;
									$disabled   = ! $is_current && ! in_array( $key, $transitions, true );
									?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php
									selected( $is_current );
									disabled( $disabled );
									?>>
										<?php echo esc_html( $post_status_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</span>

						<?php else : ?>

							<span id="post-status-display">
							<?php
								$status = get_post_status_object( $post->post_status );
								echo esc_html( $status->label );
							?>
						</span>

						<?php endif; ?>
					</label>
				</div>
				<div class="clear"></div>
			</div> <!-- #misc-publishing-actions -->

			<div class="clear"></div>
		</div> <!-- #minor-publishing -->

		<div id="major-publishing-actions">
			<div id="delete-action">
				<?php if ( current_user_can( $wcpt->cap->delete_post, $post->ID ) ) : ?>
					<a class="submitdelete deletion" href="<?php echo get_delete_post_link( $post->ID ); ?>">
						<?php _e( 'Delete', 'wordcamporg' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<div id="publishing-action">
				<?php submit_button( 'Update', 'primary button-large', 'wcpt-update', false, array( 'accesskey' => 'p' ) ); ?>
			</div>

			<div class="clear"></div>
		</div> <!-- #major-publishing-actions -->

	</div> <!-- .submitbox -->
	<?php
}
