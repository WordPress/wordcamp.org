/******/ (function(modules) { // webpackBootstrap
/******/ 	// The module cache
/******/ 	var installedModules = {};
/******/
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/
/******/ 		// Check if module is in cache
/******/ 		if(installedModules[moduleId]) {
/******/ 			return installedModules[moduleId].exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = installedModules[moduleId] = {
/******/ 			i: moduleId,
/******/ 			l: false,
/******/ 			exports: {}
/******/ 		};
/******/
/******/ 		// Execute the module function
/******/ 		modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
/******/
/******/ 		// Flag the module as loaded
/******/ 		module.l = true;
/******/
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/
/******/
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = modules;
/******/
/******/ 	// expose the module cache
/******/ 	__webpack_require__.c = installedModules;
/******/
/******/ 	// define getter function for harmony exports
/******/ 	__webpack_require__.d = function(exports, name, getter) {
/******/ 		if(!__webpack_require__.o(exports, name)) {
/******/ 			Object.defineProperty(exports, name, {
/******/ 				configurable: false,
/******/ 				enumerable: true,
/******/ 				get: getter
/******/ 			});
/******/ 		}
/******/ 	};
/******/
/******/ 	// getDefaultExport function for compatibility with non-harmony modules
/******/ 	__webpack_require__.n = function(module) {
/******/ 		var getter = module && module.__esModule ?
/******/ 			function getDefault() { return module['default']; } :
/******/ 			function getModuleExports() { return module; };
/******/ 		__webpack_require__.d(getter, 'a', getter);
/******/ 		return getter;
/******/ 	};
/******/
/******/ 	// Object.prototype.hasOwnProperty.call
/******/ 	__webpack_require__.o = function(object, property) { return Object.prototype.hasOwnProperty.call(object, property); };
/******/
/******/ 	// __webpack_public_path__
/******/ 	__webpack_require__.p = "";
/******/
/******/ 	// Load entry module and return exports
/******/ 	return __webpack_require__(__webpack_require__.s = 0);
/******/ })
/************************************************************************/
/******/ ([
/* 0 */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";
Object.defineProperty(__webpack_exports__, "__esModule", { value: true });
/* harmony import */ var __WEBPACK_IMPORTED_MODULE_0__speakers__ = __webpack_require__(1);
/* harmony import */ var __WEBPACK_IMPORTED_MODULE_0__speakers___default = __webpack_require__.n(__WEBPACK_IMPORTED_MODULE_0__speakers__);
/**
 * Internal dependencies
 */


/***/ }),
/* 1 */
/***/ (function(module, __webpack_exports__) {

"use strict";
// License: GPLv2+

var registerBlockType = wp.blocks.registerBlockType;
var InspectorControls = wp.editor.InspectorControls;
var _wp$components = wp.components,
    ServerSideRender = _wp$components.ServerSideRender,
    PanelBody = _wp$components.PanelBody,
    PanelRow = _wp$components.PanelRow,
    CheckboxControl = _wp$components.CheckboxControl,
    RangeControl = _wp$components.RangeControl,
    SelectControl = _wp$components.SelectControl,
    TextControl = _wp$components.TextControl;


var el = wp.element.createElement,
    data = WordCampBlocks.speakers || {};

var supports = {
	className: false
};

/* todo We might be able to use this instead of the PHP version once this becomes fully native JS instead of SSR.
const schema = {
	'show_all_posts': {
		type: 'boolean',
		default: true,
	},
	'posts_per_page': {
		type: 'integer',
		minimum: 1,
		maximum: 100,
		default: 10,
	},
	'track': {
		type: 'array',
		items: {
			type: 'string',
			enum: data.options.track, //todo
		}
	},
	'groups': {
		type: 'array',
		items: {
			type: 'string',
			enum: data.options.groups, //todo
		}
	},
	'sort': {
		type: 'string',
		enum: data.options.sort, //todo
		default: 'title_asc',
	},
	'speaker_link': {
		type: 'boolean',
		default: false,
	},
	'show_avatars': {
		type: 'boolean',
		default: true,
	},
	'avatar_size': {
		type: 'integer',
		minimum: 64,
		maximum: 512,
		default: 100,
	},
};
*/

var schema = data.schema;

registerBlockType('wordcamp/speakers', {
	title: data.l10n['block_label'],
	description: data.l10n['block_description'],
	icon: 'megaphone',
	category: 'wordcamp',
	attributes: schema,
	supports: supports,

	edit: function edit(props) {
		var whichControls = [],
		    displayControls = [];

		if (data.options['track'].length > 1) {
			whichControls.push(
			// Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564
			//el( PanelRow, {}, [
			el(SelectControl, {
				label: data.l10n['track_label'],
				value: props.attributes['track'],
				options: data.options['track'],
				multiple: true,
				onChange: function onChange(value) {
					props.setAttributes({ 'track': value });
				}
			})
			//] )
			);
		}

		if (data.options['groups'].length > 1) {
			whichControls.push(
			// Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564
			//el( PanelRow, {}, [
			el(SelectControl, {
				label: data.l10n['groups_label'],
				value: props.attributes['groups'],
				options: data.options['groups'],
				multiple: true,
				onChange: function onChange(value) {
					props.setAttributes({ 'groups': value });
				}
			})
			//] )
			);
		}

		whichControls.push(el(PanelRow, {}, [el(CheckboxControl, {
			label: data.l10n['show_all_posts_label'],
			checked: props.attributes['show_all_posts'],
			onChange: function onChange(value) {
				props.setAttributes({ 'show_all_posts': value });
			}
		})]));

		if (!props.attributes['show_all_posts']) {
			whichControls.push(
			// Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564
			//el( PanelRow, {}, [
			el(RangeControl, {
				label: data.l10n['posts_per_page_label'],
				value: props.attributes['posts_per_page'],
				min: schema['posts_per_page'].minimum,
				max: schema['posts_per_page'].maximum,
				initialPosition: schema['posts_per_page'].default,
				allowReset: true,
				onChange: function onChange(value) {
					props.setAttributes({ 'posts_per_page': value });
				}
			})
			//] )
			);
		}

		whichControls.push(
		// Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564
		//el( PanelRow, {}, [
		el(SelectControl, {
			label: data.l10n['sort_label'],
			value: props.attributes['sort'],
			options: data.options['sort'],
			onChange: function onChange(value) {
				props.setAttributes({ 'sort': value });
			}
		})
		//] )
		);

		displayControls.push(el(PanelRow, {}, [el(CheckboxControl, {
			label: data.l10n['speaker_link_label'],
			help: data.l10n['speaker_link_help'],
			checked: props.attributes['speaker_link'],
			onChange: function onChange(value) {
				props.setAttributes({ 'speaker_link': value });
			}
		})]));

		displayControls.push(el(PanelRow, {}, [el(CheckboxControl, {
			label: data.l10n['show_avatars_label'],
			checked: props.attributes['show_avatars'],
			onChange: function onChange(value) {
				props.setAttributes({ 'show_avatars': value });
			}
		})]));

		if (props.attributes['show_avatars']) {
			displayControls.push(
			// Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564
			//el( PanelRow, {}, [
			el(RangeControl, {
				label: data.l10n['avatar_size_label'],
				help: data.l10n['avatar_size_help'],
				value: props.attributes['avatar_size'],
				min: schema['avatar_size'].minimum,
				max: schema['avatar_size'].maximum,
				initialPosition: schema['avatar_size'].default,
				allowReset: true,
				onChange: function onChange(value) {
					props.setAttributes({ 'avatar_size': value });
				}
			})
			//] )
			);
		}

		return [el(ServerSideRender, {
			block: 'wordcamp/speakers',
			attributes: props.attributes
		}), el(InspectorControls, {}, [el(PanelBody, {
			title: data.l10n['panel_which_title'],
			initialOpen: true
		}, whichControls), el(PanelBody, {
			title: data.l10n['panel_display_title'],
			initialOpen: false
		}, displayControls)])];
	},

	save: function save() {
		return null;
	}
});

/***/ })
/******/ ]);