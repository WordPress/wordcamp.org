# Global Font Subsets

Inter and EB Garamond are used across WordPress.org. This mu-plugin sets up local versions to load, rather than loading from Google fonts.

Sources:

- [Inter](https://github.com/rsms/inter), compressed and subsetted to woff2 with [glyphhanger](https://github.com/zachleat/glyphhanger)
- [EB Garamond](https://fonts.google.com/specimen/EB+Garamond), compressed and subsetted to woff2 with [glyphhanger](https://github.com/zachleat/glyphhanger)
- [IBM Plex Mono](https://fonts.google.com/specimen/IBM+Plex+Mono), compressed and subsetted to woff2 with [glyphhanger](https://github.com/zachleat/glyphhanger)

## How to use:

If you want to use these fonts in a theme, just add the `wporg-global-fonts` handle as a dependency.

```php
wp_register_style(
	'wporg-some-theme',
	get_stylesheet_uri(),
	array( 'wporg-global-fonts' ),
	$css_version
);
```

If you wish to have one (or more) font subsets preloaded automatically, you can call the global function `global_fonts_preload()`.

For example, to preload `Inter Latin`:

```php
global_fonts_preload( 'Inter', 'Latin' );
```

to preload `Inter Latin`, `Inter Cyrillic`, `EB Garamond Italic Latin`, and `EB Garamond Italic Cyrillic`:

```php
global_fonts_preload( 'Inter, EB Garamond Italic', 'Latin, Cyrillic' );
```