# ScreenShot Preview

This block utilizes [mShots](https://github.com/Automattic/mShots) and renders a card preview.

## Full Site Editing themes

1. `require_once .../screenshot-preview/blocks.php` file.
1. Add `<!-- wp:wporg/screenshot-preview /-->` with the required attributes to the theme's `block-templates/*.html` files.

## Classic themes in the w.org network

The same as above, but instead of adding the block to `block-templates/*.html` files, you'd add it to `themes/{template}`:

```php
echo do_blocks( '<!-- wporg/screenshot-preview" /-->' );
```

## Attributes

| Name         | Type   | Description                                                              |
| ------------ | ------ | ------------------------------------------------------------------------ |
| link         | string | `href` for `<a>`                                                         |
| preview-link | string | `url` that is passed to `mShots`                                         |
| version      | string | Used to cache break [mShots](https://github.com/Automattic/mShots) cache |
