/**
 * WordPress dependencies
 */
import { Placeholder, TextControl } from '@wordpress/components';

export default function( { className, help, icon, instructions, label, onChange, value } ) {
	return (
		<Placeholder className={ className } icon={ icon } label={ label } instructions={ instructions }>
			<TextControl label={ label } hideLabelFromVision value={ value } onChange={ onChange } help={ help } />
		</Placeholder>
	);
}
