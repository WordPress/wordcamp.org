const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,

	entry: {
		// 'tracker' is flagged by ad blockers, use different name.
		applications: './javascript/tracker/source/tracker.js',
	},

	output: {
		path: path.join( __dirname, 'javascript/tracker/build' ),
		filename: '[name].min.js',
	},
};
