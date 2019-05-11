/**
 * WordPress dependencies
 */
const { createContext } = wp.element;

const defaultContext = {
	attributes    : {},
	definitions   : {},
	entities      : {},
	setAttributes : {},
};

export const BlockContext = createContext( defaultContext );
