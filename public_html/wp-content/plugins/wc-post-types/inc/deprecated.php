<?php
/**
 * Deprecated functionality.
 *
 * This is still loaded on all WordCamp sites, but is considered unsupported. Instead of using shortcodes to
 * display content, the corresponding blocks should be used.
 */

namespace WordCamp\Post_Types\Deprecated;
use WP_Query;

/**
 * Set up functionality.
 */
add_shortcode( 'speakers', __NAMESPACE__ . '\shortcode_speakers' );
add_shortcode( 'sessions', __NAMESPACE__ . '\shortcode_sessions' );
add_shortcode( 'sponsors', __NAMESPACE__ . '\shortcode_sponsors' );
add_shortcode( 'organizers', __NAMESPACE__ . '\shortcode_organizers' );

/**
 * The [speakers] shortcode handler.
 */
function shortcode_speakers( $attr, $content ) {
	global $post;

	// Prepare the shortcode arguments.
	$attr = shortcode_atts(
		array(
			'show_avatars'   => true,
			'avatar_size'    => 100,
			'posts_per_page' => - 1,
			'orderby'        => 'date',
			'order'          => 'desc',
			'speaker_link'   => '',
			'track'          => '',
			'groups'         => '',
		),
		$attr
	);

	foreach ( array( 'orderby', 'order', 'speaker_link' ) as $key_for_case_sensitive_value ) {
		$attr[ $key_for_case_sensitive_value ] = strtolower( $attr[ $key_for_case_sensitive_value ] );
	}

	$attr['show_avatars'] = wp_validate_boolean( $attr['show_avatars'] );
	$attr['orderby']      = in_array( $attr['orderby'],      array( 'date', 'title', 'rand' ) ) ? $attr['orderby']      : 'date';
	$attr['order']        = in_array( $attr['order'],        array( 'asc', 'desc'           ) ) ? $attr['order']        : 'desc';
	$attr['speaker_link'] = in_array( $attr['speaker_link'], array( 'permalink'             ) ) ? $attr['speaker_link'] : '';
	$attr['track']        = array_filter( explode( ',', $attr['track'] ) );
	$attr['groups']       = array_filter( explode( ',', $attr['groups'] ) );

	// Fetch all the relevant sessions.
	$session_args = array(
		'post_type'      => 'wcb_session',
		'posts_per_page' => -1,
	);

	if ( ! empty( $attr['track'] ) ) {
		$session_args['tax_query'] = array(
			array(
				'taxonomy' => 'wcb_track',
				'field'    => 'slug',
				'terms'    => $attr['track'],
			),
		);
	}

	$sessions = get_posts( $session_args );

	// Parse the sessions.
	$speaker_ids     = array();
	$speakers_tracks = array();
	foreach ( $sessions as $session ) {
		// Get the speaker IDs for all the sessions in the requested tracks.
		$session_speaker_ids = get_post_meta( $session->ID, '_wcpt_speaker_id' );
		$speaker_ids         = array_merge( $speaker_ids, $session_speaker_ids );

		// Map speaker IDs to their corresponding tracks.
		$session_terms = wp_get_object_terms( $session->ID, 'wcb_track' );
		foreach ( $session_speaker_ids as $speaker_id ) {
			if ( isset( $speakers_tracks[ $speaker_id ] ) ) {
				$speakers_tracks[ $speaker_id ] = array_merge( $speakers_tracks[ $speaker_id ], wp_list_pluck( $session_terms, 'slug' ) );
			} else {
				$speakers_tracks[ $speaker_id ] = wp_list_pluck( $session_terms, 'slug' );
			}
		}
	}

	// Remove duplicate entries.
	$speaker_ids = array_unique( $speaker_ids );
	foreach ( $speakers_tracks as $speaker_id => $tracks ) {
		$speakers_tracks[ $speaker_id ] = array_unique( $tracks );
	}

	// Fetch all specified speakers.
	$speaker_args = array(
		'post_type'      => 'wcb_speaker',
		'posts_per_page' => intval( $attr['posts_per_page'] ),
		'orderby'        => $attr['orderby'],
		'order'          => $attr['order'],
	);

	if ( ! empty( $attr['track'] ) ) {
		$speaker_args['post__in'] = $speaker_ids;
	}

	if ( ! empty( $attr['groups'] ) ) {
		$speaker_args['tax_query'] = array(
			array(
				'taxonomy' => 'wcb_speaker_group',
				'field'    => 'slug',
				'terms'    => $attr['groups'],
			),
		);
	}

	$speakers = new WP_Query( $speaker_args );

	if ( ! $speakers->have_posts() ) {
		return '';
	}

	// Render the HTML for the shortcode.
	ob_start();
	?>

	<div class="wcorg-speakers">

		<?php while ( $speakers->have_posts() ) :
			$speakers->the_post();

			$speaker_classes = array( 'wcorg-speaker', 'wcorg-speaker-' . sanitize_html_class( $post->post_name ) );

			if ( isset( $speakers_tracks[ get_the_ID() ] ) ) {
				foreach ( $speakers_tracks[ get_the_ID() ] as $track ) {
					$speaker_classes[] = sanitize_html_class( 'wcorg-track-' . $track );
				}
			}

			?>

			<!-- Organizers note: The id attribute is deprecated and only remains for backwards compatibility, please use the corresponding class to target individual speakers -->
			<div id="wcorg-speaker-<?php echo sanitize_html_class( $post->post_name ); ?>" class="<?php echo( esc_attr( implode( ' ', $speaker_classes ) ) ); ?>">
				<h2>
					<?php if ( 'permalink' === $attr['speaker_link'] ) : ?>

						<a href="<?php the_permalink(); ?>">
							<?php the_title(); ?>
						</a>

					<?php else : ?>

						<?php the_title(); ?>

					<?php endif; ?>
				</h2>

				<div class="wcorg-speaker-description">
					<?php echo ( $attr['show_avatars'] ) ? get_avatar( get_post_meta( get_the_ID(), '_wcb_speaker_email', true ), absint( $attr['avatar_size'] ) ) : ''; ?>
					<?php the_content(); ?>
				</div>
			</div>

		<?php endwhile; ?>

	</div>

	<?php

	wp_reset_postdata();
	return ob_get_clean();
}

