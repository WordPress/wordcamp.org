/**
 * Flat ESLint config for WordPress Scripts 32+.
 *
 * Workspace lint scripts run from their package directories, so they pass this
 * root config explicitly.
 */
const wpPlugin = require( '@wordpress/eslint-plugin' );

const testUnitConfig = wpPlugin.configs[ 'test-unit' ].map( ( config ) => ( {
	...config,
	files: [ '**/@(test|__tests__)/**/*.js', '**/?(*.)test.js' ],
} ) );

module.exports = [
	{
		ignores: [ '**/build/**', '**/node_modules/**', '**/vendor/**', '**/*.min.js' ],
	},
	...wpPlugin.configs.recommended,
	...testUnitConfig,
	{
		settings: {
			'import/extensions': [ '.js', '.jsx', '.json' ],
			'import/resolver': {
				node: {
					extensions: [ '.js', '.jsx', '.json' ],
				},
				typescript: {
					extensions: [ '.js', '.jsx', '.json', '.ts', '.tsx' ],
				},
			},
		},
		languageOptions: {
			parserOptions: {
				requireConfigFile: false,
				babelOptions: {
					presets: [ require.resolve( '@wordpress/babel-preset-default' ) ],
				},
			},
			globals: {
				wp: 'readonly',
			},
		},
		rules: {
			'@wordpress/i18n-text-domain': [ 'error', { allowedTextDomain: [ 'wordcamporg' ] } ],
			'@wordpress/no-unused-vars-before-return': 'off',
			camelcase: 'off',
			'id-length': [
				'error',
				{
					min: 3,
					exceptions: [ '__', '_n', '_x', 'id', 'a', 'b', 'i', 'q1', 'q2', 'q3', '$' ],
				},
			],
			'jsdoc/require-returns-description': 'off',
			'max-len': [
				'error',
				{
					code: 115,
					ignoreUrls: true,
					ignoreTrailingComments: true,
					ignoreStrings: true,
					ignoreTemplateLiterals: true,
				},
			],
			'prefer-const': [
				'error',
				{
					destructuring: 'all',
				},
			],
			'react/no-multi-comp': [
				'error',
				{
					ignoreStateless: true,
				},
			],
			'require-jsdoc': 'off',
			'sort-imports': [
				'error',
				{
					ignoreDeclarationSort: true,
				},
			],
		},
	},
	{
		files: [
			'public_html/wp-content/mu-plugins/blocks/source/blocks/schedule/data.js',
			'public_html/wp-content/mu-plugins/blocks/source/blocks/schedule/inspector-controls.js',
			'public_html/wp-content/mu-plugins/blocks/source/blocks/schedule/utils/date.js',
			'source/blocks/schedule/data.js',
			'source/blocks/schedule/inspector-controls.js',
			'source/blocks/schedule/utils/date.js',
		],
		languageOptions: {
			globals: {
				WordCampBlocks: 'readonly',
			},
		},
	},
	{
		files: [
			'public_html/wp-content/plugins/wc-post-types/js/src/session/panel-info/index.js',
			'js/src/session/panel-info/index.js',
		],
		languageOptions: {
			globals: {
				WCPT_Session_Defaults: 'readonly',
			},
		},
	},
	{
		files: [
			'public_html/wp-content/plugins/wcpt/javascript/tracker/source/tracker.js',
			'public_html/wp-content/plugins/wcpt/javascript/tracker/source/stores/table-store.js',
			'javascript/tracker/source/tracker.js',
			'javascript/tracker/source/stores/table-store.js',
		],
		languageOptions: {
			globals: {
				wpcApplicationTracker: 'readonly',
			},
		},
	},
	{
		files: [
			'public_html/wp-content/plugins/wordcamp-speaker-feedback/assets/js/admin.js',
			'public_html/wp-content/plugins/wordcamp-speaker-feedback/assets/js/script.js',
			'assets/js/admin.js',
			'assets/js/script.js',
		],
		languageOptions: {
			globals: {
				jQuery: 'readonly',
				location: 'readonly',
				lodash: 'readonly',
				SpeakerFeedbackData: 'readonly',
			},
		},
		rules: {
			'vars-on-top': 'off',
			'wrap-iife': 'off',
		},
	},
];
