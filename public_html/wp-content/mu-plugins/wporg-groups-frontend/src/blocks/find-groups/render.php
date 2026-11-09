<?php
/**
 * Server-side rendering for the wporg/find-groups block.
 *
 * A plain GET form, so a search is a shareable link and works without JavaScript.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Group_Location\get_location_label;
use function WordCamp\Groups\Frontend\Group_Location\search_groups_by_location;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public, read-only search.
$search_term = sanitize_text_field( wp_unslash( $_GET['groups_near'] ?? '' ) );
$groups      = search_groups_by_location( $search_term );
?>
<section <?php echo get_block_wrapper_attributes( array( 'id' => 'find-groups' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core. ?>>
	<h2 class="wporg-find-groups__heading"><?php esc_html_e( 'Find groups near you', 'wordcamporg' ); ?></h2>

	<form class="wporg-find-groups__form" role="search" method="get" action="#find-groups">
		<label for="wporg-find-groups-input"><?php esc_html_e( 'City or country', 'wordcamporg' ); ?></label>
		<input id="wporg-find-groups-input" type="search" name="groups_near" value="<?php echo esc_attr( $search_term ); ?>" required />
		<div class="wp-block-button is-style-outline">
			<button type="submit" class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Search', 'wordcamporg' ); ?></button>
		</div>
	</form>

	<?php if ( $groups ) : ?>
		<ul class="wporg-find-groups__results">
			<?php foreach ( $groups as $group ) : ?>
				<li>
					<a href="<?php echo esc_url( $group->home ); ?>"><?php echo esc_html( $group->blogname ); ?></a>
					<span><?php echo esc_html( get_location_label( (int) $group->blog_id ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( '' !== $search_term ) : ?>
		<p class="wporg-find-groups__empty">
			<?php
			printf(
				/* translators: %s: the city or country that was searched for. */
				esc_html__( 'No groups found for "%s".', 'wordcamporg' ),
				esc_html( $search_term )
			);
			?>
		</p>
	<?php endif; ?>
</section>
