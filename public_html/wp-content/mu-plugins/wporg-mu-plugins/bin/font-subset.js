#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Prerequisite:
 * 1. Install glyphhanger - https://github.com/zachleat/glyphhanger
 * Usage:
 * 1. Add {"type": "module"} to package.json.
 * 2. mkdir output fonts.
 * 3. Put all font files into /fonts and change the file name if it's hard to read.
 * 4. Change the fontFileName, fontWeight and fontFamily etc. accroding to the font you use in this script.
 * 5. npm run font-subset.
 * 6. Copy subsetting files and css styles to where they should be placed.
 * 7. remove output and fonts.
 * 8. Remove {"type": "module"} from package.json or the linter would prompt an error.
 */
import { spawn } from 'child_process';
import path from 'path';
import { renameSync, writeFile } from 'fs';

const alphabets = [
	{
		name: 'cyrillic-ext',
		unicodeRange: 'U+0460-052F, U+1C80-1C88, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F',
	},
	{
		name: 'cyrillic',
		unicodeRange: 'U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116',
	},
	{ name: 'greek-ext', unicodeRange: 'U+1F00-1FFF' },
	{ name: 'greek', unicodeRange: 'U+0370-03FF' },
	{
		name: 'vietnamese',
		unicodeRange:
			'U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+1EA0-1EF9, U+20AB',
	},
	{
		name: 'latin-ext',
		unicodeRange:
			'U+0100-024F, U+0259, U+1E00-1EFF, U+2020, U+20A0-20AB, U+20AD-20CF, U+2113, U+2C60-2C7F, U+A720-A7FF',
	},
	{
		name: 'latin',
		unicodeRange:
			'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+2074, U+20AC, U+2122, U+2212, U+2215, U+FEFF, U+FFFD',
	},
	{
		name: 'arrows',
		unicodeRange: 'U+2190-2199',
	},
];

const __dirname = process.cwd();
const fontFileName = 'Inter';
const fontFileExt = 'ttf';
const fontFamily = 'Inter';
const fontWeight = '100 900';
const fontStyle = 'normal';
const fontFinalDir = 'Inter';

for ( const alphabet of alphabets ) {
	await new Promise( ( resolve ) => {
		const glyphhangerProcess = spawn(
			'glyphhanger',
			[
				// Spaces in the whitelist breaks the CLI script
				`--whitelist=${ alphabet.unicodeRange.replace( /' '/g, '' ) }`,
				`--subset=./fonts/${ fontFileName }.${ fontFileExt }`,
				'--output=output',
				'--formats=woff2',
			],
			{ stdio: 'inherit' }
		);

		glyphhangerProcess.on( 'close', () => {
			// There's currently no way to change the
			// file name with glyphhanger. We need to manually change
			// it after the file is generated
			const oldPath = path.join( __dirname, `output/${ fontFileName }-subset.woff2` );
			const newPath = path.join( __dirname, `output/${ fontFileName }-${ alphabet.name }.woff2` );
			renameSync( oldPath, newPath );

			resolve();
		} );
	} );
}

let cssCode = '';

// Create our font face rules
// This would need to be modified for other weights and styles
alphabets.forEach( ( alphabet ) => {
	cssCode += `
	/* ${ alphabet.name } */
    @font-face {
		font-family: ${ fontFamily };
		font-weight: ${ fontWeight };
		font-style: ${ fontStyle };
      	font-display: swap;
      	src: url(./${ fontFinalDir }/${ fontFileName }-${ alphabet.name }.woff2) format("woff2");
      	unicode-range: ${ alphabet.unicodeRange };
    }
  `;
} );

// Determine where to save our file
const cssPath = path.join( __dirname, `output/${ fontFileName } - style.css` );

// Write our CSS file
writeFile(
	cssPath,
	`
/* ${ fontFileName } */
${ cssCode }
  `.trim(),
	{},
	() => {
		console.log( 'CSS file written to:', cssPath );
	}
);
