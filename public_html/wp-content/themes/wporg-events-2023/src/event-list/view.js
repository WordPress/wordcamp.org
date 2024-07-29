/* global globalEventsPayload */

document.addEventListener( 'DOMContentLoaded', function() {
	const speak = wp.a11y.speak;
	const globalEventList = document.querySelector( '.wp-block-wporg-event-list' );

	/**
	 * Initialize the component.
	 */
	function init() {
		if ( 'undefined' === typeof globalEventsPayload ) {
			// eslint-disable-next-line no-console
			console.error( 'Missing globalEventsPayload' );
			return;
		}

		renderGlobalEvents( globalEventsPayload.events, globalEventsPayload.groupByMonth );
	}

	/**
	 * Render global events
	 *
	 * @param {Array}   events
	 * @param {boolean} groupByMonth
	 */
	function renderGlobalEvents( events, groupByMonth ) {
		const loadingElement = globalEventList.querySelector( '.wporg-marker-list__loading' );
		const groupedEvents = {};
		let markup = '';

		if ( groupByMonth ) {
			for ( let i = 0; i < events.length; i++ ) {
				const eventMonthYear = new Date( events[ i ].timestamp * 1000 ).toLocaleDateString( [], {
					year: 'numeric',
					month: 'long',
				} );

				groupedEvents[ eventMonthYear ] = groupedEvents[ eventMonthYear ] || [];
				groupedEvents[ eventMonthYear ].push( events[ i ] );
			}

			for ( const [ month, eventGroup ] of Object.entries( groupedEvents ) ) {
				markup += renderEventGroup( eventGroup, month );
			}
		} else {
			markup = renderEventList( events );
		}

		globalEventList.innerHTML = markup;

		loadingElement.classList.add( 'wporg-events__hidden' );
		speak( 'Global events loaded.' );
	}

	/**
	 * Encode any HTML in a string to prevent XSS.
	 *
	 * @param {string} unsafe
	 *
	 * @return {string}
	 */
	function escapeHtml( unsafe ) {
		const safe = document.createTextNode( unsafe ).textContent;

		return safe;
	}

	/**
	 * Render a group of events for a given month
	 *
	 * @param {Array}  group
	 * @param {string} month
	 *
	 * @return {string}
	 */
	function renderEventGroup( group, month ) {
		let markup = `
			<h2
				class="wp-block-heading has-charcoal-1-color has-text-color has-link-color has-inter-font-family has-medium-font-size"
				style="margin-top:var(--wp--preset--spacing--40);margin-bottom:var(--wp--preset--spacing--20);font-style:normal;font-weight:700">
				${ escapeHtml( month ) }
			</h2>`;

		markup += renderEventList( group );

		return markup;
	}

	/**
	 * Render a list of events
	 *
	 * @param {Array} events
	 *
	 * @return {string}
	 */
	function renderEventList( events ) {
		let markup = '<ul class="wporg-marker-list__container">';

		for ( let i = 0; i < events.length; i++ ) {
			markup += renderEvent( events[ i ] );
		}

		markup += '</ul>';

		return markup;
	}

	/**
	 * Render a single event
	 *
	 * @param {Object} event
	 * @param {string} event.title
	 * @param {string} event.type
	 * @param {string} event.url
	 * @param {string} event.location
	 * @param {number} event.timestamp
	 *
	 * @return {string}
	 */
	function renderEvent( { title, url, location, timestamp, type } ) {
		const markup = `
			<li class="wporg-marker-list-item">
				<h3 class="wporg-marker-list-item__title">
					<a class="external-link" href="${ escapeHtml( url ) }">
						${ escapeHtml( title ) }
					</a>
				</h3>

				<div class="wporg-marker-list-item__type">
					${ escapeHtml( type ) }
				</div>

				<div class="wporg-marker-list-item__location">
					${ escapeHtml( location ) }
				</div>

				${ getEventDateTime( title, timestamp ) }
			</li>
		`;

		return markup;
	}

	/**
	 * Display a timestamp in the user's timezone and locale format.
	 *
	 * Note: The start time and day of the week are important pieces of information to include, since that helps
	 * attendees know at a glance if it's something they can attend. Otherwise they have to click to open it. The
	 * timezone is also important to make it clear that we're showing the user's timezone, not the venue's.
	 *
	 * @see https://make.wordpress.org/community/2017/03/23/showing-upcoming-local-events-in-wp-admin/#comment-23297
	 * @see https://make.wordpress.org/community/2017/03/23/showing-upcoming-local-events-in-wp-admin/#comment-23307
	 *
	 * @param {string} title
	 * @param {number} timestamp
	 *
	 * @return {string} The formatted date and time.
	 */
	function getEventDateTime( title, timestamp ) {
		const eventDate = new Date( parseInt( timestamp ) * 1000 );

		const localeDate = eventDate.toLocaleDateString( [], {
			weekday: 'short',
			year: 'numeric',
			month: 'short',
			day: 'numeric',
		} );

		const localeTime = eventDate.toLocaleString( [], {
			timeZoneName: 'short',
			hour: 'numeric',
			minute: '2-digit',
		} );

		return `
			<time
			    class="wporg-marker-list-item__date-time"
			    datetime="${ eventDate.toISOString() }"
			    title="${ escapeHtml( title ) }"
		    >
				<span class="wporg-google-map__date">${ localeDate }</span>
				<span class="wporg-google-map__time">${ localeTime }</span>
	        </time>
		`;
	}

	init();
} );
