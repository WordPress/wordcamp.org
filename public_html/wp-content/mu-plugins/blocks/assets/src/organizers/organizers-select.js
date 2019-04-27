/**
 * External dependencies
 */
import { flatMap, includes } from 'lodash';
import createSelector    from 'rememo';

/**
 * WordPress dependencies
 */
const { Dashicon }  = wp.components;
const { Component } = wp.element;
const { __ }        = wp.i18n;

/**
 * Internal dependencies
 */
import { AvatarImage } from '../shared/avatar';
import ItemSelect      from '../shared/item-select';
import { ICON }        from './index';

const parseOrganizerPosts = ( posts ) => {
	let parsed = [];

	if ( Array.isArray( posts ) ) {
		parsed = posts.map( ( post ) => {
			return {
				label  : post.title.rendered.trim() || __( '(Untitled)', 'wordcamporg' ),
				value  : post.id,
				type   : 'wcb_organizer',
				avatar : post.avatar_urls[ '24' ],
			};
		} );
	}

	return parsed;
};

const parseOrganizerTerms = ( terms ) => {
	let parsed = [];

	if ( Array.isArray( terms ) ) {
		parsed = terms.map( ( term ) => {
			return {
				label : term.name.trim() || __( '(Untitled)', 'wordcamporg' ),
				value : term.id,
				type  : 'wcb_organizer_team',
				count : term.count,
			};
		} );
	}

	return parsed;
};

/**
 * The select options only need to be generated once after all the data is loaded,
 * so memoize them.
 */
const buildSelectOptions = createSelector(
	( props ) => { console.log('built');
		const { allOrganizerPosts, allOrganizerTerms } = props;

		const optionTypes = [ 'wcb_organizer', 'wcb_organizer_team' ];
		const options     = [];

		optionTypes.forEach( ( type ) => {
			let label, items;

			switch ( type ) {
				case 'wcb_organizer':
					label = __( 'Organizers', 'wordcamporg' );
					items = parseOrganizerPosts( allOrganizerPosts );
					break;
				case 'wcb_organizer_team':
					label = __( 'Teams', 'wordcamporg' );
					items = parseOrganizerTerms( allOrganizerTerms );
					break;
			}

			if ( items.length ) {
				options.push( {
					label,
					options: items,
				} );
			}
		} );

		return options;
	},
	( props ) => [ props.allOrganizerPosts, props.allOrganizerTerms ]
);

function OrganizersOption( { type, label = '', avatar = '', count = 0 } ) {
	let image, content;

	switch ( type ) {
		case 'wcb_organizer' :
			image = (
				<AvatarImage
					className="wordcamp-item-select-option-avatar"
					name={ label }
					size={ 24 }
					url={ avatar }
				/>
			);
			content = (
				<span className="wordcamp-item-select-option-label">
					{ label }
				</span>
			);
			break;

		case 'wcb_organizer_team' :
			image = (
				<div className="wordcamp-item-select-option-icon-container">
					<Dashicon
						className="wordcamp-item-select-option-icon"
						icon={ ICON }
						size={ 16 }
					/>
				</div>
			);
			content = (
				<span className="wordcamp-item-select-option-label">
					{ label }
					<span className="wordcamp-item-select-option-label-term-count">
						{ count }
					</span>
				</span>
			);
			break;
	}

	return (
		<div className="wordcamp-item-select-option">
			{ image }
			{ content }
		</div>
	);
}

class OrganizersSelect extends Component {
	constructor( props ) {
		super( props );

		this.getCurrentSelectValue = this.getCurrentSelectValue.bind( this );
		this.isLoading             = this.isLoading.bind( this );
	}

	getCurrentSelectValue() {
		const { attributes } = this.props;
		const { mode, item_ids } = attributes;

		const options = flatMap( buildSelectOptions( this.props ), ( group ) => {
			return group.options;
		} );

		let value = [];

		if ( mode && item_ids.length ) {
			value = options.filter( ( option ) => {
				return mode === option.type && includes( item_ids, option.value );
			} );
		}

		return value;
	}

	isLoading() {
		const { allOrganizerPosts, allOrganizerTerms } = this.props;

		return ! ( Array.isArray( allOrganizerPosts ) && Array.isArray( allOrganizerTerms ) );
	}

	render() {
		const { label, setAttributes } = this.props;

		return (
			<ItemSelect
				className="wordcamp-organizers-select"
				label={ label }
				value={ this.getCurrentSelectValue() }
				onChange={ ( changed ) => setAttributes( changed ) }
				selectProps={ {
					options           : buildSelectOptions( this.props ),
					isLoading         : this.isLoading(),
					formatGroupLabel  : ( groupData ) => {
						return (
							<span className="wordcamp-item-select-option-group-label">
								{ groupData.label }
							</span>
						);
					},
					formatOptionLabel : ( optionData ) => {
						return (
							<OrganizersOption { ...optionData } />
						);
					},
				} }
			/>
		);
	}
}

export default OrganizersSelect;
