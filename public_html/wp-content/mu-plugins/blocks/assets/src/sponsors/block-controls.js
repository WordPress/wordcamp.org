/**
 * WordPress dependencies.
 */
const { __ } = wp.i18n;
const { Placeholder } = wp.components;
const { Component } = wp.element;
const { decodeEntities } = wp.htmlEntities;

/**
 * Internal dependencies.
 */
import { BlockControls, PlaceholderNoContent, PlaceholderSpecificMode } from "../shared/block-controls";
import SponsorBlockContent from './block-content';
import VersatileSelect from '../shared/versatile-select';



const LABEL = __( 'Sponsors', 'wordcamporg' );

/**
 * Render select box for selecting sponsors.
 */
class SponsorSelect extends Component {

	constructor( props ) {
		super( props );
		this.state = {
			loading: true,
			wcb_sponsors: [],
		}
	}

	onChange( selectedOptions ) {
		console.log("This called: ", selectedOptions, this.props);
	}

	render() {
		const { label, attributes, setAttributes, sponsorPosts } = this.props;
		const { mode, item_ids } = attributes;

		const sponsorOptions = ( sponsorPosts || [] ).map( ( sponsor ) => {
			return {
				label: decodeEntities( sponsor.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' ),
				value: sponsor.id,
				type: 'post',
			}
		} );

		const options = [
			{
				label: __( 'Sponsors', 'wordcamporg' ),
				options: sponsorOptions,
			}
		];

		console.log("Options", options);
		return (
			<VersatileSelect
				className="wordcamp-sponsors-select"
				selectProps = { {
					options: options,
					isMulti: true,
					isLoading: this.state.loading,
				} }
				onChange={ this.onChange }
			/>
		)
	}
}

/**
 * Implements sponsor block controls.
 */
class SponsorBlockControls extends BlockControls {

	/**
	 * Renders Sponsor Block Control view
	 */
	render() {
		const { sponsorPosts, attributes } = this.props;
		const { mode } = attributes;

		const hasPosts = Array.isArray( sponsorPosts ) && sponsorPosts.length;

		console.log("Mode is: ", mode);
		// Check if posts are still loading.
		if ( mode && ! hasPosts ) {
			return (
				<PlaceholderNoContent
					label = { LABEL }
					loading = { () => {
						return ! Array.isArray( sponsorPosts );
					} }
				/>
			)
		}

		let output;

		switch ( mode ) {
			case 'all' :
				output = (
					<SponsorBlockContent { ...this.props } />
				);
				break;
			default:
				output = (
					<SponsorSelect
						{ ...this.props }
					/>
				)
		}

		return output;
	}

}

export default SponsorBlockControls;