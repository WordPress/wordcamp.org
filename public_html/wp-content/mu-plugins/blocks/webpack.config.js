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

	entry: {
		blocks: path.resolve( __dirname, 'assets/src/blocks.js' ),
	},
	output: {
		filename: '[name].min.js',
		path: path.resolve( __dirname, 'assets' ),
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
		{ // https://stackoverflow.com/a/49786887/402766
			apply: ( compiler ) => {
				compiler.hooks.afterEmit.tap( 'AfterEmitPlugin', () => {
					[
						'npm run php-l10n',
					].forEach( ( cmd ) => {
						exec(
							cmd,
							( err, stdout, stderr ) => {
								if ( stdout ) process.stdout.write( stdout );
								if ( stderr ) process.stderr.write( stderr );
							}
						);
					} );
				} );
			},
		},
	],
	externals,
};

module.exports = webpackConfig;
