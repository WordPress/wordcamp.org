const baseConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...baseConfig,
	testMatch: [ '<rootDir>/tests-js/**/*.test.js' ],
	moduleNameMapper: {
		...baseConfig.moduleNameMapper,
		'\\.(png|jpe?g|gif|webp|svg)$': require.resolve( '@wordpress/jest-preset-default/scripts/style-mock.js' ),
	},
};
