/**
 * WordPress dependencies
 */
const { Dashicon } = wp.components;
const { Component } = wp.element;
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import AvatarImage from '../shared/avatar';
import CustomPostTypeSelect from '../shared/block-controls/custom-post-select';

class SpeakersSelect extends Component {

	render() {

		const { allSpeakerPosts, allSpeakerTerms, attributes } = this.props;

		return (
			<CustomPostTypeSelect
				allPosts = { allSpeakerPosts }
				allTerms = { allSpeakerTerms }
				getAvatarUrl = { ( post ) => {
					return post.avatar_urls[ '24' ];
				} }
				termLabel = { __( 'Groups', 'wordcamporg' ) }
				postLabel = { __( 'Speakers', 'wordcamporg' ) }
				selectClassName = { "wordcamp-speakers-select" }
				selectProps = { {
					formatGroupLabel : ( groupData ) => {
						return (<span className="wordcamp-speakers-select-option-group-label">
								{ groupData.label }
							</span>
						)
					},
					formatOptionLabel: ( optionData ) => {
						return (
							<SpeakersOption { ...optionData } />
						);
					},
				} }
				{ ...this.props }
			/>
		)
	};

}

function SpeakersOption( { type, label = '', avatar = '', count = 0 } ) {
	let image, content;

	switch ( type ) {
		case 'post' :
			image = (
				<AvatarImage
					className="wordcamp-speakers-select-option-avatar"
					name={ label }
					size={ 24 }
					url={ avatar }
				/>
			);
			content = (
				<span className="wordcamp-speakers-select-option-label">
					{ label }
				</span>
			);
			break;

		case 'term' :
			image = (
				<div className="wordcamp-speakers-select-option-icon-container">
					<Dashicon
						className="wordcamp-speakers-select-option-icon"
						icon={ 'megaphone' }
						size={ 16 }
					/>
				</div>
			);
			content = (
				<span className="wordcamp-speakers-select-option-label">
					{ label }
					<span className="wordcamp-speakers-select-option-label-term-count">
						{ count }
					</span>
				</span>
			);
			break;
	}

	return (
		<div className="wordcamp-speakers-select-option">
			{ image }
			{ content }
		</div>
	);
}

export default SpeakersSelect;
