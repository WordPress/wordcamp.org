const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );
const path = require( 'path' );

const NODE_ENV = process.env.NODE_ENV || 'development';

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

	// Bring in sourcemaps for non-production builds.
	devtool: 'production' === NODE_ENV ? 'none' : 'cheap-module-eval-source-map',

	module: {
		...defaultConfig.module,
		rules: [
			...defaultConfig.module.rules,
			{
				test: /\.(sc|sa|c)ss$/,
				exclude: [ /node_modules/ ],
				use: [ MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader' ],
			},
		],
	},

	plugins: [
		...defaultConfig.plugins,
		new MiniCssExtractPlugin( {
			filename: '[name].min.css',
		} ),
	],
};
