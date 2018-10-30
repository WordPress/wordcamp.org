const webpack = require( 'webpack' );
const { exec } = require( 'child_process' );

const NODE_ENV = process.env.NODE_ENV || 'development';

const webpackConfig = {
	mode: NODE_ENV === 'production' ? 'production' : 'development',

	entry: {
		blocks: './assets/src/blocks.js',
	},
	output: {
		filename: '[name].min.js',
		path: __dirname + '/assets',
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
		],
	},
	plugins: [
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
			}
		},
	],
};

module.exports = webpackConfig;
