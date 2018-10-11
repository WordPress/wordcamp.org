var webpack = require( 'webpack' ),
	NODE_ENV = process.env.NODE_ENV || 'development',

	webpackConfig = {
		entry: {
			speakers: './speakers/src/speakers.js',
		},
		output: {
			filename: './[name]/[name].js',
			path: __dirname,
		},

		module: {
			loaders: [
				{
					test: /.js$/,
					loader: 'babel-loader',
					exclude: /node_modules/,
				},
			],
		},

		plugins: [
			new webpack.DefinePlugin( {
				'process.env.NODE_ENV': JSON.stringify( NODE_ENV )
			} ),
		]
	};

if ( 'production' === NODE_ENV ) {
	webpackConfig.plugins.push( new webpack.optimize.UglifyJsPlugin() );
}

module.exports = webpackConfig;
