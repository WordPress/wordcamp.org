/**
 * External dependencies
 */
import { get, includes, intersection } from 'lodash';

/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import { BlockControls, PlaceholderNoContent } from '../shared/block-controls';
import SponsorBlockContent from './block-content';
import { LABEL } from './index';
import SponsorsSelect from './sponsor-select';

const { Button, Placeholder } = wp.components;

/**
 * Implements sponsor block controls.
 */
class SponsorBlockControls extends BlockControls {
	constructor( props ) {
		super( props );
		this.state = {};
	}

	static getDerivedStateFromProps( nextProps, state ) {
		const { sponsorPosts, sponsorLevels, siteSettings } = nextProps;

		if ( ! state.hasOwnProperty( 'sponsorPosts' ) && Array.isArray( sponsorPosts ) ) {
			state.posts = sponsorPosts.map(
				( post ) => {
					const label = post.title.rendered.trim() || __( '(Untitled)', 'wordcamporg' );

					return {
						label             : label,
						value             : post.id,
						type              : 'post',
						featuredImageData : get( post, '_embedded.wp:featuredmedia[0].media_details', '' ),
					};
				}
			);
			state.sponsorPosts = sponsorPosts;
		}

		// Adding check for sponsorPosts here because looks like sponsorLevels
		// be emtpy array till `sponsorPosts` is initialized.
		if ( state.sponsorPosts && ! state.hasOwnProperty( 'terms' ) && null !== sponsorLevels ) {
			state.terms = sponsorLevels.map( ( term ) => {
				return {
					label : term.name.trim() || __( '(Untitled)', 'wordcamporg' ),
					value : term.id,
					type  : 'term',
					count : term.count,
				};
			} );
		}

		if ( ! state.hasOwnProperty( 'sponsorTermOrder' ) && siteSettings ) {
			state.sponsorTermOrder = siteSettings.wcb_sponsor_level_order;
		}

		if ( state.posts && state.terms && state.sponsorTermOrder ) {
			state.loading = false;
		}
		return state;
	}

	/**
	 * Renders Sponsor Block Control view
	 *
	 * @return {Element}
	 */
	render() {
		const {
			icon, attributes, setAttributes,
		} = this.props;
		const { post_ids, term_ids, mode } = attributes;
		const { sponsorPosts, sponsorTermOrder } = this.state;

		const hasPosts      = Array.isArray( sponsorPosts ) && sponsorPosts.length;

		// Check if posts are still loading.
		if ( ! hasPosts ) {
			return (
				<PlaceholderNoContent
					label={ LABEL }
					loading={ () => {
						return mode
					} }
				/>
			);
		}

		const selectedPosts = [];

		for ( const post of sponsorPosts ) {
			if ( ! post.hasOwnProperty( 'id' ) ) {
				continue;
			}

			switch ( mode ) {
				case 'all':
					selectedPosts.push( post );
					break;

				case 'specific_posts':
					if ( -1 !== post_ids.indexOf( post.id ) ) {
						selectedPosts.push( post );
					}
					break;

				case 'specific_terms':
					if ( intersection( term_ids, post.sponsor_level || [] ).length ) {
						selectedPosts.push( post );
					}
					break;

				default:
					break;
			}
		}

		return (
			<div>
				<SponsorBlockContent
					sponsorTermOrder={ sponsorTermOrder }
					selectedPosts={ selectedPosts }
					{ ...this.props }
				/>

				{ 'all' !== mode &&
					<Placeholder
						icon={ icon }
						label={ __( 'Sponsors', 'wordcamporg' ) }
					>
						<div className="" >
							<Button
								isDefault
								isLarge
								onClick={
									() => {
										setAttributes( { mode: 'all' } );
									}
								}
							>
								{ __( 'List all sponsors', 'wordcamporg' ) }
							</Button>
						</div>
						<SponsorsSelect
							icon={ icon }
							{ ...this.props }
							{ ...this.state }
						/>
					</Placeholder>
				}
			</div>
		);
	}
}

export default SponsorBlockControls;
