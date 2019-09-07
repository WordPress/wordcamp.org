const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const NODE_ENV = process.env.NODE_ENV || 'development';

module.exports = {
	...defaultConfig,

	// Override the default filename to keep `min` in the name.
	output: {
		...defaultConfig.output,
		filename: '[name].min.js',
	},

	// Bring in sourcemaps for non-production builds.
	devtool: 'production' === NODE_ENV ? 'none' : 'cheap-module-eval-source-map',

	// We need to extend the module.rules & plugins to add the SCSS build process.
	// @todo remove this when https://github.com/WordPress/gutenberg/issues/14801 is resolved.
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
