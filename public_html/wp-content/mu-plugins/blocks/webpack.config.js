const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,

	entry: {
		blocks: path.resolve( __dirname, './source/blocks.js' ),
		'live-schedule': path.resolve( __dirname, './source/blocks/live-schedule/front-end.js' ),
		'live-posts': path.resolve( __dirname, './source/hooks/latest-posts/front-end.js' ),
		'schedule-front-end': path.resolve( __dirname, './source/blocks/schedule/front-end.js' ),
	},

	// Override the default filename to keep `min` in the name.
	output: {
		...defaultConfig.output,
		filename: '[name].min.js',
	},
};