/**
 * The [organizers] shortcode callback.
 */
function shortcode_organizers( $attr, $content ) {
	$attr = shortcode_atts(
		array(
			'show_avatars'   => true,
			'avatar_size'    => 100,
			'posts_per_page' => - 1,
			'orderby'        => 'date',
			'order'          => 'desc',
			'teams'          => '',
		),
		$attr
	);

	$attr['show_avatars'] = wp_validate_boolean( $attr['show_avatars'] );
	$attr['orderby']      = strtolower( $attr['orderby'] );
	$attr['orderby']      = ( in_array( $attr['orderby'], array( 'date', 'title', 'rand' ) ) ) ? $attr['orderby'] : 'date';
	$attr['order']        = strtolower( $attr['order'] );
	$attr['order']        = ( in_array( $attr['order'], array( 'asc', 'desc' ), true ) ) ? $attr['order'] : 'desc';

	$query_args = array(
		'post_type'      => 'wcb_organizer',
		'posts_per_page' => intval( $attr['posts_per_page'] ),
		'orderby'        => $attr['orderby'],
		'order'          => $attr['order'],
	);

	if ( ! empty( $attr['teams'] ) ) {
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'wcb_organizer_team',
				'field'    => 'slug',
				'terms'    => explode( ',', $attr['teams'] ),
			),
		);
	}

	$organizers = new WP_Query( $query_args );

	if ( ! $organizers->have_posts() ) {
		return '';
	}

	ob_start();
	?>
	<div class="wcorg-organizers">

		<?php while ( $organizers->have_posts() ) :
			$organizers->the_post(); ?>

			<div class="wcorg-organizer">
				<h2><?php the_title(); ?></h2>
				<div class="wcorg-organizer-description">
					<?php /* Unlike speakers, organizers don't have a Gravatar e-mail field, so we pass the linked user ID to get_avatar */ ?>
					<?php echo ( $attr['show_avatars'] ) ? get_avatar( absint( get_post_meta( get_the_ID(), '_wcpt_user_id', true ) ), absint( $attr['avatar_size'] ) ) : ''; ?>
					<?php the_content(); ?>
				</div>
			</div>

		<?php endwhile; ?>

	</div><!-- .wcorg-organizers -->
	<?php
	wp_reset_postdata();
	$content = ob_get_contents();
	ob_end_clean();
	return $content;
}

