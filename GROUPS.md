# Groups / GatherPress

This document covers the plugins and theme involved in the [Meetup.com → GatherPress Migration](https://github.com/WordPress/wordcamp.org/milestone/17) project (replacing Meetup.com with an open-source, GatherPress-based platform for WordPress community groups and events), and the steps to deploy them to production.

## Folder structure

```
public_html/wp-content/
├── sunrise-groups.php
├── plugins/
│   ├── gatherpress/
│   └── gatherpress-alpha/
├── mu-plugins/
│   ├── groups/
│   ├── wporg-groups-frontend/
│   └── gatherpress-recurring-events/
├── themes/
│   └── groups-site/
└── miscellaneous/
    └── active-groups-map/
```

### `plugins/gatherpress`

Upstream [GatherPress](https://github.com/GatherPress/gatherpress) plugin — the core events/RSVP/venue engine this whole project is built on. Vendored as-is (not WordCamp-authored); upgraded by pulling in new releases rather than editing in place. In production, it is pinned to a specific GatherPress release through `svn:externals` on `wp-content/plugins/`, not via git. See [GatherPress upgrade](#gatherpress-upgrade).

- `includes/core/classes` — core PHP classes (events, RSVPs, venues, etc.)
- `includes/core/templates`, `includes/templates/blocks|calendar|admin` — block and admin markup
- `includes/data` — data layer

### `plugins/gatherpress-alpha`

Upstream GatherPress companion plugin (`Requires Plugins: gatherpress`) used to pull in not-yet-released GatherPress features/templates ahead of stable releases. Also vendored upstream code. **Does not exist in production** — it's activated temporarily when upgrading GatherPress, then removed once the upgrade is done.

- `includes/classes` — setup/bootstrap classes
- `includes/templates` — versioned block template HTML (e.g. `rsvp-0.32.0.html`), swapped in for upcoming GatherPress versions
- `includes/commands` — WP-CLI commands

### `mu-plugins/groups`

WordCamp-authored network-level glue code for the groups network (`events.wordpress.org` and group sites). This is where GatherPress is wired into WordCamp/wordpress.org infrastructure.

- `group-site-provisioning.php` — creates/configures a new group site
- `wporg-groups-archive.php` — group archive/directory logic
- `network-messaging.php` — cross-network messaging for groups
- `gatherpress-groups-tweaks.php` — WordCamp-specific tweaks/patches to GatherPress behavior
- `gatherpress-recurring-events.php` — network-level glue for the recurring-events feature
- `wporg-groups-frontend.php` — stub that loads the `wporg-groups-frontend` mu-plugin only on the groups network (see below)
- `tests/` — PHPUnit tests for the above

### `mu-plugins/wporg-groups-frontend`

WordCamp-authored front-end management UI for group sites on `events.wordpress.org`. Lets organizers create/edit GatherPress events without touching wp-admin, via a React modal talking to a REST API. Loaded only on the groups network via the `mu-plugins/groups/wporg-groups-frontend.php` stub above.

- `inc/` — PHP: `rest.php` (REST API), `capabilities.php`, `defaults.php`, `rsvp-questions.php`, `modal.php`, `blocks.php`, `sponsors.php`, `my-events.php`, `notifications.php`, `class-members-controller.php`
- `src/blocks/` — editor blocks (`group-settings`, `event-manage`, `event-rsvp`, `event-speakers`, `my-events`, `sponsors`, `group-members`, `group-membership`, `page-content`)
- `src/components/` — shared React components (e.g. recurrence controls)
- `build/` — compiled JS/CSS output (built via `npm run build`, not committed to run)
- `tests/`, `tests-js/` — PHPUnit and JS (Jest) tests

After any GatherPress version bump, or any change to `mu-plugins/wporg-groups-frontend`, `mu-plugins/groups`, or `themes/groups-site`, run the [`groups-gatherpress-compat-test`](https://github.com/WordPress/wordcamp.org/blob/production/.claude/skills/groups-gatherpress-compat-test/SKILL.md) skill to check front-end integration compatibility.

### `mu-plugins/gatherpress-recurring-events`

WordCamp-authored experimental GatherPress extension that stores recurring events as one editable series post with lightweight projected occurrence rows, instead of GatherPress's default of one post per occurrence.

- `includes/class-plugin.php` — bootstrap
- `includes/class-rule.php`, `class-occurrences.php` — recurrence rule definition and occurrence projection
- `includes/class-database.php`, `class-query.php` — storage and querying of occurrences
- `includes/class-rest-api.php` — REST endpoints
- `includes/class-admin.php` — wp-admin integration
- `includes/class-comments.php`, `class-context.php` — supporting glue
- `assets/` — front-end assets
- `tests/` — PHPUnit tests
- See its `README.md` for known limitations (calendar feeds and the admin list stay series-based, not per-occurrence, in the MVP).

### `themes/groups-site`

Block theme (FSE) for group sites — the public-facing side of `events.wordpress.org` group sites (event pages, archives, venue pages, member pages).

- `templates/` — page templates: `front-page.html`, `single-event.html`, `single-venue.html`, `archive-gatherpress_event.html`, `page-members.html`, `search.html`, `404.html`, etc.
- `parts/` — `header.html`, `footer.html`
- `patterns/` — reusable block patterns (`event-card.php`, `group-back-link.php`, `manage-event-cta.php`, etc.)
- `assets/`, `theme.json`, `style.css`, `functions.php`

### Other related files

- `public_html/wp-content/sunrise-groups.php` — sunrise-level domain/URL routing for group sites
- `public_html/wp-content/miscellaneous/active-groups-map` — static map of active groups (`index.html` + KML data), not a plugin/theme

## Deployment

### Pre-requisite

- Run `ssh-keygen`, and add your SSH key at https://github.com/settings/ssh/new
- Initial setup:

```
cd /home/wordcamp
git init .
git remote add origin git@github.com:WordPress/wordcamp.org.git
git fetch
git checkout production -f
```

### Steps to deploy

```
cd /home/wordcamp/public_html
git pull origin production
svnup-all.sh
php bin/php/multiple-use/miscellaneous/sync-svn-with-git.php
# Don't forget to press RETURN after it's committed, because it'll just stay
```

Finally:

```
deploy-wordcamp.sh
```

### Post-deploy

- Smoketest `skopje.wordcamp.org`, `wordcamp.org`, `events.wordpress.org` and https://events.wordpress.org/group/internal-testing-group/
- Test shipped changes in production

### GatherPress upgrade

1. Locally, switch `plugins/gatherpress` to the new version, then run the [`groups-gatherpress-compat-test`](https://github.com/WordPress/wordcamp.org/blob/production/.claude/skills/groups-gatherpress-compat-test/SKILL.md) skill and an end-to-end pass on it.
2. Open a PR with any compatibility fixes, test it manually, then squash and merge.
3. SSH to your sandbox and pull it in:

   ```
   cd /home/wordcamp/public_html
   git pull origin production
   svnup-all.sh
   ```

4. Bump the pinned version. GatherPress is pinned through `svn:externals`, so change its tag in the editor, then update the directory that holds the property (not `gatherpress/` itself, which would refresh against the old URL):

   ```
   cd /home/wordcamp/public_html/wp-content/plugins
   svn propedit svn:externals .
   svn update .
   ../../bin/wp-cli/wp plugin get gatherpress --field=version --url=https://events.wordpress.org/
   ```

5. Commit and deploy as usual:

   ```
   cd /home/wordcamp/public_html
   php bin/php/multiple-use/miscellaneous/sync-svn-with-git.php
   deploy-wordcamp.sh
   ```

6. Run `svnup-all.sh` again so that you fetch the new GP version on your sandbox
7. Run the `gatherpress-alpha` migrations below right away, then do the [post-deploy](#post-deploy) checks.

#### Running the `gatherpress-alpha` migrations

Most GatherPress releases change stored data (block markup, post meta, options). [`gatherpress-alpha`](https://github.com/GatherPress/gatherpress-alpha) ships the matching fixes, and `wp gatherpress alpha fix` applies them to every site that has GatherPress. Each site records the last alpha version it ran, so only the new fixes run.

The alpha version must match the deployed GatherPress version exactly, otherwise the plugin stays inert. Only run this after step 5.

```
cd /home/wordcamp/public_html
VERSION=0.36.0 # the GatherPress version you just deployed
URL=events.wordpress.org/group/internal-testing-group/

bin/wp-cli/wp plugin install "https://github.com/GatherPress/gatherpress-alpha/releases/download/${VERSION}/gatherpress-alpha.${VERSION}.zip" --activate --url=$URL
bin/wp-cli/wp gatherpress alpha fix --url=$URL
# Success: Fixes applied to N sites.
```

Next, run GatherPress's own per-site upgrade. It normally runs on `admin_init`, so a site stays on the old version (no `dbDelta` on its tables, no rewrite flush, stale `gatherpress_version`) until someone opens its wp-admin. This runs it now on every site alpha fixed, and prints each site with its new version:

```
bin/wp-cli/wp eval 'foreach ( get_sites( array( "number" => 0 ) ) as $s ) { switch_to_blog( $s->blog_id ); if ( get_option( "gatherpress_alpha_last_version" ) === GATHERPRESS_VERSION && get_option( "gatherpress_version" ) !== GATHERPRESS_VERSION ) { GatherPress\Core\Setup::get_instance()->check_plugin_version(); echo $s->domain . $s->path, " ", get_option( "gatherpress_version" ), PHP_EOL; } restore_current_blog(); }' --url=$URL
```

Then verify. This lists every site that has GatherPress with its `gatherpress_version` and `gatherpress_alpha_last_version`. Both columns should show the new version on every group site:

```
bin/wp-cli/wp eval 'foreach ( get_sites( array( "number" => 0 ) ) as $s ) { $v = get_blog_option( $s->blog_id, "gatherpress_version" ); if ( $v ) { echo $s->domain . $s->path, " ", $v, " ", get_blog_option( $s->blog_id, "gatherpress_alpha_last_version", "-" ), PHP_EOL; } }'
```

`bin/wp-cli/wp plugin get gatherpress --field=version` shows the deployed version.

Finally, clean up. The sandbox shares the production database, so the fixes above applied to production directly, but the plugin files only exist on your sandbox:

```
bin/wp-cli/wp plugin deactivate gatherpress-alpha --url=$URL
rm -rf wp-content/plugins/gatherpress-alpha
```
