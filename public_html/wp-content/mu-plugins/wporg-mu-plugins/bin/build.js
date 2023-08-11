#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * External dependencies.
 */
const chalk = require( 'chalk' );
const fs = require( 'fs' ); // eslint-disable-line id-length
const path = require( 'path' );
const postcss = require( 'postcss' );
const rtlcss = require( 'rtlcss' );
const { sync: resolveBin } = require( 'resolve-bin' );
const { sync: spawn } = require( 'cross-spawn' );
const postCssConfig = require( '../postcss.config.js' );

/**
 * Build the JS files using webpack, if the `src` directory exists.
 *
 * @param {string} inputDir
 * @param {string} outputDir
 */
async function maybeBuildJavaScript( inputDir, outputDir ) {
	const project = path.basename( path.dirname( inputDir ) );
	if ( fs.existsSync( inputDir ) ) {
		// Set the src directory based on the relative location from projet root.
		process.env.WP_SRC_DIRECTORY = path.relative( path.dirname( __dirname ), inputDir );
		const { status, stdout } = spawn(
			resolveBin( 'webpack' ),
			[
				'--config',
				path.join( path.dirname( __dirname ), 'webpack.config.js' ),
				'--output-path',
				outputDir,
				'--color', // Enables colors in `stdout`.
			],
			{
				stdio: 'pipe',
			}
		);
		// Only output the webpack result if there was an issue.
		if ( 0 !== status ) {
			console.log( stdout.toString() );
			console.log( chalk.red( `Error in JavaScript for ${ project }` ) );
		} else {
			console.log( chalk.green( `JavaScript built for ${ project }` ) );
		}
	}
}

/**
 * Build the CSS files using PostCSS, if the `postcss` directory exists.
 *
 * @param {string} inputDir
 * @param {string} outputDir
 */
async function maybeBuildPostCSS( inputDir, outputDir ) {
	const project = path.basename( path.dirname( inputDir ) );
	if ( fs.existsSync( inputDir ) ) {
		if ( ! fs.existsSync( outputDir ) ) {
			fs.mkdirSync( outputDir );
		}

		const pcssRe = /^[^_].*\.pcss$/i;
		const files = fs.readdirSync( inputDir ).filter( ( name ) => pcssRe.test( name ) );

		for ( let i = 0; i < files.length; i++ ) {
			const inputFile = path.resolve( inputDir, files[ i ] );
			const outputFile = path.resolve( outputDir, files[ i ].replace( '.pcss', '.css' ) );
			const css = fs.readFileSync( inputFile );

			const result = await postcss( postCssConfig.plugins ).process( css, { from: inputFile } );
			result.warnings().forEach( ( warn ) => {
				console.log( chalk.yellow( `Warning in ${ project }:` ), warn.toString() );
			} );
			fs.writeFileSync( outputFile, result.css );

			const rtlResult = await postcss( [ ...postCssConfig.plugins, rtlcss ] ).process( css, {
				from: inputFile,
			} );
			rtlResult.warnings().forEach( ( warn ) => {
				console.log( chalk.yellow( `Warning in ${ project }:` ), warn.toString() );
			} );
			fs.writeFileSync( outputFile.replace( '.css', '-rtl.css' ), rtlResult.css );
		}
		console.log( chalk.green( `CSS built for ${ project }` ) );
	}
}

// If we have more paths that need building, we could switch this to an array.
const projectPath = path.join( path.dirname( __dirname ), 'mu-plugins/blocks' );
const cliProjects = process.argv.slice( 2 );
const projects = cliProjects.length
	? cliProjects
	: fs
			.readdirSync( projectPath )
			.filter( ( file ) => fs.statSync( path.join( projectPath, file ) ).isDirectory() );

/**
 * Build the files.
 * For each subfolder in `mu-plugins/blocks`…
 * 1. If there is a `src` folder, run the JS build.
 * 2. If there is a `postcss` folder, run the CSS build.
 *      Will build any top-level Sass files (unless they start with `_`).
 */
projects.forEach( async ( file ) => {
	const basePath = path.join( projectPath, file );

	try {
		const outputDir = path.resolve( path.join( basePath, 'build' ) );

		// We `await` because JS needs to be built first— the first webpack step deletes the build
		// directory, and could remove the built CSS if it was truely async.
		await maybeBuildJavaScript( path.resolve( path.join( basePath, 'src' ) ), outputDir );
		await maybeBuildPostCSS( path.resolve( path.join( basePath, 'postcss' ) ), outputDir );
	} catch ( error ) {
		console.log( chalk.red( `Error in ${ file }:` ), error.message );
	}
} );
