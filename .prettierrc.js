// Import the default config for core compatibility, but enable us to add some overrides as needed.
const defaultConfig = require( '@wordpress/prettier-config' );

module.exports = {
	...defaultConfig,
	printWidth: 115,
};
