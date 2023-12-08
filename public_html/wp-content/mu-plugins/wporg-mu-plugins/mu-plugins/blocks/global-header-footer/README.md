# Global Header and Footer

⚠️ Changes here must be tested on all sites. See the old `header.php` for more info, until that's ported here.


## Full Site Editing themes

1. See `../../../README.md` for installation prerequisites.
1. `require_once .../global-header-footer/blocks.php` file. See `wporg-news-2021` as an example.
1. Add `<!-- wp:wporg/global-header /-->` to the theme's `block-templates/*.html` files.


## Classic themes in the w.org network

The same as above, but instead of adding the block to `block-templates/*.html` files, you'd add it to `themes/{name}/header.php`:

```php
echo do_blocks( '<!-- wp:wporg/global-header /-->' );
```

ℹ️ The block will output `<html>`, `wp_head()`, etc, so the above statement is the only thing you need in a `themes/{name}/header.php` file. The same is true for the footer and `</html>`, etc.

⚠️ You can't just `require header.php` directly, because the dynamic blocks need to be processed by `do_blocks()`, and `blocks.php` does additional work that's necessary.


## Classic themes in other networks

If a WP site isn't in the main w.org network (e.g., buddypress.org, *.wordpress.net, etc), then the setup is still the same:

```php
echo do_blocks( '<!-- wp:wporg/global-footer /-->' );
```

The only difference is that `get_global_styles()` fetches them via the endpoint rather than using `switch_to_blog()`.

See `r18316-dotorg` for an example.


## Non-WP software (like Trac, Codex, etc)

Use the API endpoints to get the markup and styles. See `register_routes()` for the endpoints. Examples:

**Trac**

Make sure to update these files if any markup has changed!

- Check out the folder locally (it wasn't on my sandbox)
  ```
  svn co https://meta.svn.wordpress.org/sites/trunk/trac.wordpress.org/templates
  ```
- Run `php templates/update-headers.php`. This script will use the header & footer rest API endpoints to parse out the markup, and sync it into the local trac template files ([source](https://github.com/wordpress/wordpress.org/blob/trunk/trac.wordpress.org/templates/update-headers.php)).
- Check the changes & commit the updated templates.
- No deploy needed, the templates are cached based on page hits, so they'll expire eventually.

**Codex**

`grab-wporg-header-footer.sh` runs on a cron and updates `header.inc`, then `skins/codex/Codex.php` includes them. See `r14081-deploy`. Codex has heavy page caching, only systems can clear the page cache.

**Planet**

`planet/bin/generate-index-template.sh` in `dotorg`.
