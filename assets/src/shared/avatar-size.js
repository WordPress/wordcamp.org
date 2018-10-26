
import classnames from 'classnames';

const { BaseControl, Button, ButtonGroup, RangeControl } = wp.components;
const { __ } = wp.i18n;
import { withInstanceId } from '@wordpress/compose';

const sizePresets = [
	{
		name: __( 'Small' ),
		shortName: __( 'S' ),
		size: 90,
		slug: 'small',
	},
	{
		name: __( 'Regular' ),
		shortName: __( 'M' ),
		size: 150,
		slug: 'regular',
	},
	{
		name: __( 'Large' ),
		shortName: __( 'L' ),
		size: 300,
		slug: 'large',
	},
	{
		name: __( 'Larger' ),
		shortName: __( 'XL' ),
		size: 600,
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

	const updateSize = ( size ) => {

	};

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
							onClick={ updateSize( size ) }
						>
							{ shortName }
						</Button>
					);
				} ) }
			</ButtonGroup>

			<Button
				isSmall
				onClick={  }
			>
				{ __( 'Reset', 'wordcamporg' ) }
			</Button>

			<RangeControl
				value={  }
				min={  }
				max={  }
				initialPosition={  }
				onChange{  }
				beforeIcon={  }
				afterIcon={  }
			/>
		</BaseControl>
	);
}

export default withInstanceId( AvatarSizeControl )
