/**
 * WordPress dependencies
 */
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

function closeDropdown( store ) {
	const { context } = store;
	context.wporg.queryFilter.isOpen = false;
	context.wporg.queryFilter.form?.reset();

	const count = context.wporg.queryFilter.form?.querySelectorAll( 'input:checked' ).length;
	updateToggleLabel( store, count );
}

function updateToggleLabel( store, count ) {
	const { context } = store;
	const toggle = context.wporg.queryFilter.toggleButton;
	if ( ! toggle ) {
		return;
	}
	if ( toggle.querySelector( 'span' ) ) {
		toggle.querySelector( 'span' ).innerText = count;
	}
	if ( count ) {
		toggle.classList.remove( 'has-no-filter-applied' );
	} else {
		toggle.classList.add( 'has-no-filter-applied' );
	}
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
					updateToggleLabel( store, count );
				},
				clearSelection: ( store ) => {
					const { context } = store;
					context.wporg.queryFilter.form
						.querySelectorAll( 'input' )
						.forEach( ( input ) => ( input.checked = false ) );
					updateToggleLabel( store, 0 );
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
				focusFirstElement: ( { context, ref } ) => {
					if ( context.wporg.queryFilter.isOpen ) {
						ref.querySelector( 'form input:first-child' ).focus();
					}
				},
			},
		},
	},
} );
