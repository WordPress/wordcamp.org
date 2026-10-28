/* global MultiEventSponsor */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Dashicon, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { PluginSidebar } from '@wordpress/edit-post';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { decodeEntities } from '@wordpress/html-entities';

const { stripTags } = wp.sanitize;

/*
 * Render the main view for the sidebar.
 *
 * Pushing is two steps: a preview (a dry run) that lists what will change, then a push the user
 * confirms. A draft is a brand the working group is staging, so it's also added to camps that
 * don't have it yet, as a draft.
 */
function PushToActiveCamps( { adminUrl } ) {
	const [ loading, setLoading ] = useState( false );
	const [ preview, setPreview ] = useState( null );
	const [ result, setResult ] = useState( null );

	const sourcePost = useSelect( ( select ) => {
		// This doesn't update until the `Update` button is saved, but that's good enough for our uses.
		return select( 'core/editor' ).getCurrentPost();
	} );

	const isDirty = useSelect( ( select ) => {
		return select( 'core/editor' ).isEditedPostDirty();
	} );

	if ( isDirty && ( result !== null || preview !== null ) ) {
		// Make sure the UI doesn't show a preview or results from before the current changes.
		setResult( null );
		setPreview( null );
	}

	const isDraft = 'draft' === sourcePost.status;

	async function request( dryRun ) {
		setLoading( true );

		let response = null;

		try {
			response = await apiFetch( {
				path: '/multi-event-sponsors/v1/push-to-active-camps',
				method: 'POST',
				data: {
					sponsorId: sourcePost.id,
					dryRun,
				},
			} );
		} catch ( error ) {
			response = {
				success: false,
				error:
					error.message ??
					__(
						'An unknown error occurred, please try again or ask the maintenance developer for help.',
						'wordcamporg'
					),
			};
		}

		setLoading( false );

		if ( dryRun && response.success ) {
			setResult( null );
			setPreview( response );
		} else {
			setPreview( null );
			setResult( response );
		}
	}

	return (
		<PluginSidebar
			name="push-to-active-camps"
			className="push-to-active-camps"
			title={ __( 'Push to Active Camps', 'wordcamporg' ) }
			icon={ <Dashicon icon="heart" /> }
		>
			<p>
				{ sprintf(
					// translators: %s: Title of the post.
					__(
						'This will copy the title/content/etc of this post to all of its corresponding %s posts on active WordCamp sites (except regional camps).',
						'wordcamporg'
					),
					decodeEntities( stripTags( sourcePost.title ) )
				) }
			</p>

			{ isDraft ? (
				<p className="disclaimer">
					{ __(
						"This post is a draft. Pushing it also adds it, as a draft, to the active camps in its regions or groups that don't have it yet, so organizers can publish it when the brand is confirmed.",
						'wordcamporg'
					) }
				</p>
			) : (
				<p className="disclaimer">
					{ createInterpolateElement(
						__(
							"This won't push to sites that were created before this post; for that please <a>edit their WordCamp post</a> and <code>Push new sponsors to site</code>.",
							'wordcamporg'
						),
						{
							a: <a href={ adminUrl + 'edit.php?post_type=wordcamp' }>#21441-gutenberg</a>,
							code: <code />,
						}
					) }
				</p>
			) }

			{ loading && <Spinner /> }

			{ isDirty && (
				<div className={ `notice notice-error inline` }>
					<p>
						{ __(
							'Please save or discard the current changes before pushing the post to other sites.',
							'wordcamporg'
						) }
					</p>
				</div>
			) }

			{ ! loading && ! isDirty && null === preview && (
				<Button isSecondary onClick={ () => request( true ) }>
					{ __( 'Preview push', 'wordcamporg' ) }
				</Button>
			) }

			{ ! loading && ! isDirty && null !== preview && (
				<Preview
					preview={ preview }
					onConfirm={ () => request( false ) }
					onCancel={ () => setPreview( null ) }
				/>
			) }

			{ ! loading && ! isDirty && null !== result && <Result result={ result } /> }
		</PluginSidebar>
	);
}

