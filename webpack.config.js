const webpack = require( 'webpack' );

const NODE_ENV = process.env.NODE_ENV || 'development';

const webpackConfig = {
	mode: NODE_ENV === 'production' ? 'production' : 'development',

	entry: {
		blocks: './assets/src/blocks.js',
	},
	output: {
		filename: '[name].js',
		path: __dirname + '/assets',
	},
	module: {
		rules: [
			{
				test: /\.js$/,
				exclude: [
					/node_modules/,
				],
				use: 'babel-loader',
			},
		],
	},
	plugins: [
		new webpack.DefinePlugin( {
			'process.env.NODE_ENV': JSON.stringify( NODE_ENV )
		} ),
	],
};

module.exports = webpackConfig;
