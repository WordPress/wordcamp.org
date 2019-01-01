const path = require( 'path' );
const webpack = require( 'webpack' );
const { exec } = require( 'child_process' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );

const NODE_ENV = process.env.NODE_ENV || 'development';

const externals = {
	react: 'React',
	'react-dom': 'ReactDOM',
	lodash: 'lodash',
};

const webpackConfig = {
	mode: NODE_ENV === 'production' ? 'production' : 'development',

	optimization: {
		minimize: true,
	},

	entry: {
		blocks: path.resolve( __dirname, 'assets/src/blocks.js' ),
	},
	output: {
		filename: '[name].min.js',
		path: path.resolve( __dirname, 'assets' ),
		// should we leave the js un-minified in dev mode, so that it's easier to debug?

		// the browser debugger isn't usable b/c it can only see the minified file, i'm guessing we need source maps enabled
		// er, well, it seems like it works sometimes, and doesn't others
	},
	module: {
		rules: [
			{
				test: /\.jsx?$/,
				exclude: [
					/node_modules/,
				],
				use: 'babel-loader',
			},
			{
				test: /\.(sc|sa|c)ss$/,
				exclude: [
					/node_modules/,
				],
				use: [
					MiniCssExtractPlugin.loader,
					'css-loader',
					'sass-loader',
				],
			},
		],
	},
	plugins: [
		new MiniCssExtractPlugin( {
			filename: '[name].min.css',
		} ),
		new webpack.DefinePlugin( {
			'process.env.NODE_ENV': JSON.stringify( NODE_ENV )
		} ),
	],
	externals,
};

module.exports = webpackConfig;
