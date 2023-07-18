const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,

	entry: {
		sessions: path.resolve( __dirname, 'js/src/session/index.js' ),
		speakers: path.resolve( __dirname, './js/src/speaker/index.js' ),
		organizers: path.resolve( __dirname, 'js/src/organizer/index.js' ),
		volunteers: path.resolve( __dirname, 'js/src/volunteer/index.js' ),
	},
};
