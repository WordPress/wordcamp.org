#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * External dependencies
 */
const chalk = require( 'chalk' );
const fs = require( 'fs' ); // eslint-disable-line id-length
const path = require( 'path' );
const watch = require( 'node-watch' );
const { sync: spawn } = require( 'cross-spawn' );

/**
 * Internal dependencies
 */
const BUILD_SCRIPT = path.resolve( __dirname, './build.js' );
const PROJECTS_DIR = path.join( path.dirname( __dirname ), 'mu-plugins/blocks' );

// Based on https://github.com/WordPress/gutenberg/blob/trunk/bin/packages/watch.js.

let projectsToBuild = new Map();

/**
 * Determines whether a file exists.
 *
 * @param {string} filename
 *
 * @return {boolean} True if a file exists.
 */
function exists( filename ) {
	try {
		return fs.statSync( filename ).isFile();
	} catch ( error ) {}
	return false;
}

/**
 * Determine if a file is source code (JS or CSS).
 *
 * Filter down to src//*.js and postcss//*.pcss.
 * Exclude test files inside of __tests__ folders and files with a suffix of .test or .spec (e.g. blocks.test.js).
 *
 * @param {string} filename
 *
 * @return {boolean} True if the file is a source file.
 */
function isSourceFile( filename ) {
	// Only run this regex on the relative path, otherwise we might run
	// into some false positives when eg. the project directory contains `src`
	const relativePath = path.relative( process.cwd(), filename ).replace( /\\/g, '/' );

	return (
		[ /\/src\/.+\.(js|json|ts|tsx)$/, /\/postcss\/.+\.(pcss)$/ ].some( ( regex ) =>
			regex.test( relativePath )
		) && ! [ /\/__tests__\/.+/, /.\.(spec|test)\.js$/ ].some( ( regex ) => regex.test( relativePath ) )
	);
}

/**
 * Determine if a file is a built file (is in a build directory).
 *
 * @param {string} filename
 *
 * @return {boolean} True if the file is a build file.
 */
function isBuildFile( filename ) {
	const relativePath = path.relative( process.cwd(), filename ).replace( /\\/g, '/' );
	return /\/build\//.test( relativePath );
}

/**
 * Adds the project for a file to the list of things to build.
 *
 * @param {'update'} event    The event name
 * @param {string}   filename
 */
function addProject( event, filename ) {
	if ( exists( filename ) ) {
		try {
			const [ project ] = filename.replace( PROJECTS_DIR + '/', '' ).split( '/' );
			console.log( chalk.green( '->' ), `${ event }: ${ project }` );
			projectsToBuild.set( project, true );
		} catch ( error ) {
			console.log( chalk.red( 'Error:' ), `Unable to update file: ${ filename } - `, error );
		}
	}
}

// Start watching packages.
watch( PROJECTS_DIR, { recursive: true, delay: 500 }, ( event, filename ) => {
	// Double check whether we're dealing with a file that needs watching.
	if ( ! isSourceFile( filename ) || isBuildFile( filename ) ) {
		return;
	}

	addProject( event, filename );
} );

// Run a separate interval that calls the build script.
// This effectively acts as a throttle for building files.
setInterval( () => {
	const projects = Array.from( projectsToBuild.keys() );
	if ( projects.length ) {
		projectsToBuild = new Map();
		try {
			spawn( 'node', [ BUILD_SCRIPT, ...projects ], { stdio: 'inherit' } );
		} catch ( error ) {}
	}
}, 100 );

console.log( chalk.red( '->' ), chalk.cyan( 'Watching for changes...' ) );
