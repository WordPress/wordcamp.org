# Bumping a Composer dependency

`composer.json` lives in the git checkout root (`/home/wordcamp/`), but its `vendor-dir` (`public_html/wp-content/mu-plugins/vendor`) is inside the separate SVN working copy (`public_html`). No `composer.lock` is committed — deploys always resolve to the newest version matching each constraint.

## 1. Bump the constraint

Composer's `^` on a `0.x.y` version only allows patch bumps (`^0.5.0` = `<0.6.0`). For a minor/major bump, edit the constraint by hand — `composer update` won't cross that boundary on its own.

Check the new version's changelog for breaking changes and grep the repo for anything removed. Open a PR, let CI run (`composer install` fresh each time, so it'll catch new transitive deps). Watch for: a new dependency shipping a Composer plugin will fail CI with `PluginBlockedException` unless added to `config.allow-plugins` in `composer.json`.

## 2. Build on the server, after merging to `production`

```
cd /home/wordcamp
git pull origin production
composer update <package> --no-interaction   # scoped, not a blind full update
```

Verify: `grep -A5 '"name": "<package>"' public_html/wp-content/mu-plugins/vendor/composer/installed.json`

## 3. Sync to SVN

```
cd /home/wordcamp/public_html
php bin/php/multiple-use/miscellaneous/sync-svn-with-git.php
```

**Gotcha:** brand-new files/dirs Composer creates under `mu-plugins/vendor/` won't be picked up by the script — it only sees adds/deletes from the *git* diff, and `vendor/` is gitignored. `svn add` them yourself first:

```
svn add wp-content/mu-plugins/vendor/<new-package-dir>
svn add wp-content/mu-plugins/vendor/<pkg>/<any-new-file-in-an-existing-package>
svn status wp-content/mu-plugins/vendor   # confirm A/M only, no ?
```

**Bigger gotcha:** the script's `svn propset wordcamp:last-sync-git-rev` runs *before* the `[y/n]` confirmation prompt. If you decline (or it errors after that point), the working copy is left thinking everything through HEAD already synced — even though nothing committed. Next run's diff comes back empty, which mis-routes the script into `svn revert -R .../mu-plugins/vendor`, wiping any pending adds.

**Don't decline the prompt if you can avoid it.** If you already did, recover with:

```
svn propget wordcamp:last-sync-git-rev wp-content   # check if it's your HEAD (bad)
svn revert wp-content                                 # undo just that property change (no -R)
svn propget wordcamp:last-sync-git-rev wp-content     # confirm it's back to the real last sync
```

Then re-`svn add` whatever got reverted, and re-run the script.
