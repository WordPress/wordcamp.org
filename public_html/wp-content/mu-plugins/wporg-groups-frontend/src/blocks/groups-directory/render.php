<?php
/**
 * Server-side rendering for the wporg/groups-directory block.
 *
 * The search is a plain GET form, so a search is a link that can be shared
 * and the page works without JavaScript.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Groups_Directory\get_directory_url;
use function WordCamp\Groups\Frontend\Groups_Directory\get_events_site_url;
use function WordCamp\Groups\Frontend\Groups_Directory\get_groups;
use function WordCamp\Groups\Frontend\Groups_Directory\get_search_term;
use function WordCamp\Groups\Frontend\Groups_Directory\render_search_form;
use function WordCamp\Groups\Frontend\Groups_Directory\search_groups;

use const WordCamp\Groups\Frontend\Groups_Directory\ANNOUNCEMENT_URL;

$search_term = get_search_term();
$groups      = search_groups( get_groups(), $search_term );
$group_count = count( $groups );
$is_search   = '' !== $search_term;
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core. ?>>
	<p class="wporg-groups-directory__notice">
		<?php
		printf(
			wp_kses(
				/* translators: 1: URL of the post announcing the move from Meetup.com; 2: URL of the events home page. */
				__( '<strong>This list is still growing.</strong> WordPress groups are moving here from Meetup.com, so a group near you may not be listed yet. <a href="%1$s">Read about the move</a>, or <a href="%2$s">find events near you</a>.', 'wordcamporg' ),
				array(
					'strong' => array(),
					'a'      => array( 'href' => array() ),
				)
			),
			esc_url( ANNOUNCEMENT_URL ),
			esc_url( get_events_site_url() )
		);
		?>
	</p>

	<div class="wporg-groups-directory__search">
		<?php echo render_search_form( $search_term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's Search block, with its attributes set through the HTML API. ?>
	</div>

	<h2 class="screen-reader-text"><?php esc_html_e( 'Group list', 'wordcamporg' ); ?></h2>

	<?php if ( $groups ) : ?>
		<p class="wporg-groups-directory__count">
			<?php
			if ( $is_search ) {
				echo esc_html(
					sprintf(
						/* translators: 1: number of groups; 2: what the visitor searched for. */
						_n( '%1$s group matches “%2$s”', '%1$s groups match “%2$s”', $group_count, 'wordcamporg' ),
						number_format_i18n( $group_count ),
						$search_term
					)
				);
				?>
				<a class="wporg-groups-directory__reset" href="<?php echo esc_url( get_directory_url() ); ?>"><?php esc_html_e( 'View all groups', 'wordcamporg' ); ?></a>
				<?php
			} else {
				echo esc_html(
					sprintf(
						/* translators: %s: number of groups. */
						_n( '%s group', '%s groups', $group_count, 'wordcamporg' ),
						number_format_i18n( $group_count )
					)
				);
			}
			?>
		</p>

		<ul class="wporg-groups-directory__list">
			<?php foreach ( $groups as $group ) : ?>
				<li class="wporg-groups-directory__group">
					<a class="wporg-groups-directory__name" href="<?php echo esc_url( $group['url'] ); ?>"><?php echo esc_html( $group['name'] ); ?></a>

					<?php if ( '' !== $group['location'] ) : ?>
						<span class="wporg-groups-directory__location">
							<svg class="wporg-groups-directory__location-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Z" fill="none" stroke="currentColor" stroke-width="2"/>
								<circle cx="12" cy="9" r="2.25"/>
							</svg>
							<?php echo esc_html( $group['location'] ); ?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( $is_search ) : ?>
		<div class="wporg-groups-directory__empty">
			<p class="wporg-groups-directory__empty-title">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: what the visitor searched for. */
						__( 'No groups match “%s”.', 'wordcamporg' ),
						$search_term
					)
				);
				?>
			</p>
			<p class="wporg-groups-directory__empty-hint">
				<?php esc_html_e( 'Try another name, city, or country.', 'wordcamporg' ); ?>
				<a href="<?php echo esc_url( get_directory_url() ); ?>"><?php esc_html_e( 'View all groups', 'wordcamporg' ); ?></a>
			</p>
		</div>
	<?php else : ?>
		<div class="wporg-groups-directory__empty">
			<p class="wporg-groups-directory__empty-title"><?php esc_html_e( 'No groups are listed yet.', 'wordcamporg' ); ?></p>
		</div>
	<?php endif; ?>
</div>
