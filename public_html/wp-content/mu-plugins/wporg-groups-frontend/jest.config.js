const baseConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...baseConfig,
	testMatch: [ '<rootDir>/tests-js/**/*.test.js' ],
};
