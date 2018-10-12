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
/******/ 	// define __esModule on exports
/******/ 	__webpack_require__.r = function(exports) {
/******/ 		Object.defineProperty(exports, '__esModule', { value: true });
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
/******/
/******/ 	// Load entry module and return exports
/******/ 	return __webpack_require__(__webpack_require__.s = "./assets/src/blocks.js");
/******/ })
/************************************************************************/
/******/ ({

/***/ "./assets/src/blocks.js":
/*!******************************!*\
  !*** ./assets/src/blocks.js ***!
  \******************************/
/*! no exports provided */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";
eval("__webpack_require__.r(__webpack_exports__);\n/* harmony import */ var _speakers__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./speakers */ \"./assets/src/speakers.js\");\n/* harmony import */ var _speakers__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_speakers__WEBPACK_IMPORTED_MODULE_0__);\n/**\n * Internal dependencies\n */\n\n\n//# sourceURL=webpack:///./assets/src/blocks.js?");

/***/ }),

/***/ "./assets/src/speakers.js":
/*!********************************!*\
  !*** ./assets/src/speakers.js ***!
  \********************************/
/*! no static exports found */
/***/ (function(module, exports) {

eval("// License: GPLv2+\nvar registerBlockType = wp.blocks.registerBlockType;\nvar InspectorControls = wp.editor.InspectorControls;\nvar _wp$components = wp.components,\n    ServerSideRender = _wp$components.ServerSideRender,\n    PanelBody = _wp$components.PanelBody,\n    PanelRow = _wp$components.PanelRow,\n    CheckboxControl = _wp$components.CheckboxControl,\n    RangeControl = _wp$components.RangeControl,\n    SelectControl = _wp$components.SelectControl,\n    TextControl = _wp$components.TextControl;\nvar el = wp.element.createElement,\n    data = WordCampBlocks.speakers || {};\nvar supports = {\n  className: false\n};\n/* todo We might be able to use this instead of the PHP version once this becomes fully native JS instead of SSR.\nconst schema = {\n\t'show_all_posts': {\n\t\ttype: 'boolean',\n\t\tdefault: true,\n\t},\n\t'posts_per_page': {\n\t\ttype: 'integer',\n\t\tminimum: 1,\n\t\tmaximum: 100,\n\t\tdefault: 10,\n\t},\n\t'track': {\n\t\ttype: 'array',\n\t\titems: {\n\t\t\ttype: 'string',\n\t\t\tenum: data.options.track, //todo\n\t\t}\n\t},\n\t'groups': {\n\t\ttype: 'array',\n\t\titems: {\n\t\t\ttype: 'string',\n\t\t\tenum: data.options.groups, //todo\n\t\t}\n\t},\n\t'sort': {\n\t\ttype: 'string',\n\t\tenum: data.options.sort, //todo\n\t\tdefault: 'title_asc',\n\t},\n\t'speaker_link': {\n\t\ttype: 'boolean',\n\t\tdefault: false,\n\t},\n\t'show_avatars': {\n\t\ttype: 'boolean',\n\t\tdefault: true,\n\t},\n\t'avatar_size': {\n\t\ttype: 'integer',\n\t\tminimum: 64,\n\t\tmaximum: 512,\n\t\tdefault: 100,\n\t},\n};\n*/\n\nvar schema = data.schema;\nregisterBlockType('wordcamp/speakers', {\n  title: data.l10n['block_label'],\n  description: data.l10n['block_description'],\n  icon: 'megaphone',\n  category: 'wordcamp',\n  attributes: schema,\n  supports: supports,\n  edit: function edit(props) {\n    var whichControls = [],\n        displayControls = [];\n\n    if (data.options['track'].length > 1) {\n      whichControls.push( // Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564\n      //el( PanelRow, {}, [\n      el(SelectControl, {\n        label: data.l10n['track_label'],\n        value: props.attributes['track'],\n        options: data.options['track'],\n        multiple: true,\n        onChange: function onChange(value) {\n          props.setAttributes({\n            'track': value\n          });\n        }\n      }) //] )\n      );\n    }\n\n    if (data.options['groups'].length > 1) {\n      whichControls.push( // Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564\n      //el( PanelRow, {}, [\n      el(SelectControl, {\n        label: data.l10n['groups_label'],\n        value: props.attributes['groups'],\n        options: data.options['groups'],\n        multiple: true,\n        onChange: function onChange(value) {\n          props.setAttributes({\n            'groups': value\n          });\n        }\n      }) //] )\n      );\n    }\n\n    whichControls.push(el(PanelRow, {}, [el(CheckboxControl, {\n      label: data.l10n['show_all_posts_label'],\n      checked: props.attributes['show_all_posts'],\n      onChange: function onChange(value) {\n        props.setAttributes({\n          'show_all_posts': value\n        });\n      }\n    })]));\n\n    if (!props.attributes['show_all_posts']) {\n      whichControls.push( // Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564\n      //el( PanelRow, {}, [\n      el(RangeControl, {\n        label: data.l10n['posts_per_page_label'],\n        value: props.attributes['posts_per_page'],\n        min: schema['posts_per_page'].minimum,\n        max: schema['posts_per_page'].maximum,\n        initialPosition: schema['posts_per_page'].default,\n        allowReset: true,\n        onChange: function onChange(value) {\n          props.setAttributes({\n            'posts_per_page': value\n          });\n        }\n      }) //] )\n      );\n    }\n\n    whichControls.push( // Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564\n    //el( PanelRow, {}, [\n    el(SelectControl, {\n      label: data.l10n['sort_label'],\n      value: props.attributes['sort'],\n      options: data.options['sort'],\n      onChange: function onChange(value) {\n        props.setAttributes({\n          'sort': value\n        });\n      }\n    }) //] )\n    );\n    displayControls.push(el(PanelRow, {}, [el(CheckboxControl, {\n      label: data.l10n['speaker_link_label'],\n      help: data.l10n['speaker_link_help'],\n      checked: props.attributes['speaker_link'],\n      onChange: function onChange(value) {\n        props.setAttributes({\n          'speaker_link': value\n        });\n      }\n    })]));\n    displayControls.push(el(PanelRow, {}, [el(CheckboxControl, {\n      label: data.l10n['show_avatars_label'],\n      checked: props.attributes['show_avatars'],\n      onChange: function onChange(value) {\n        props.setAttributes({\n          'show_avatars': value\n        });\n      }\n    })]));\n\n    if (props.attributes['show_avatars']) {\n      displayControls.push( // Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564\n      //el( PanelRow, {}, [\n      el(RangeControl, {\n        label: data.l10n['avatar_size_label'],\n        help: data.l10n['avatar_size_help'],\n        value: props.attributes['avatar_size'],\n        min: schema['avatar_size'].minimum,\n        max: schema['avatar_size'].maximum,\n        initialPosition: schema['avatar_size'].default,\n        allowReset: true,\n        onChange: function onChange(value) {\n          props.setAttributes({\n            'avatar_size': value\n          });\n        }\n      }) //] )\n      );\n    }\n\n    return [el(ServerSideRender, {\n      block: 'wordcamp/speakers',\n      attributes: props.attributes\n    }), el(InspectorControls, {}, [el(PanelBody, {\n      title: data.l10n['panel_which_title'],\n      initialOpen: true\n    }, whichControls), el(PanelBody, {\n      title: data.l10n['panel_display_title'],\n      initialOpen: false\n    }, displayControls)])];\n  },\n  save: function save() {\n    return null;\n  }\n});\n\n//# sourceURL=webpack:///./assets/src/speakers.js?");

/***/ })

/******/ });