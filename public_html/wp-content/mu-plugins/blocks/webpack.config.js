const path = require( 'path' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const NODE_ENV = process.env.NODE_ENV || 'development';

module.exports = {
	...defaultConfig,

	// We use a custom entry point since our source directory is not `src`.
	entry: {
		blocks: path.resolve( __dirname, 'source/blocks.js' ),
	},

	// Override the default filename to keep `min` in the name.
	output: {
		...defaultConfig.output,
		filename: '[name].min.js',
	},

	// Bring in sourcemaps for non-production builds.
	devtool: 'production' === NODE_ENV ? 'none' : 'cheap-module-eval-source-map',

	// We need to extend the module.rules & plugins to add the scss build process.
	module: {
		...defaultConfig.module,
		rules: [
			...defaultConfig.module.rules,
			{
				test    : /\.(sc|sa|c)ss$/,
				exclude : [ /node_modules/ ],
				use     : [ MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader' ],
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
