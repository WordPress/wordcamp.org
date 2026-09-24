( function ( wp ) {
	const el = wp.element.createElement;
	const { useSelect, useDispatch } = wp.data;
	const { useEffect, useState } = wp.element;
	const { PluginDocumentSettingPanel } = wp.editPost;
	const { Button, DatePicker, Notice, RadioControl, SelectControl, TextControl, Tooltip } = wp.components;
	const { __ } = wp.i18n;
	const prefix = '_gpre_';
	const controlStackStyle = {
		display: 'flex',
		flexDirection: 'column',
		gap: '16px',
	};
	const weekdaysStyle = {
		display: 'flex',
		flexDirection: 'column',
		alignItems: 'stretch',
		gap: '8px',
	};
	const weekdayButtonsStyle = {
		display: 'flex',
		alignItems: 'center',
		justifyContent: 'flex-start',
		flexWrap: 'wrap',
		gap: '4px',
		rowGap: '6px',
	};
	const weekdayButtonStyle = {
		display: 'inline-flex',
		alignItems: 'center',
		justifyContent: 'center',
		minWidth: '32px',
		height: '30px',
		padding: '0 6px',
		border: 0,
		borderRadius: '15px',
		background: '#f0f0f0',
		color: 'var(--wp-admin-theme-color, #3858e9)',
		fontSize: '13px',
		fontWeight: 500,
		lineHeight: 1,
	};
	const sectionLabelStyle = {
		boxSizing: 'border-box',
		display: 'block',
		width: '100%',
		maxWidth: '100%',
		padding: 0,
		fontSize: '11px',
		fontWeight: 500,
		lineHeight: 1.4,
		textTransform: 'uppercase',
	};

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
		const dateTimeStart = useSelect( ( select ) => select( 'gatherpress/datetime' )?.getDateTimeStart?.() ?? '', [] );

		if ( data.postType !== 'gatherpress_event' ) {
			return null;
		}

		const locked = data.status === 'publish';
		const get = ( name, fallback ) => data.meta[ prefix + name ] ?? fallback;
		const set = ( name, value ) => editPost( { meta: { ...data.meta, [ prefix + name ]: value } } );
		const frequency = get( 'frequency', '' );
		const weekdays = get( 'weekdays', [] );
		const dayLabels = { MO: __( 'Mon', 'wordcamporg' ), TU: __( 'Tue', 'wordcamporg' ), WE: __( 'Wed', 'wordcamporg' ), TH: __( 'Thu', 'wordcamporg' ), FR: __( 'Fri', 'wordcamporg' ), SA: __( 'Sat', 'wordcamporg' ), SU: __( 'Sun', 'wordcamporg' ) };
		const dayFullLabels = {
			MO: __( 'Monday', 'wordcamporg' ),
			TU: __( 'Tuesday', 'wordcamporg' ),
			WE: __( 'Wednesday', 'wordcamporg' ),
			TH: __( 'Thursday', 'wordcamporg' ),
			FR: __( 'Friday', 'wordcamporg' ),
			SA: __( 'Saturday', 'wordcamporg' ),
			SU: __( 'Sunday', 'wordcamporg' ),
		};
		const weekdayCodes = [ 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ];
		const startDate = dateTimeStart ? new Date( dateTimeStart.replace( ' ', 'T' ) ) : null;
		const startWeekday = startDate && ! isNaN( startDate ) ? weekdayCodes[ startDate.getDay() ] : '';
		const startWeekdayMismatch = frequency === 'weekly' && weekdays.length > 0 && startWeekday && ! weekdays.includes( startWeekday );

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
			if ( window.confirm( __( 'End the series after this occurrence? Later projected occurrences will be cancelled.', 'wordcamporg' ) ) ) {
				wp.apiFetch( { path: '/gpre/v1/series/' + postId + '/' + occurrence.recurrence_id + '/end', method: 'POST' } ).then( () => {
					setOccurrences( occurrences.map( ( item ) => item.start > occurrence.start ? { ...item, status: 'cancelled' } : item ) );
				} );
			}
		};

		return el( PluginDocumentSettingPanel, { name: 'gpre', title: __( 'Repeating event', 'wordcamporg' ) },
			el( 'div', { style: controlStackStyle },
			locked && el( Notice, { status: 'info', isDismissible: false }, __( 'The recurrence schedule is locked after publication. Shared event content can still be edited.', 'wordcamporg' ) ),
			el( SelectControl, {
				label: __( 'Repeats', 'wordcamporg' ), value: frequency, disabled: locked,
				options: [
					{ label: __( 'Does not repeat', 'wordcamporg' ), value: '' },
					{ label: __( 'Weekly', 'wordcamporg' ), value: 'weekly' },
					{ label: __( 'Monthly', 'wordcamporg' ), value: 'monthly' },
					{ label: __( 'Yearly', 'wordcamporg' ), value: 'yearly' },
				], onChange: ( value ) => set( 'frequency', value ),
			} ),
			frequency && el( TextControl, { label: __( 'Repeat every', 'wordcamporg' ), type: 'number', min: 1, value: get( 'interval', 1 ), disabled: locked, onChange: ( value ) => set( 'interval', Math.max( 1, Number( value ) ) ) } ),
			frequency === 'weekly' && el( 'div', { style: weekdaysStyle, role: 'group', 'aria-label': __( 'Repeat on', 'wordcamporg' ) },
				el( 'span', { style: sectionLabelStyle }, __( 'Repeat on', 'wordcamporg' ) ),
				// Buttons render the three-letter dayLabels rather than a single-letter
				// form. Single letters collide under gettext dedup (e.g. "T" for both
				// Tue and Thu, "S" for Sat and Sun), which blocks translation in
				// languages where those days start with different letters — Italian
				// Martedì/Giovedì, Spanish Sábado/Domingo, etc. See #1893.
				el( 'div', { style: weekdayButtonsStyle },
					Object.keys( dayFullLabels ).map( ( day ) => {
						const selected = weekdays.includes( day );
						const buttonStyle = selected ? { ...weekdayButtonStyle, background: 'var(--wp-admin-theme-color, #3858e9)', color: '#fff' } : weekdayButtonStyle;

						return el( Tooltip, { key: day, text: dayFullLabels[ day ] },
							el( Button, {
								style: buttonStyle,
								disabled: locked,
								'aria-label': dayFullLabels[ day ],
								'aria-pressed': selected,
								onClick: () => set( 'weekdays', selected ? weekdays.filter( ( value ) => value !== day ) : [ ...weekdays, day ] ),
							}, dayLabels[ day ] )
						);
					} )
				)
			),
			startWeekdayMismatch && ! locked && el( Notice, { status: 'warning', isDismissible: false },
				__( 'The event start date doesn’t fall on a selected repeat day. Publishing will add an extra occurrence on the start date, one day before the weekly pattern begins.', 'wordcamporg' )
			),
			frequency === 'monthly' && el( RadioControl, { label: __( 'Monthly pattern', 'wordcamporg' ), selected: get( 'monthly_mode', 'day' ), disabled: locked, options: [ { label: __( 'Day of month', 'wordcamporg' ), value: 'day' }, { label: __( 'Ordinal weekday', 'wordcamporg' ), value: 'weekday' } ], onChange: ( value ) => set( 'monthly_mode', value ) } ),
			frequency === 'monthly' && get( 'monthly_mode', 'day' ) === 'day' && el( TextControl, { label: __( 'Day', 'wordcamporg' ), type: 'number', min: 1, max: 31, value: get( 'monthly_day', 1 ), disabled: locked, onChange: ( value ) => set( 'monthly_day', Math.min( 31, Math.max( 1, Number( value ) ) ) ) } ),
			frequency === 'monthly' && get( 'monthly_mode', 'day' ) === 'weekday' && el( wp.element.Fragment, {},
				el( SelectControl, { label: __( 'Order', 'wordcamporg' ), value: get( 'monthly_order', 'first' ), disabled: locked, options: [ 'first', 'second', 'third', 'fourth', 'last' ].map( ( value ) => ( { label: value, value } ) ), onChange: ( value ) => set( 'monthly_order', value ) } ),
				el( SelectControl, { label: __( 'Weekday', 'wordcamporg' ), value: get( 'monthly_weekday', 'MO' ), disabled: locked, options: Object.keys( dayLabels ).map( ( value ) => ( { label: dayLabels[ value ], value } ) ), onChange: ( value ) => set( 'monthly_weekday', value ) } )
			),
			frequency && el( RadioControl, { label: __( 'Ends', 'wordcamporg' ), selected: get( 'end_type', 'never' ), disabled: locked, options: [ { label: __( 'Never', 'wordcamporg' ), value: 'never' }, { label: __( 'On date', 'wordcamporg' ), value: 'until' }, { label: __( 'After occurrences', 'wordcamporg' ), value: 'count' } ], onChange: ( value ) => set( 'end_type', value ) } ),
			frequency && ! locked && get( 'end_type', 'never' ) === 'until' && el( DatePicker, { currentDate: get( 'until', '' ) || undefined, onChange: ( value ) => set( 'until', value.slice( 0, 10 ) ) } ),
			frequency && get( 'end_type', 'never' ) === 'count' && el( TextControl, { label: __( 'Occurrences', 'wordcamporg' ), type: 'number', min: 1, value: get( 'count', 12 ), disabled: locked, onChange: ( value ) => set( 'count', Math.max( 1, Number( value ) ) ) } ),
			locked && frequency && el( 'div', { className: 'gpre-editor-occurrences' },
				el( 'h3', {}, __( 'Upcoming occurrences', 'wordcamporg' ) ),
				occurrences.slice( 0, 12 ).map( ( occurrence ) => el( 'div', { key: occurrence.recurrence_id, style: { marginBottom: '12px' } },
					el( 'div', {}, new Date( occurrence.start ).toLocaleString(), occurrence.status === 'cancelled' ? ' — ' + __( 'Cancelled', 'wordcamporg' ) : '' ),
					el( wp.components.Button, { variant: 'secondary', size: 'small', onClick: () => changeStatus( occurrence, occurrence.status === 'cancelled' ? 'scheduled' : 'cancelled' ) }, occurrence.status === 'cancelled' ? __( 'Restore', 'wordcamporg' ) : __( 'Cancel', 'wordcamporg' ) ),
					occurrence.status !== 'cancelled' && el( wp.components.Button, { variant: 'tertiary', size: 'small', onClick: () => endSeries( occurrence ) }, __( 'End series here', 'wordcamporg' ) )
				) )
			)
			)
		);
	}

	wp.plugins.registerPlugin( 'gpre', { render: Panel } );
} )( window.wp );
