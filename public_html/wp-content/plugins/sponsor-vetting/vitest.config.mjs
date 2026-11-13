import { defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		include: [ 'routes/**/test/*.test.ts' ],
		// Unescaping a name needs a document.
		environment: 'jsdom',
		globals: false,
		restoreMocks: true,
	},
} );
