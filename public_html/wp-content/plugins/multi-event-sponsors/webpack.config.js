const fileSystem = require( 'fs' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const rootDirectory = process.cwd().replace( 'public_html/wp-content/plugins/multi-event-sponsors', '' );

const config = { ...defaultConfig };

if ( fileSystem.existsSync( rootDirectory + '.docker/wordcamp.test.key.pem' ) ) {
	config.devServer = {
		...defaultConfig.devServer,

		static: {
			directory: rootDirectory + 'public_html/',
		},

		// This may need to change if we ever use this on subdomains.
		host: 'central.wordcamp.test',

		// This is needed to work outside of wp-env, but may need to be expanded to account for more environments.
		//
		// `all` can't be used for security, though.
		// See https://github.com/WordPress/gutenberg/pull/28273#issuecomment-1036439982
		// See https://github.com/webpack/webpack-dev-server/issues/887#issuecomment-302801375
		allowedHosts: [ 'localhost', '127.0.0.1', 'central.wordcamp.test' ],

		server: {
			type: 'https',
			options: {
				key: fileSystem.readFileSync( rootDirectory + '.docker/wordcamp.test.key.pem' ),
				cert: fileSystem.readFileSync( rootDirectory + '.docker/wordcamp.test.pem' ),
			},
		},
	};
}

module.exports = config;
