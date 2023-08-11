# Latest News

A block for use across the whole wp.org network. Display the latest news from WordPress.org.

This plugin is designed to be part of the wporg-mu-plugins repo, in the `blocks` directory. It does not have its own build step, it uses the build script in `wporg-mu-plugins`.

## Getting Started

Add this file to the `loader.php` file in `mu-plugins`.

```php
require_once __DIR__ . '/blocks/latest-news/latest-news.php';
```

The javascript and CSS are split between the `src` and `postcss` directories respectively, there shouldn't be CSS in the `src` directory. The files are built into a `build` folder by `wporg-mu-plugins`'s [build script](#). The `block.json` file loads the built CSS and JS.

Before you can use the block, you'll need to build it. In `wporg-mu-plugins`, run `npm run build` to build all projects, or `npm run build latest-news` to build just this project.
