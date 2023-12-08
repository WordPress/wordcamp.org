/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { store as wpStore } from '@wordpress/interactivity';

// See https://github.com/WordPress/gutenberg/blob/37f52ae884a40f7cb77ac2484648b4e4ad973b59/packages/block-library/src/navigation/view-interactivity.js
const focusableSelectors = [
	'a[href]',
	'input:not([disabled]):not([type="hidden"]):not([aria-hidden])',
	'select:not([disabled]):not([aria-hidden])',
	'textarea:not([disabled]):not([aria-hidden])',
	'button:not([disabled]):not([aria-hidden])',
	'[contenteditable]',
	'[tabindex]:not([tabindex^="-"])',
];

/**
 * Toggles the overflow-x style of the query filter between 'hidden' and 'scroll'.
 *
 * In certain themes (e.g., showcase), an 'overflow-x: scroll' is added on mobile screens to always display
 * the horizontal scrollbar, indicating to users that there's more content to the right.
 * However, this persistent display feature causes the dropdown menu to be overlaid by the scrollbar
 * when opened (See issue https://github.com/WordPress/wporg-mu-plugins/issues/467#issuecomment-1754349676).
 * This function serves to address that issue.
 *
 */
function toggleOverflowX() {
	const filtersElement = document.querySelector( '.wporg-query-filters' );

	if ( filtersElement ) {
		const currentOverflowX = window.getComputedStyle( filtersElement ).overflowX;

		if ( 'hidden' === currentOverflowX ) {
			filtersElement.style.overflowX = 'scroll';
		} else if ( 'scroll' === currentOverflowX || 'auto' === currentOverflowX ) {
			filtersElement.style.overflowX = 'hidden';
		}
	}
}

function closeDropdown( store ) {
	const { context } = store;
	context.wporg.queryFilter.isOpen = false;
	context.wporg.queryFilter.form?.reset();

	const count = context.wporg.queryFilter.form?.querySelectorAll( 'input:checked' ).length;
	updateButtons( store, count );
	document.documentElement.classList.remove( 'is-query-filter-open' );

	toggleOverflowX();
}

function updateButtons( store, count ) {
	const { context } = store;
	if ( ! context.wporg.queryFilter.form ) {
		return;
	}

	const applyButton = context.wporg.queryFilter.form.querySelector( 'input[type="submit"]' );
	const clearButton = context.wporg.queryFilter.form.querySelector( '.wporg-query-filter__modal-action-clear' );

	// Only update the apply button if multiple selections are allowed.
	if ( context.wporg.queryFilter.hasMultiple ) {
		if ( count ) {
			/* translators: %s is count of currently selected filters. */
			applyButton.value = sprintf( __( 'Apply (%s)', 'wporg' ), count );
		} else {
			applyButton.value = __( 'Apply', 'wporg' );
		}
	}

	clearButton.setAttribute( 'aria-disabled', count ? 'false' : 'true' );
}

wpStore( {
	actions: {
		wporg: {
			queryFilter: {
				toggle: ( store ) => {
					const { context } = store;
					if ( context.wporg.queryFilter.isOpen ) {
						closeDropdown( store );
					} else {
						context.wporg.queryFilter.isOpen = true;
						document.documentElement.classList.add( 'is-query-filter-open' );
						toggleOverflowX();
					}
				},
				handleKeydown: ( store ) => {
					const { context, event } = store;
					// If Escape close the dropdown.
					if ( event.key === 'Escape' ) {
						closeDropdown( store );
						context.wporg.queryFilter.toggleButton.focus();
						return;
					}

					// Trap focus.
					if ( event.key === 'Tab' ) {
						// If shift + tab it change the direction.
						if (
							event.shiftKey &&
							window.document.activeElement === context.wporg.queryFilter.firstFocusableElement
						) {
							event.preventDefault();
							context.wporg.queryFilter.lastFocusableElement.focus();
						} else if (
							! event.shiftKey &&
							window.document.activeElement === context.wporg.queryFilter.lastFocusableElement
						) {
							event.preventDefault();
							context.wporg.queryFilter.firstFocusableElement.focus();
						}
					}
				},
				handleFormChange: ( store ) => {
					const { context } = store;
					const count = context.wporg.queryFilter.form.querySelectorAll( 'input:checked' ).length;
					updateButtons( store, count );
				},
				clearSelection: ( store ) => {
					const { context, ref } = store;
					if ( 'true' === ref.getAttribute( 'aria-disabled' ) ) {
						return;
					}
					context.wporg.queryFilter.form
						.querySelectorAll( 'input' )
						.forEach( ( input ) => ( input.checked = false ) );
					updateButtons( store, 0 );
				},
			},
		},
	},
	effects: {
		wporg: {
			queryFilter: {
				init: ( { context, ref } ) => {
					context.wporg.queryFilter.toggleButton = ref.querySelector( '.wporg-query-filter__toggle' );
					context.wporg.queryFilter.form = ref.querySelector( 'form' );

					if ( context.wporg.queryFilter.isOpen ) {
						const focusableElements = ref.querySelectorAll( focusableSelectors );
						context.wporg.queryFilter.firstFocusableElement = focusableElements[ 0 ];
						context.wporg.queryFilter.lastFocusableElement =
							focusableElements[ focusableElements.length - 1 ];
					}
				},
				checkPosition: ( { context, ref } ) => {
					if ( context.wporg.queryFilter.isOpen ) {
						const position = ref.getBoundingClientRect();
						if ( position.left < 0 ) {
							ref.style.left = 0;
						}
					}
				},
				focusFirstElement: ( { context, ref } ) => {
					if ( context.wporg.queryFilter.isOpen ) {
						ref.querySelector( 'form input:first-child' ).focus();
					}
				},
			},
		},
	},
} );
