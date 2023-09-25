/**
 * This is the calculated value of the admin bar + header height + local nav bar.
 */
const FIXED_HEADER_HEIGHT = 179;

/**
 * Get the value of a CSS custom property.
 *
 * @param {string}      name    Custom property name
 * @param {HTMLElement} element The element to use when calculating the custom property, defaults to body.
 *
 * @return {*} A number value if the property was in pixels, otherwise the value as seen in CSS.
 */
function getCustomPropValue( name, element = document.body ) {
	const value = window.getComputedStyle( element ).getPropertyValue( name );
	if ( 'px' === value.slice( -2 ) ) {
		return Number( value.replace( 'px', '' ) );
	}
	return value;
}

/**
 * Check the position of the sidebar vs the height of the viewport & page
 * container, and toggle the "bottom" class to position the sidebar without
 * overlapping the footer.
 *
 * @return {boolean} True if the sidebar is at the bottom of the page.
 */
function onScroll() {
	// Only run the scroll code if the sidebar is fixed.
	const sidebarContainer = document.querySelector( '.wp-block-wporg-sidebar-container' );
	if ( ! sidebarContainer || ! sidebarContainer.classList.contains( 'is-fixed-sidebar' ) ) {
		return;
	}

	const mainEl = document.getElementById( 'wp--skip-link--target' );
	const footerStart = mainEl.offsetTop + mainEl.offsetHeight;

	const gap = getCustomPropValue( '--wp--preset--spacing--edge-space' );
	const viewportYOffset = window
		.getComputedStyle( document.documentElement )
		.getPropertyValue( 'margin-top' )
		.replace( 'px', '' );

	// This value needs to take account the margin on `html`.
	const scrollPosition = window.scrollY - viewportYOffset;

	if ( ! sidebarContainer.classList.contains( 'is-bottom-sidebar' ) ) {
		// The pixel location of the bottom of the sidebar, relative to the top of the page.
		const sidebarBottom = scrollPosition + sidebarContainer.offsetHeight + sidebarContainer.offsetTop;

		// Is the sidebar bottom crashing into the footer?
		if ( footerStart - gap < sidebarBottom ) {
			sidebarContainer.classList.add( 'is-bottom-sidebar' );
			// Bottom sidebar is absolutely positioned, so we need to set the top relative to the page origin.
			sidebarContainer.style.setProperty(
				'top',
				// Starting from the footer Y position, subtract the sidebar height and gap/margins, and add
				// the viewport offset. This ensures the sidebar doesn't jump when the class is switched.
				`${ footerStart - sidebarContainer.clientHeight - gap * 2 + viewportYOffset * 1 }px`
			);
			return true;
		}
	} else if ( footerStart - sidebarContainer.offsetHeight - FIXED_HEADER_HEIGHT - gap * 2 > scrollPosition ) {
		// If the scroll position is higher than the top of the sidebar, switch back to just a fixed sidebar.
		sidebarContainer.classList.remove( 'is-bottom-sidebar' );
		sidebarContainer.style.removeProperty( 'top' );
	}
	return false;
}

function isSidebarWithinViewport( container ) {
	// Margin offset from the top of the sidebar.
	const gap = getCustomPropValue( '--wp--preset--spacing--edge-space' );
	// Usable viewport height.
	const viewHeight = window.innerHeight - FIXED_HEADER_HEIGHT;
	// Get the height of the sidebar, plus the top margin and 50px for the
	// "Back to top" link, which isn't visible until `is-fixed-sidebar` is
	// added, therefore not included in the offsetHeight value.
	const sidebarHeight = container.offsetHeight + gap + 50;
	// If the sidebar is shorter than the view area, apply the class so
	// that it's fixed and scrolls with the page content.
	return sidebarHeight < viewHeight;
}

function init() {
	const container = document.querySelector( '.wp-block-wporg-sidebar-container' );
	const toggleButton = container?.querySelector( '.wporg-table-of-contents__toggle' );
	const list = container?.querySelector( '.wporg-table-of-contents__list' );

	if ( toggleButton && list ) {
		// If the page is at least two columns, expand the toggle by default.
		if ( window.matchMedia( '(min-width: 1200px)' ).matches ) {
			toggleButton.setAttribute( 'aria-expanded', true );
			list.removeAttribute( 'style' );
		}

		toggleButton.addEventListener( 'click', function () {
			if ( toggleButton.getAttribute( 'aria-expanded' ) === 'true' ) {
				toggleButton.setAttribute( 'aria-expanded', false );
				list.setAttribute( 'style', 'display:none;' );
			} else {
				toggleButton.setAttribute( 'aria-expanded', true );
				list.removeAttribute( 'style' );
			}

			// Use the same media query that determines whether it's 2 columns,
			// because we don't need to manage scroll when one column.
			if ( ! window.matchMedia( '(min-width: 1200px)' ).matches ) {
				return;
			}

			// After toggle, see if we need to update the sidebar classes.
			if ( isSidebarWithinViewport( container ) ) {
				container.classList.add( 'is-fixed-sidebar' );
			} else {
				container.classList.remove( 'is-fixed-sidebar' );
				window.scrollTo( { top: 0, left: 0, behavior: 'instant' } );
			}
			// Remove the bottom sidebar class and re-run the check to re-add
			// it if the newly-expanded sidebar crashes into the footer.
			container.classList.remove( 'is-bottom-sidebar' );
			const isBottom = onScroll();
			// If the sidebar is at the bottom, opening it might push it
			// upwards off the screen, so scroll to it (take into account
			// the fixed headers, plus a little extra space).
			if ( isBottom ) {
				window.scrollTo( {
					top:
						container.offsetTop -
						FIXED_HEADER_HEIGHT -
						getCustomPropValue( '--wp--preset--spacing--20' ),
					left: 0,
					behavior: 'instant',
				} );
			}
		} );
	}

	if ( container ) {
		if ( isSidebarWithinViewport( container ) ) {
			container.classList.add( 'is-fixed-sidebar' );
			onScroll(); // Run once to avoid footer collisions on load (ex, when linked to #reply-title).
			window.addEventListener( 'scroll', onScroll );
		}
	}

	// If there is no table of contents, hide the heading.
	if ( ! document.querySelector( '.wp-block-wporg-table-of-contents' ) ) {
		const heading = document.querySelector( '.wp-block-wporg-sidebar-container h2' );
		heading?.style.setProperty( 'display', 'none' );
	}
}

window.addEventListener( 'load', init );
