<?php defined( 'WPINC' ) || die();

/**
 * Slightly modify allowed html tags in entry message because default
 * "data" set is too restrictive and "post" set too broad.
 */
$allowed_html = wp_kses_allowed_html( 'data' );
$allowed_html['p'] = array(); // allow paragraph for easier reading
$allowed_html['br'] = array(); // allow line changes for easier reading
$allowed_html['a']['target'] = true; // target to ensure not losing application view
$allowed_html['a']['rel'] = true; // we want to set nofollow so search engines don't use link for ranking, crawling or indexing

/**
 * Loop all entries and search for notes. In notes, turn plaintext links
 * to linkified html links for easier usage. Add always target="_blank" and
 * rel="nofollow".
 */
if ( $entries ) {
	foreach ( $entries as $entry_key => $entry ) {
		if ( 'note' !== $entry['type'] ) {
			continue;
		}

		$entries[ $entry_key ]['message'] = preg_replace("/(^|[\n ])([\w]*?)(http(s)?:\/\/[\w]+[^ \,\"\n\r\t<]*)/is", "$1$2<a href=\"$3\" target=\"_blank\" rel=\"nofollow\">$3</a>", $entries[ $entry_key ]['message'] );
	}
} ?>

<table class="widefat striped">
	<thead>
		<tr>
			<th>Timestamp</th>
			<th>Type</th>
			<th>User</th>
			<th>Message</th>
		</tr>
	</thead>

	<tbody>
		<?php if ( $entries ) : ?>

			<?php foreach ( $entries as $entry ) : ?>
				<tr class="<?php echo esc_attr( str_replace( '_', '-', $entry['type'] ) ); ?>">
					<th><p><?php echo esc_html( date( 'Y-m-d h:ia', $entry['timestamp'] ) ); ?></p></th>
					<th><p><?php echo esc_html( ucwords( str_replace( '_', ' ', $entry['type'] ) ) ); ?></p></th>
					<th><p><?php echo esc_html( $entry['user_display_name'] ); ?></p></th>
					<th><?php echo wp_kses( wpautop( $entry['message'] ), $allowed_html ); ?></th>
				</tr>
			<?php endforeach; ?>

		<?php else : ?>

			<tr>
				<td colspan="4">
					There aren't any log entries yet.
				</td>
			</tr>

		<?php endif; ?>
	</tbody>
</table>
