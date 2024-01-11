const isProduction = process.env.NODE_ENV === 'production';

const plugins = [
	// This has to run before any other plugins, to concatenate all files into one.
	require( 'postcss-import' ),

	require( 'postcss-custom-media' ),

	// Enable transforms for stage 2+, explictly enable nesting (stage 1).
	require( 'postcss-preset-env' )( {
		stage: 2,
		features: {
			'nesting-rules': true,
		},
	} ),
];

// Minify.
if ( isProduction ) {
	plugins.push( require( 'cssnano' ) );
}

module.exports = {
	plugins,
};