/**
 * The [sessions] shortcode handler
 */
function shortcode_sessions( $attr, $content ) {
	global $post;

	$attr = shortcode_atts(
		array(
			'date'           => null,
			'show_meta'      => false,
			'show_avatars'   => false,
			'avatar_size'    => 100,
			'track'          => 'all',
			'speaker_link'   => 'wporg', // anchor|wporg|permalink|none.
			'posts_per_page' => - 1,
			'orderby'        => 'date', // date|title|rand|session_time.
			'order'          => 'desc', // asc|desc.
		),
		$attr
	);

	// Convert bools to real booleans.
	$bools = array( 'show_meta', 'show_avatars' );
	foreach ( $bools as $key ) {
		$attr[ $key ] = wp_validate_boolean( $attr[ $key ] );
	}

	// Clean up other attributes.
	foreach ( array( 'track', 'speaker_link', 'orderby', 'order' ) as $key_for_case_sensitive_value ) {
		$attr[ $key_for_case_sensitive_value ] = strtolower( $attr[ $key_for_case_sensitive_value ] );
	}

	$attr['avatar_size'] = absint( $attr['avatar_size'] );

	if ( ! in_array( $attr['speaker_link'], array( 'anchor', 'wporg', 'permalink', 'none' ) ) ) {
		$attr['speaker_link'] = 'anchor';   // todo this is inconsistent with the values passed to shortcode_atts, and probably not needed if the default above is changed to 'anchor'.
	}

	$attr['orderby'] = ( in_array( $attr['orderby'], array( 'date', 'title', 'rand', 'session_time' ) ) ) ? $attr['orderby'] : 'date';

	if ( 'asc' != $attr['order'] ) {
		$attr['order'] = 'desc';
	}

	$args = array(
		'post_type'      => 'wcb_session',
		'posts_per_page' => intval( $attr['posts_per_page'] ),
		'tax_query'      => array(),
		'orderby'        => $attr['orderby'],
		'order'          => $attr['order'],

		// Only ones marked "session" or where the meta key does.
		// not exist, for backwards compatibility.
		'meta_query' => array(
			'relation' => 'AND',

			array(
				'relation' => 'OR',

				array(
					'key'     => '_wcpt_session_type',
					'value'   => 'session',
					'compare' => '=',
				),

				array(
					'key'     => '_wcpt_session_type',
					'value'   => '',
					'compare' => 'NOT EXISTS',
				),
			),
		),
	);

	if ( $attr['date'] && strtotime( $attr['date'] ) ) {
		$args['meta_query'][] = array(
			'key'     => '_wcpt_session_time',
			'value'   => array(
				strtotime( $attr['date'] ),
				strtotime( $attr['date'] . ' +1 day' ),
			),
			'compare' => 'BETWEEN',
			'type'    => 'NUMERIC',
		);
	}

	if ( 'all' != $attr['track'] ) {
		$args['tax_query'][] = array(
			'taxonomy' => 'wcb_track',
			'field'    => 'slug',
			'terms'    => $attr['track'],
		);
	}

	// Order by session date/time.
	if ( 'session_time' === $args['orderby'] ) {
		$args['meta_key'] = '_wcpt_session_time';
		$args['orderby']  = 'meta_value_num title';
	}

	// Fetch sessions.
	$sessions = new WP_Query( $args );

	if ( ! $sessions->have_posts() ) {
		return;
	}

	ob_start();
	?>

	<div class="wcorg-sessions">
		<?php while ( $sessions->have_posts() ) :
			$sessions->the_post();

			// Things to be output, or not.
			$session_meta     = '';
			$speakers_avatars = '';
			$links            = array();

			// Fetch speakers associated with this session.
			$speakers     = array();
			$speakers_ids = array_map( 'absint', (array) get_post_meta( get_the_ID(), '_wcpt_speaker_id' ) );

			if ( ! empty( $speakers_ids ) ) {
				$speakers = get_posts( array(
					'post_type'      => 'wcb_speaker',
					'posts_per_page' => -1,
					'post__in'       => $speakers_ids,
				) );
			}

			// Should we add avatars?
			if ( $attr['show_avatars'] ) {
				foreach ( $speakers as $speaker ) {
					$speakers_avatars .= get_avatar( get_post_meta( $speaker->ID, '_wcb_speaker_email', true ), absint( $attr['avatar_size'] ) );
				}
			}

			// Should we output meta?
			if ( $attr['show_meta'] ) {
				$speaker_permalink = '';
				$speakers_names    = array();
				$tracks_names      = array();

				foreach ( $speakers as $speaker ) {
					$speaker_name = apply_filters( 'the_title', $speaker->post_title );

					if ( 'anchor' == $attr['speaker_link'] ) {
						// speakers/#wcorg-speaker-slug.
						$speaker_permalink = $GLOBALS['wcpt_plugin']->get_wcpt_anchor_permalink( $speaker->ID );
					} elseif ( 'wporg' == $attr['speaker_link'] ) {
						// profiles.wordpress.org/user.
						$speaker_permalink = $GLOBALS['wcpt_plugin']->get_speaker_wporg_permalink( $speaker->ID );
					} elseif ( 'permalink' == $attr['speaker_link'] ) {
						// year.city.wordcamp.org/speakers/slug.
						$speaker_permalink = get_permalink( $speaker->ID );
					}

					if ( ! empty( $speaker_permalink ) ) {
						$speaker_name = sprintf( '<a href="%s">%s</a>', esc_url( $speaker_permalink ), esc_html( $speaker_name ) );
					}

					$speakers_names[] = $speaker_name;
				}

				$tracks = get_the_terms( get_the_ID(), 'wcb_track' );

				if ( is_array( $tracks ) ) {
					foreach ( $tracks as $track ) {
						$tracks_names[] = apply_filters( 'the_title', $track->name );
					}
				}

				// Add speakers and tracks to session meta.
				if ( ! empty( $speakers_names ) && ! empty( $tracks_names ) ) {
					$session_meta .= sprintf( __( 'Presented by %1$s in %2$s.', 'wordcamporg' ), implode( ', ', $speakers_names ), implode( ', ', $tracks_names ) );
				} elseif ( ! empty( $speakers_names ) ) {
					$session_meta .= sprintf( __( 'Presented by %s.', 'wordcamporg' ), implode( ', ', $speakers_names ) );
				} elseif ( ! empty( $tracks_names ) ) {
					$session_meta .= sprintf( __( 'Presented in %s.', 'wordcamporg' ), implode( ', ', $tracks_names ) );
				}

				if ( ! empty( $session_meta ) ) {
					$session_meta = sprintf( '<p class="wcpt-session-meta">%s</p>', $session_meta );
				}
			}

			// Gather data for list of links.
			$url = get_post_meta( $post->ID, '_wcpt_session_slides', true );
			if ( $url ) {
				$links['slides'] = array(
					'url'   => $url,
					'label' => __( 'Slides', 'wordcamporg' ),
				);
			}

			$url = get_post_meta( $post->ID, '_wcpt_session_video', true );
			if ( $url ) {
				$links['video'] = array(
					'url'   => $url,
					'label' => __( 'Video', 'wordcamporg' ),
				);
			}

			?>

			<div id="wcorg-session-<?php the_ID(); ?>" class="wcorg-session" >
				<h2>
					<?php the_title(); ?>
				</h2>

				<div class="wcorg-session-description">
					<?php the_post_thumbnail(); ?>
					<?php echo wp_kses_post( $session_meta ); ?>
					<?php echo wp_kses_post( $speakers_avatars ); ?>
					<?php the_content(); ?>

					<?php if ( $links ) : ?>
						<ul class="wcorg-session-links">
							<?php foreach ( $links as $link ) : ?>
								<li>
									<a href="<?php echo esc_url( $link['url'] ); ?>">
										<?php echo esc_html( $link['label'] ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>

		<?php endwhile; ?>
	</div><!-- .wcorg-sessions -->

	<?php

	wp_reset_postdata();
	$content = ob_get_contents();
	ob_end_clean();
	return $content;
}

/**
 * The [sponsors] shortcode handler.
 */
function shortcode_sponsors( $attr, $content ) {
	global $post;

	$attr = shortcode_atts(
		array(
			'link'           => 'none',
			'title'          => 'visible',
			'content'        => 'full',
			'excerpt_length' => 55,
		),
		$attr
	);

	$attr['link'] = strtolower( $attr['link'] );
	$terms        = $GLOBALS['wcpt_plugin']->get_sponsor_levels();

	ob_start();
	?>

	<div class="wcorg-sponsors">
		<?php foreach ( $terms as $term ) :
			$sponsors = new WP_Query( array(
				'post_type'      => 'wcb_sponsor',
				'order'          => 'ASC',
				'posts_per_page' => -1,
				'taxonomy'       => $term->taxonomy,
				'term'           => $term->slug,
			) );

			if ( ! $sponsors->have_posts() ) {
				continue;
			}

			?>

			<div class="wcorg-sponsor-level-<?php echo sanitize_html_class( $term->slug ); ?>">
				<h2><?php echo esc_html( $term->name ); ?></h2>

				<?php while ( $sponsors->have_posts() ) :
					$sponsors->the_post();
					$website = get_post_meta( get_the_ID(), '_wcpt_sponsor_website', true );
					?>

					<div id="wcorg-sponsor-<?php the_ID(); ?>" class="wcorg-sponsor">
						<?php if ( 'visible' === $attr['title'] ) : ?>
							<?php if ( 'website' === $attr['link'] && $website ) : ?>
								<h3>
									<a href="<?php echo esc_attr( esc_url( $website ) ); ?>" rel="nofollow">
										<?php the_title(); ?>
									</a>
								</h3>
							<?php elseif ( 'post' === $attr['link'] ) : ?>
								<h3>
									<a href="<?php echo esc_attr( esc_url( get_permalink() ) ); ?>">
										<?php the_title(); ?>
									</a>
								</h3>
							<?php else : ?>
								<h3>
									<?php the_title(); ?>
								</h3>
							<?php endif; ?>
						<?php endif; ?>

						<div class="wcorg-sponsor-description">
							<?php if ( 'website' == $attr['link'] && $website ) : ?>
								<a href="<?php echo esc_attr( esc_url( $website ) ); ?>">
									<?php the_post_thumbnail( 'wcb-sponsor-logo-horizontal-2x', array( 'alt' => get_the_title() ) ); ?>
								</a>
							<?php elseif ( 'post' == $attr['link'] ) : ?>
								<a href="<?php echo esc_attr( esc_url( get_permalink() ) ); ?>">
									<?php the_post_thumbnail( 'wcb-sponsor-logo-horizontal-2x', array( 'alt' => get_the_title() ) ); ?>
								</a>
							<?php else : ?>
								<?php the_post_thumbnail( 'wcb-sponsor-logo-horizontal-2x', array( 'alt' => get_the_title() ) ); ?>
							<?php endif; ?>

							<?php if ( 'full' === $attr['content'] ) : ?>
								<?php the_content(); ?>
							<?php elseif ( 'excerpt' === $attr['content'] ) : ?>
								<?php echo wp_kses_post(
									wpautop(
										wp_trim_words(
											get_the_content(),
											absint( $attr['excerpt_length'] ),
											apply_filters( 'excerpt_more', ' &hellip;' )
										)
									)
								); ?>
							<?php endif; ?>
						</div>
					</div><!-- #sponsor -->
				<?php endwhile; ?>
			</div><!-- .wcorg-sponsor-level -->
		<?php endforeach; ?>
	</div><!-- .wcorg-sponsors -->

	<?php

	wp_reset_postdata();
	$content = ob_get_contents();
	ob_end_clean();
	return $content;
}
