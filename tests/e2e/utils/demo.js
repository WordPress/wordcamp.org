const recordDemo = process.env.RECORD_DEMO === '1';

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'https://events.wordpress.test/group/sunshine-coast-qld/';

const demoUse = {
	viewport: { width: 1440, height: 900 },
	video: recordDemo ? { mode: 'on', size: { width: 1440, height: 900 } } : 'off',
};

/**
 * Keeps normal E2E runs fast while giving a recorded stakeholder demo a
 * brief, consistent visual beat between actions.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number}                          duration
 */
async function demoBeat( page, duration = 450 ) {
	if ( recordDemo ) {
		await page.waitForTimeout( duration );
	}
}

/**
 * Authenticate through WordPress's public login endpoint without putting the
 * login form into the stakeholder recording.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          username
 * @param {string}                          password
 */
async function loginForDemo( page, username, password ) {
	await page.request.get( 'wp-login.php' );
	const response = await page.request.post( 'wp-login.php', {
		form: {
			log: username,
			pwd: password,
			'wp-submit': 'Log In',
			redirect_to: baseURL,
			testcookie: '1',
		},
		maxRedirects: 0,
	} );

	if ( response.status() !== 302 ) {
		throw new Error( `Could not log in ${ username } for the demo.` );
	}
}

/**
 * Playwright's video records the page rather than the operating-system
 * pointer. Add a small cursor and click pulse so the recorded journey stays
 * easy to follow without affecting regular test runs.
 *
 * @param {import('@playwright/test').Page} page
 */
async function installDemoCursor( page ) {
	if ( ! recordDemo ) {
		return;
	}

	await page.addInitScript( () => {
		const install = () => {
			if ( document.getElementById( 'e2e-demo-cursor' ) ) {
				return;
			}

			const style = document.createElement( 'style' );
			style.textContent = `
				html { scroll-behavior: smooth !important; }
				#e2e-demo-cursor {
					background: #3858e9;
					border: 2px solid #fff;
					border-radius: 50%;
					box-shadow: 0 0 0 7px rgba(56, 88, 233, .25), 0 3px 10px rgba(0, 0, 0, .3);
					height: 16px;
					left: 50%;
					pointer-events: none;
					position: fixed;
					top: 50%;
					transform: translate(-50%, -50%);
					transition: transform 120ms ease, box-shadow 120ms ease;
					width: 16px;
					z-index: 2147483647;
				}
				#e2e-demo-cursor.is-clicking {
					box-shadow: 0 0 0 14px rgba(56, 88, 233, .12), 0 2px 6px rgba(0, 0, 0, .25);
					transform: translate(-50%, -50%) scale(.72);
				}
			`;
			document.head.appendChild( style );

			const cursor = document.createElement( 'div' );
			cursor.id = 'e2e-demo-cursor';
			document.body.appendChild( cursor );

			document.addEventListener( 'mousemove', ( event ) => {
				cursor.style.left = `${ event.clientX }px`;
				cursor.style.top = `${ event.clientY }px`;
			} );
			document.addEventListener( 'mousedown', () => {
				cursor.classList.add( 'is-clicking' );
				setTimeout( () => cursor.classList.remove( 'is-clicking' ), 180 );
			} );
		};

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', install, { once: true } );
		} else {
			install();
		}
	} );
}

/**
 * Return a REST nonce from GatherPress's public nonce endpoint.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<string>} REST nonce.
 */
async function getRestNonce( page ) {
	const response = await page.request.get( 'wp-json/gatherpress/v1/event/nonce' );
	const data = await response.json();
	return data.nonce;
}

module.exports = {
	baseURL,
	demoBeat,
	demoUse,
	getRestNonce,
	installDemoCursor,
	loginForDemo,
	recordDemo,
};
