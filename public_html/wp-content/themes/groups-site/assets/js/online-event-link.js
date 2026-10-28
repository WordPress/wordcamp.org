/**
 * Keeps the online-event link honest after an RSVP made without a reload.
 *
 * The meeting URL is for attendees only, so a page load decides whether this
 * element is a link to it or a description of the event's format (see
 * `name_the_online_event_link_action()` in functions.php). The RSVP block
 * updates in place, which leaves the element behind: someone who has just
 * RSVP'd still sees "Online event" and no way through to the meeting, and
 * someone who has just cancelled still sees a live link. So listen for the
 * RSVP and rewrite the element into the state the next page load would have
 * rendered.
 *
 * The URL only ever comes from the RSVP response. A response without one is
 * from a server that predates this, and is left alone rather than guessed at.
 */
( function () {
	const BLOCK_SELECTOR = '.wp-block-gatherpress-online-event-link';
	const TEXT_SELECTOR = '.gatherpress-online-event__text';

	/**
	 * The event this element belongs to, as GatherPress's own render records it.
	 *
	 * @param {Element} block The online-event-link wrapper.
	 * @return {number} The event's post ID, or 0 when it can't be read.
	 */
	function getPostId( block ) {
		try {
			return Number( JSON.parse( block.dataset.wpContext || '{}' ).postId ) || 0;
		} catch {
			return 0;
		}
	}

	/**
	 * Replace the label element, keeping it in the same place in the block.
	 *
	 * The tag itself changes with the state — GatherPress styles and scripts
	 * both key off `<a>` versus `<span>` — so this swaps the node rather than
	 * mutating one in place.
	 *
	 * @param {Element}     block The online-event-link wrapper.
	 * @param {string}      tag   'a' or 'span'.
	 * @param {string|null} href  The meeting URL, for 'a'.
	 * @param {string}      html  The label markup, as PHP escaped it.
	 */
	function render( block, tag, href, html ) {
		const current = block.querySelector( TEXT_SELECTOR );
		if ( ! current ) {
			return;
		}

		const next = document.createElement( tag );
		next.className = 'gatherpress-online-event__text';
		if ( 'a' === tag ) {
			next.href = href;
			next.target = '_blank';
			next.rel = 'noopener noreferrer';
		}
		// Both labels are authored in PHP — one in single-event.html, one in
		// functions.php — and arrive already escaped for output.
		next.innerHTML = html;

		current.replaceWith( next );
	}

	/**
	 * @param {CustomEvent} event The RSVP block's `wporg-groups-rsvp-changed`.
	 */
	function onRsvpChanged( event ) {
		const detail = event.detail || {};

		// Undefined means the endpoint said nothing about the link; empty
		// string means it said there is none to show.
		if ( undefined === detail.onlineEventLink ) {
			return;
		}

		document.querySelectorAll( BLOCK_SELECTOR ).forEach( function ( block ) {
			if ( detail.postId && getPostId( block ) !== detail.postId ) {
				return;
			}

			const join = block.dataset.groupsSiteJoinLabel;
			const description = block.dataset.groupsSiteDescriptionLabel;
			if ( undefined === join || undefined === description ) {
				return;
			}

			if ( detail.onlineEventLink ) {
				render( block, 'a', detail.onlineEventLink, join );
			} else {
				render( block, 'span', null, description );
			}
		} );
	}

	document.addEventListener( 'wporg-groups-rsvp-changed', onRsvpChanged );
} )();
