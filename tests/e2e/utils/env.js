/**
 * Local-environment plumbing shared by `playwright.config.js` and the specs.
 *
 * The dev stack's host bindings are overridable (see `.env.example` and
 * `.docker/readme.md`), so nothing here may assume `0.0.0.0:443` /
 * `localhost:1080`.
 */

const fileSystem = require( 'fs' );
const path = require( 'path' );

const PROJECT_ROOT = path.join( __dirname, '..', '..', '..' );

/**
 * Loads the project root's `.env` into `process.env` — the same file
 * `docker compose` reads, so the overrides a developer set for the stack are
 * the ones these tests target.
 *
 * Hand-rolled rather than pulling in `dotenv` for a handful of optional
 * integers. Values already present in the environment win, so an explicit
 * shell export — and CI, which has no `.env` at all — still take precedence.
 *
 * Called from `playwright.config.js`, which Playwright re-loads in every
 * worker process, so the assignments are visible to the specs too.
 */
function loadDotEnv() {
	const envPath = path.join( PROJECT_ROOT, '.env' );

	if ( ! fileSystem.existsSync( envPath ) ) {
		return;
	}

	for ( const line of fileSystem.readFileSync( envPath, 'utf8' ).split( '\n' ) ) {
		const match = line.match( /^\s*([\w.-]+)\s*=\s*(.*)$/ );

		if ( ! match ) {
			continue; // Blank line or `#` comment.
		}

		const [ , key, rawValue ] = match;

		if ( undefined === process.env[ key ] ) {
			process.env[ key ] = rawValue.trim().replace( /^["']|["']$/g, '' );
		}
	}
}

/**
 * The origin the events network is reachable at.
 *
 * A non-default `WORDCAMP_BIND_IP` doesn't appear here: the hostname is what
 * resolves to it (via the hosts file), and the TLS cert and the network
 * routing in `.docker/wp-config.php` are both keyed on that hostname.
 *
 * @return {string} e.g. `https://events.wordpress.test`.
 */
function siteOrigin() {
	const port = process.env.WORDCAMP_HTTPS_PORT || '443';

	// Only a non-default port belongs in the URL; 443 is implied by `https://`.
	return '443' === port ? 'https://events.wordpress.test' : `https://events.wordpress.test:${ port }`;
}

/**
 * The base URL of the dev stack's MailCatcher instance.
 *
 * Unlike `siteOrigin()` this one does follow `WORDCAMP_BIND_IP`: MailCatcher
 * is addressed by IP, with no hostname in the hosts file pointing at it.
 *
 * @return {string} e.g. `http://localhost:1080`.
 */
function mailcatcherUrl() {
	const bindIp = process.env.WORDCAMP_BIND_IP || '';
	const host = '' === bindIp || '0.0.0.0' === bindIp ? 'localhost' : bindIp;
	const port = process.env.WORDCAMP_MAILCATCHER_PORT || '1080';

	return `http://${ host }:${ port }`;
}

module.exports = { loadDotEnv, siteOrigin, mailcatcherUrl };
