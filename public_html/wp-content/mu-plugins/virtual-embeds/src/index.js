/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import * as crowdcast from './crowdcast';
import * as streamtext from './streamtext';
import * as youtubeLiveChat from './youtube-live-chat';

[ crowdcast, streamtext, youtubeLiveChat ].forEach( ( block ) => {
	const { name, settings } = block;
	registerBlockType( name, settings );
} );
