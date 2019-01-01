/**
 * External dependencies
 */
import { find, get, head } from 'lodash';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { Disabled } = wp.components;
const { Component, Fragment, RawHTML } = wp.element;
const { decodeEntities } = wp.htmlEntities;
const { __, _n, sprintf } = wp.i18n;

/**
 * Internal dependencies
 */
import AvatarImage from '../shared/avatar';
import './block-content.scss';

class SpeakersBlockContent extends Component {
	// it's not immediately obvious why `add` is here, so jsdoc would really help explain that. we might not want to keep it, though (see below)
	static maybeAddMoreLink( content, add ) {
		if ( add ) {
			/*
			 this feels a bit odd. is it here because JSX can only contain expressions, so we cant do something like:

			if ( speaker_link ) { addMoreLink() )

			what if instead we used a ternary?

			{ ( speaker_link ? this.constructor.addMoreLink( post.excerpt.rendered ) : post.excerpt.rendered ).trim() }

			then this function would be more straightforward, and the calling function read more naturally and be self-explanatory
			*/

			const more = sprintf(
				'<a href="#" class="wordcamp-speaker-more-link">%s</a>',
				// should the href have the actual value? blocks like Recent Posts have real links, but make them open in a new window if clicked on inside gutenberg
				__( 'Read more', 'wordcamporg' )
			);

			const pattern = /<\/p>$/;

			if ( Array.isArray( content.match( pattern ) ) ) {
				content = content.replace( pattern, ' ' + more + '</p>' );
				// could post.content.rendered contain divs or other things we need to worry about here?
			} else {
				content += ' ' + more;
			}
		}

		return content;
		// if we return content.trim() then we wouldn't have to worry about using it in the caller statements
	}

	render() {
		const { attributes, speakerPosts, tracks } = this.props;
		const {
			layout, grid_columns, className,
			show_avatars, avatar_size, avatar_align,
			content, speaker_link, show_session,
		} = attributes;

		let containerClasses = [
			'wordcamp-speakers-block',
			'layout-' + layout,
			className,
		];

		if ( 'grid' === layout ) {
			containerClasses.push( 'grid-columns-' + Number( grid_columns ) );
		}

		return (
			// this feels a bit overwhelming. i think this would be easier to grok and maintain if it were modularized more
			// maybe pull out the `wordcamp-speaker-content` and session parts into functions that just get called from here?

			<ul className={ classnames( containerClasses ) }>
				{ speakerPosts.map( ( post, i ) =>
					<li
						key={ i }
						className={ classnames( 'wordcamp-speaker', 'wordcamp-speaker-' + post.slug ) }
						// should we use decodeEntities here too?
					>
						<h3 className={ 'wordcamp-speaker-name-heading' }>
							{ decodeEntities( post.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' ) }
						</h3>

						{ show_avatars &&
							<AvatarImage
								className={ classnames( 'wordcamp-speaker-avatar', 'align-' + avatar_align ) }
								name={ decodeEntities( post.title.rendered.trim() ) || '' }
								size={ avatar_size }
								url={ post[ 'avatar_urls' ][ '24' ] }
							/>
						}

						{ ( 'none' !== content || true === speaker_link ) &&
							<div className={ 'wordcamp-speaker-content' }>
								{ 'full' === content &&
									<Disabled>
										{ /* why is Disabled used here? */ }
										<RawHTML>
											{ this.constructor.maybeAddMoreLink( post.content.rendered.trim(), speaker_link ).trim() }
											{ // why do we need a read more link if we're showing the full bio?
												// is it just to link to the full session post? if so, maybe the anchor text should say something like "view speaker page" instead?
												// or just let their name be a link?
											}
										</RawHTML>
									</Disabled>
								}
								{ 'excerpt' === content &&
									<Disabled>
										<RawHTML>
											{ this.constructor.maybeAddMoreLink( post.excerpt.rendered.trim(), speaker_link ).trim() }
											{ /* i think calling it like `SpeakersBlockContent.maybeAddMoreLink()` seems a bit nicer than `this.constructor. obviously not a big deal either way though.  */ }
											{ /*
											 excerpts shouldn't have HTML, should they? it seems like we can remove the RawHTML wrapper.
											or maybe it's only needed around the speaker link, but not the excerpt?
											maybe `maybeAddMoreLink` should return an Element instead of a string, so that we wouldn't need rawhtml? i'm worried about using rawhtml when we don't absolutely need to, since it can be an attack vector
											*/ }
										</RawHTML>
									</Disabled>
								}
								{ 'none' === content &&
									<Disabled>
										<RawHTML>
											{ this.constructor.maybeAddMoreLink( '', speaker_link ).trim() }
											{ /* similar question to above about avoiding rawhtml */ }
										</RawHTML>
									</Disabled>
								}
							</div>
						}

						{ true === show_session && post._embedded.sessions.length &&
							<Fragment>
								<h4 className={ 'wordcamp-speaker-session-heading' }>
									{ _n( 'Session', 'Sessions', post._embedded.sessions.length, 'wordcamporg' ) }
								</h4>

								<ul className={ 'wordcamp-speaker-session-list' }>
									{ post._embedded.sessions.map( ( session, x ) =>
										<li
											key={ x }
											className={ 'wordcamp-speaker-session-content' }
										>
											<Disabled>
												<a
													className={ 'wordcamp-speaker-session-link' }
													href={ session.link }
													// is there an esc_url() equivalent in g? or do we not need it because we're returning an Element, which gets escaped automatically?
												>
													{ decodeEntities( session.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' ) }
												</a>
												<br />
												<span className={ 'wordcamp-speaker-session-info' }>
													{ session.session_track.length &&
														// will this fatal similar to https://meta.trac.wordpress.org/changeset/8025/ ?
														// if so, it'd be good to check for other instances of that problem throughout the plugin

														sprintf(
															/* translators: 1: A date; 2: A time; 3: A location; */
															__( '%1$s at %2$s in %3$s', 'wordcamporg' ),
															session.session_date_time.date,
															session.session_date_time.time,
															get( find( tracks, ( value ) => { return parseInt( value.id ) === head( session.session_track ) } ), 'name' )
															// this is pretty difficult for a human to parse when it's all written as a single line, can it be broken up into more digestable chunks?
															// i don't think it needs to be a named function, but just spreading it across multiple lines would increase the readability a lot

															// why use `head` instead of `session.session_track[0]` ?
															// it's one extra thing to learn, but the benefit isn't obvious. is it useful because it does some extra error checking or something?
															// if so, could that be avoided by an extra condition in the `{ session.session_track.length &&` bit above? maybe that's overkill and head() is easier, though.
															// seems like it's unnecessary in es6 though? https://www.sitepoint.com/lodash-features-replace-es6/
														)
													}
													{ ! session.session_track.length &&
														sprintf(
															/* translators: 1: A date; 2: A time; */
															__( '%1$s at %2$s', 'wordcamporg' ),
															session.session_date_time.date,
															session.session_date_time.time
														)
													}
												</span>
											</Disabled>
										</li>
									) }
								</ul>
							</Fragment>
						}
					</li>,
				) }
			</ul>
		);
	}
}

export default SpeakersBlockContent;
