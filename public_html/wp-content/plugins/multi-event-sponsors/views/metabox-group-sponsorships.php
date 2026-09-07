<?php
/**
 * Sponsor-side table mapping each sponsor group to a sponsorship level.
 *
 * @var array $groups             Available mes_sponsor_group terms.
 * @var array $sponsorship_levels Available level posts.
 * @var array $group_sponsorships Stored { group term ID => level post ID } map.
 */

?>
<?php if ( empty( $groups ) ) : ?>

	<p>
		No sponsor groups exist yet. Create them under
		<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . MES_Sponsor_Group::TAXONOMY_SLUG . '&post_type=' . MES_Sponsor::POST_TYPE_SLUG ) ); ?>">Sponsor Groups</a>.
	</p>

<?php else : ?>

	<table>
		<thead>
			<tr>
				<th>Group</th>
				<th>Sponsorship Level</th>
			</tr>
		</thead>

		<tbody>
			<?php foreach ( $groups as $group ) : ?>

				<tr>
					<td>
						<label for="mes_group_sponsorships-<?php echo esc_attr( $group->term_id ); ?>">
							<?php echo esc_html( $group->name ); ?>
						</label>
					</td>

					<td>
						<select id="mes_group_sponsorships-<?php echo esc_attr( $group->term_id ); ?>" name="mes_group_sponsorships[<?php echo esc_attr( $group->term_id ); ?>]">
							<option value="">None</option>

							<?php foreach ( $sponsorship_levels as $level ) : ?>
								<option value="<?php echo esc_attr( $level->ID ); ?>" <?php selected( $group_sponsorships[ $group->term_id ] ?? 0, $level->ID ); ?>>
									<?php echo esc_html( $level->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>

			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="description">
		A sponsor is placed on every camp that belongs to a group with a level selected here.
		Groups take precedence over the legacy region mapping.
	</p>

<?php endif; ?>
