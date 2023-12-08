/**
 * Fallback values for custom properties match CSS defaults.
 */
const globalNavHeight = 90;

const LOCAL_NAV_HEIGHT = getCustomPropValue( '--wp--custom--local-navigation-bar--spacing--height' ) || 60;
const ADMIN_BAR_HEIGHT = parseInt(
	window.getComputedStyle( document.documentElement ).getPropertyValue( 'margin-top' ),
	10
);
const SPACE_FROM_BOTTOM = getCustomPropValue( '--wp--preset--spacing--edge-space' ) || 80;
const SPACE_TO_TOP = getCustomPropValue( '--wp--custom--wporg-sidebar-container--spacing--margin--top' ) || 80;
const FIXED_HEADER_HEIGHT = globalNavHeight + LOCAL_NAV_HEIGHT + ADMIN_BAR_HEIGHT;
const SCROLL_POSITION_TO_FIX = globalNavHeight + SPACE_TO_TOP - LOCAL_NAV_HEIGHT - ADMIN_BAR_HEIGHT;

let container;
let mainEl;

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
	// Only run the scroll code if the sidebar is floating on a wide screen.
	if ( ! mainEl || ! container || ! window.matchMedia( '(min-width: 1200px)' ).matches ) {
		return;
	}

	const scrollPosition = window.scrollY - ADMIN_BAR_HEIGHT;

	if ( ! container.classList.contains( 'is-bottom-sidebar' ) ) {
		const footerStart = mainEl.offsetTop + mainEl.offsetHeight;
		// The pixel location of the bottom of the sidebar, relative to the top of the page.
		const sidebarBottom = scrollPosition + container.offsetHeight + container.offsetTop - ADMIN_BAR_HEIGHT;

		// Is the sidebar bottom crashing into the footer?
		if ( footerStart - SPACE_FROM_BOTTOM < sidebarBottom ) {
			container.classList.add( 'is-bottom-sidebar' );

			// Bottom sidebar is absolutely positioned, so we need to set the top relative to the page origin.
			// The pixel location of the top of the sidebar, relative to the footer.
			const sidebarTop =
				footerStart - container.offsetHeight - LOCAL_NAV_HEIGHT * 2 + ADMIN_BAR_HEIGHT - SPACE_FROM_BOTTOM;
			container.style.setProperty( 'top', `${ sidebarTop }px` );

			return true;
		}
	} else if ( container.getBoundingClientRect().top > LOCAL_NAV_HEIGHT * 2 + ADMIN_BAR_HEIGHT ) {
		// If the top of the sidebar is above the top fixing position, switch back to just a fixed sidebar.
		container.classList.remove( 'is-bottom-sidebar' );
		container.style.removeProperty( 'top' );
	}

	// Toggle the fixed position based on whether the scrollPosition is greater than the initial gap from the top.
	container.classList.toggle( 'is-fixed-sidebar', scrollPosition > SCROLL_POSITION_TO_FIX );

	return false;
}

function isSidebarWithinViewport() {
	if ( ! container ) {
		return false;
	}
	// Usable viewport height.
	const viewHeight = window.innerHeight - LOCAL_NAV_HEIGHT + ADMIN_BAR_HEIGHT;
	// Get the height of the sidebar, plus the top offset and 60px for the
	// "Back to top" link, which isn't visible until `is-fixed-sidebar` is
	// added, therefore not included in the offsetHeight value.
	const sidebarHeight = container.offsetHeight + LOCAL_NAV_HEIGHT + 60;
	// If the sidebar is shorter than the view area, apply the class so
	// that it's fixed and scrolls with the page content.
	return sidebarHeight < viewHeight;
}

function init() {
	container = document.querySelector( '.wp-block-wporg-sidebar-container' );
	mainEl = document.getElementById( 'wp--skip-link--target' );
	const toggleButton = container?.querySelector( '.wporg-table-of-contents__toggle' );
	const list = container?.querySelector( '.wporg-table-of-contents__list' );

	if ( toggleButton && list ) {
		toggleButton.addEventListener( 'click', function () {
			if ( toggleButton.getAttribute( 'aria-expanded' ) === 'true' ) {
				toggleButton.setAttribute( 'aria-expanded', false );
				list.removeAttribute( 'style' );
			} else {
				toggleButton.setAttribute( 'aria-expanded', true );
				list.setAttribute( 'style', 'display:block;' );
			}
		} );
	}

	if ( isSidebarWithinViewport() ) {
		onScroll(); // Run once to avoid footer collisions on load (ex, when linked to #reply-title).
		window.addEventListener( 'scroll', onScroll );

		const observer = new window.ResizeObserver( () => {
			// If the sidebar is positioned at the bottom and mainEl resizes,
			// it will remain fixed at the previous bottom position, leading to a broken page layout.
			// In this case manually trigger the scroll handler to reposition.
			if ( container.classList.contains( 'is-bottom-sidebar' ) ) {
				container.classList.remove( 'is-bottom-sidebar' );
				container.style.removeProperty( 'top' );
				const isBottom = onScroll();
				// After the sidebar is repositioned, also adjusts the scroll position
				// to a point where the sidebar is visible.
				if ( isBottom ) {
					window.scrollTo( {
						top: container.offsetTop - FIXED_HEADER_HEIGHT,
						behavior: 'instant',
					} );
				}
			}
		} );

		observer.observe( mainEl );
	}

	// If there is no table of contents, hide the heading.
	if ( ! document.querySelector( '.wp-block-wporg-table-of-contents' ) ) {
		const heading = document.querySelector( '.wp-block-wporg-sidebar-container h2' );
		heading?.style.setProperty( 'display', 'none' );
	}
}

window.addEventListener( 'load', init );
