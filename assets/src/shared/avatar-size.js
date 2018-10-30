/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { BaseControl, Button, ButtonGroup, RangeControl } = wp.components;
const { __, _x } = wp.i18n;
import { withInstanceId } from '@wordpress/compose';

const sizePresets = [
	{
		name: __( 'Small', 'wordcamporg' ),
		shortName: _x( 'S', 'size small', 'wordcamporg' ),
		size: 90,
		slug: 'small',
	},
	{
		name: __( 'Regular', 'wordcamporg' ),
		shortName: _x( 'M', 'size medium', 'wordcamporg' ),
		size: 150,
		slug: 'regular',
	},
	{
		name: __( 'Large', 'wordcamporg' ),
		shortName: _x( 'L', 'size large', 'wordcamporg' ),
		size: 300,
		slug: 'large',
	},
	{
		name: __( 'Larger', 'wordcamporg' ),
		shortName: _x( 'XL', 'size extra large', 'wordcamporg' ),
		size: 500,
		slug: 'larger',
	},
];

function AvatarSizeControl( {
	className,
	label,
	help,
	value,
	instanceId,
	onChange,
	initialPosition,
	...props
} ) {
	const id = `wordcamp-inspector-avatar-size-control-${ instanceId }`;

	return (
		<BaseControl
			id={ id }
			className={ classnames( 'wordcamp-components-avatar-size', className ) }
			label={ label }
			help={ help }
		>
			<ButtonGroup>
				{ sizePresets.map( ( preset ) => {
					const { name, shortName, size, slug } = preset;
					const isCurrent = value === size;

					return (
						<Button
							key={ slug }
							isSmall
							isPrimary={ isCurrent }
							aria-pressed={ isCurrent }
							onClick={ () => onChange( Number( size ) ) }
						>
							{ shortName || name }
						</Button>
					);
				} ) }
			</ButtonGroup>

			<Button
				isSmall
				onClick={ () => onChange( Number( initialPosition ) ) }
			>
				{ __( 'Reset', 'wordcamporg' ) }
			</Button>

			<RangeControl
				value={ value }
				initialPosition={ initialPosition }
				onChange={ onChange }
				beforeIcon={ 'format-image' }
				afterIcon={ 'format-image' }
				{ ...props }
			/>
		</BaseControl>
	);
}

export default withInstanceId( AvatarSizeControl )
