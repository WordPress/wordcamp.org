const path = require( 'path' );
const baseConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...baseConfig,
	globals: {
		CSS: {},
	},
	moduleNameMapper: {
		...( baseConfig.moduleNameMapper || {} ),
		// Force uuid to resolve to its CJS entry instead of the ESM browser build.
		'^uuid$': path.join( __dirname, '../../../../node_modules/uuid/dist/index.js' ),
	},
};
