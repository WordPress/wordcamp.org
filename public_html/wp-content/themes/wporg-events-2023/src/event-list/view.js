/* global globalEventsPayload */

/**
 * WordPress dependencies
 */
import { escapeAttribute, escapeHTML } from '@wordpress/escape-html';

document.addEventListener( 'DOMContentLoaded', function () {
	const speak = wp.a11y.speak;
	const NEARBY_LOCATION_KEY = 'wporg-events-nearby-location';

	/**
	 * Initialize the component.
	 */
	function init() {
		document
			.querySelectorAll( '.wporg-event-list__nearby[data-rest-url]' )
			.forEach( initNearby );

		if ( 'undefined' === typeof globalEventsPayload ) {
			// eslint-disable-next-line no-console
			console.error( 'Missing globalEventsPayload' );
			return;
		}

		for ( const id in globalEventsPayload ) {
			const listContainer = document.querySelector(
				`#wp-block-wporg-event-list-${ id }`
			);
			if ( ! listContainer ) {
				// eslint-disable-next-line no-console
				console.error(
					`Missing container for global events with id ${ id }`
				);
				continue;
			}

			renderGlobalEvents(
				listContainer,
				globalEventsPayload[ id ].events,
				globalEventsPayload[ id ].groupByMonth
			);
		}
	}

	/**
	 * Render global events
	 *
	 * @param {Object}  container
	 * @param {Array}   events
	 * @param {boolean} groupByMonth
	 */
	function renderGlobalEvents( container, events, groupByMonth ) {
		const loadingElement = container.querySelector(
			'.wporg-marker-list__loading'
		);
		const groupedEvents = {};
		let markup = '';

		if ( groupByMonth ) {
			for ( let i = 0; i < events.length; i++ ) {
				const eventMonthYear = new Date(
					events[ i ].timestamp * 1000
				).toLocaleDateString( [], {
					year: 'numeric',
					month: 'long',
				} );

				groupedEvents[ eventMonthYear ] =
					groupedEvents[ eventMonthYear ] || [];
				groupedEvents[ eventMonthYear ].push( events[ i ] );
			}

			for ( const [ month, eventGroup ] of Object.entries(
				groupedEvents
			) ) {
				markup += renderEventGroup( eventGroup, month );
			}
		} else {
			markup = renderEventList( events );
		}

		container.innerHTML = markup;

		loadingElement.classList.add( 'wporg-events__hidden' );
		speak( 'Global events loaded.' );
	}

	/**
	 * Set up a list of events near the visitor.
	 *
	 * @param {Element} container
	 */
	function initNearby( container ) {
		const changeButton = container.querySelector(
			'.wporg-event-list__nearby-change'
		);
		const locationPill = container.querySelector(
			'.wporg-event-list__nearby-location'
		);
		const form = container.querySelector(
			'.wporg-event-list__nearby-form'
		);
		const cityInput = container.querySelector(
			'.wporg-event-list__nearby-city'
		);
		const savedLocation = getSavedLocation();

		/**
		 * Go back from the search form to the location pill.
		 */
		function closeForm() {
			form.classList.add( 'wporg-events__hidden' );
			locationPill.classList.remove( 'wporg-events__hidden' );
			changeButton.focus();
		}

		changeButton.addEventListener( 'click', () => {
			locationPill.classList.add( 'wporg-events__hidden' );
			form.classList.remove( 'wporg-events__hidden' );
			cityInput.focus();
			cityInput.select();
		} );

		form.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key ) {
				closeForm();
			}
		} );

		cityInput.addEventListener( 'input', () =>
			cityInput.setCustomValidity( '' )
		);

		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();

			const city = cityInput.value.trim();

			if ( ! city ) {
				return;
			}

			if ( ! ( await loadNearbyEvents( container, city ) ) ) {
				cityInput.setCustomValidity(
					`Couldn't find a place called "${ city }". Try a nearby city.`
				);
				cityInput.reportValidity();
				return;
			}

			closeForm();
		} );

		loadNearbyEvents( container, savedLocation ).then( ( loaded ) => {
			// Fall back to the approximate location if the saved place no longer works.
			if ( ! loaded && savedLocation ) {
				loadNearbyEvents( container, '' );
			}
		} );
	}

	/**
	 * Fetch and render events near a place.
	 *
	 * Searching for a city goes straight to the Events API, which allows any origin. Without a city, the visitor's
	 * approximate location comes from their IP, which only the server knows, so that goes through our endpoint.
	 * The front page is page-cached, so it can't be rendered into the HTML.
	 *
	 * @param {Element} container
	 * @param {string}  city      Optional. A place the visitor searched for.
	 *
	 * @return {Promise<boolean>} Whether the events could be loaded.
	 */
	async function loadNearbyEvents( container, city ) {
		const loadingElement = container.querySelector(
			'.wporg-marker-list__loading'
		);
		const results = container.querySelector(
			'.wporg-event-list__nearby-results'
		);
		const emptyMessage = container.querySelector(
			'.wporg-event-list__nearby-empty'
		);
		const timezone = window.Intl
			? window.Intl.DateTimeFormat().resolvedOptions().timeZone
			: '';
		let url, events, place;

		if ( city ) {
			url = `https://api.wordpress.org/events/1.0/?${ new URLSearchParams(
				{
					location: city,
					number: 10,
				}
			) }`;
		} else {
			url = new URL( container.dataset.restUrl );

			if ( timezone ) {
				url.searchParams.set( 'timezone', timezone );
			}
		}

		loadingElement.classList.remove( 'wporg-events__hidden' );

		try {
			/*
			 * This uses `fetch()` directly instead of `apiFetch()`, because the latter is only intended for
			 * interacting with WP REST API endpoints, and there are lots of difficulties making it work with
			 * other APIs.
			 *
			 * See https://github.com/WordPress/gutenberg/pull/15900#issuecomment-497139968.
			 */
			const response = await fetch( url, { credentials: 'omit' } );

			if ( ! response.ok ) {
				throw new Error( `HTTP ${ response.status }` );
			}

			const body = await response.json();

			if ( city ) {
				// The API didn't recognize the place, which is different from there being nothing there.
				if ( ! body.location?.description ) {
					throw new Error( body.error || 'Unknown location' );
				}

				events = normalizeApiEvents( body.events );
				place = body.location.description;
			} else {
				events = body.events;
			}
		} catch ( error ) {
			loadingElement.classList.add( 'wporg-events__hidden' );

			if ( city ) {
				return false;
			}

			// eslint-disable-next-line no-console
			console.error( error );

			// Better to show nothing than a section that's stuck loading.
			container.classList.add( 'wporg-events__hidden' );
			return false;
		}

		if ( city ) {
			saveLocation( city );
		}

		container.querySelector(
			'.wporg-event-list__nearby-location-name'
		).textContent = place || 'Near you';

		container.querySelector(
			'.wporg-event-list__nearby-empty-place'
		).textContent = place || 'you';

		// The approximate-location explanation doesn't apply to a place the visitor chose.
		container
			.querySelector( '.wporg-event-list__nearby-description' )
			.classList.toggle( 'wporg-events__hidden', !! place );

		results.innerHTML = events.length ? renderEventList( events ) : '';
		emptyMessage.classList.toggle(
			'wporg-events__hidden',
			events.length > 0
		);
		loadingElement.classList.add( 'wporg-events__hidden' );

		speak(
			events.length
				? `Showing events near ${ place || 'you' }.`
				: `No events found near ${ place || 'you' }.`
		);

		return true;
	}

	/**
	 * Reduce Events API results to the shape the global events use.
	 *
	 * Keep in sync with `prepare_nearby_events()` in `index.php`, which does the same for the REST endpoint.
	 *
	 * @param {Array} events
	 *
	 * @return {Array} Events with `title`, `url`, `location`, `timestamp` and `type`.
	 */
	function normalizeApiEvents( events ) {
		const now = Date.now() / 1000;

		return ( events || [] )
			.filter( ( event ) => event.end_unix_timestamp > now )
			.map( ( event ) => ( {
				title: decodeEntities( event.title ),
				url: event.url,
				location: event.location?.location ?? '',
				timestamp: event.start_unix_timestamp,
				type: event.type,
			} ) );
	}

	/**
	 * Decode HTML entities in an API value, like Core's widget does, so they aren't double-encoded when escaped.
	 *
	 * @param {string} text
	 *
	 * @return {string} The decoded text.
	 */
	function decodeEntities( text ) {
		return new window.DOMParser().parseFromString(
			String( text ?? '' ),
			'text/html'
		).documentElement.textContent;
	}

	/**
	 * Get the place the visitor last searched for.
	 *
	 * @return {string} The place, or an empty string.
	 */
	function getSavedLocation() {
		try {
			return window.localStorage.getItem( NEARBY_LOCATION_KEY ) || '';
		} catch {
			return '';
		}
	}

	/**
	 * Remember the place the visitor searched for, so it's still there next visit.
	 *
	 * @param {string} city
	 */
	function saveLocation( city ) {
		try {
			window.localStorage.setItem( NEARBY_LOCATION_KEY, city );
		} catch {
			// Storage may be disabled, which only means the search isn't remembered.
		}
	}

	/**
	 * Reduce a URL to one that is safe to place in an `href` attribute.
	 *
	 * Encoding alone isn't enough for a URL, since the scheme matters as much as
	 * the characters. Anything that isn't HTTP(S) is dropped. Values arrive
	 * absolute from `index.php`, so no base is passed -- that way a value which
	 * isn't a whole URL fails the parse rather than resolving against this page.
	 *
	 * @param {string} unsafe
	 *
	 * @return {string} The encoded URL, or an empty string if it isn't linkable.
	 */
	function escapeUrl( unsafe ) {
		let parsed;

		try {
			parsed = new URL( String( unsafe ?? '' ) );
		} catch {
			return '';
		}

		if ( 'http:' !== parsed.protocol && 'https:' !== parsed.protocol ) {
			return '';
		}

		return escapeAttribute( parsed.href );
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
				${ escapeHTML( month ) }
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
	 * @param {string} event.url
	 * @param {string} event.location
	 * @param {number} event.timestamp
	 * @param {string} event.type      Optional. Only nearby events have one.
	 *
	 * @return {string}
	 */
	function renderEvent( { title, url, location, timestamp, type } ) {
		const markup = `
			<li class="wporg-marker-list-item">
				<h3 class="wporg-marker-list-item__title">
					<a class="external-link" href="${ escapeUrl( url ) }">
						${ escapeHTML( title ) }
					</a>
					${
						type
							? `<span class="wporg-marker-list-item__type">${ escapeHTML(
									type
							  ) }</span>`
							: ''
					}
				</h3>

				<div class="wporg-marker-list-item__location">
					${ escapeHTML( location ) }
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
			    title="${ escapeAttribute( title ) }"
		    >
				<span class="wporg-google-map__date">${ localeDate }</span>
				<span class="wporg-google-map__time">${ localeTime }</span>
	        </time>
		`;
	}

	init();
} );
