/* global MultiEventSponsor */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Dashicon, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { PluginSidebar } from '@wordpress/edit-post';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { decodeEntities } from '@wordpress/html-entities';

const { stripTags } = wp.sanitize;

/*
 * Render the main view for the sidebar.
 */
function PushToActiveCamps( { adminUrl } ) {
	const [ loading, setLoading ] = useState( false );
	const [ result, setResult ] = useState( null );

	const sourcePost = useSelect(
		( select ) => {
			// This doesn't update until the `Update` button is saved, but that's good enough for our uses.
			return select( 'core/editor' ).getCurrentPost();
		}
	);

	const isDirty = useSelect(
		( select ) => {
			return select( 'core/editor' ).isEditedPostDirty();
		}
	);

	if ( isDirty && result !== null ) {
		// Make sure the UI doesn't show results from a previous push once the current changes are saved.
		setResult( null );
	}

	return (
		<PluginSidebar
			name="push-to-active-camps"
			className="push-to-active-camps"
			title="Push to Active Camps"
			icon={ <Dashicon icon="heart" /> }
		>
			<p>
				{ sprintf(
					// translators: Title of the post
					__( 'This will copy the title/content/etc of this post to all of its corresponding %s posts on active WordCamp sites (except regional camps).', 'wordcamporg' ),
					decodeEntities( stripTags( sourcePost.title ) )
				) }
			</p>

			<p className="disclaimer">
				{ createInterpolateElement(
					__( "This won't push to sites that were created before this post; for that please <a>edit their WordCamp post</a> and <code>Push new sponsors to site</code>.", 'wordcamporg' ),
					{
						a: <a href={ adminUrl + 'edit.php?post_type=wordcamp' } >#21441-gutenberg</a>,
						code: <code />,
					}
				) }
			</p>

			{ loading &&
				<Spinner />
			}

			{ isDirty &&
				<div className={ `notice notice-error inline` }>
					<p>
						{ __( 'Please save or discard the current changes before pushing the post to other sites.', 'wordcamporg' ) }
					</p>
				</div>
			}

			{ ! loading && ! isDirty &&
				<PushButton
					sponsorId={ sourcePost.id }
					setLoading={ setLoading }
					setResult={ setResult }
				/>
			}

			{ ! loading && ! isDirty && null !== result &&
				<Result result={ result } />
			}
		</PluginSidebar>
	);
}

/*
 * Render the <button>, and make the API request when it's clicked.
 */
function PushButton( { sponsorId, setLoading, setResult } ) {
	async function postRequest() {
		setLoading( true );

		let result = null;

		const fetchParams = {
			path: '/multi-event-sponsors/v1/push-to-active-camps',
			method: 'POST',
			data: {
				sponsorId,
			},
		};

		try {
			result = await apiFetch( fetchParams );
		} catch ( error ) {
			result = {
				success: false,
				error: error.message ?? __( 'An unknown error occurred, please try again or ask the maintenance developer for help.', 'wordcamporg' ),
			};
		} finally {
			setResult( result );
			setLoading( false );
		}
	}

	return (
		<Button isSecondary onClick={ postRequest }>
			{ __( 'Push to Active WordCamps', 'wordcamporg' ) }
		</Button>
	);
}

/*
 * Render the result of a push.
 */
function Result( { result } ) {
	const { success, edited_posts, error } = result;

	return (
		<div id="push-to-active-camps__result">
			<div className={ `notice inline notice-${ success ? 'success' : 'error' }` }>
				<p>
					{ success
						? _x( 'Success!', 'admin notice', 'wordcamporg' )
						: _x( 'Error: ', 'admin notice', 'wordcamporg' ) + error
					}
				</p>
			</div>

			{ success && edited_posts.length > 0 &&
				<div className="notice notice-warning">
					<p>
						{ __( 'These sites have already edited the post, so they were skipped to avoid overwriting their changes.', 'wordcamporg' ) }
					</p>

					<ul className="ul-disc">
						{ edited_posts.map( ( { edit_url, site_name } ) => {
							return (
								<li key={ edit_url }>
									<a href={ edit_url }>
										{ site_name }
									</a>
								</li>
							);
						} ) }
					</ul>
				</div>
			}
		</div>
	);
}

registerPlugin( 'push-to-active-camps', {
	render: () => {
		return (
			<PushToActiveCamps adminUrl={ MultiEventSponsor.admin_url } />
		);
	} }
);
