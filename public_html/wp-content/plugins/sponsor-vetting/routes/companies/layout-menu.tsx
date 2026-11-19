/**
 * The Layout menu of the list of companies.
 */

import { DropdownMenu, MenuGroup, MenuItemsChoice } from '@wordpress/components';
import type { View } from '@wordpress/dataviews';
import { __, isRTL } from '@wordpress/i18n';
import { blockTable, formatListBullets, formatListBulletsRTL, listView } from '@wordpress/icons';
import { applyLayoutChoice, getLayoutChoice } from './view';
import type { LayoutChoice } from './view';

/**
 * Gets what the menu shows for each of its choices. The table's and the list's are those of DataViews' own menu.
 *
 * @return The label and icon, by choice.
 */
function getChoiceDetails(): Record< LayoutChoice[ 'id' ], { label: string; icon: JSX.Element } > {
	return {
		table: { label: __( 'Table', 'wordcamporg' ), icon: blockTable },
		grouped: { label: __( 'Grouped by status', 'wordcamporg' ), icon: listView },
		list: { label: __( 'List', 'wordcamporg' ), icon: isRTL() ? formatListBulletsRTL : formatListBullets },
	};
}

/**
 * The Layout menu of the list of companies: DataViews' own, with the table grouped by status as a layout of its
 * own.
 *
 * @param props              Props.
 * @param props.view         The list's view.
 * @param props.onChangeView Changes the view.
 * @param props.choices      The layouts to choose from.
 * @return The menu.
 */
export function LayoutMenu( {
	view,
	onChangeView,
	choices,
}: {
	view: View;
	onChangeView: ( view: View ) => void;
	choices: LayoutChoice[];
} ) {
	const details = getChoiceDetails();
	const current = getLayoutChoice( view, choices );

	return (
		<DropdownMenu
			icon={ current ? details[ current.id ].icon : blockTable }
			label={ __( 'Layout', 'wordcamporg' ) }
			toggleProps={ { size: 'compact' } }
		>
			{ ( { onClose } ) => (
				<MenuGroup>
					<MenuItemsChoice
						choices={ choices.map( ( { id } ) => ( { value: id, label: details[ id ].label } ) ) }
						value={ current?.id ?? '' }
						onSelect={ ( id ) => {
							const choice = choices.find( ( candidate ) => candidate.id === id );

							if ( choice ) {
								onChangeView( applyLayoutChoice( view, choice ) );
							}

							onClose();
						} }
						onHover={ () => {} }
					/>
				</MenuGroup>
			) }
		</DropdownMenu>
	);
}
