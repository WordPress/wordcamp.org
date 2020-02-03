<?php

namespace WordCamp\SpeakerFeedback\View;

$session_args = array(
	'post_type'      => 'wcb_session',
	'posts_per_page' => -1,
	'orderby'        => 'title',
	'order'          => 'asc',
	// get only sessions, no breaks.
	'meta_key'       => '_wcpt_session_type',
	'meta_value'     => 'session',
);

$sessions = new \WP_Query( $session_args );

if ( $sessions->have_posts() ) : ?>
<form>
	<select name="sft_session">
		<?php while ( $sessions->have_posts() ) {
			$sessions->the_post();
			printf(
				'<option value="%s">%s</option>',
				esc_attr( get_the_ID() ),
				wp_kses_post( get_the_title() )
			);
		} ?>
	</select>
	<input type="submit" value="<?php esc_attr_e( 'Give Feedback', 'wordcamporg' ); ?>" />
</form>
<?php endif;
