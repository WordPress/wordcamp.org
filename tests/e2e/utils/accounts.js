const path = require( 'path' );

const AUTH_DIR = path.join( __dirname, '..', '..', '..', 'playwright', '.auth' );

/**
 * The test accounts (all `password`), one per role tier, mapped to the saved login `auth.setup.js` writes for each.
 *
 * Specs reuse a login with `test.use( { storageState: STORAGE_STATE.<account> } )` rather than logging in themselves.
 *
 * A fourth account, `organiser2` (editor), never logs in. It keeps `organiser1` from being the group's only
 * organizer, which changes what the sidebar offers, and is the member picked as a speaker.
 */
const STORAGE_STATE = {
	// Editor, "Organizer": group settings and both event-form surfaces.
	organiser1: path.join( AUTH_DIR, 'organiser1.json' ),
	// Author, "Event Organizer": the narrowest role that can create events.
	eventorganiser1: path.join( AUTH_DIR, 'eventorganiser1.json' ),
	// Subscriber, "Member": sees no management UI, and is who the other two message or pick as a speaker.
	member1: path.join( AUTH_DIR, 'member1.json' ),
};

module.exports = { STORAGE_STATE };
