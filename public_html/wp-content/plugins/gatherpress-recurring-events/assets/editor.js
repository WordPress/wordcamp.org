( function ( wp ) {
	const el = wp.element.createElement;
	const { useSelect, useDispatch } = wp.data;
	const { useEffect, useState } = wp.element;
	const { PluginDocumentSettingPanel } = wp.editPost;
	const { CheckboxControl, DatePicker, Notice, RadioControl, SelectControl, TextControl } = wp.components;
	const { __ } = wp.i18n;
	const prefix = '_gpre_';

	function Panel() {
		const [ occurrences, setOccurrences ] = useState( [] );
		const data = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return {
				meta: editor.getEditedPostAttribute( 'meta' ) || {},
				status: editor.getCurrentPostAttribute( 'status' ),
				postType: editor.getCurrentPostType(),
			};
		}, [] );
		const { editPost } = useDispatch( 'core/editor' );
		const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );

		if ( data.postType !== 'gatherpress_event' ) {
			return null;
		}

		const locked = data.status === 'publish';
		const get = ( name, fallback ) => data.meta[ prefix + name ] ?? fallback;
		const set = ( name, value ) => editPost( { meta: { ...data.meta, [ prefix + name ]: value } } );
		const frequency = get( 'frequency', '' );
		const weekdays = get( 'weekdays', [] );
		const dayLabels = { MO: __( 'Mon', 'gpre' ), TU: __( 'Tue', 'gpre' ), WE: __( 'Wed', 'gpre' ), TH: __( 'Thu', 'gpre' ), FR: __( 'Fri', 'gpre' ), SA: __( 'Sat', 'gpre' ), SU: __( 'Sun', 'gpre' ) };

		useEffect( () => {
			if ( locked && frequency ) {
				wp.apiFetch( { path: '/gpre/v1/occurrences/' + postId } ).then( setOccurrences ).catch( () => setOccurrences( [] ) );
			}
		}, [ locked, frequency, postId ] );

		const changeStatus = ( occurrence, status ) => {
			wp.apiFetch( { path: '/gpre/v1/occurrence/' + postId + '/' + occurrence.recurrence_id + '/status', method: 'POST', data: { status } } ).then( () => {
				setOccurrences( occurrences.map( ( item ) => item.recurrence_id === occurrence.recurrence_id ? { ...item, status } : item ) );
			} );
		};

		const endSeries = ( occurrence ) => {
			if ( window.confirm( __( 'End the series after this occurrence? Later projected occurrences will be cancelled.', 'gpre' ) ) ) {
				wp.apiFetch( { path: '/gpre/v1/series/' + postId + '/' + occurrence.recurrence_id + '/end', method: 'POST' } ).then( () => {
					setOccurrences( occurrences.map( ( item ) => item.start > occurrence.start ? { ...item, status: 'cancelled' } : item ) );
				} );
			}
		};

		return el( PluginDocumentSettingPanel, { name: 'gpre', title: __( 'Repeating event', 'gpre' ) },
			locked && el( Notice, { status: 'info', isDismissible: false }, __( 'The recurrence schedule is locked after publication. Shared event content can still be edited.', 'gpre' ) ),
			el( SelectControl, {
				label: __( 'Repeats', 'gpre' ), value: frequency, disabled: locked,
				options: [
					{ label: __( 'Does not repeat', 'gpre' ), value: '' },
					{ label: __( 'Weekly', 'gpre' ), value: 'weekly' },
					{ label: __( 'Monthly', 'gpre' ), value: 'monthly' },
					{ label: __( 'Yearly', 'gpre' ), value: 'yearly' },
				], onChange: ( value ) => set( 'frequency', value ),
			} ),
			frequency && el( TextControl, { label: __( 'Repeat every', 'gpre' ), type: 'number', min: 1, value: get( 'interval', 1 ), disabled: locked, onChange: ( value ) => set( 'interval', Math.max( 1, Number( value ) ) ) } ),
			frequency === 'weekly' && el( 'fieldset', {},
				el( 'legend', {}, __( 'Repeat on', 'gpre' ) ),
				Object.keys( dayLabels ).map( ( day ) => el( CheckboxControl, { key: day, label: dayLabels[ day ], checked: weekdays.includes( day ), disabled: locked, onChange: ( checked ) => set( 'weekdays', checked ? [ ...weekdays, day ] : weekdays.filter( ( value ) => value !== day ) ) } ) )
			),
			frequency === 'monthly' && el( RadioControl, { label: __( 'Monthly pattern', 'gpre' ), selected: get( 'monthly_mode', 'day' ), disabled: locked, options: [ { label: __( 'Day of month', 'gpre' ), value: 'day' }, { label: __( 'Ordinal weekday', 'gpre' ), value: 'weekday' } ], onChange: ( value ) => set( 'monthly_mode', value ) } ),
			frequency === 'monthly' && get( 'monthly_mode', 'day' ) === 'day' && el( TextControl, { label: __( 'Day', 'gpre' ), type: 'number', min: 1, max: 31, value: get( 'monthly_day', 1 ), disabled: locked, onChange: ( value ) => set( 'monthly_day', Math.min( 31, Math.max( 1, Number( value ) ) ) ) } ),
			frequency === 'monthly' && get( 'monthly_mode', 'day' ) === 'weekday' && el( wp.element.Fragment, {},
				el( SelectControl, { label: __( 'Order', 'gpre' ), value: get( 'monthly_order', 'first' ), disabled: locked, options: [ 'first', 'second', 'third', 'fourth', 'last' ].map( ( value ) => ( { label: value, value } ) ), onChange: ( value ) => set( 'monthly_order', value ) } ),
				el( SelectControl, { label: __( 'Weekday', 'gpre' ), value: get( 'monthly_weekday', 'MO' ), disabled: locked, options: Object.keys( dayLabels ).map( ( value ) => ( { label: dayLabels[ value ], value } ) ), onChange: ( value ) => set( 'monthly_weekday', value ) } )
			),
			frequency && el( RadioControl, { label: __( 'Ends', 'gpre' ), selected: get( 'end_type', 'never' ), disabled: locked, options: [ { label: __( 'Never', 'gpre' ), value: 'never' }, { label: __( 'On date', 'gpre' ), value: 'until' }, { label: __( 'After occurrences', 'gpre' ), value: 'count' } ], onChange: ( value ) => set( 'end_type', value ) } ),
			frequency && ! locked && get( 'end_type', 'never' ) === 'until' && el( DatePicker, { currentDate: get( 'until', '' ) || undefined, onChange: ( value ) => set( 'until', value.slice( 0, 10 ) ) } ),
			frequency && get( 'end_type', 'never' ) === 'count' && el( TextControl, { label: __( 'Occurrences', 'gpre' ), type: 'number', min: 1, value: get( 'count', 12 ), disabled: locked, onChange: ( value ) => set( 'count', Math.max( 1, Number( value ) ) ) } ),
			locked && frequency && el( 'div', { className: 'gpre-editor-occurrences' },
				el( 'h3', {}, __( 'Upcoming occurrences', 'gpre' ) ),
				occurrences.slice( 0, 12 ).map( ( occurrence ) => el( 'div', { key: occurrence.recurrence_id, style: { marginBottom: '12px' } },
					el( 'div', {}, new Date( occurrence.start ).toLocaleString(), occurrence.status === 'cancelled' ? ' — ' + __( 'Cancelled', 'gpre' ) : '' ),
					el( wp.components.Button, { variant: 'secondary', size: 'small', onClick: () => changeStatus( occurrence, occurrence.status === 'cancelled' ? 'scheduled' : 'cancelled' ) }, occurrence.status === 'cancelled' ? __( 'Restore', 'gpre' ) : __( 'Cancel', 'gpre' ) ),
					occurrence.status !== 'cancelled' && el( wp.components.Button, { variant: 'tertiary', size: 'small', onClick: () => endSeries( occurrence ) }, __( 'End series here', 'gpre' ) )
				) )
			)
		);
	}

	wp.plugins.registerPlugin( 'gpre', { render: Panel } );
} )( window.wp );
