const { defineConfig, devices } = require( '@playwright/test' );

/**
 * E2E config for the Groups/GatherPress front-end integration.
 *
 * Assumes the local dev stack (`docker compose up`) is already running with
 * GatherPress installed and active on the groups network — see the
 * groups-gatherpress-compat-test skill for setup steps. This config does not
 * start a webServer itself; it targets the existing dev site.
 */
module.exports = defineConfig( {
	testDir: 'tests/e2e',
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	reporter: process.env.CI ? 'github' : 'list',
	use: {
		// Trailing slash matters: Playwright/URL resolution treats a
		// leading-slash path in `page.goto()` as relative to the origin
		// root, not to this path — specs must use relative paths without a
		// leading slash (e.g. `page.goto( 'members/' )`, not `'/members/'`)
		// or they'll silently drop the `/group/sunshine-coast-qld` prefix.
		baseURL: 'https://events.wordpress.test/group/sunshine-coast-qld/',
		// The local dev cert is self-signed (matches the `-k` flag used
		// throughout the project's curl-based test plans).
		ignoreHTTPSErrors: true,
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
