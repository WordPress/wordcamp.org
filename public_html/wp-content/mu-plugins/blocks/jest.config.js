const path = require( 'path' );
const baseConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...baseConfig,
	globals: {
		CSS: {},
	},
	moduleNameMapper: {
		...( baseConfig.moduleNameMapper || {} ),
		'^uuid$': path.join( __dirname, 'jest/uuid-mock.js' ),
	},
};