/*
 * A list of camp names under a heading, or nothing when it's empty.
 */
function CampList( { heading, names } ) {
	if ( ! names.length ) {
		return null;
	}

	return (
		<>
			<p>{ heading }</p>
			<ul className="ul-disc">
				{ names.map( ( name ) => (
					<li key={ name }>{ decodeEntities( name ) }</li>
				) ) }
			</ul>
		</>
	);
}

/*
 * Render what a push will do, and ask to confirm it.
 */
function Preview( { preview, onConfirm, onCancel } ) {
	const { added, updated } = preview;
	const total = added.length + updated.length;

	return (
		<div id="push-to-active-camps__preview">
			{ 0 === total ? (
				<p>{ __( 'Nothing to push: no camp needs this sponsor added or updated.', 'wordcamporg' ) }</p>
			) : (
				<>
					<CampList
						heading={ sprintf(
							// translators: %d: Number of camps.
							_n(
								'Add it as a draft to %d camp:',
								'Add it as a draft to %d camps:',
								added.length,
								'wordcamporg'
							),
							added.length
						) }
						names={ added }
					/>
					<CampList
						heading={ sprintf(
							// translators: %d: Number of camps.
							_n(
								'Update the copy on %d camp:',
								'Update the copy on %d camps:',
								updated.length,
								'wordcamporg'
							),
							updated.length
						) }
						names={ updated }
					/>
				</>
			) }
			<SkippedPosts skippedPosts={ preview.skipped_posts } />
			{ total > 0 && (
				<Button isPrimary onClick={ onConfirm }>
					{ sprintf(
						// translators: %d: Number of camps.
						_n( 'Push to %d camp', 'Push to %d camps', total, 'wordcamporg' ),
						total
					) }
				</Button>
			) }{ ' ' }
			<Button isTertiary onClick={ onCancel }>
				{ __( 'Cancel', 'wordcamporg' ) }
			</Button>
		</div>
	);
}

/*
 * The camp copies that were left alone because organizers edited them.
 */
function SkippedPosts( { skippedPosts } ) {
	if ( ! skippedPosts?.length ) {
		return null;
	}

	return (
		<div className="notice notice-warning">
			<p>
				{ __(
					'These sites have already edited the post, so they were skipped to avoid overwriting their changes.',
					'wordcamporg'
				) }
			</p>

			<ul className="ul-disc">
				{ skippedPosts.map( ( { edit_url, site_name } ) => {
					return (
						<li key={ edit_url }>
							<a href={ edit_url }>{ decodeEntities( site_name ) }</a>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}

/*
 * Render the result of a push.
 */
function Result( { result } ) {
	const { success, added, updated, skipped_posts, error } = result;

	return (
		<div id="push-to-active-camps__result">
			<div className={ `notice inline notice-${ success ? 'success' : 'error' }` }>
				<p>
					{ success
						? [
								sprintf(
									// translators: %d: Number of camps.
									_n( 'Added to %d camp.', 'Added to %d camps.', added.length, 'wordcamporg' ),
									added.length
								),
								sprintf(
									// translators: %d: Number of camps.
									_n(
										'Updated on %d camp.',
										'Updated on %d camps.',
										updated.length,
										'wordcamporg'
									),
									updated.length
								),
						  ].join( ' ' )
						: // eslint-disable-next-line @wordpress/i18n-no-flanking-whitespace
						  _x( 'Error: ', 'admin notice', 'wordcamporg' ) + error }
				</p>
			</div>

			{ success && <SkippedPosts skippedPosts={ skipped_posts } /> }
		</div>
	);
}

registerPlugin( 'push-to-active-camps', {
	render: () => {
		return <PushToActiveCamps adminUrl={ MultiEventSponsor.admin_url } />;
	},
} );
