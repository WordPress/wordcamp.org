const path = require( 'path' );
const webpack = require( 'webpack' );
const { exec } = require( 'child_process' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );

const NODE_ENV = process.env.NODE_ENV || 'development';

/**
 * Given a string, returns a new string with dash separators converted to
 * camelCase equivalent. This is not as aggressive as `_.camelCase` in
 * converting to uppercase, where Lodash will also capitalize letters
 * following numbers.
 *
 * @see https://github.com/WordPress/gutenberg/blob/master/webpack.config.js
 *
 * @param {string} string Input dash-delimited string.
 *
 * @return {string} Camel-cased string.
 */
function camelCaseDash( string ) {
	return string.replace(
		/-([a-z])/g,
		( match, letter ) => letter.toUpperCase()
	);
}

const gutenbergPackages = [
	'a11y',
	'annotations',
	'api-fetch',
	'autop',
	'blob',
	'blocks',
	'block-library',
	'block-serialization-default-parser',
	'block-serialization-spec-parser',
	'components',
	'compose',
	'core-data',
	'data',
	'date',
	'deprecated',
	'dom',
	'dom-ready',
	'edit-post',
	'editor',
	'element',
	'escape-html',
	'format-library',
	'hooks',
	'html-entities',
	'i18n',
	'is-shallow-equal',
	'keycodes',
	'list-reusable-blocks',
	'notices',
	'nux',
	'plugins',
	'redux-routine',
	'rich-text',
	'shortcode',
	'token-list',
	'url',
	'viewport',
	'wordcount',
];

const externals = {
	react: 'React',
	'react-dom': 'ReactDOM',
	lodash: 'lodash',
};

gutenbergPackages.forEach( ( name ) => {
	externals[ `@wordpress/${ name }` ] = {
		this: [ 'wp', camelCaseDash( name ) ],
	};
} );

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
